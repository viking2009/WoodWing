<?php

/**
 * @package 	DossierDeadline plug-in for Enterprise
 * @since 		v8.1
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectProperties_EnterpriseConnector.class.php';

class DossierDeadline_WflSetObjectProperties extends WflSetObjectProperties_EnterpriseConnector {

	final public function getPrio()      { return self::PRIO_DEFAULT; }
	final public function getRunMode()   { return self::RUNMODE_BEFORE; }

	final public function runBefore( WflSetObjectPropertiesRequest &$req )
	{
		if ( defined('IGNORE_DOSSIER_DEADLINE') && IGNORE_DOSSIER_DEADLINE == true )
		{
			if ($req->MetaData->BasicMetaData->Type == 'Dossier')
			{
				$id = $req->MetaData->BasicMetaData->ID;
				$deadline = $req->MetaData->WorkflowMetaData->Deadline;
			
				#$now = mktime();
				#file_put_contents(OUTPUTDIRECTORY . $now .'-'.$id.'-before', print_r($req, true));			

				if (empty($deadline))
				{
					require_once dirname(__FILE__) . '/DossierDeadlineUtils.class.php';
					$extraMetaData = DossierDeadlineUtils::getExtraMetaData($req->MetaData, 'DEADLINE');
				
					if (isset($extraMetaData->Values) && count($extraMetaData->Values) > 0)
					{
						$deadline = $extraMetaData->Values[0];
						$req->MetaData->WorkflowMetaData->Deadline = $deadline;
					}
				}
			
				#file_put_contents(OUTPUTDIRECTORY . $now .'-'.$id.'-after', print_r($req, true));			
			}
		}
	}

	final public function runAfter( WflSetObjectPropertiesRequest $req, WflSetObjectPropertiesResponse &$resp ) {}

	final public function runOverruled( WflSetObjectPropertiesRequest $req ) {}

}

?>