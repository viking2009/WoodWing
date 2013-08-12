<?php

/**
 * @package 	Enterprise
 * @subpackage 	ServerPlugins
 * @since 		v7.5.1
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectProperties_EnterpriseConnector.class.php';

class myUpdateBeamer_WflSetObjectProperties extends WflSetObjectProperties_EnterpriseConnector {

	final public function getPrio()      { return self::PRIO_DEFAULT; }
	final public function getRunMode()   { return self::RUNMODE_AFTER; }

	final public function runBefore( WflSetObjectPropertiesRequest &$req ) {}

	final public function runAfter( WflSetObjectPropertiesRequest $req, WflSetObjectPropertiesResponse &$resp )
	{
 		require_once dirname(__FILE__) . '/myUpdateBeamerDispatcher.class.php';
		if( MYUB_SERVER != '' && isset($resp->MetaData->BasicMetaData->ID)) {
			$id = $resp->MetaData->BasicMetaData->ID;
			if ($resp->MetaData->BasicMetaData->Type == 'Layout') {
				myUpdateBeamerDispatcher::doPrint( $id, $req->Ticket );
			}
        }

	}

	final public function runOverruled( WflSetObjectPropertiesRequest $req ) {}
}

?>