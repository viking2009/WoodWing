<?php

/**
 * @package 	Print plug-in for Enterprise
 * @since 		v7.6.6
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Interface for Print ( myTruePrint )
 */

require_once BASEDIR.'/server/interfaces/plugins/EnterprisePlugin.class.php';
require_once BASEDIR.'/server/interfaces/plugins/PluginInfoData.class.php';
require_once BASEDIR.'/config/plugins/myTruePrint/config.php';

class myTruePrint_EnterprisePlugin extends EnterprisePlugin
{
	public function getPluginInfo()
	{
		$info = new PluginInfoData();
		$info->DisplayName = 'myTruePrint';
		$info->Version     = 'v1.1.1'; // don't use PRODUCTVERSION
		$info->Description = 'Interface for Print ( myTruePrint )';
		$info->Copyright   = '(c) 2012-2013 iCenter Ukraine LTD';
		return $info;
	}

	final public function getConnectorInterfaces()
	{
		return array(	
				//'WflCopyObject_EnterpriseConnector',
				'WflSendTo_EnterpriseConnector',
				'WflSaveObjects_EnterpriseConnector',
				'WflSetObjectProperties_EnterpriseConnector'
				);
	}

function testMyTruePrintConfigServer()
{
	$errors = array();

	// The application server name determines if myTruePrint is enabled or not.
	if( trim(MYTP_SERVER_DEF_ID) == '' ) {
		$errors[] = 'myTruePrint is disabled. Set options to enable. The MYTP_SERVER_DEF_ID option tells if myTruePrint is enabled or not.';
	}

	// Check if the myTruePrint user is configured and try to logon/logoff 
	if( trim(MYTP_USER) == '' ) {
		$errors[] = 'No myTruePrint user name specified. Please check MYTP_USER option.';
	}
	if( trim(MYTP_PASSWORD) == '' ) {
		$errors[] = 'No myTruePrint user password specified. Please check MYTP_PASSWORD option.';
	}
	require_once BASEDIR.'/server/protocols/soap/WflClient.php';
	try {			
		$client = new WW_SOAP_WflClient();

		// LogOn
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnResponse.class.php';
		$logonResp = $client->LogOn( new WflLogOnRequest( 
			// $User, $Password, $Ticket, $Server, $ClientName, $Domain,
			MYMTP_USER, MYMTP_PASSWORD, '', '', '', '',
			//$ClientAppName, $ClientAppVersion, $ClientAppSerial, $ClientAppProductKey, $RequestTicket
			$this->getPluginInfo()->DisplayName.' Test', $this->getPluginInfo()->Version, '', '', true ) );
	
		// LogOff
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffResponse.class.php';
		/*$logoffResp =*/ $client->LogOff( new WflLogOffRequest( $logonResp->Ticket, false, null, null ));
			
	} catch( SoapFault $e ) {
		if( stripos( $e->getMessage(), '(S1053)' ) !== false ) { // wrong user/password?
			$errors[] = 'Make sure the TESTSUITE setting at "' . BASEDIR . '/config/plugins/myTruePrint/config.php" has options named "MYMTP_USER" and "MYMTP_PASSWORD". '.
					'That should be an existing user account at your server installation. '.
					'For new installations, typically the defaults "woodwing" and "ww" are used.';
		} else if( preg_match( '/(S2[0-9]{3})/is', $e->getMessage() ) > 0 ) { // S2xxx code => license error
			$errors[] = 'See license test for more details.';
		} else {
			$errors[] = $e->getMessage();
		}
	} catch( BizException $e ) {
		$errors[] = 'Please check your LOCALURL_ROOT setting at the "' . BASEDIR . '/config/config.php" file. Make sure the server can access that URL.';
	}

	// Check if out folder are configured correctly and accessable		
	if( trim(MYTP_IDS_FOLDER_OUT) == '' ) {
		$errors[] = 'No myTruePrint out-folder specified. Please check MYTP_IDS_FOLDER_OUT option';
	}
	if( strrpos(MYTP_IDS_FOLDER_OUT,'/') != (strlen(MYTP_IDS_FOLDER_OUT)-1) ) {
		$errors[] = 'The specified myTruePrint out-folder has no slash (/) at the end. Please check MYTP_IDS_FOLDER_OUT option.';
	}

	// Check if presets folder are configured correctly and accessable		
	if( trim(MYTP_IDS_PRESETS_PATH) == '' ) {
		$errors[] = 'No myTruePrint presets folder specified. Please check MYTP_IDS_PRESETS_PATH option.';
	}
	if( strrpos(MYTP_IDS_PRESETS_PATH,'/') != (strlen(MYTP_IDS_PRESETS_PATH)-1) ) {
		$errors[] = 'The specified myTruePrint presets folder has no slash (/) at the end. Please check MYTP_IDS_PRESETS_PATH option.';
	}

	// Check if post process is configured and can be ping-ed
	if( trim(MYTP_POSTPROCESS_LOC) == '' ) {
		$errors[] =  'No myTruePrint post process specified. Please check MYTP_POSTPROCESS_LOC option.';
	}
	$urlParts = @parse_url( MYTP_POSTPROCESS_LOC );
	if( !$urlParts || !isset($urlParts["host"]) ) {
		$errors[] = 'The specified myTruePrint post process is not valid. Please check MYTP_POSTPROCESS_LOC option.';
	}
	$host = $urlParts["host"];
	$port = isset($urlParts["port"]) ? $urlParts["port"] : 80;
	$errno = 0;
	$errstr = '';
	$socket = @fsockopen( $host, $port, $errno, $errstr, 5 );
	if( !$socket ) {
		$errors[] = 'The specified myTruePrint post process is not responsive ('.$errstr.'). Please check MYTP_POSTPROCESS_LOC option.';
	}
	fclose( $socket );
	
	// Check if server name determines if myTruePrint is enabled or not.
	if( trim(MYTP_FILENAME_FORMAT) == '' ) {
		$errors[] = 'myTruePrint is disabled. Set options at config.php to enable. The MYTP_FILENAME_FORMAT option tells if myTruePrint is enabled or not.';
	}
	
	return implode("<br/>\n", $errors);
}



	/**
	 * Checks if this plug-in is configured/installed correctly.
	 * 
	 * @return boolean true if configuration is OK.
	 */
	public function isInstalled()
	{
		$installed = false;
		// load config
		require_once dirname(__FILE__) . '/config.php';
		// check configuration options
		if (!$this->testMyTruePrintConfigServer()) {
			$installed = true;
		}
		
		return $installed;
	}
	
	/**
	 * Checks if this plug-in is configured/installed correctly.
	 * Throws a BizException if it's not correct.
	 */
	public function runInstallation()
	{
		if (!$this->isInstalled()){
			$msg = 'Configuration of this plug-in is not done or not correct in "' . dirname(__FILE__) . '/config.php' . '"';
			$msg .= "<br/>\n".$this->testMyTruePrintConfigServer();
			throw new BizException('' , 'Server', null, $msg);
		}
	}
}
