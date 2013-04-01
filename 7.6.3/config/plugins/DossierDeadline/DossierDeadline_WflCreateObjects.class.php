<?php

/**
 * @package     DossierDeadline plug-in for Enterprise
 * @since       v7.6.3
 * @copyright   Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR.'/server/interfaces/services/wfl/WflCreateObjects_EnterpriseConnector.class.php';

class DossierDeadline_WflCreateObjects extends WflCreateObjects_EnterpriseConnector {

    final public function getPrio()      { return self::PRIO_DEFAULT; }
    final public function getRunMode()   { return self::RUNMODE_BEFORE; }

    final public function runBefore( WflCreateObjectsRequest &$req )
    {
        if ( defined('IGNORE_DOSSIER_DEADLINE') && IGNORE_DOSSIER_DEADLINE == true )
        {
			if (isset($req->Objects) && count($req->Objects) > 0)
			{
				foreach ($req->Objects as $object)
				{
					if ($object->MetaData->BasicMetaData->Type == 'Dossier')
					{
						#$now = mktime();
						#file_put_contents(OUTPUTDIRECTORY . $now .'-'.$id.'-before', print_r($object, true));           

						require_once dirname(__FILE__) . '/DossierDeadlineUtils.class.php';
						$extraMetaData = DossierDeadlineUtils::getExtraMetaData($object->MetaData, 'DEADLINE');
						// DEADLINE is required field, so next expression always TRUE
						if (isset($extraMetaData->Values) && count($extraMetaData->Values) > 0)
						{
							$deadline = $extraMetaData->Values[0];
							// rewrite deadline
							$object->MetaData->WorkflowMetaData->Deadline = $deadline;
						}
                    
						#file_put_contents(OUTPUTDIRECTORY . $now .'-'.$id.'-after', print_r($object, true));
					}
				}   
			}
		}
	}

    final public function runAfter( WflCreateObjectsRequest $req, WflCreateObjectsResponse &$resp ) {}

    final public function runOverruled( WflCreateObjectsRequest $req ) {}
    
}

?>