<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.2
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier children metadata ( ParentMetadata )
 */

require_once BASEDIR.'/server/interfaces/plugins/EnterprisePlugin.class.php';
require_once BASEDIR.'/server/interfaces/plugins/PluginInfoData.class.php';
require_once BASEDIR.'/config/plugins/ParentMetadata/config.php';

class ParentMetadata_EnterprisePlugin extends EnterprisePlugin
{
	public function getPluginInfo()
	{
		$info = new PluginInfoData();
		$info->DisplayName = 'ParentMetadata';
		$info->Version     = 'v1.0a'; // don't use PRODUCTVERSION
		$info->Description = 'Overrule for dossier children metadata ( ParentMetadata )';
		$info->Copyright   = '(c) 2013 iCenter Ukraine LTD';
		return $info;
	}

	final public function getConnectorInterfaces()
	{
		return array(	
				'WflCreateObjectRelations_EnterpriseConnector',
				'WflSetObjectProperties_EnterpriseConnector'
				);
	}

function testParentMetadataConfig()
{
	$errors = array();

	// The application server name determines ParentMetadata is enabled or not.
	if( !defined('PM_EXTRAMETADATA_KEYS') )
	{
		$errors[] = "ParentMetadata is disabled. Define PM_EXTRAMETADATA_KEYS in configserver.php";
	} else {
		$keys = unserialize(PM_EXTRAMETADATA_KEYS);
		if ( !is_array($keys) || empty($keys) )
		{
			$errors[] = "ParentMetadata is disabled. Define PM_EXTRAMETADATA_KEYS as serialized array of extra metadata tags for overrule in config.php";
		}
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
		if (!$this->testParentMetadataConfig()) {
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
			$msg .= "<br/>\n".$this->testParentMetadataConfig();
			throw new BizException('' , 'Server', null, $msg);
		}
	}
}
