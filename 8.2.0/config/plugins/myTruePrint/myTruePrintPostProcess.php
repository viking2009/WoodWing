<?php

// Heavy debug only:
// LogHandler::Log('tp', 'INFO', print_r($_REQUEST, true));

$layoutId = $_REQUEST['id'];
$layStatusId = $_REQUEST['state'];
$layEditionId = $_REQUEST['edition'];

$success = $_REQUEST['success'];

$message = null;
$message = isset($_REQUEST['message']) ? $_REQUEST['message'] : '';
if($message){
    $message = addslashes(html_entity_decode($message));
}

require_once dirname(__FILE__) . '/../../config.php';
require_once dirname(__FILE__) . '/myTruePrintDispatcher.class.php';
myTruePrintDispatcher::postProcess( $layoutId, $layStatusId, $layEditionId, $success, $message );

?>
