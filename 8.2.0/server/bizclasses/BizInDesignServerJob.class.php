<?php
/**
 * @package     Enterprise
 * @subpackage  BizClasses
 * @since       v4.2
 * @copyright   WoodWing Software bv. All Rights Reserved.
 *
 * v7.0.12 + startBackgroundJobs from v8.2.0 Build 60 
 */


// TODO: Move SQL to DB layer
require_once BASEDIR.'/server/dbclasses/DBInDesignServerJob.class.php';
require_once BASEDIR.'/server/interfaces/services/BizException.class.php';
require_once BASEDIR.'/server/protocols/soap/IdsSoapClient.php';

class BizInDesignServerJobs
{
	
	/**
	 * createURL - adds http:// to a url if it is not yet starting with http
	 *
	 * @param string $url URL including ('http:' or 'https://') or excluding 'http:' prefix
	 * 
	 * @return string $url - URL including 'http://' or 'https://'
	 * @todo Deprecated. Remove this when BizInDesignServer::createURL has fully taken care.
	 */	
	
	private static function createURL( $url ) 
	{
		return ( substr($url,0,4) != 'http') ? 'http://' . $url : $url;
	}
	
	/**
	 * isResponsive - checks if InDesign server port is responsive
	 *
	 * @param string $serverURL URL including 'http://' and ':'portnumber of InDesign Server
	 * 
	 * @return bool - responsive or not
	 */	
	 
	public static function isResponsive( $serverURL )
	{
		$url_parts = @parse_url( $serverURL );
		$host = $url_parts["host"];
		$port = $url_parts["port"];
		$errno = 0;
		$errstr = '';
		$socket = fsockopen( $host, $port, $errno, $errstr, 3 );
		if( $socket ) {
			fclose( $socket );
			return true;
		}
		LogHandler::Log('idserver', 'WARN', "Responsive check result [$errstr]" );
		return false;
	}
	
	/**
	 * isHandlingJobs - checks if InDesign server handles requests
	 *
	 * @param string $serverURL - URL including 'http://' and ':'portnumber of InDesign Server
	 * 
	 * @return bool - handling requests or not
	 */	
	 
	public static function isHandlingJobs( $serverURL )
	{
		$options = array( 'location' => $serverURL, 'connection_timeout' => 5 ); // time out 5 seconds
		$soapclient = new WW_SOAP_IdsSoapClient( null, $options );
		$scriptParams = array('scriptLanguage' => 'javascript');
		$scriptParams['scriptText'] = "
			// if InDesign Server has documents open.... it is still working on something
			function checkBusy () {
				app.consoleout('Server instances has -> [' + app.documents.length + '] documents open.');
				if ( app.documents.length > 0 ) {
					return 'BUSY';
				}
				
				return 'NOT BUSY';
			}
			checkBusy();
			";
		$soapParams = array('runScriptParameters' => $scriptParams );
		try {
			$jobResult = $soapclient->RunScript( $soapParams );
			$jobResult = (array)$jobResult; // let's act like it was before (v6.1 or earlier)
		} catch( SoapFault $e ) {
			$jobResult = null;
			LogHandler::Log('idserver', 'ERROR', 'Script failed: '.$e->getMessage() );
		} catch( Exception $e ) {
			$jobResult = null;
			LogHandler::Log('idserver', 'INFO', "Assume Server [$serverURL] is still busy [$e]");
		}
		if( is_array($jobResult) && $jobResult['errorNumber'] == 0 ){
			if ( $jobResult['scriptResult'] == "BUSY") {
				LogHandler::Log('idserver', 'INFO', "Server [$serverURL] is still busy" );
			} else {
				LogHandler::Log('idserver', 'INFO', "Server [$serverURL] is not busy" );	
				return true;
			}	
		}
		return false;
	}	

	/**
	 * cleanupJobs - Keep InDesignServerJobs table clean
	 * 
	 * + remove all jobs older then 2 weeks ( automatic purge )
	 * 
	 * + end started jobs that have:
	 * 	- assigned server active and responsive
	 * 		+ last longer then 10 minutes
	 * 		+ is foreground job and has not been started yet
	 *
	 * @param dbdriver $dbh - database handle
	 * 
	 * @return nothing
	 */
	
