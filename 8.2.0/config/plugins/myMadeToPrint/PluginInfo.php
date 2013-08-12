<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 	v6.1
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Interface to Made To Print  ( myMadeToPrint )
 */

require_once BASEDIR.'/server/interfaces/plugins/EnterprisePlugin.class.php';
require_once BASEDIR.'/server/interfaces/plugins/PluginInfoData.class.php';
require_once BASEDIR.'/config/plugins/myMadeToPrint/config.php';

class myMadeToPrint_EnterprisePlugin extends EnterprisePlugin
{
	public function getPluginInfo()
	{
		$info = new PluginInfoData();
		$info->DisplayName = 'myMadeToPrint';
		$info->Version     = 'v1.5.1'; // don't use PRODUCTVERSION
		$info->Description = 'Interface to Made To Print  ( myMadeToPrint )';
		$info->Copyright   = '(c) 2011-2013 iCenter Ukraine LTD';
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

function testMyMadeToPrintConfigServer()
{
	$errors = array();

	// The application server name determines if myMadeToPrint is enabled or not.
	if( trim(MYMTP_SERVER_DEF_ID) == '' ) {
		$errors[] = 'myMadeToPrint is disabled. Set options to enable. The MYMTP_SERVER_DEF_ID option tells if myMadeToPrint is enabled or not.';
	}

	// Check if the myMadeToPrint user is configured and try to logon/logoff 
	if( trim(MYMTP_USER) == '' ) {
		$errors[] = 'No myMadeToPrint user name specified. Please check MYMTP_USER option.';
	}
	if( trim(MYMTP_PASSWORD) == '' ) {
		$errors[] = 'No myMadeToPrint user password specified. Please check MTP_PASSWORD option.';
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
			$errors[] = 'Make sure the TESTSUITE setting at "' . BASEDIR . '/config/plugins/myMadeToPrint/config.php" has options named "MYMTP_USER" and "MYMTP_PASSWORD". '.
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
	if( trim(MYMTP_IDS_FOLDER_OUT) == '' ) {
		$errors[] = 'No myMadeToPrint out-folder specified. Please check MYMTP_IDS_FOLDER_OUT option';
	}
	if( strrpos(MYMTP_IDS_FOLDER_OUT,'/') != (strlen(MYMTP_IDS_FOLDER_OUT)-1) ) {
		$errors[] = 'The specified myMadeToPrint out-folder has no slash (/) at the end. Please check MYMTP_IDS_FOLDER_OUT option.';
	}

	// Check if presets folder are configured correctly and accessable		
	if( trim(MYMTP_IDS_PRESETS_PATH) == '' ) {
		$errors[] = 'No myMadeToPrint presets folder specified. Please check MYMTP_IDS_PRESETS_PATH option.';
	}
	if( strrpos(MYMTP_IDS_PRESETS_PATH,'/') != (strlen(MYMTP_IDS_PRESETS_PATH)-1) ) {
		$errors[] = 'The specified myMadeToPrint presets folder has no slash (/) at the end. Please check MYMTP_IDS_PRESETS_PATH option.';
	}

	// Check if post process is configured and can be ping-ed
	if( trim(MYMTP_POSTPROCESS_LOC) == '' ) {
		$errors[] =  'No myMadeToPrint post process specified. Please check MYMTP_POSTPROCESS_LOC option.';
	}
	$urlParts = @parse_url( MYMTP_POSTPROCESS_LOC );
	if( !$urlParts || !isset($urlParts["host"]) ) {
		$errors[] = 'The specified myMadeToPrint post process is not valid. Please check MYMTP_POSTPROCESS_LOC option.';
	}
	$host = $urlParts["host"];
	$port = isset($urlParts["port"]) ? $urlParts["port"] : 80;
	$errno = 0;
	$errstr = '';
	$socket = @fsockopen( $host, $port, $errno, $errstr, 5 );
	if( !$socket ) {
		$errors[] = 'The specified myMadeToPrint post process is not responsive ('.$errstr.'). Please check MYMTP_POSTPROCESS_LOC option.';
	}
	fclose( $socket );
	
	// Check if server name determines if myMadeToPrint is enabled or not.
	if( trim(MYMTP_FILENAME_FORMAT) == '' ) {
		$errors[] = 'myMadeToPrint is disabled. Set options at config.php to enable. The MYMTP_FILENAME_FORMAT option tells if myMadeToPrint is enabled or not.';
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
		if (!$this->testMyMadeToPrintConfigServer()) {
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
			$msg .= "<br/>\n".$this->testMyMadeToPrintConfigServer();
			throw new BizException('' , 'Server', null, $msg);
		}
	}
}
