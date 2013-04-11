<?php

set_time_limit(3600);

require_once'../../config.php';
require_once BASEDIR.'/server/utils/FolderUtils.class.php';
require_once BASEDIR.'/config/plugins/ImageOriginals/ImageOriginalsUtils.class.php';
require_once BASEDIR.'/server/soap/WflClient.php';

$client = new WW_SOAP_WflClient();

try {
	require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnRequest.class.php';
	require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnResponse.class.php';
	$logonReq = new WflLogOnRequest( IO_USER, IO_PASSWORD, null, null, '', null,
			'ImageOriginalsDispatcher', 'v1.4', null, null, true );
	$logonResp = $client->LogOn($logonReq);
} catch ( BizException $e ) {
	echo 'Failed to logon the configured ImageOriginals user. Please check IO_USER and IO_PASSWORD options.'
	.' Error returned from server: '.$e->getMessage()."\n";
}

if( $logonResp->Ticket ) {

	try {
		require_once BASEDIR.'/server/interfaces/services/wfl/WflQueryObjectsRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflQueryObjectsResponse.class.php';
		$ArrayOfQueryParams = array();
		$ArrayOfQueryParams[] = new QueryParam ('LockedBy', '=', IO_USER);
		$ArrayOfQueryParams[] = new QueryParam ('Type', '=', 'Image');
		$queryObjectsReq = new WflQueryObjectsRequest( $logonResp->Ticket, $ArrayOfQueryParams,
					1, 0, false, null, null, array('ID') );
		$queryObjectsResp = $client->QueryObjects( $queryObjectsReq );

		$unlockObjects = array();
		foreach( $queryObjectsResp->Rows as $row ) {
			$unlockObjects[] = $row[0];
		}
		
		//print_r($unlockObjects); die();
		try {
			require_once BASEDIR.'/server/interfaces/services/wfl/WflUnlockObjectsRequest.class.php';
			require_once BASEDIR.'/server/interfaces/services/wfl/WflUnlockObjectsResponse.class.php';
			$unlockReq = new WflUnlockObjectsRequest( $logonResp->Ticket, $unlockObjects, null );
			$unlockResp = $client->UnlockObjects( $unlockReq );
		} catch( BizException $e ) {
			echo 'Failed to unlock objects.'.' Error returned from server: '.$e->getMessage()."\n";
		}

	} catch(BizException $e) {
		echo 'Failed to get objects locked by '.IO_USER
		.' Error returned from server: '.$e->getMessage()."\n";
	}

	$ioUtils = new ImageOriginalsUtils($logonResp->Ticket);
	FolderUtils::scanDirForFiles($ioUtils, IO_EXPORTDIR, unserialize(IO_SUPPORTED));

	try {
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffResponse.class.php';
		$logOffReq = new WflLogOffRequest( $logonResp->Ticket, false, null, null );
		$logoffResp = $client->LogOff( $logOffReq );
	} catch(BizException $e) {
		echo 'Failed to logoff the configured ImageOriginals user. Please check IO_USER and IO_PASSWORD options.'
		.' Error returned from server: '.$e->getMessage()."\n";
	}
}

?>