	public static function cleanupJobs() 
	{
		$dbh = DBDriverFactory::gen();
		
		$indservers = $dbh->tablename('indesignservers');
		$indserverjobs = $dbh->tablename('indesignserverjobs');
		$date = date('Y-m-d\TH:i:s', time());
		
		// remove all jobs older then 2 weeks ( automatic purge )
		require_once BASEDIR.'/server/utils/DateTimeFunctions.class.php';
		$purgedate = DateTimeFunctions::calcTime( $date, -1209600 ); 
		$sql = "delete from $indserverjobs where queuetime <= '$purgedate'";
		$sth = $dbh->query($sql);
		if( is_null($sth) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
		}
		
		LogHandler::Log('idserver', 'INFO', "End certain jobs -> perhaps InDesign Server crashed..." );	
		$checkdate = DateTimeFunctions::calcTime( $date, -180 );  // 3 min timeout
		$sql = "select b.`id`, a.`hostname`, a.`portnumber`, b.`foreground`, b.`errormessage` ".
		       " from $indservers a, $indserverjobs b " .
		       " where a.`active` = 'on' ".
		       " and a.`id` = b.`assignedserverid` ".
		       " and b.`readytime` is null ".
		         // job started 3 mins ago
		       " and ( b.`starttime` <= '$checkdate' ".
		         // or foreground job waiting for 3 mins for available server		       
		         " or (b.`foreground` = 1 and b.`starttime` is null and b.`queuetime` <= '$checkdate'))";
		$sth = $dbh->query($sql);
		$timeOutDBStr = $dbh->toDBString( BizResources::localize('IDS_TIMEOUT') );
		if ( $sth ) {
			$row = $dbh->fetch($sth);
			while ( $row )
			{
				$sql2 = '';
				if ( $row['foreground'] != 1 ) {
					$requeue = false;
					$timeout = false;					
					LogHandler::Log('idserver', 'INFO', "Background job running longer then 3 mins... [" . $row['id'] . "]");
					$serverUrl = self::createURL($row['hostname']).':'.$row['portnumber'];	
					LogHandler::Log('idserver', 'INFO', "Running on InDesign Server [$serverUrl]");
					// background jobs running longer then 3 mins
					if ( ! self::isResponsive($serverUrl) ) {
						LogHandler::Log('idserver', 'INFO', "InDesign Server is no longer responsive");	
						if ( $row['errorcode'] != -1 ) {
							$requeue = true;
						} else {
							$timeout = true;
						}
					} else {
						LogHandler::Log('idserver', 'INFO', "InDesign Server is responsive...");							
						// check if very small dummy job, on SAME server, is ready within 5 seconds, if so, server no longer busy...
						if ( self::isHandlingJobs( $serverUrl ) ) {
							LogHandler::Log('idserver', 'INFO', "InDesign Server is handling jobs, so no longer busy with ours...");
							if ( $row['errormessage'] != 'REQUEUED' ) {
								$requeue = true;
							} else {							
								$timeout = true;
							}
						} else {
							LogHandler::Log('idserver', 'INFO', "indesign server is responsive and not handling jobs -> so must be busy with our current job" );
						}
					}
					if ( $requeue ) {	
						LogHandler::Log('idserver', 'INFO', "Requeue background job ");
						$sql2 = "update $indserverjobs set `starttime` = null, `assignedserverid` = null, errormessage = 'REQUEUED' where `readytime` is null and `id` = ".$row['id'];
					}
					if ( $timeout ) {	
						LogHandler::Log('idserver', 'INFO', "Job was already requeued before, set it to time-out");
						$sql2 = "update $indserverjobs set `readytime` = '$date', `errorcode` = 'IDS_TIMEOUT', `errormessage` = '".$timeOutDBStr. "' where `readytime` is null and `id` = ".$row['id'];
					}
				}
				else {
					LogHandler::Log('idserver', 'INFO', "Foreground job running longer then 3 mins... -> set TIMEOUT" );
					$sql2 = "update $indserverjobs set `readytime` = '$date', `errorcode` = 'IDS_TIMEOUT', `errormessage` = '".$timeOutDBStr. "' where `readytime` is null and `id` = ".$row['id'];				
				}
				if ( $sql2 != '') {
					$sth2 = $dbh->query($sql2);
					if( is_null($sth2) ) {
						throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
					}
				}
				$row = $dbh->fetch($sth);
			}
		}
		
		// end all foreground jobs never started and queued longer then 3 mins...
		$sql = "update $indserverjobs set `readytime` = '$date', `errorcode` = 'IDS_TIMEOUT', `errormessage` = '".$timeOutDBStr. "' where `foreground` = 1 and `assignedserverid` is null and `readytime` is null and `queuetime` <= '$checkdate'";				
		$sth = $dbh->query($sql);
		if( is_null($sth) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
		}	
	}
	
