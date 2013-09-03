<?php

require_once BASEDIR.'/config/plugins/myMadeToPrint/config.php';
class myMadeToPrintDispatcher
{
    private function __construct(){
    }
    private function __destruct(){
    }
    private function __copy(){
    }
    public static function postProcess($layoutId,$layStatusId,$layEditionId,$success,$message){
        $dbDriver=DBDriverFactory::gen();
        if ($success!=1) {
            LogHandler::Log('mtp','ERROR','postProcess: MtP failed with message: '.$message);
        }
        $dbobjects=$dbDriver->tablename("objects");
        $mtpConfig=self::getMtpConfig($layStatusId);
        if (!$mtpConfig) {
            LogHandler::Log('mtp','ERROR','postProcess: Could not find MtP configuration for layout status '.$layStatusId);
            return;
        }
        $refstatelayout=$mtpConfig['layprogstate'];
        $refstatearticle=$mtpConfig['artprogstate'];
        $refstateimage=$mtpConfig['imgprogstate'];
        if ($success==1) {
            $childIds=self::getPlacedChilds($layoutId);
            foreach($childIds as $childId){
                $objType=self::getObjectType($childId);
                if ($objType=='Image') {
                    if ($refstateimage!=0) {
                        $sql='update '.$dbobjects.' set `state`='.$refstateimage.' where `id`='.$childId;
                        $sth=$dbDriver->query($sql);
                    }
                } else if ($objType=='Article') {
                    if ($refstatearticle!=0) {
                        $sql='update '.$dbobjects.' set `state`='.$refstatearticle.' where `id`='.$childId;
                        $sth=$dbDriver->query($sql);
                    }
                }
            }
        }
        if ($refstatelayout!=0&&$success==1) {
            $sql='update '.$dbobjects.' set `state`='.$refstatelayout.' where `id`='.$layoutId;
            $sth=$dbDriver->query($sql);
        }
        LogHandler::Log('mtp','DEBUG','postProcess: layout status='.$refstatelayout.' success='.$success);
    }
    private static function queueLayoutObject($ticket,$layoutId,$layPubId,$layIssueId,$layStatusId,$layEditions){
        require_once BASEDIR.'/server/dbclasses/DBTicket.class.php';
        $user=DBTicket::checkTicket($ticket);
        require_once BASEDIR.'/server/bizclasses/BizQuery.class.php';
        $fullrow=BizQuery::queryObjectRow($layoutId);
        require_once BASEDIR.'/server/dbclasses/DBIssue.class.php';
        $fullrow['IssueId']=$layIssueId;
		$fullrow['Issue']=DBIssue::getIssueName($layIssueId);
        $mtparr=array();
        foreach($fullrow as $propName=>$propValue){
            if (strncasecmp($propName,'C_MTP_',6)==0) {
                $mtparr[substr($propName,6,strlen($propName)-6)]=$propValue;
            }
        }
        $mtpConfig=self::getMtpConfig($layStatusId);
        if (!$mtpConfig) {
            LogHandler::Log('mtp','ERROR','queueLayoutObject: Could not find MtP configuration for layout status '.$layStatusId);
            return;
        }
        $jobname=$mtpConfig['mtptext'];
        $jobname=(trim($jobname)=='')?trim(MTP_JOB_NAME):trim($jobname);
/**/		
		if (defined('MYMTP_PREFIX') && strncasecmp($jobname, MYMTP_PREFIX, strlen(MYMTP_PREFIX))==0) {
			$jobname = substr($jobname,strlen(MYMTP_PREFIX),strlen($jobname)-strlen(MYMTP_PREFIX));
		} else {
			return;
        }
/**/			
        if (count($layEditions)>0) {
            foreach($layEditions as $layEdition){
                self::outputProcessingFiles($layoutId,$layStatusId,$layEdition,$jobname,$fullrow,$mtparr);
            }
        } else {
            self::outputProcessingFiles($layoutId,$layStatusId,null,$jobname,$fullrow,$mtparr);
        }
    }
    private static function outputProcessingFiles($layoutId,$layStatusId,$layEdition,$jobname,$fullrow,$mtparr){
        $layEditionId=$layEdition?$layEdition->Id:0;
        $name=$layoutId.'_'.$layStatusId.'_'.$layEditionId;
        $list=array('ID','Name','State','StateId','RouteTo','LockedBy','Modifier','Modified','Creator','Created','Publication','PublicationId','Issue','IssueId','Section','SectionId','Deadline','Edition','EditionId');
        if ($layEdition&&$layEdition->Id>0) {
            $fullrow['EditionId']=$layEdition->Id;
            $fullrow['Edition']=$layEdition->Name;
        } else {
            $fullrow['EditionId']=0;
            $fullrow['Edition']='';
        }
        $requestUrl=MYMTP_POSTPROCESS_LOC.'?id='.$layoutId.'&state='.$layStatusId.'&edition='.$layEditionId.'&success=';
        $scriptText='function AddZeroes(page){page="000"+page;return page.substr(-3);}try{app.scriptPreferences.userInteractionLevel=UserInteractionLevels.neverInteract;}catch(e){}try{app.entSession.login("'.MYMTP_USER.'","'.MYMTP_PASSWORD.'","'.MYMTP_SERVER_DEF_ID.'");}catch(err){app.performSimpleRequest("'.$requestUrl.'2&message=cannot%20logon%20MtP%20user");exit(0);}try{myDoc=app.openObject("'.$layoutId.'",false);}catch(err){app.performSimpleRequest("'.$requestUrl.'3&message=cannot%20open%20layout%20'.$layoutId.'");app.entSession.logout();exit(0);}';
        if ($layEditionId>0) {
            $scriptText.='try{myDoc.activeEdition="'.$layEdition->Name.'";}catch(err){app.performSimpleRequest("'.$requestUrl.'4&message=cannot%20activate%20edition%20'.$layEditionId.'");myDoc.close(SaveOptions.no);app.entSession.logout();exit(0);}';
        }
        $scriptText.='var myPreset=app.pdfExportPresets.item("'.$jobname.'");if(myPreset==null){try{var presetFile="'.MYMTP_IDS_PRESETS_PATH.$jobname.'.joboptions";app.importFile(ExportPresetFormat.pdfExportPresetsFormat,presetFile);myPreset=app.pdfExportPresets.item("'.$jobname.'");}catch(err){app.performSimpleRequest("'.$requestUrl.'5&message=cannot%20load%20preset%20'.$jobname.'");myDoc.close(SaveOptions.no);app.entSession.logout();exit(0);}}';
        $pdfFolder=MYMTP_IDS_FOLDER_OUT;
        $pdfFile=MYMTP_FILENAME_FORMAT;
        foreach($list as $key){
            $pdfFolder=str_replace("%$key%",$fullrow[$key],$pdfFolder);
            $pdfFile=str_replace("%$key%",$fullrow[$key],$pdfFile);
        }
        $scriptText.='app.consoleout("Generating [PDF] for layoutId = '.$layoutId.'");function getDateShort(){var today=new Date();var year=today.getFullYear().toString();var month="0"+(today.getMonth()+1).toString();var day="0"+today.getDate().toString();var h="0"+today.getHours();var m="0"+today.getMinutes();var s="0"+today.getSeconds();var ms="00"+today.getMilliseconds();return year.substr(-4)+"-"+month.substr(-2)+"-"+day.substr(-2)+" "+h.substr(-2)+":"+m.substr(-2)+":"+s.substr(-2)+"."+ms.substr(-3);}var missingLinks=[];for(n=0;n<myDoc.links.length;n++){aLink=myDoc.links[n];if(aLink.status==LinkStatus.linkMissing){missingLinks.push(aLink);}}if(missingLinks.length>=1){oLogFile=File("'.$pdfFolder.$pdfFile.'_'.$fullrow["Name"].'.log");if(oLogFile.open("e")){oLogFile.seek(0,2);oLogFile.writeln("["+getDateShort()+"] document contains "+missingLinks.length+" missing links:\r");for(i=0;i<missingLinks.length;i++){oLogFile.writeln(missingLinks[i].filePath+"\r");}oLogFile.close();}}try{for(docPages=0;docPages<myDoc.pages.length;docPages++){myPageName=myDoc.pages.item(docPages).name;app.pdfExportPreferences.pageRange=myPageName;exportfile=File("'.$pdfFolder.'"+AddZeroes(myPageName)+"_'.$pdfFile.'.pdf");myDoc.exportFile(ExportFormat.pdfType,exportfile,app.pdfExportPresets.item("'.$jobname.'"));}}catch(err){app.performSimpleRequest("'.$requestUrl.'6&message=cannot%20generate%20layout%20pdfs");myDoc.close(SaveOptions.no);app.entSession.logout();exit(0);}myDoc.close(SaveOptions.no);app.performSimpleRequest("'.$requestUrl.'1&message=success");app.entSession.logout();';
        require_once BASEDIR.'/server/bizclasses/BizInDesignServerJob.class.php';
        $jobId=BizInDesignServerJobs::createJob($scriptText,null,false,'mtp',$layoutId,false,'7.5');
        BizInDesignServerJobs::startBackgroundJobs();
    }
    public static function clearSentObject($objectId,$newPubId,$newStatusId,$oldStatusId){
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
            $layPubId=$layIssueId=$layStatusId=0;
            $layEditions=array();
            if (self::getLayoutDetails($layoutId,$layPubId,$layIssueId,$layStatusId,$layEditions)) {
                if (self::checkTriggerStatuses($layoutId,$layStatusId)) {
                    self::queueLayoutObject($ticket,$layoutId,$layPubId,$layIssueId,$layStatusId,$layEditions);
                }
            }
        }
    }
    private static function checkTriggerStatuses($layoutId,$layStatusId){
        $mtpConfig=self::getMtpConfig($layStatusId);
        if (!$mtpConfig) {
            return false;
        }
        $childIds=self::getPlacedChilds($layoutId);
        foreach($childIds as $childId){
            $objType=self::getObjectType($childId);
            if ($objType=='Article') {
                if ($mtpConfig['arttriggerstate']!=0) {
                    $childStatusId=self::getObjectStatus($childId);
                    if ($mtpConfig['arttriggerstate']!=$childStatusId) {
                        return false;
                    }
                }
            } else if ($objType=='Image') {
                if ($mtpConfig['imgtriggerstate']!=0) {
                    $childStatusId=self::getObjectStatus($childId);
                    if ($mtpConfig['imgtriggerstate']!=$childStatusId) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
    private static function getObjectStatus($objectId){
        $dbDriver=DBDriverFactory::gen();
        $dbobjects=$dbDriver->tablename("objects");
        $sql='select `state` from '.$dbobjects.' where `id`='.$objectId;
        $sth=$dbDriver->query($sql);
        $res=$dbDriver->fetch($sth);
        return $res['state'];
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
    private static function getMtpConfig($layStatusId){
        $dbDriver=DBDriverFactory::gen();
        $dbmtp=$dbDriver->tablename("mtp");
        $sql='select * from '.$dbmtp.' where `laytriggerstate`='.$layStatusId;
        $sth=$dbDriver->query($sql);
        $row=$dbDriver->fetch($sth);
        if (!$row) {
            return null;
        }
        if (trim($row['arttriggerstate'])=='') {
            $row['arttriggerstate']=0;
        }
        if (trim($row['imgtriggerstate'])=='') {
            $row['imgtriggerstate']=0;
        }
        if (trim($row['layprogstate'])=='') {
            $row['layprogstate']=0;
        }
        if (trim($row['artprogstate'])=='') {
            $row['artprogstate']=0;
        }
        if (trim($row['imgprogstate'])=='') {
            $row['imgprogstate']=0;
        }
        return $row;
    }
    private static function getLayoutDetails($layoutId,&$layPubId,&$layIssueId,&$layStatusId,&$layEditions){
        require_once BASEDIR.'/server/bizclasses/BizTarget.class.php';
        $targets=BizTarget::getTargets(null,$layoutId);
        if (count($targets)!=1) {
            LogHandler::Log('mtp','ERROR','Layout '.$layoutId.' is NOT bound to ONE issue. Target count = '.count($targets));
            return false;
        }
        if (!isset($targets[0]->Issue->Id)||!$targets[0]->Issue->Id) {
            LogHandler::Log('mtp','ERROR','Layout '.$layoutId.' has unknown issue. Target count = '.count($targets));
            return false;
        }
        $layIssueId=$targets[0]->Issue->Id;
        $layEditions=$targets[0]->Editions;
        $dbDriver=DBDriverFactory::gen();
        $dbobjects=$dbDriver->tablename("objects");
        $sql='select `publication`, `state` from '.$dbobjects.' where `id`='.$layoutId;
        $sth=$dbDriver->query($sql);
        $res=$dbDriver->fetch($sth);
        if (!$res) {
            LogHandler::Log('mtp','ERROR','Layout not found. Id='.$layoutId);
            return false;
        }
        $layPubId=$res['publication'];
        if (!$layPubId) {
            LogHandler::Log('mtp','ERROR','Layout '.$layoutId.' has unknown publication.');
            return false;
        }
        $layStatusId=$res['state'];
        if (!$layStatusId) {
            LogHandler::Log('mtp','ERROR','Layout '.$layoutId.' has unknown status.');
            return false;
        }
        return true;
    }
}

?>