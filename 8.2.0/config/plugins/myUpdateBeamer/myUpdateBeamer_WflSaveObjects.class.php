<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v7.0
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR . '/server/interfaces/services/wfl/WflSaveObjects_EnterpriseConnector.class.php';

class myUpdateBeamer_WflSaveObjects extends WflSaveObjects_EnterpriseConnector
{
	final public function getPrio () {	return self::PRIO_DEFAULT; }
	final public function getRunMode () { return self::RUNMODE_AFTER; }

	final public function runBefore (WflSaveObjectsRequest &$req) {}	

	final public function runAfter (WflSaveObjectsRequest $req, WflSaveObjectsResponse &$resp)
	{
		require_once dirname(__FILE__) . '/config.php';
        	if( MYUB_SERVER != '' ) {
			
			if (isset($req->Objects) && count($req->Objects) > 0) {
				require_once dirname(__FILE__) . '/myUpdateBeamerDispatcher.class.php';
				foreach ($req->Objects as $object) {
					$id = $object->MetaData->BasicMetaData->ID;
					myUpdateBeamerDispatcher::doPrint( $id, $req->Ticket );
				}	
			}
        	}
	}

	final public function runOverruled (WflSaveObjectsRequest $req) {}
}