	/**
	 * removeJob - Removes an InDesign Server job
	 *
	 * @param number $jobid 	- job id to remove
	 * 
	 * @return nothing
	 */	
	
	public static function removeJob( $jobid ) 
	{
		$dbh = DBDriverFactory::gen();

		$indserverjobs = $dbh->tablename('indesignserverjobs');		
		$sql = "delete from $indserverjobs where `id` = $jobid";
		$sth = $dbh->query($sql);
		if( is_null($sth) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
		}		
	}
	
	/**
	 * restartJob - Restarts an InDesign Server background job
	 *
	 * @param number $jobid 	- job id to remove
	 * 
	 * @return nothing
	 */	
	
	public static function restartJob( $jobid ) 
	{
		$dbh = DBDriverFactory::gen();

		$indserverjobs = $dbh->tablename('indesignserverjobs');	
		// reset	
		$sql = "update $indserverjobs set `assignedserverid` = null, `starttime` = null, `readytime` = null, `errorcode` = null, `errormessage` = null, `scriptresult` = null where `id` = $jobid";
		$sth = $dbh->query($sql);
		if( is_null($sth) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
		}		
		// try to start new handler
		self::startBackgroundJobs(false);
	}	
	
	/**
	 * createJob - Create a new InDesign Server job
	 *
	 * @param string $scriptText 	- script content
	 * @param key/value array $params - params for script
	 * @param integer $foreground 	- start this job on background, or do we need to execute job immediate..
 	 * @param string $jobType    	- what kind of job are we performing? ( Web Editor preview / lowres generation / page PDF... )
	 * @param string $objId      	- the object id of the SCE item we are performing this job for
	 * @param integer $exclusiveLock - should the object be unlocked before we can perform the job
	 * @param integer $serverVersion - Mininum required internal IDS version to run the job. Typically the version that was used to create the article/layout.
	 * 
	 * @return newly generated Job id
	 */	
	
	public static function createJob( $scriptText, $params, $foreground, $jobType, $objId, $exclusiveLock, $serverVersion ) 
	{
		$dbh = DBDriverFactory::gen();

		$indserverjobs = $dbh->tablename('indesignserverjobs');		
		$date = date('Y-m-d\TH:i:s', time());

		// prevent multiple background jobs with same task...
		if (!$foreground && $objId && $jobType) {
			DBInDesignServerJob::removeDuplicateJobs($objId, $jobType);
		}

		// insert our new job
		if (!$exclusiveLock) $exclusiveLock = 0;
		if (!$foreground) $foreground = 0;
		if (!$objId) $objId = 'null';
		require_once BASEDIR.'/server/dbclasses/DBVersion.class.php';
		$versionInfo = array();
		DBVersion::splitMajorMinorVersion($serverVersion, $versionInfo);
		$servermajorversion = $versionInfo['majorversion']; 
		$serverminorversion = $versionInfo['minorversion'];
		
		$sql = "insert into $indserverjobs (`queuetime` , `foreground`, `jobscript`, `jobparams`, `jobtype`, `objid`, `exclusivelock`, `servermajorversion`, `serverminorversion`) ";
		$sql .= "values ('$date', '$foreground', #BLOB#, ?, '$jobType',  $objId, '$exclusiveLock', $servermajorversion, $serverminorversion)";
		$sql = $dbh->autoincrement($sql);
		$sth = $dbh->query($sql, array( serialize($params) ), $scriptText);
		if( is_null($sth) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
		}

		// return db generated job id
		return $dbh->newid($indserverjobs, true);
	}
	
	/**
	 * execInBackground - Creates a background process
	 *
	 * @param string $command - command to start
	 * @param string $args    - parameters for command
	 * 
	 * @return process handle id ( or -1 when error starting process )
	 */	
		
