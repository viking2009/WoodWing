<?php

define ('XMLTYPE',      'xml');
    
define ('INPUTDIR',     'rekeywordsmapping');
define ('OUTPUTDIR',    'rekeywordsmapping2');

define ('PLAINCONTENT', 'PlainContent');
define ('KEYWORDS',     'Keywords');
define ('DELIMITER',    ',');

define ('KEYWORDSMAP', serialize( array(
        'Срочно' => array( 'экономика', 'политика' ),
        'Crimea' => array( 'Крым' ),
        'Ukraine' => array( 'УКРАИНА' ),
        'Achtung' => 'генпрокурора',
        'Halt' => 'Депутаты'
)));

?>