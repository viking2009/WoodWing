<?php

// Heavy debug only:
// LogHandler::Log('myUpdateBeamer', 'DEBUG', print_r($_REQUEST, true));

$layoutId = $_REQUEST['id'];
$success = $_REQUEST['success'];

$message = null;
$message = isset($_REQUEST['message']) ? $_REQUEST['message'] : '';
if($message){
	$message = addslashes(html_entity_decode($message));
}

require_once dirname(__FILE__) . '/../../config.php';
require_once dirname(__FILE__) . '/myUpdateBeamerDispatcher.class.php';
myUpdateBeamerDispatcher::postProcess( $layoutId, $success, $message );

