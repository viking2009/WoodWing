<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v6.1
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR . '/server/interfaces/services/wfl/WflSaveObjects_EnterpriseConnector.class.php';

class myMadeToPrint_WflSaveObjects extends WflSaveObjects_EnterpriseConnector
{
	final public function getPrio () {	return self::PRIO_DEFAULT; }
	final public function getRunMode () { return self::RUNMODE_AFTER; }

	final public function runBefore (WflSaveObjectsRequest &$req) {}	

	final public function runAfter (WflSaveObjectsRequest $req, WflSaveObjectsResponse &$resp)
	{
		require_once dirname(__FILE__) . '/config.php';
        if( MYMTP_SERVER_DEF_ID != '' ) {
			if (isset($req->Objects) && count($req->Objects) > 0) {
				require_once dirname(__FILE__) . '/myMadeToPrintDispatcher.class.php';
				foreach ($req->Objects as $object) {
					$id = $object->MetaData->BasicMetaData->ID;
					if ($object->MetaData->BasicMetaData->Type == 'Layout') {
						myMadeToPrintDispatcher::doPrint( $id, $req->Ticket );
					}
				}	
			}           
        }
	}

	final public function runOverruled (WflSaveObjectsRequest $req) {} // Not called because we're just doing run before and after
}
