<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.2
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier children metadata ( ParentMetadata )
 */

require_once BASEDIR . '/server/interfaces/services/wfl/WflCreateObjectRelations_EnterpriseConnector.class.php';
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/ParentMetadataUtils.class.php';

class ParentMetadata_WflCreateObjectRelations extends WflCreateObjectRelations_EnterpriseConnector
{
	final public function getPrio () 	{	return self::PRIO_DEFAULT; 	}
	final public function getRunMode () {	return self::RUNMODE_AFTER; }
	
	final public function runBefore (WflCreateObjectRelationsRequest &$req)	{} 

	final public function runAfter (WflCreateObjectRelationsRequest $req, WflCreateObjectRelationsResponse &$resp) 
	{
		if (isset($resp->Relations) && count($resp->Relations) > 0) {			
			$ticket = $req->Ticket;

			foreach ($resp->Relations as $relation) {
				if ($relation->Type == 'Contained') {
					$parentObjectType = ParentMetadataUtils::getObjectType($relation->Parent);
				
					if ($parentObjectType == 'Dossier') {
						$childObjectType = ParentMetadataUtils::getObjectType($relation->Child);
						
						$supportedFormats = unserialize(PM_SUPPORTED_FORMATS);

						if (in_array($childObjectType, $supportedFormats)) {
							$dossierId = $relation->Parent;
							$objectId = $relation->Child;
		
							require_once BASEDIR.'/server/protocols/soap/WflClient.php';
							$soapClient = new WW_SOAP_WflClient();

							$IDs = array($dossierId);
							try {
								require_once BASEDIR.'/server/interfaces/services/wfl/WflGetObjectsRequest.class.php';
								require_once BASEDIR.'/server/interfaces/services/wfl/WflGetObjectsResponse.class.php';
								$getObjectsReq = new WflGetObjectsRequest($ticket, $IDs, false, 'none');
								$getObjectsResp = $soapClient->GetObjects($getObjectsReq);
								$objects = $getObjectsResp->Objects;
							}
							catch(BizException $e){
								// silent mode
								//echo'Error returned from server: '.$e->getMessage()."\n";
							}
		
							if (isset($objects) && count($objects) == 1) {
								$dossier = $objects[0];
							
								ParentMetadataUtils::overruleObjectProperties($ticket, $objectId, $dossier->MetaData);
							}
						}
					}
				}
			}
		}
	} 
	
	final public function runOverruled (WflCreateObjectRelationsRequest $req) {} 
}