	public static function execInBackground($command, $args = "") 
	{
		$return_value = 0;
	  	if(OS == 'WIN') { 
			$handle = popen("start \"dontremovethistext\" \"" . $command . "\" " . escapeshellarg($args), "r");
			if ( $handle ) {
				pclose($handle); 
			} else {
				$return_value = -1;
			}
		} else {
			$output= array();
			exec($command . " " . escapeshellarg($args) . " > /dev/null &", $output, $return_value);    
		}
		return $return_value;
	}
	
		/**
	 * startBackgroundJobs - Starts background process to handle background jobs
	 *  background jobs are handled by a seperate php page (InDesignServerBackGroundJobs.php)
	 * 	  this php page is loaded in a new proces with help of CURL
	 * 	  the php page will start InDesignServer::runBackgroundJobs() (below)* 
	 * 
	 * @param boolean $checkResponsiveness - if set, check if at least 1 server responds
	 * 										this can be done for backgroundjob handlers starting
	 * 										other backgroundjob handlers ( no real need for speed! )
	 * 
	 * @return nothing
	 */		
	
	public static function startBackgroundJobs( $checkResponsiveness = false )
	{
		$backgroundjobs = DBInDesignServerJob::getAvailableBGJobs();
		if( is_null($backgroundjobs) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', '');
		}		
		
		if ($backgroundjobs > 0) {
			// check if available InDesign Servers
			$row = null;
			$availableServers = 0;
			$tries = 1;
			$maxtries = 60; // BZ#22789

			require_once BASEDIR.'/server/dbclasses/DBInDesignServer.class.php';
			while( $availableServers == 0 && $tries <= $maxtries ) {
				// check if active server is really responsive!
				if ( $checkResponsiveness ) {
					$foundAvailableServers = DBInDesignServer::getAllAvailable();
					if ( is_null( $foundAvailableServers )) {
						throw new BizException( 'ERR_DATABASE', 'Server', '' ); //@todo proper error message
					}	
					if($foundAvailableServers) foreach ($foundAvailableServers as $row) {
						$serverURL = self::createURL($row['hostname']).':'.$row['portnumber'];
						if( self::isResponsive($serverURL)){
							$availableServers = 1; 
							// one active + responsive is enough!
							break;
						}
					}
				} else {
					$availableServers = DBInDesignServer::isTotalAvailable();
					if ( is_null( $availableServers )) {
						throw new BizException( 'ERR_DATABASE', 'Server', '' ); //@todo proper error message
					}	
				}
				if( $availableServers == 0 ) {
					// try again and again, wait for available InDesign Server...
					sleep(1);
					$tries++;
				}
			}

			if ($availableServers > 0) {
				// BZ#15335 set first job to reserved (= -1) so it won't be detected by the SQL above
				// and not too many curl processes will be started
				if (DBInDesignServerJob::setFirstJobReserved()){
					// start new curl process, this might start too many processes because another process might have
					// started the job during setFirstJobReserved. This doesn't give problems because the new process
					// will check if there are jobs and when not found it will quit (in this function)
					$url = SERVERURL_ROOT.INETROOT."/server/apps/InDesignServerBackGroundJobs.php";

					require_once BASEDIR.'/server/utils/InDesignServer.class.php';
					$curl = InDesignServer::getCurlPath();
					LogHandler::Log('idserver', 'INFO', "START background job with CURL [$curl]" );
					self::execInBackground($curl,$url);
					LogHandler::Log('idserver', 'INFO', "END background job with CURL [$curl]" );
				}
			} else {
				LogHandler::Log('idserver', 'INFO', "No active - responsive InDesign servers found." );
			}
		}
		return array( 'errorNumber' => 0, 'errorString' => '' );
			
	}
	
	/**
	 * runBackgroundJob - runs first background job available in jobs table
	 *
	 * @param dbdriver $dbh - database handle
	 * 
	 * @return nothing
	 */			
	
