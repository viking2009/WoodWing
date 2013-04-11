<?php

require_once('config.php');
require_once('osconfig.php');
require_once('ProcessingInterface.class.php');
require_once('FolderUtils.class.php');

if( FolderUtils::mkFullDir(OUTPUTDIR) ) {
	$processingInterface = new ProcessingInterface();
	FolderUtils::scanDirForFiles($processingInterface, INPUTDIR, array(XMLTYPE));
} else {
	die("Failed to create OUTPUTDIR folder (".OUTPUTDIR.")");
}

?>