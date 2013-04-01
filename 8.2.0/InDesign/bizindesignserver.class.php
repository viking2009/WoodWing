<?php
/**
 * @package     Enterprise
 * @subpackage  BizClasses
 * @since       v6.5
 * @copyright   WoodWing Software bv. All Rights Reserved.
 */

require_once BASEDIR.'/server/dbclasses/DBInDesignServer.class.php';
 
class BizInDesignServer
{
	/**
	 * Returns list of InDesign Server configuration objects.
	 *
	 * @return array
	 */
	public static function listInDesignServers()
	{
		$idsObjs = DBInDesignServer::listInDesignServers();
		foreach( $idsObjs as &$idsObj ) {
			self::enrichServerObject( $idsObj );
		}
		return $idsObjs;
	}

	/**
	 * Retrieves one InDesign Server configuration (object) from DB.
	 *
	 * @param integer $idsId Server id
	 * @return object
	 */
	public static function getInDesignServer( $idsId )
	{
		$idsObj = DBInDesignServer::getInDesignServer( $idsId );
		self::enrichServerObject( $idsObj );
		return $idsObj;
	}

	/**
	 * Returns a new default InDesign Server configuration (object), NOT from DB.
	 *
	 * @return object
	 */
	public static function newInDesignServer()
	{
		$defaultVersion = self::getMaxSupportedVersion();
		$idsObj = new stdClass();
		$idsObj->Id = 0;
		$idsObj->HostName = '';
		$idsObj->PortNumber = 0;
		$idsObj->Description = '';
		$idsObj->Active = true;
		$idsObj->ServerVersion = $defaultVersion;
		self::enrichServerObject( $idsObj );
		return $idsObj;
	}

