<?php

// ----------------------------------------------------------------------------
// myMadeToPrint server settings
// ----------------------------------------------------------------------------
define('MYMTP_SERVER_DEF_ID',       INDESIGNSERV_APPSERVER);   	//server name used to log-in in Enterprise (wwsettings.xml)
define('MYMTP_USER',                'woodwing');    //the user name used to log-in in Enterprise
define('MYMTP_PASSWORD',            'ww');          //the password used to log-in in Enterprise

define('MYMTP_IDS_FOLDER_OUT',      'C:/1/');     //output folder result MTP perspective (the location where the results are placed)

//define('MYMTP_IDS_FOLDER_OUT',      'F:/APPL/WWE/Smartmover/Press/Common/');     //output folder result MTP perspective (the location where the results are placed)

//define('MYMTP_IDS_PRESETS_PATH',    'C:/Program Files (x86)/Adobe/Adobe InDesign CS5 Server/Presets/myMadeToPrint/');
define('MYMTP_IDS_PRESETS_PATH',    'C:/1/presets/');
define('MYMTP_PREFIX', 			    'MYMTP_');

define('MYMTP_POSTPROCESS_LOC',     'http://127.0.0.1/Enterprise/config/plugins/myMadeToPrint/myMadeToPrintPostProcess.php');
                                                    //location of the post processing file
define('MYMTP_FILENAME_FORMAT',     '%Publication%_%Issue%_%Name%');      // This allows output (PS) file name configuration at MtP!

/*
Use following parameters:
    'ID', 'Name', 'State', 'StateId', 'RouteTo', 'LockedBy', 
    'Modifier', 'Modified', 'Creator', 'Created', 
    'Publication', 'PublicationId', 'Issue', 'IssueId', 'Section', 'SectionId', 
    'Deadline', 'Edition', 'EditionId'
Example:
    %Publication%_%Issue%_%Name%
    %Publication%-%Issue%-%Edition%-%Name%
*/

?>