	public static function runBackgroundJob( $dbh, $handler_id ) 
	{
		
		$indservers = $dbh->tablename('indesignservers');			
		$indserverjobs = $dbh->tablename('indesignserverjobs');
		$objectlocks = $dbh->tablename('objectlocks');		
		$morejobsavailable = 1;
		$lastProcessedJob = 0;
		
		while ( $morejobsavailable ) {
			$morejobsavailable = 0;
			// select first inserted background job (FIFO)
			// when exclusivelock is requested, item should not exist in smart_objectlocks table
			$sql = "select min(id) as firstjob, max(id) as maxjob ".
							" from $indserverjobs ".
							" where `foreground` = 0 ".
// BZ#15335 not assigned ( NULL) or reserved ( -1 )
							" AND ( `assignedserverid` IS NULL OR `assignedserverid` = -1 )".
// not exclusive for a certain object	
							" and ((`exclusivelock` = 0) or ". 
// OR exclusive 
							"      (`exclusivelock` = 1 and ".  
// 		AND object not locked by other application
							"        not exists ( select 1 from $objectlocks where `object` = $indserverjobs.`objid` ) and ".
// 		AND not another job for same object running
							"        not exists ( select 1 from $indserverjobs `j` where ( j.`assignedserverid` IS NOT NULL AND j.`assignedserverid` != -1 ) and `j`.`objid` = $indserverjobs.`objid` and `j`.`readytime` is null) ) ".
							"     )";
			$sth = $dbh->query($sql);
			if( is_null($sth) ) {
				throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
			}			

			$row = $dbh->fetch($sth);
			$jobId = $row['firstjob'];

			// found job is valid AND not the only job available AND not same as last processed job
			// BZ#11478 only run job if $jobId is valid
			if ( !empty($jobId) && $jobId > 0 ){
				if ($jobId != $row['maxjob'] && $jobId != $lastProcessedJob) {
					$morejobsavailable = 1;
					$lastProcessedJob = $jobId;
				}
				// check if available InDesign Servers
				$sql = "select count(1) as cntservers from $indservers a where a.`active` = 'on' and not exists ( select 1 from $indserverjobs b where a.`id` = b.`assignedserverid` and b.`readytime` is null )";
				$sth = $dbh->query($sql);
				if( is_null($sth) ) {
					throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
				}
				$row = $dbh->fetch($sth);
				$availableServers = $row ? $row['cntservers'] : 0;
				if ($availableServers >= 1) { // there are more servers available
					if ($availableServers > 1 && $morejobsavailable) { 
						LogHandler::Log('idserver', 'INFO', "More servers [$availableServers] and jobs [$morejobsavailable] available, start new background job handler" );
						self::startBackgroundJobs(true); // try to use as many servers as possible
					}
					LogHandler::Log('idserver', 'INFO', "START handling job [$jobId] by background job handler [$handler_id]." );
					self::runJob( $jobId, false, null );
					LogHandler::Log('idserver', 'INFO', "END handling job [$jobId] by background job handler [$handler_id]." );					
				} else {
					$morejobsavailable = 0; // no available servers.... jobs will be picked up later on when another job ends
				}
			}
		}
		// clean up - we have time to purge now, running as background process
		self::cleanupJobs();
		self::startBackgroundJobs(true); // perhaps REQUEUED jobs...		
	}

/**
	 * Determines IDS instance that could run the given job.
	 * It selects from configured IDSs that are active, responsive and capable to handle document version.
	 * From those available IDSs, a random IDS is picked to do some kind of load balancing.
	 *
	 * @param integer $jobId          - job number
	 * @param boolean $foreground     - foreground or background job
	 * @param string $serverURL       - http path and port where IDS is listening for SOAP requests.
	 * @return array with errNumber and errString keys in case of error. Null when IDS was selected properly.
	 */
	private static function findServerForJob( $jobId, $foreground, &$serverURL )
	{
		require_once BASEDIR.'/server/dbclasses/DBInDesignServer.class.php';	

		$tries = 1;
		$maxtries = 60; // BZ#21109
		$assignedserver = null;
		$nonResponsiveServers = array();
		$errCode = 'IDS_NOTAVAILABLE';

		// get available InDesign Server ( with no job assigned currently )
		while ( empty($assignedserver) && $tries <= $maxtries ) {
			LogHandler::Log('idserver', 'DEBUG', "Find random available InDesign Server, try[$tries/$maxtries]" );
			$tries++;
			// 1 query to get all available servers...
			$idserversrow = false;
			$availableServers = DBInDesignServer::getAvailableServersForJob($jobId, $nonResponsiveServers);
			if ( is_null( $availableServers)) {
				throw new BizException( 'ERR_DATABASE', 'Server', '' ); //@todo proper error message
			}		
			if ($availableServers) {
				$randomKey = array_rand($availableServers);
				$idserversrow = $availableServers[$randomKey];
				unset($availableServers[$randomKey]);
			}
			while ( $idserversrow && empty($assignedserver) )
			{
				$serverURL = self::createURL($idserversrow['hostname']).':'.$idserversrow['portnumber'];
				LogHandler::Log('idserver', 'DEBUG', "Checking InDesign Server [" . $idserversrow['description'] . "] at URL [$serverURL]" );
				$values =  array('assignedserverid' => $idserversrow['idsid']);
				$where = "`id` = ? AND ( `assignedserverid` IS NULL OR `assignedserverid` = -1 )" ;
				$params = array( $jobId );
				$result = DBInDesignServerJob::update($values, $where, $params);
				if( DBInDesignServerJob::hasError() || $result === false ) {
					throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
				}			
				if ( ! $foreground ) {
					$where = '`id` = ? and `assignedserverid` = ?';
					$params = array($jobId, $idserversrow['idsid']);
					$jobsrow = DBInDesignServerJob::selectRow($where, array('id'), $params);
					if( DBInDesignServerJob::hasError() || is_null($jobsrow) ) {
						throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
					}	
					if ($jobsrow['id'] != $jobId) {
						$result = array( 'errorNumber' => 0, 'errorString' => 'Job already handled' );
						LogHandler::Log('idserver', 'INFO', "Job [$jobId] already handled" );							
						LogHandler::Log('idserver', 'INFO', "END handling job [$jobId]" );
						return $result;
					}
				}
					
				if( ! self::isResponsive($serverURL)){
					// remove server reservation for this job
					$values =  array('assignedserverid' => null);
					$where = '`id` = ?' ;
					$params = array( $jobId );
					$result = DBInDesignServerJob::update($values, $where, $params);
					if( DBInDesignServerJob::hasError() || $result === false ) {
						throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
					}
					$nonResponsiveServers[] = $idserversrow['idsid'];
					// Get next available server
					if ($availableServers) {
						$randomKey = array_rand($availableServers);
						$idserversrow = $availableServers[$randomKey];
						unset($availableServers[$randomKey]);
					}
				} else {
					// YES, we found an available server
					$assignedserver = $idserversrow['idsid']; 
					$date = date('Y-m-d\TH:i:s', time());
					$values =  array('starttime' => $date);
					$where = '`id` = ?' ;
					$params = array( $jobId );
					$result = DBInDesignServerJob::update($values, $where, $params);
					if( DBInDesignServerJob::hasError() || $result === false ) {
						throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
					}
				}
			}

			if ($foreground && empty($assignedserver) && $tries < $maxtries) {
				// try again and again, wait for available InDesign Server...
				sleep(1);
				$errCode = 'IDS_NOTAVAILABLE';
			}
		}
		
		$date = date('Y-m-d\TH:i:s', time());
		if (empty($assignedserver) ) {
			$result = array( 'errorNumber' => -1, 'errorString' => BizResources::localize($errCode) );
			if ( $foreground ) { 
				$values =  array('readytime' => $date, 'errorcode' => $errCode, 'errormessage' => BizResources::localize($errCode));
			} else { // reinsert background job
				$values =  array('starttime' => null, 'assignedserverid' => null);
			}
			$where = '`id` = ?' ;
			$params = array( $jobId );
			$result = DBInDesignServerJob::update($values, $where, $params);
			if( DBInDesignServerJob::hasError() || $result === false ) {
				throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
			}
			LogHandler::Log('idserver', 'INFO', "No InDesign Server available" );
			LogHandler::Log('idserver', 'INFO', "END handling job [$jobId]" );
			
			return $result;
		}
		return null;
	}