	/**
	 * Removes one InDesign Server configuration (object) from DB.
	 *
	 * @param integer $idsId Server id
	 * @throw BizException on DB error
	 */
	public static function deleteInDesignServer( $idsId )
	{
		$retVal = DBInDesignServer::deleteInDesignServer( $idsId );
		if( DBInDesignServer::hasError() || is_null($retVal) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServer::getError() );
		}
	}
	
	/**
	 * Updates one InDesign Server configuration (object) at DB.
	 *
	 * @param object $idsObj
	 * @return object
	 * @throw BizException on DB error
	 */
	public static function updateInDesignServer( $idsObj )
	{
		// Check whether InDesign Server exist in the DB
		$idsFound = DBInDesignServer::getInDesignServerByHostAndPort( $idsObj->HostName, $idsObj->PortNumber );
		if( $idsFound && $idsFound->Id != $idsObj->Id ) {
			$errorString = $idsObj->HostName . ':' . $idsObj->PortNumber;
			throw new BizException( 'ERR_SUBJECT_EXISTS', 'Client', null, null, array( '{IDS_INDSERVER}', $errorString ));
		}
		if( $idsObj->Id == 0 ) {
			$retObj = DBInDesignServer::createInDesignServer( $idsObj );
		} else {
			$retObj = DBInDesignServer::updateInDesignServer( $idsObj );
		}
		if( DBInDesignServer::hasError() || is_null($retObj) ) {
			throw new BizException( 'ERR_DATABASE', 'Server', DBInDesignServer::getError() );
		}
		return $retObj;
	}
	
	/**
	 * Walks through an InDesign Server configuration objects (at DB) for which the version 
	 * is undetermined yet. Those versions are marked with "-1" at DB. Nevertheless, the
	 * returned object has a repaired version, which is best guess. This version might be
	 * or might NOT be the correct version. Use autoDetectServerVersion function to detect.
	 * 
	 * @param integer $iterId Server id used for iteration. Set to zero for first call. Keep passing updated version for next calls.
	 * @return object The IDS configuration object. Null when no more found (all walked through).
	 */
	public static function nextIDSWithUnknownVersion( &$iterId )
	{
		if( is_null($iterId) ) {
			return null;
		}
		$iterId = DBInDesignServer::nextIDSWithUnknownVersion( $iterId );
		if( is_null($iterId) ) {
			return null;
		}
		return self::getInDesignServer( $iterId );
	}

	/**
	 * Calls a given InDesign Server to determines the ServerVersion property runtime.
	 *
	 * @param object $idsObj
	 */
	public static function autoDetectServerVersion( &$idsObj )
	{
		require_once BASEDIR.'/server/utils/InDesignServer.class.php';
		$prodInfoPathInDesignServer = WEBEDITDIRIDSERV.'idsdetectversion.dat';
		$prodInfoPath = WEBEDITDIR.'idsdetectversion.dat';
		if( file_exists( $prodInfoPath ) ) {
			unlink( $prodInfoPath ); // clear previous runs
		}
		$jobResult = InDesignServer::runScript( null,
						file_get_contents( BASEDIR.'/server/admin/idsdetectversion.js' ),
						array( 'respfile' => $prodInfoPathInDesignServer ),
						true, 'AUTO_DETECT_VERSIONS', null, // foreground, job type, object id
						false, null, $idsObj // exclusive lock, server version, server obj
					);
		if( is_array($jobResult) && $jobResult['errorNumber'] != 0 ) {
			$message = $jobResult['errorString'];
			throw new BizException( null, 'Server', '', $message );
		}

		if( file_exists( $prodInfoPath ) ) {
			$idsVersion = file_get_contents($prodInfoPath);
			$verInfo = array();
			$adobeVersions = unserialize( ADOBE_VERSIONS);
			preg_match( '/([0-9]+)\.([0-9]+)\.([0-9]+)\.([0-9]+)/', $idsVersion, $verInfo );
			if( count( $verInfo ) >= 4 ) { // major, minor, patch, build
				$idsVersion = "$verInfo[1].$verInfo[2]"; // major/minor
				if( $idsVersion != $idsObj->ServerVersion ) {
					if( in_array($idsVersion, $adobeVersions )) {
						$idsObj->ServerVersion = $idsVersion;
					}
				}
			}
		}
	}

	/**
	 * Lists the InDesign Server versions that are supported by current Enterprise Server.
	 * Typically used to fill combo boxes to let user pick configured version.
	 * 
	 * @return array Keys are internal versions. Values are display versions with 'CS' prefix.
	 */
	public static function supportedServerVersions( $idObj )
	{
		$adobeVersions = unserialize( ADOBE_VERSIONS);
		$idsVersions = array();
		if( !is_null($idObj) && $idObj->ServerVersion == -1 ) {
			$idsVersions[-1] = '???'; // needed to show undetermined versions
		}
		foreach ( $adobeVersions as $adobeVersion ) {
			$idsVersions[$adobeVersion] =  self::convertInternalVersionToExternal($adobeVersion); 
		}
		return $idsVersions;
	}

	/**
	 * Converts the internal Adobe version number to the public know 'CS'-version.
	 * @param string $internalVersion
	 * @return string externalversion
	 */
	public static function convertInternalVersionToExternal( $internalVersion )
	{
		return 'CS'.($internalVersion-2); // v5=CS3, v6=CS4, etc
	}	
	
	/**
	 * Validates the server version and repairs it to fit within supported range of CS versions.
	 * The version reparation is NOT reflected to DB yet (on purpose).
	 * And, it enriches the given InDesign Server configuration object with two extra properties:
	 * - ServerURL       => Full URL to server (including host name and port number).
	 * - DisplayVersion  => Server version translated for displaying purposes.
	 *
	 * @param object $idsObj
	 */
	public static function enrichServerObject( &$idsObj )
	{
		//$idsObj->ServerVersion = self::fixServerVersion( $idsObj->ServerVersion );
		$idsObj->ServerURL = self::createURL( $idsObj->HostName.':'.$idsObj->PortNumber );
		$idsObj->Name = empty($idsObj->Description) ? $idsObj->ServerURL : $idsObj->Description.' ('.$idsObj->ServerURL.')';
		$idsObj->DisplayVersion = ($idsObj->ServerVersion == -1) ? '???' : 'CS'.($idsObj->ServerVersion-2); // v5=CS3, v6=CS4, etc
	}

	/** Returns the maximum version number of supported Adobe versions.
	 *
	 * @return string version number in major.minor format.
	 */
	public static function getMaxSupportedVersion()
	{
		$IDSVersions = unserialize(ADOBE_VERSIONS);
		sort($IDSVersions, SORT_NUMERIC);
		$maxSupportedVersion = array_pop($IDSVersions);			

		return $maxSupportedVersion;
	}
	// - - - - - - - - - - - - - - - - PRIVATE FUNCTIONS - - - - - - - - - - - - - - - - - - - - - -

	/**
	 * Adds http:// to a url if it is not yet starting with http
	 *
	 * @param string $url - URL including ('http:' or 'https://') or excluding 'http:' prefix
	 * @return string $url - URL including 'http://' or 'https://'
	 */	
	private static function createURL( $url ) 
	{
		return ( substr($url,0,4) != 'http') ? 'http://' . $url : $url;
	}

	/**
	 * Returns a best guess of Server Version when given version is out-of-range of supported versions.
	 *
	 * @param integer $idsVersion Internal IDS version.
	 * @return integer Repaired version.
	 */
	/*private static function fixServerVersion( $idsVersion )
	{
		// -1 means undetermined; happens when DB has been migrated from v6.1 (or older).
		if( $idsVersion == -1 || $idsVersion > ADOBE_MAXVERSION ) { 
			$idsVersion = ADOBE_MAXVERSION; 
		}
		if( $idsVersion < ADOBE_MINVERSION ) {
			$idsVersion = ADOBE_MINVERSION; 
		}
		return $idsVersion;
	}*/
}