<?php

/**
 * @package 	DossierDeadline plug-in for Enterprise
 * @since 		v8.1
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectProperties_EnterpriseConnector.class.php';

class DossierDeadlineUtils {

	public static function getExtraMetaData($metaData, $key)
	{
        if (isset($metaData->ExtraMetaData)) {
            foreach($metaData->ExtraMetaData as $extraMetaData) {
                if ($extraMetaData->Property == "C_".$key) {
                    return $extraMetaData;
                }
            }
        }
        
        return null;
    }

	public static function setDeadline($objectId, $deadline)
	{
		if (!$objectId || !$deadline) {
			// silent ignore 
			return null;
		}

		$dbDriver=DBDriverFactory::gen();
		$dbobjects=$dbDriver->tablename("objects");
		$sql='update '.$dbobjects.' set `deadline`='.$deadline.' where `id`='.$objectId;
		$sth=$dbDriver->query($sql);
		if (!$sth) {
			// silent ignore 
			#throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		
		return null;
    }
	
	public static function clearCDeadline($objectId)
	{
		if (!$objectId) {
			// silent ignore 
			return null;
		}

		$dbDriver=DBDriverFactory::gen();
		$dbobjects=$dbDriver->tablename("objects");
		$deadline = "";
		$sql='update '.$dbobjects.' set `C_DEADLINE`='.$deadline.' where `id`='.$objectId;
		$sth=$dbDriver->query($sql);
		if (!$sth) {
			// silent ignore 
			#throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		
		return null;
    }
	
}

?>