	/**
	 * Assigns given job to IDS and checks if IDS is responsive.
	 *
	 * @param integer $jobId    - job number
	 * @param object $idsObj    - The IDS to use to run the job.
	 * @return array with errNumber and errString keys in case of error. Null when IDS was assigned properly.
	 */
	private static function assignServerToJob( $jobId, $idsObj )
	{
		$dbh = DBDriverFactory::gen();	
		$indserverjobs = $dbh->tablename('indesignserverjobs');	

		// assign job to ids
		LogHandler::Log('idserver', 'DEBUG', "Checking InDesign Server [{$idsObj->Description}] at URL [{$idsObj->ServerURL}]" );
		$sql = "update $indserverjobs set `assignedserverid` = ". $idsObj->Id.
				" where `id` = $jobId and `assignedserverid` is null";
		$sth2 = $dbh->query($sql); // make reservation for this server 
		if( is_null($sth2) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
		}

		if( self::isResponsive($idsObj->ServerURL) ) {
			// update execution times
			$date = date('Y-m-d\TH:i:s', time());
			$sql = "update $indserverjobs set `starttime` = '$date' where `id` = $jobId";
			$sth = $dbh->query($sql);
			if( is_null($sth) ) {
				throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
			}
			return null; // ok
		} else {
			// log error for this job at DB
			$date = date('Y-m-d\TH:i:s', time());
			$errMsg = BizResources::localize('IDS_NOT_RESPONDING');
			$sql = "update $indserverjobs set `readytime` = ?, `errorcode` = ?, `errormessage` = ? where `id` = $jobId";
			$sth = $dbh->query($sql, array($date, 'IDS_NOT_RESPONDING', $errMsg));
			if( is_null($sth) ) {
				throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
			}
			return array( 'errorNumber' => -1, 'errorString' => $errMsg );
		}
	}
	
