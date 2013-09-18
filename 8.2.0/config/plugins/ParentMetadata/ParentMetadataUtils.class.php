<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v8.2
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 *
 * Overrule for dossier children metadata ( ParentMetadata )
 */

require_once dirname(__FILE__) . '/config.php';

class ParentMetadataUtils {

	public static function getExtraMetaData($metaData, $key)
	{
        if (isset($metaData->ExtraMetaData)) {
            foreach($metaData->ExtraMetaData as $extraMetaData) {
                if ($extraMetaData->Property == $key) {
                    return $extraMetaData;
                }
            }
        }
        
        return null;
    }
	
	public static function getObjectType($objectId)
	{
        $dbDriver=DBDriverFactory::gen();
        $dbobjects=$dbDriver->tablename("objects");
        $sql='select `type` from '.$dbobjects.' where `id`='.$objectId;
        $sth=$dbDriver->query($sql);
        $res=$dbDriver->fetch($sth);
        return $res['type'];
    }
	
    public static function getContainedChildren($dossierId)
	{
		$dbDriver=DBDriverFactory::gen();
        $dbobjectrel=$dbDriver->tablename("objectrelations");
        $children=array();
        $sql='select `child` from '.$dbobjectrel.' where `parent`='.$dossierId.' and `type` = \'Contained\'';
        $sth=$dbDriver->query($sql);
        while (($res=$dbDriver->fetch($sth))) {
            array_push($children,$res['child']);
        }
        return $children;
    }
	
	public static function overruleObjectProperties($ticket, $objectId, $extraMetadataForOverrule)
	{
        require_once BASEDIR.'/server/protocols/soap/WflClient.php';
        $soapClient = new WW_SOAP_WflClient();

		$IDs = array($objectId);
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
		
		if (!$objects||count($objects)!=1) {
				return;
		}
		
		$object = $objects[0];
		
		try {
			$objectMetaData = $object->MetaData;
			if (isset($objectMetaData->ExtraMetaData)) {
				$extraMetadataKeys = unserialize(PM_EXTRAMETADATA_KEYS);

				foreach($objectMetaData->ExtraMetaData as &$extraMetaData) {
					if(in_array($extraMetaData->Property, $extraMetadataKeys)) {
						$newExtraMetaData = self::getExtraMetaData($extraMetadataForOverrule, $extraMetaData->Property);
						$extraMetaData->Values = $newExtraMetaData->Values;
					}
				}
            }
			
			require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectPropertiesRequest.class.php';
			require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectPropertiesResponse.class.php';
			$setObjectPropertiesReq = new WflSetObjectPropertiesRequest($ticket, $objectId, $objectMetaData);
			$soapClient->SetObjectProperties($setObjectPropertiesReq);
		}
		catch(BizException $e){
			// silent mode
			//echo'Error returned from server: '.$e->getMessage()."\n";
		}
    }
	
}

?>