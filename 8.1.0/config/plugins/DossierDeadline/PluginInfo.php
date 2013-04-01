<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.1
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier deadline ( DossierDeadline )
 */

require_once BASEDIR.'/server/interfaces/plugins/EnterprisePlugin.class.php';
require_once BASEDIR.'/server/interfaces/plugins/PluginInfoData.class.php';

class DossierDeadline_EnterprisePlugin extends EnterprisePlugin
{
	public function getPluginInfo()
	{
		$info = new PluginInfoData();
		$info->DisplayName = 'DossierDeadline';
		$info->Version     = 'v1.0a'; // don't use PRODUCTVERSION
		$info->Description = 'Overrule for dossier deadline ( DossierDeadline )';
		$info->Copyright   = '(c) 2013 iCenter Ukraine LTD';
		return $info;
	}

	final public function getConnectorInterfaces()
	{
		return array(	
				//'WflCreateObjects_EnterpriseConnector'
				'WflSetObjectProperties_EnterpriseConnector'
				);
	}

function testDossierDeadlineConfigServer()
{
	$errors = array();

	// The application server name determines DossierDeadline is enabled or not.
	if( !defined('IGNORE_DOSSIER_DEADLINE') )
	{
		$errors[] = "DossierDeadline is disabled. Add\n\"define('IGNORE_DOSSIER_DEADLINE', true);\"\nto configserver.php";
	} else if (IGNORE_DOSSIER_DEADLINE != true) {
		$errors[] = "DossierDeadline is disabled. Set\n\"define('IGNORE_DOSSIER_DEADLINE', true);\"\nto configserver.php";
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
		// check configuration options
		if (!$this->testDossierDeadlineConfigServer()) {
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
			$msg = 'Configuration of this plug-in is not done.';
			$msg .= "<br/>\n".$this->testDossierDeadlineConfigServer();
			throw new BizException('' , 'Server', null, $msg);
		}
	}
}
