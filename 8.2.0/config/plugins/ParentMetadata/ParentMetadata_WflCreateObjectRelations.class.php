<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.2
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier children metadata ( ParentMetadata )
 */

require_once BASEDIR . '/server/interfaces/services/Wfl/WflCreateObjectRelations_EnterpriseConnector.class.php';
require_once dirname(__FILE__) . '/config.php';

class ParentMetadata_WflCreateObjectRelations extends WflCreateObjectRelations_EnterpriseConnector
{
	final public function getPrio () 	{	return self::PRIO_DEFAULT; 	}
	final public function getRunMode () {	return self::RUNMODE_AFTER; }
	
	final public function runBefore (WflCreateObjectRelationsRequest &$req)	{} 

	final public function runAfter (WflCreateObjectRelationsRequest $req, WflCreateObjectRelationsResponse &$resp) 
	{
		#LogHandler::Log("ParentMetadata","DEBUG","ParentMetadata WflCreateObjectRelations runAfter");
		#LogHandler::Log("ParentMetadata","DEBUG", print_r($resp,true));
	} 
	
	final public function runOverruled (WflCreateObjectRelationsRequest $req) {} 
}
