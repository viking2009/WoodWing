<?php

/**
 * @package 	Print plug-in for Enterprise
 * @since 		v7.6.6
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectProperties_EnterpriseConnector.class.php';

class myTruePrint_WflSetObjectProperties extends WflSetObjectProperties_EnterpriseConnector {

	final public function getPrio()      { return self::PRIO_DEFAULT; }
	final public function getRunMode()   { return self::RUNMODE_AFTER; }

	final public function runBefore( WflSetObjectPropertiesRequest &$req ) {}

	final public function runAfter( WflSetObjectPropertiesRequest $req, WflSetObjectPropertiesResponse &$resp )
	{
		require_once dirname(__FILE__) . '/config.php';
		if( MYTP_SERVER_DEF_ID != '' && isset($resp->MetaData->BasicMetaData->ID)) {
			require_once dirname(__FILE__) . '/myTruePrintDispatcher.class.php';
			$id = $resp->MetaData->BasicMetaData->ID;
			if ($resp->MetaData->BasicMetaData->Type == 'Article' ||$resp->MetaData->BasicMetaData->Type == 'Layout') {
				myTruePrintDispatcher::doPrint( $id, $req->Ticket );
			}
		}
	}

	final public function runOverruled( WflSetObjectPropertiesRequest $req ) {}
}

?>