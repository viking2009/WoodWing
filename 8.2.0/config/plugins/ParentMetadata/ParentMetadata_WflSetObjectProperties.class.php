<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.2
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier children metadata ( ParentMetadata )
 */

require_once BASEDIR . '/server/interfaces/services/wfl/WflSetObjectProperties_EnterpriseConnector.class.php';
require_once dirname(__FILE__) . '/config.php';

class ParentMetadata_WflSetObjectProperties extends WflSetObjectProperties_EnterpriseConnector
{
	final public function getPrio () 	{	return self::PRIO_DEFAULT; 	}
	final public function getRunMode () {	return self::RUNMODE_AFTER; }
	
	final public function runBefore (WflSetObjectPropertiesRequest &$req) {} 

	final public function runAfter (WflSetObjectPropertiesRequest $req, WflSetObjectPropertiesResponse &$resp) 
	{
		if ($resp->MetaData->BasicMetaData->Type == 'Dossier') {
			$id = $resp->MetaData->BasicMetaData->ID;
			
			require_once dirname(__FILE__) . '/ParentMetadataUtils.class.php';
			$relations = ParentMetadataUtils::getContainedChildren($id);
			
			if (count($relations) > 0) {
				$ticket = $req->Ticket;
			
				$supportedFormats = unserialize(PM_SUPPORTED_FORMATS);

				foreach ($relations as $childId) {
					$objectType = ParentMetadataUtils::getObjectType($childId);

					if ( in_array ($objectType, $supportedFormats) ) {
						ParentMetadataUtils::overruleObjectProperties($ticket, $childId, $resp->MetaData);
					}
				}
			}
		}
	} 
	
	final public function runOverruled (WflSetObjectPropertiesRequest $req) {} 
}
