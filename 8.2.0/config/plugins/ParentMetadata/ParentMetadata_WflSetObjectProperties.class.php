<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.2
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier children metadata ( ParentMetadata )
 */

require_once BASEDIR . '/server/interfaces/services/Wfl/WflSetObjectProperties_EnterpriseConnector.class.php';
require_once dirname(__FILE__) . '/config.php';

class ParentMetadata_WflSetObjectProperties extends WflSetObjectProperties_EnterpriseConnector
{
	final public function getPrio () 	{	return self::PRIO_DEFAULT; 	}
	final public function getRunMode () {	return self::RUNMODE_AFTER; }
	
	final public function runBefore (WflSetObjectPropertiesRequest &$req) {} 

	final public function runAfter (WflSetObjectPropertiesRequest $req, WflSetObjectPropertiesResponse &$resp) 
	{
		#LogHandler::Log("ParentMetadata","DEBUG","ParentMetadata WflSetObjectProperties runAfter");
		#LogHandler::Log("ParentMetadata","DEBUG", print_r($resp,true));
	} 
	
	final public function runOverruled (WflSetObjectPropertiesRequest $req) {} 
}
