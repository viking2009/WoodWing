<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.2
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier children metadata ( ParentMetadata )
 */

require_once BASEDIR.'/server/interfaces/services/wfl/WflCreateObjects_EnterpriseConnector.class.php';
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/ParentMetadataUtils.class.php';

class ParentMetadata_WflCreateObjects extends WflCreateObjects_EnterpriseConnector {

	final public function getPrio()      { return self::PRIO_DEFAULT; }
    final public function getRunMode()   { return self::RUNMODE_BEFORE; }

	final public function runBefore( WflCreateObjectsRequest &$req )
	{
		if (isset($req->Objects) && count($req->Objects) > 0) {
			$ticket = $req->Ticket;

			foreach ($req->Objects as $object) {
				$objectId = $object->MetaData->BasicMetaData->ID;
				$objectType = $object->MetaData->BasicMetaData->Type;

				$supportedFormats = unserialize(PM_SUPPORTED_FORMATS);

				if (in_array($objectType, $supportedFormats) && isset($object->Relations) && count($object->Relations) > 0) {							

					foreach ($resp->Relations as $relation) {
						if ($relation->Type == 'Contained') {
							$parentObjectType = ParentMetadataUtils::getObjectType($relation->Parent);
				
							if ($parentObjectType == 'Dossier') {
								$dossierId = $relation->Parent;	
		
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
							
									$objectMetaData = $object->MetaData;
									$extraMetaData = $dossier->MetaData;
									if (isset($objectMetaData->ExtraMetaData)) {
										$extraMetadataKeys = unserialize(PM_EXTRAMETADATA_KEYS);
								
										foreach($objectMetaData->ExtraMetaData as &$extraMetaData) {
											if(in_array($extraMetaData->Property, $extraMetadataKeys)) {
												$newExtraMetaData = ParentMetadataUtils::getExtraMetaData($extraMetadataForOverrule, $extraMetaData->Property);
												$extraMetaData->Values = $newExtraMetaData->Values;
											}
										}
            						}
								}								
							}
						}
					}
				}
			}
		}
	}

	final public function runAfter( WflCreateObjectsRequest $req, WflCreateObjectsResponse &$resp ) {}

	final public function runOverruled( WflCreateObjectsRequest $req ) {}
	
}

?>