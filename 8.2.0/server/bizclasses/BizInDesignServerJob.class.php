<?php
/**
 * @package     Enterprise
 * @subpackage  BizClasses
 * @since       v4.2
 * @copyright   WoodWing Software bv. All Rights Reserved.
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
		$date = date('Y-m-d\TH:i:s', time());
		
		// remove all jobs older then 2 weeks ( automatic purge )
		require_once BASEDIR.'/server/utils/DateTimeFunctions.class.php';
		$purgedate = DateTimeFunctions::calcTime( $date, -1209600 ); 
		$result = DBInDesignServerJob::delete('queuetime <= ?', array($purgedate));
		if( DBInDesignServerJob::hasError() || is_null($result) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
		}		
		
		LogHandler::Log('idserver', 'INFO', "End certain jobs -> perhaps InDesign Server crashed..." );	
		$checkdate = DateTimeFunctions::calcTime( $date, -180 );  // 3 min timeout
		$activeJobs = DBInDesignServerJob::getActiveJobs($checkdate); 
		$timeOutDBStr = BizResources::localize('IDS_TIMEOUT');
		if ( $activeJobs ) foreach ( $activeJobs as $row ) {
			$update = false;
			if ( $row['foreground'] != 1 ) {
				$requeue = false;
				$timeout = false;
				LogHandler::Log( 'idserver', 'INFO', "Background job running longer then 3 mins... [" . $row['id'] . "]" );
				$serverUrl = self::createURL( $row['hostname'] ) . ':' . $row['portnumber'];
				LogHandler::Log( 'idserver', 'INFO', "Running on InDesign Server [$serverUrl]" );
				// background jobs running longer then 3 mins
				if ( !self::isResponsive( $serverUrl ) ) {
					LogHandler::Log( 'idserver', 'INFO', "InDesign Server is no longer responsive" );
					if ( $row['errorcode'] != -1 ) {
						$requeue = true;
					} else {
						$timeout = true;
					}
				} else {
					LogHandler::Log( 'idserver', 'INFO', "InDesign Server is responsive..." );
					// check if very small dummy job, on SAME server, is ready within 5 seconds, if so, server no longer busy...
					if ( self::isHandlingJobs( $serverUrl ) ) {
						LogHandler::Log( 'idserver', 'INFO', "InDesign Server is handling jobs, so no longer busy with ours..." );
						if ( $row['errormessage'] != 'REQUEUED' ) {
							$requeue = true;
						} else {
							$timeout = true;
						}
					} else {
						LogHandler::Log( 'idserver', 'INFO', "indesign server is responsive and not handling jobs -> so must be busy with our current job" );
					}
				}
				if ( $requeue ) {
					LogHandler::Log( 'idserver', 'INFO', "Requeue background job " );
					$values = array('starttime' => '', 'assignedserverid' => 0, 'errormessage' => 'REQUEUED'); 
					$update = true;
				}
				if ( $timeout ) {
					LogHandler::Log( 'idserver', 'INFO', "Job was already requeued before, set it to time-out" );
					$values = array('readytime' => $date, 'errorcode' => 'IDS_TIMEOUT', 'errormessage' => $timeOutDBStr); 
					$update = true;
				}
			} else {
				LogHandler::Log( 'idserver', 'INFO', "Foreground job running longer then 3 mins... -> set TIMEOUT" );
				$values = array('readytime' => $date, 'errorcode' => 'IDS_TIMEOUT', 'errormessage' => $timeOutDBStr); 
				$update = true;
			}
			if ( $update ) {
				$where = "`readytime` = '' AND `id` = ? ";
				$params = array( $row['id'] ); 
				$result = DBInDesignServerJob::update($values, $where, $params);
				if( DBInDesignServerJob::hasError() || $result === false ) {
					throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
				}
			}
		}
		
		// end all foreground jobs never started and queued longer then 3 mins...
		$values = array('readytime' => $date, 'errorcode' => 'IDS_TIMEOUT', 'errormessage' => $timeOutDBStr); 
		$where = "`foreground` = ? AND `assignedserverid` = 0 AND `readytime` = '' AND `queuetime` <= ?";
		$params = array(1, $checkdate); 
		$result = DBInDesignServerJob::update($values, $where, $params);
		if( DBInDesignServerJob::hasError() || $result === false ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
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
		$where = '`id` = ?';
		$params = array($jobid);
		$result = DBInDesignServerJob::delete($where, $params);

		if( DBInDesignServerJob::hasError() || is_null( $result )) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
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

		// reset
		$values = array('assignedserverid' => 0, 'starttime' => '', 'readytime' => '', 'errorcode' => '', 'errormessage' => '', 'scriptresult' => ''); 
		$where = '`id` = ?';
		$params = array($jobid); 
		$result = DBInDesignServerJob::update($values, $where, $params);
		if( DBInDesignServerJob::hasError() || $result === false ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
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
		$date = date('Y-m-d\TH:i:s', time());

		// prevent multiple background jobs with same task...
		if (!$foreground && $objId && $jobType) {
			DBInDesignServerJob::removeDuplicateJobs($objId, $jobType);
		}

		// insert our new job
		if (!$exclusiveLock) {$exclusiveLock = 0;}
		if (!$foreground) {$foreground = 0;}
		if (!$objId) {$objId = 0;} // Column cannot be null, has to be 0.
		require_once BASEDIR.'/server/dbclasses/DBVersion.class.php';
		$versionInfo = array();
		DBVersion::splitMajorMinorVersion($serverVersion, $versionInfo);

		$values = array('queuetime' => $date,
						'foreground' => $foreground,
						'jobscript' => '#BLOB#',
						'jobparams' => serialize($params),
						'jobtype' => $jobType,
						'objid' => $objId,
						'exclusivelock' => $exclusiveLock,
						'servermajorversion' => $versionInfo['majorversion'], 
						'serverminorversion' => $versionInfo['minorversion']);
		
		$result= DBInDesignServerJob::insert($values, $scriptText);

		if( DBInDesignServerJob::hasError() || is_null($result) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
		}		
		// return db generated job id
		return $result;  
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
			// BZ#27655: Do not use >2&1 since that makes the exec() call synchronous! 
			// Therefore, partially rolled back CL#55346 fix, which was made for BZ#22789.
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
	 * @return nothing
	 */			
	
	public static function runBackgroundJob( $dbh, $handler_id ) 
	{
		$dbh = $dbh; // To make analyzer happy.
		$morejobsavailable = 1;
		$lastProcessedJob = 0;
		
		while ( $morejobsavailable ) {
			$morejobsavailable = 0;
		  	$row = DBInDesignServerJob::getOldestBackgroundJob();
			if ( is_null( $row )) {
				throw new BizException( 'ERR_DATABASE', 'Server', '' ); //@todo proper error message
			}				
			$jobId = $row['firstjob']; 
			// found job is valid AND not the only job available AND not same as last processed job
			// BZ#11478 only run job if $jobId is valid
			if ( !empty($jobId) && $jobId > 0 ){
				if ($jobId != $row['maxjob'] && $jobId != $lastProcessedJob) {
					$morejobsavailable = 1;
					$lastProcessedJob = $jobId;
				}
				// check if available InDesign Servers
				require_once BASEDIR.'/server/dbclasses/DBInDesignServer.class.php';
				$availableServers = DBInDesignServer::isTotalAvailable();
				if ( is_null( $availableServers )) {
					throw new BizException( 'ERR_DATABASE', 'Server', '' ); //@todo proper error message
				}				
				if ($availableServers >= 1) { // there are more servers available
					if ($availableServers > 1 && $morejobsavailable) {// More servers available for more jobs 
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

		$tries = 0;
		$maxtries = 60; // BZ#21109
		$assignedserver = null;
		$nonResponsiveServers = array();
		$errCode = 'IDS_NOTAVAILABLE';

		// get available InDesign Server ( with no job assigned currently )
		while ( empty($assignedserver) && $tries < $maxtries ) {
			LogHandler::Log('idserver', 'DEBUG', "Find random available InDesign Server, try[$tries/$maxtries]" );
			$tries++;
			// 1 query to get all available servers...
			$idserversrow = false;
			$availableServers = DBInDesignServer::getAvailableServersForJob($jobId, $nonResponsiveServers);
			if ( $tries === 1 ) {
				$initialAvailableIDS = count( $availableServers );
			}	
			if ( is_null( $availableServers)) {
				throw new BizException( 'ERR_DATABASE', 'Server', '' ); //@todo proper error message
			}		
			if ($availableServers) {
				$randomKey = array_rand($availableServers);
				$idserversrow = $availableServers[$randomKey];
				unset($availableServers[$randomKey]);
			}
			while ( $idserversrow && empty($assignedserver) ) {
				$serverURL = self::createURL($idserversrow['hostname']).':'.$idserversrow['portnumber'];
				LogHandler::Log('idserver', 'DEBUG', "Checking InDesign Server [" . $idserversrow['description'] . "] at URL [$serverURL]" );
				$values =  array('assignedserverid' => $idserversrow['idsid']);
				$where = "`id` = ? AND ( `assignedserverid` = 0 OR `assignedserverid` = -1 )" ;
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
					$values =  array('assignedserverid' => 0);
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
					} else {
						$idserversrow = false; //break the innner while and try to get another ids row. 
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
  			// Check first if there is at least one IDS configured with a high enough version.
			$jobVersion = DBInDesignServerJob::getServerVersionOfJob(  $jobId ); 
			if ( !is_null($jobVersion) && !self::compareInDesignSeverVersions( $jobVersion )) {
				require_once BASEDIR.'/server/dbclasses/DBVersion.class.php';
				$requiredVersion = BizInDesignServer::convertInternalVersionToExternal( $jobVersion );
				LogHandler::Log('idserver', 'ERROR', "Serverjob ($jobId) requires $requiredVersion. No Indesign Server with version $requiredVersion or higher is available." );
			}
			// Check if there was an active IDS at all
			if ( $initialAvailableIDS > 0 && count( $nonResponsiveServers) ===  $initialAvailableIDS ) {
				LogHandler::Log('idserver', 'WARN', "None of the potential available InDesign Servers is responding. Check the InDesign Server configuration and run the WWTest page." );
			}	
			
			$result = array( 'errorNumber' => -1, 'errorString' => BizResources::localize($errCode) );
			if ( $foreground ) { 
				$values =  array('readytime' => $date, 'errorcode' => $errCode, 'errormessage' => BizResources::localize($errCode));
			} else { // reinsert background job
				$values =  array('starttime' => '', 'assignedserverid' => 0);
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
	 * Checks if there is at least one Indesign Sever available with the correct
	 * version (with a version at least as high as the passed one).
	 * @param string $version Version to check against..
	 * @return boolean Is available
	 */
	private static  function compareInDesignSeverVersions( $version ) 
	{	
		$indesignServers = DBInDesignServer::listInDesignServers();
		require_once BASEDIR.'/server/dbclasses/DBVersion.class.php';
		if ( $indesignServers ) foreach ( $indesignServers as $indesignServer ) {
			if ( version_compare( $version, $indesignServer->ServerVersion, '<=' )) {
				return true;
			}
		}

		return false;
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
		// assign job to ids
		LogHandler::Log('idserver', 'DEBUG', "Checking InDesign Server [{$idsObj->Description}] at URL [{$idsObj->ServerURL}]" );
		
		$values =  array('assignedserverid' => $idsObj->Id);
		$where = "`id` = ? AND `assignedserverid` = 0" ;
		$params = array( $jobId );
		$result = DBInDesignServerJob::update($values, $where, $params);
			
		if( DBInDesignServerJob::hasError() || $result === false ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
		}			

		if( self::isResponsive($idsObj->ServerURL) ) {
			// update execution times
			$date = date('Y-m-d\TH:i:s', time());
			$values =  array('starttime' => $date);
			$where = "`id` = ? " ;
			$params = array( $jobId );
			$result = DBInDesignServerJob::update($values, $where, $params);
			if( DBInDesignServerJob::hasError() || $result === false ) {
				throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
			}			
			return null; // ok
		} else {
			// log error for this job at DB
			$date = date('Y-m-d\TH:i:s', time());
			$errMsg = BizResources::localize('IDS_NOT_RESPONDING');
			$values =  array('readytime' => $date, 'errorcode' => 'IDS_NOT_RESPONDING', 'errormessage' => $errMsg);
			$where = "`id` = ? " ;
			$params = array( $jobId );
			$result = DBInDesignServerJob::update($values, $where, $params);
			if( DBInDesignServerJob::hasError() || $result === false ) {
				throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
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
		$where = '`id` = ? ';
		$params = array($jobId);
		$row = DBInDesignServerJob::selectRow($where, '*', $params); // All fields
		if( DBInDesignServerJob::hasError() || is_null($row) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
		}		
		$scriptText = $row['jobscript'];
		$params = unserialize($row['jobparams']);	
		
		$autolog = false;
		// if no logfile specified, create one ourself (autolog). This will end up in database and removed afterwards.
		if ( empty($params['logfile']) ) {
			$autolog = true;
			$params['logfile'] = WEBEDITDIRIDSERV.'autolog_'.$jobId.'.log';
		}
		
		$timeout = 3600; // seconds
		$defaultSocketTimeout = ini_get( 'default_socket_timeout' );
		ini_set( 'default_socket_timeout', $timeout ); // BZ#24309
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
		ini_set( 'default_socket_timeout', $defaultSocketTimeout );

		$requeue = false;
		if ( !is_array($jobResult) ) {
			$errcode = 'IDS_ERROR';
			$errstr = BizResources::localize('IDS_ERROR');
			/* needed exclusivelock, so we are sure there was no lock before we started!
			  , if indesign server crashes, remove possible lock done by script 
			    also remove locks for child objects locked by same IP and same USR */
			if ( $row['exclusivelock'] == 1 && isset($row['objid']) && $row['objid'] != '' ) {
				$where = '`object` = ? ';
				$objid = $row['objid'];
				$params = array($objid);
				require_once BASEDIR.'/server/dbclasses/DBObjectLock.class.php';
				$lockinfo = DBObjectLock::selectRow($where, '*', $params); // All fields
				if( DBObjectLock::hasError() || is_null($lockinfo) ) {
					throw new BizException( 'ERR_DATABASE', 'Server', DBObjectLock::getError() );
				}	
				if ( $lockinfo ) {
					$ip = $lockinfo['ip'];
					$usr = $lockinfo['usr'];
					LogHandler::Log('idserver', 'DEBUG', "Exclusive object still locked by [$usr] - ip [$ip], unlock object and child objects.");
					$sth = DBObjectLock::deleteLocksOfChildren($ip, $usr, $objid);	
					if( DBObjectLock::hasError() || is_null($sth) ) {
						throw new BizException( 'ERR_DATABASE', 'Server', DBObjectLock::getError() );
					}
					$sth = DBObjectLock::unlockObject($objid, '');
					if( DBObjectLock::hasError() || is_null($sth) ) {
						throw new BizException( 'ERR_DATABASE', 'Server', DBObjectLock::getError() );
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
				$values =  array('starttime' => '', 'assignedserverid' => 0, 'errormessage' => 'REQUEUED');
				$where = "`readytime` = '' AND `id` = ?";
				$params = array($row['id']);
				$result = DBInDesignServerJob::update($values, $where, $params);
				if( DBInDesignServerJob::hasError() || $result === false ) {
					throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
				}	
			} else {
				LogHandler::Log('idserver', 'INFO', 'Job failed due to IDS crash, but this job was already requeued last time' );
				$errstr = "Job was requeued, but was still causing error on InDesign Server";
				$requeue = false;
			}
		}
		
		if ( ! $requeue ) {
			if ( $errstr ) {
				$values =  array('readytime' => $date, 'scriptresult' => '#BLOB#', 'errorcode' => $errcode, 'errormessage' => $errstr );
			} else {
				$values =  array('readytime' => $date, 'scriptresult' => '#BLOB#', 'errorcode' => '', 'errormessage' => '' );
			}
			$where = '`id` = ?' ;
			$params = array($jobId);
			$result = DBInDesignServerJob::update($values, $where, $params, $scriptresult);
			if( DBInDesignServerJob::hasError() || $result === false ) {
				throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServerJob::getError() );
			}	
		}
		
		LogHandler::Log('idserver', 'INFO', "END handling job [$jobId]" );
		return $jobResult;
	}
}
?>