<?php

/**
 * @package 	Print plug-in for Enterprise
 * @since 		v7.6.6
 * @copyright	Mykola Vyshynskyi. All Rights Reserved.
 */

require_once BASEDIR.'/server/interfaces/services/wfl/WflSendTo_EnterpriseConnector.class.php';

class myTruePrint_WflSendTo  extends WflSendTo_EnterpriseConnector {

	final public function getPrio()      { return self::PRIO_DEFAULT; }       // VERYLOW, LOW, DEFAULT, HIGH, VERYHIGH
	final public function getRunMode()   { return self::RUNMODE_AFTER; }  // BEFORE, AFTER, BEFOREAFTER, OVERRULE

	final public function runBefore( WflSendToRequest &$req ) {}

	final public function runAfter( WflSendToRequest $req, WflSendToResponse &$resp )
	{
		require_once dirname(__FILE__) . '/config.php';
		if( MYTP_SERVER_DEF_ID != '' ) {
			if (isset($req->IDs) && count($req->IDs) > 0) {
				require_once dirname(__FILE__) . '/myTruePrintDispatcher.class.php';
				foreach ($req->IDs as $id) {
					myTruePrintDispatcher::doPrint( $id, $req->Ticket );
				}	
			}
		}
	}

	final public function runOverruled( WflSendToRequest $req ) {}
}

?>