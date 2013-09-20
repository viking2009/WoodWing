<?php

require_once BASEDIR.'/config/plugins/myUpdateBeamer/config.php';
class myUpdateBeamerDispatcher{
    private function __construct(){
    }
    private function __destruct(){
    }
    private function __copy(){
    }
    private static function convertFile($src,$dest,$width){
        if (extension_loaded('imagick')) {
            $thumb=new Imagick($src);
            $thumb->thumbnailImage($width,0);
            if ($thumb->writeImage($dest)) {
                return true;
            }
        } else {
            $image_stats=GetImageSize($src);
            $imagewidth=$image_stats[0];
            $imageheight=$image_stats[1];
            $new_w=$width;
            $ratio=$imagewidth/$new_w;
            $new_h=round($imageheight/$ratio);
            $src_img=imagecreatefromjpeg($src);
            $dst_img=imagecreatetruecolor($new_w,$new_h);
            imagecopyresampled($dst_img,$src_img,0,0,0,0,$new_w,$new_h,imagesx($src_img),imagesy($src_img));
            if (imagejpeg($dst_img,$dest)) {
                return true;
            }
        }
        return false;
    }
    public static function postProcess($layoutId,$layEditionId,$success,$message){
        $dbDriver=DBDriverFactory::gen();
        if ($success!=1) {
            LogHandler::Log('myUpdateBeamer','ERROR','postProcess: myUpdateBeamer failed with message: '.$message);
            return;
        }
        $now=md5('mv'.$layoutId);
        $workspaceID=WEBEDITDIR."$now/";
        if (!self::getLayoutDetails($layoutId,$layName,$layStorename,$layVersion)) {
            LogHandler::Log('myUpdateBeamer','ERROR','postProcess: cannot get info for layout. Id='.$layoutId);
            return;
        }
        
        if(!$layEditionId) $layEditionId=0;
        if ($layEditionId > 0) {
        	$layEditionVersion = '-'.layEditionId.'.'.$layVersion;
        } else {
        	$layEditionVersion = '.'.$layVersion;
        }
        
        $dbpages=$dbDriver->tablename("pages");
        $sql='select `pagenumber` from '.$dbpages.' where `objid`='.$layoutId.' and `edition`='.$layEditionId.' and `instance` = \'Production\' order by `pageorder` asc';
        $sth=$dbDriver->query($sql);
        $layTypes=array();
        $layTypes['native']='application/indesign';
        $i=1;
        while ($res=$dbDriver->fetch($sth)) {
            $types=array();
            $page=$res['pagenumber'];
            LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: page='.$page);
            $JPEGsrc=$workspaceID.$layoutId.'_'.$layEditionId.(($i==1)?'':$i).'.jpg';
            LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: JPEGsrc='.$JPEGsrc);
            if (file_exists($JPEGsrc)) {
                $dest=$layStorename.'-page'.$page;
                LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: destination='.$dest);
                if (self::convertFile($JPEGsrc,$dest.'-1'.$layEditionVersion,MYUB_SIZE_THUMB)) {
                    $types[]=array('1','thumb','image/jpeg');
                    LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: converted '.$JPEGsrc.' to '.$dest.'-1.'.$layVersion);
                }
                if (copy($JPEGsrc,$dest.'-2'.$layEditionVersion)) {
                    $types[]=array('2','preview','image/jpeg');
                    LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: copied '.$JPEGsrc.' to '.$dest.'-2.'.$layVersion);
                }
                if ($i==1) {
                    if (self::convertFile($JPEGsrc,$layStorename.'-thumb.'.$layVersion,MYUB_SIZE_THUMB)) {
                        $layTypes['thumb']='image/jpeg';
                        LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: converted '.$JPEGsrc.' to '.$layStorename.'-thumb.'.$layVersion);
                    }
                    if (self::convertFile($JPEGsrc,$layStorename.'-preview.'.$layVersion,MYUB_SIZE_PREVIEW)) {
                        $layTypes['preview']='image/jpeg';
                        LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: converted '.$JPEGsrc.' to '.$layStorename.'-preview.'.$layVersion);
                    }
                }
            } else {
                LogHandler::Log('myUpdateBeamer','ERROR','postProcess: ERROR with InDesign Server, could not find image '.$JPEGsrc);
            }
            $PDFsrc=$workspaceID.$layoutId.'_'.$layEditionId.$i.'.pdf';
            LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: PDFsrc='.$PDFsrc);
            if (file_exists($PDFsrc)) {
                $dest=$layStorename.'-page'.$page;
                LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: destination='.$dest);
                if (copy($PDFsrc,$dest.'-3'.$layEditionVersion)) {
                    $types[]=array('3','output','application/pdf');
                    LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: copied '.$PDFsrc.' to '.$dest.'-3.'.$layVersion);
                }
            } else {
                LogHandler::Log('myUpdateBeamer','ERROR','postProcess: ERROR with InDesign Server, could not find document '.$PDFsrc);
            }
            $sql='update '.$dbpages.' set `types`=\''.serialize($types).'\' where `objid`='.$layoutId.' and `edition`='.$layEditionId.' and `pagenumber`=\''.$page.'\'';
            $dbDriver->query($sql);
            $i++;
        }
        $PDFsrc=$workspaceID.$layoutId.'.pdf';
        if (copy($PDFsrc,$layStorename.'-output.'.$layVersion)) {
            $layTypes['output']='application/pdf';
            LogHandler::Log('myUpdateBeamer','DEBUG','postProcess: copied '.$PDFsrc.' to '.$layStorename.'-output.'.$layVersion);
        }
        $dbobjects=$dbDriver->tablename("objects");
        $sql='update '.$dbobjects.' set `types`=\''.serialize($layTypes).'\' where `id`='.$layoutId;
        $dbDriver->query($sql);
    }
    private static function cleanUpFolder($workspaceWE){
        if (is_dir($workspaceWE)) {
            if ($handle=opendir($workspaceWE)) {
                while (FALSE!==($item=readdir($handle))) {
                    if ($item!='.'&&$item!='..') {
                        $path=$workspaceWE.$item;
                        unlink($path);
                    }
                }
               closedir($handle);
            }
          rmdir($workspaceWE);
        }
    }
    private static function queueLayoutObject($ticket,$layoutId,$layEdition){
        $layEditionId=$layEdition?$layEdition->Id:0;
        $now=md5('mv'.$layoutId);
        $workspaceWE=WEBEDITDIR."$now/";
        $workspaceID=WEBEDITDIRIDSERV."$now/";
        if (!is_dir($workspaceWE)) {
            $old_umask=umask(0);
            if (!mkdir($workspaceWE,0777)) {
                LogHandler::Log('myUpdateBeamer','ERROR','Cannot create folder '.$workspaceWE);
                return false;
            }
            if (!chmod($workspaceWE,0777)) {
                LogHandler::Log('myUpdateBeamer','ERROR','Cannot create folder '.$workspaceWE);
                umask($old_umask);
                return false;
            }
        }
        $previewfile=$workspaceID.$layoutId.'_'.$layEditionId;
        LogHandler::Log('myUpdateBeamer','DEBUG','previewfile='.$previewfile);
        require_once BASEDIR.'/server/bizclasses/BizInDesignServerJob.class.php';
        $requestUrl=MYUB_POSTPROCESS_LOC.'?id='.$layoutId.'&edition='.$layEditionId.'&success=';
        $scriptText='try{app.scriptPreferences.userInteractionLevel=UserInteractionLevels.neverInteract;}catch(e){}try{app.entSession.login("'.MYUB_USER.'","'.MYUB_PASSWORD.'","'.MYUB_SERVER.'");}catch(err){app.performSimpleRequest("'.$requestUrl.'2&message=cannot%20logon%20myUpdateBeamer%20user");exit(0);}try{myDoc=app.openObject("'.$layoutId.'",false);}catch(err){app.performSimpleRequest("'.$requestUrl.'3&message=cannot%20open%20layout%20'.$layoutId.'");app.entSession.logout();exit(0);}';
         if ($layEditionId>0) {
            $scriptText.='try{myDoc.activeEdition="'.$layEdition->Name.'";}catch(err){app.performSimpleRequest("'.$requestUrl.'4&message=cannot%20activate%20edition%20'.$layEditionId.'");myDoc.close(SaveOptions.no);app.entSession.logout();exit(0);}';
        }
        $scriptText.='app.consoleout("Generating [JPEG] for layoutId = '.$layoutId.'");try{exportfile=File("'.$previewfile.'.jpg");app.jpegExportPreferences.jpegExportRange=ExportRangeOrAllPages.exportAll;app.jpegExportPreferences.jpegQuality=JPEGOptionsQuality.maximum;app.jpegExportPreferences.exportResolution='.MYUB_JPEG_RESOLUTION.';myDoc.exportFile(ExportFormat.jpg,exportfile);}catch(err){app.performSimpleRequest("'.$requestUrl.'4&message=cannot%20generate%20layout%20previews");}app.consoleout("Generating [PDF] for layoutId = '.$layoutId.'");try{exportfile=File("'.$previewfile.'.pdf");app.pdfExportPreferences.pageRange=PageRange.allPages;myDoc.exportFile(ExportFormat.pdfType,exportfile,app.pdfExportPresets.item("'.MYUB_PDF_QUALITY.'"));for(docPages=0;docPages<myDoc.pages.length;docPages++){myPageName=""+(docPages+1);app.pdfExportPreferences.pageRange=myDoc.pages.item(docPages).name;exportfile=File("'.$previewfile.'"+myPageName+".pdf");myDoc.exportFile(ExportFormat.pdfType,exportfile,app.pdfExportPresets.item("'.MYUB_PDF_QUALITY.'"));}}catch(err){app.performSimpleRequest("'.$requestUrl.'5&message=cannot%20generate%20layout%20pdfs");myDoc.close(SaveOptions.no);app.entSession.logout();exit(0);}myDoc.close(SaveOptions.no);app.performSimpleRequest("'.$requestUrl.'1&message=success");app.entSession.logout();';
        $jobId=BizInDesignServerJobs::createJob($scriptText,null,false,'myUpdateBeamer',$layoutId,false,'8.0');
        BizInDesignServerJobs::startBackgroundJobs();
    }
    public static function doPrint($objectId,$ticket){
        $objType=self::getObjectType($objectId);
        if ($objType=='Layout') {
            $layoutIds=array($objectId);
        } else if ($objType=='Article'||$objType=='Image') {
            $layoutIds=self::getParentLayouts($objectId);
        } else {
            $layoutIds=array();
        }
        foreach($layoutIds as $layoutId){
            if (self::getLayoutDetails($layoutId,$layName,$layStorename,$layVersion,$layEditions)) {
            	if (count($layEditions)>0) {
            		foreach($layEditions as $layEdition){
                	    self::queueLayoutObject($ticket,$layoutId,$layEdition);
            		}
        		} else {
                	self::queueLayoutObject($ticket,$layoutId,null);
        		}
            }
        }
    }
    private static function getObjectType($objectId){
        $dbDriver=DBDriverFactory::gen();
        $dbobjects=$dbDriver->tablename("objects");
        $sql='select `type` from '.$dbobjects.' where `id`='.$objectId;
        $sth=$dbDriver->query($sql);
        $res=$dbDriver->fetch($sth);
        return $res['type'];
    }
    private static function getPlacedChilds($layoutId){
        $dbDriver=DBDriverFactory::gen();
        $dbobjectrel=$dbDriver->tablename("objectrelations");
        $children=array();
        $sql='select `child` from '.$dbobjectrel.' where `parent`='.$layoutId.' and `type` = \'Placed\'';
        $sth=$dbDriver->query($sql);
        while (($res=$dbDriver->fetch($sth))) {
            array_push($children,$res['child']);
        }
        return $children;
    }
    private static function getParentLayouts($objectId){
        $dbDriver=DBDriverFactory::gen();
        $dbobjectrel=$dbDriver->tablename("objectrelations");
        $parents=array();
        $sql='select `parent` from '.$dbobjectrel.' where `child`='.$objectId.' and `type` = \'Placed\'';
        $sth=$dbDriver->query($sql);
        while (($res=$dbDriver->fetch($sth))) {
            array_push($parents,$res['parent']);
        }
        return $parents;
    }
    private static function getLayoutDetails($layoutId,&$layName,&$layStorename,&$layVersion,&$layEditions){
        require_once BASEDIR.'/server/bizclasses/BizTarget.class.php';
        $targets=BizTarget::getTargets(null,$layoutId);
        if (count($targets)!=1) {
            LogHandler::Log('myUpdateBeamer','ERROR','Layout '.$layoutId.' is NOT bound to ONE issue. Target count = '.count($targets));
            return false;
        }
        $layEditions=$targets[0]->Editions;
        $dbDriver=DBDriverFactory::gen();
        $dbobjects=$dbDriver->tablename("objects");
        $sql='select `name`, `storename`, `majorversion`, `minorversion` from '.$dbobjects.' where `id`='.$layoutId;
        $sth=$dbDriver->query($sql);
        $res=$dbDriver->fetch($sth);
        if (!$res) {
            LogHandler::Log('myUpdateBeamer','ERROR','Layout not found. Id='.$layoutId);
            return false;
        }
        $layName=$res['name'];
        $layStorename=ATTACHMENTDIRECTORY.'/'.$res['storename'];
        $layVersion='v'.$res['majorversion'].'.'.$res['minorversion'];
        return true;
    }
}

?>
