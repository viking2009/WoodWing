<?php

require_once 'config.php';
require_once 'FolderInterface.intf.php';
require_once 'FileHandler.class.php';

class ProcessingInterface implements FolderIterInterface
{
    //Takes care that the KEYWORDSMAP is only serialized once
    public static function getKeywordsMap()
    {
        static $keywordsMap;
        if (!isset($keywordsMap)) {
            $keywordsMap = unserialize(KEYWORDSMAP);
        }
        return $keywordsMap;
    }
	
    public function iterFile( $filePath, $level ) {
		echo str_repeat("\t", $level)."iterating file $filePath\n";
		$fileHandler = new FileHandler( $filePath );
		$fileHandler->readFile();

		$newContent = $fileHandler->getFileContent();
/*		
		// This will check for exact words only. so "ass" will be found and flagged 
		// but not "classic"
		private static $bad_name = array("word1", "word2", "word3");
		$badFound = preg_match("/\b(" . implode(self::$bad_name,"|") . ")\b/i", $name_in);
		
		// This will match "ass" as well as "classic" and flag it
		private static $forbidden_name = array("word1", "word2", "word3");
		$forbiddenFound = preg_match("/(" . implode(self::$forbidden_name,"|") . ")/i", $name_in);
*/
		
		// find PlainContent
		$plainContentRegex = "/<".PLAINCONTENT.">(.*?)<\/".PLAINCONTENT.">/is";
		if ( preg_match( $plainContentRegex, $fileHandler->getFileContent(), $matches ) ) {
			if ( isset ($matches[1]) ) {
				$plainContent = $matches[1];
				
				$keywords = array();
				foreach( self::getKeywordsMap() as $key => $value ) {
					if( is_array($value) ) {
						$value = implode( $value, "|" );
					} else if ( !is_string($value) ) {
						continue;
					}
					
					if ( preg_match("/\b(" . $value . ")\b/iu", $plainContent) ) {
						echo str_repeat("\t", $level + 1)."found $value\n";
						$keywords[] = $key;
					}		
				}
				
				$keywordsRegex = "/<".KEYWORDS.">.*?<\/".KEYWORDS.">/is";
				$newContent = preg_replace($keywordsRegex, "<".KEYWORDS.">". implode( $keywords, DELIMITER ) ."</".KEYWORDS.">", $fileHandler->getFileContent());		
			}
		}
	
		$saveFilePath = str_replace(INPUTDIR, OUTPUTDIR, $filePath);
		if( $fileHandler->writeFile($saveFilePath, $newContent) ) {
			echo str_repeat("\t", $level)."saved to file $saveFilePath\n";
		}
	}
	
    public function skipFile( $filePath, $level ) {
		echo str_repeat("\t", $level)."skipping file $filePath\n";
	}
    
    public function iterFolder( $folderPath, $level ) {
		$saveFolderPath = str_replace(INPUTDIR, OUTPUTDIR, $folderPath);
		if ( FolderUtils::mkFullDir( $saveFolderPath ) ) {
			echo str_repeat("\t", $level)."iterating folder $folderPath\n";
		}
	}
	
    public function skipFolder( $folderPath, $level ) {
		echo str_repeat("\t", $level)."iterating folder $folderPath\n";
	}
}
?>