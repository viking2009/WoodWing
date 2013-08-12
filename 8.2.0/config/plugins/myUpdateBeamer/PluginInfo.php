<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v7.0
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Update publication overview ( myUpdateBeamer )
 */

require_once BASEDIR.'/server/interfaces/plugins/EnterprisePlugin.class.php';
require_once BASEDIR.'/server/interfaces/plugins/PluginInfoData.class.php';
require_once BASEDIR.'/config/plugins/myUpdateBeamer/config.php';

class myUpdateBeamer_EnterprisePlugin extends EnterprisePlugin
{
	public function getPluginInfo()
	{
		$info = new PluginInfoData();
		$info->DisplayName = 'myUpdateBeamer';
		$info->Version     = 'v1.5'; // don't use PRODUCTVERSION
		$info->Description = 'Update publication overview ( myUpdateBeamer )';
		$info->Copyright   = '(c) 2011-2013 iCenter Ukraine LTD';
		return $info;
	}

	final public function getConnectorInterfaces()
	{
		return array(	'WflSaveObjects_EnterpriseConnector',
						'WflSetObjectProperties_EnterpriseConnector'
					);
	}


function testUBConfigServer()
{
	$errors = array();

	// The application server name determines if myUpdateBeamer is enabled or not.
	if( trim(MYUB_SERVER) == '' ) {
		$errors[] = 'myUpdateBeamer is disabled. Set options to enable. The MYUB_SERVER option tells if myUpdateBeamer is enabled or not.';
	}

	// Check if the myUpdateBeamer user is configured and try to logon/logoff 
	if( trim(MYUB_USER) == '' ) {
		$errors[] = 'No myUpdateBeamer user name specified. Please check MYUB_USER option.';
	}
	if( trim(MYUB_PASSWORD) == '' ) {
		$errors[] = 'No myUpdateBeamer user password specified. Please check MYUB_PASSWORD option.';
	}	
	require_once BASEDIR.'/server/protocols/soap/WflClient.php';
	try {			
		$client = new WW_SOAP_WflClient();

		// LogOn
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnResponse.class.php';
		$logonResp = $client->LogOn( new WflLogOnRequest( 
			// $User, $Password, $Ticket, $Server, $ClientName, $Domain,
			MYUB_USER, MYUB_PASSWORD, '', '', '', '',
			//$ClientAppName, $ClientAppVersion, $ClientAppSerial, $ClientAppProductKey, $RequestTicket
			$this->getPluginInfo()->DisplayName.' Test', $this->getPluginInfo()->Version, '', '', true ) );
	
		// LogOff
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffResponse.class.php';
		/*$logoffResp =*/ $client->LogOff( new WflLogOffRequest( $logonResp->Ticket, false, null, null ));
			
	} catch( SoapFault $e ) {
		if( stripos( $e->getMessage(), '(S1053)' ) !== false ) { // wrong user/password?
			$errors[] = 'Make sure the TESTSUITE setting at "' . BASEDIR . '/config/plugins/myUpdateBeamer/config.php" has options named "MYUB_USER" and "MYUB_PASSWORD". '.
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
	
	// Check if post process is configured and can be ping-ed
	if( trim(MYUB_POSTPROCESS_LOC) == '' ) {
		$errors[] =  'No myUpdateBeamer post process specified. Please check MYUB_POSTPROCESS_LOC option.';
	}
	$urlParts = @parse_url( MYUB_POSTPROCESS_LOC );
	if( !$urlParts || !isset($urlParts["host"]) ) {
		$errors[] = 'The specified myUpdateBeamer post process is not valid. Please check MYUB_POSTPROCESS_LOC option.';
	}
	$host = $urlParts["host"];
	$port = isset($urlParts["port"]) ? $urlParts["port"] : 80;
	$errno = 0;
	$errstr = '';
	$socket = @fsockopen( $host, $port, $errno, $errstr, 5 );
	if( !$socket ) {
		$errors[] = 'The specified myUpdateBeamer post process is not responsive ('.$errstr.'). Please check MYUB_POSTPROCESS_LOC option.';
	}
	fclose( $socket );

	return implode("<br/>\n", $errors);
}

	public function isInstalled()
	{
		// force installed
		//return true;
		
		// load config
		require_once dirname(__FILE__) . '/config.php';
		// check configuration options
		if (!$this->testUBConfigServer()) {
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
			$msg = 'Configuration of this plug-in is not done or not correct in "' . BASEDIR.'/config/plugins/myUpdateBeamer/config.php' . '"';
			$msg .= "<br/>\n".$this->testUBConfigServer();
			throw new BizException('' , 'Server', null, $msg);
		}
	}

}