	/**
	 * runJob - runs a job, uses SOAP/Client.php to communicate with InDesign Server
	 *
	 * @param integer $jobId          - job number
	 * @param boolean $foreground     - foreground or background job
	 * @param object $idsObj          - The IDS to use to run the job. This by-passes automatic IDS selection.
	 * 
	 * @return InDesign Server result array. Returns null on IDS response failure.
	 * @throws BizException on fatal DB errors.
	 */		
	    
	public static function runJob( $jobId, $foreground, $idsObj = null ) 
	{
		// normal foreground processing...
		LogHandler::Log('idserver', 'INFO', "START handling job [$jobId]" );
		if( is_null($idsObj) ) {
			$serverURL = '';
			$result = self::findServerForJob( $jobId, $foreground, $serverURL );
		} else {
			$serverURL = $idsObj->ServerURL;
			$result = self::assignServerToJob( $jobId, $idsObj );
		}
		if( !is_null($result) ) {
			return $result;
		}

		$dbh = DBDriverFactory::gen();	
		$indserverjobs = $dbh->tablename('indesignserverjobs');	

		$sql = "select * from $indserverjobs where id = $jobId";
		$sth = $dbh->query($sql);
		if( is_null($sth) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
		}
		$row = $dbh->fetch($sth);
		$scriptText = $row['jobscript'];
		$params = unserialize($row['jobparams']);	
		
		$autolog = false;
		// if no logfile specified, create one ourself (autolog). This will end up in database and removed afterwards.
		if ( empty($params['logfile']) ) {
			$autolog = true;
			$params['logfile'] = WEBEDITDIRIDSERV.'autolog_'.$jobId.'.log';
		}
		
		$timeout = 3600; // seconds
		$options = array( 'location' => $serverURL, 'connection_timeout' => $timeout );
		$soapclient = new WW_SOAP_IdsSoapClient( null, $options );
		// also overrule PHP execution time-out
		// otherwise it might end our job and we cannot handle the result...
		set_time_limit($timeout+10);
		
		$scriptParams = array(
			'scriptText'     => $scriptText,
			'scriptLanguage' => 'javascript',
			'scriptArgs'     => array()
		);
		if( !empty($params) ) {
			foreach( $params as $key => $value ) {
				$scriptParams['scriptArgs'][] = array( 'name' => $key, 'value' => $value );
			}
		}
		$soapParams = array( 'runScriptParameters' => $scriptParams );

		// let InDesign Server do the job
		$errstr = null;
		$soapFault = null;
		try {
			$jobResult = $soapclient->RunScript( $soapParams );
			$jobResult = (array)$jobResult; // let's act like it was before (v6.1 or earlier)
		} catch( SoapFault $e ) {
			$jobResult = null;
			LogHandler::Log('idserver', 'ERROR', 'Script failed: '.$e->getMessage() );
			$soapFault = $e->getMessage();
		}

		$requeue = false;
		if ( !is_array($jobResult) ) {
			$errcode = 'IDS_ERROR';
			$errstr = BizResources::localize('IDS_ERROR');
			/* needed exclusivelock, so we are sure there was no lock before we started!
			  , if indesign server crashes, remove possible lock done by script 
			    also remove locks for child objects locked by same IP and same USR */
			if ( $row['exclusivelock'] == 1 && isset($row['objid']) && $row['objid'] != '' ) {
				$objectlocks = $dbh->tablename('objectlocks');
				$objid = $row['objid'];
				
				$sql = "select * from $objectlocks where object = $objid";
				$sth = $dbh->query($sql);
				if( is_null($sth) ) {
					throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
				}
				$lockinfo = $dbh->fetch($sth);
				
				if ( $lockinfo ) {
					$ip = $lockinfo['ip'];
					$usr = $lockinfo['usr'];
					
					LogHandler::Log('idserver', 'DEBUG', "Exclusive object still locked by [$usr] - ip [$ip], unlock object and child objects.");

					$placements = $dbh->tablename('placements');
					$sql = "delete from $objectlocks
	 				 		where ip = '".$dbh->toDBString($ip)."'
					 		and usr = '".$dbh->toDBString($usr)."'
					 		and object in ( select `child` from $placements where `type` = 'Placed' and `parent` = $objid )";
					$sth = $dbh->query($sql);
					if( is_null($sth) ) {
						throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
					}
					
					$sql = "delete from $objectlocks where `object` = " . $objid;
					$sth = $dbh->query($sql);	
					if( is_null($sth) ) {
						throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
					}
				}
			}
			if( $soapFault ) {
				$errstr .= ' '.$soapFault;
				if( $soapFault == 'Invalid HTTP Response' ){
					// IDS crashed
					if ( !$foreground ) {
						$requeue = true;
					}
				}
			}
		} else {
			if( $jobResult['errorNumber'] != 0 ){
				$errcode = $jobResult['errorNumber'];
				$errstr = $jobResult['errorString'];
				LogHandler::Log('idserver', 'DEBUG', 'Script failed: '.$errstr.' Error number: '.$errcode );
			}
		}	
		$scriptresult = '';
		if ( !empty($params['logfile']) ) {
			// correct path from InDesign server perspective to SCE server perspective
			$logfile = str_replace(WEBEDITDIRIDSERV, WEBEDITDIR, $params['logfile']);
			if( file_exists($logfile) ) {
				$scriptresult = file_get_contents($logfile);
				if ( $autolog == true ) {
					unlink($logfile);
				}
			}
		}
		
		$date = date('Y-m-d\TH:i:s', time());
		
		// REQUEUE mechanism
		// When IDS crashes, it does not have to be the job that is causing the crash
		// Therefore, try to process this job once more...
		
		if ( $requeue ) {
			if ( $row['errormessage'] != 'REQUEUED' ) { // only requeue once...
				LogHandler::Log('idserver', 'INFO', "Job failed due to IDS crash, REQUEUE this job once more..." );
				$sql = "update $indserverjobs set `starttime` = null, `assignedserverid` = null, errormessage = 'REQUEUED' where `readytime` is null and `id` = ".$row['id'];
			
				$sth = $dbh->query($sql);
				if( is_null($sth) ) {
					throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
				}
			} else {
				LogHandler::Log('idserver', 'INFO', 'Job failed due to IDS crash, but this job was already requeued last time' );
				$errstr = "Job was requeued, but was still causing error on InDesign Server";
				$requeue = false;
			}
		}
		
		if ( ! $requeue ) {
			if ( $errstr ) {
				$sql = "update $indserverjobs set `readytime` = '$date', `scriptresult` = #BLOB#, `errorcode` = '$errcode', `errormessage` = '".$dbh->toDBString($errstr)."' where `id` = $jobId";		
			} else {
				$sql = "update $indserverjobs set `readytime` = '$date', `scriptresult` = #BLOB#, `errorcode` = null, `errormessage` = null where `id` = $jobId";	
			}
			$sth = $dbh->query($sql, array(), $scriptresult);
			if( is_null($sth) ) {
				throw new BizException( 'ERR_DATABASE', 'Server', $dbh->error() );
			}
		}

		
		LogHandler::Log('idserver', 'INFO', "END handling job [$jobId]" );
		return $jobResult;
	}
}
?>