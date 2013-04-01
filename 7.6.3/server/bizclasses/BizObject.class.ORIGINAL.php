<?php
/**
 * @package 	Enterprise
 * @subpackage 	BizClasses
 * @since 		v4.2
 * @copyright 	WoodWing Software bv. All Rights Reserved.
 */

class BizObject
{
	private static function addDefaultsToArr(&$arr) 
	{
		$publid = $arr['publication'];
		$objtype = $arr['type'];

		require_once BASEDIR.'/server/bizclasses/BizProperty.class.php';
		$staticProps = array_flip( BizProperty::getStaticPropIds() );
		$staticProps = array_change_key_case( $staticProps, CASE_LOWER );

		require_once BASEDIR.'/server/dbclasses/DBProperty.class.php';
		$customprops = DBProperty::getProperties( $publid, $objtype );
		
		foreach( $customprops as $custompropname => $customprop ) {
			//BZ#10907 always lowercase db fields
			$custompropname = strtolower($custompropname);
			if( !array_key_exists($custompropname, $arr) && 
				!array_key_exists($custompropname, $staticProps) ) { // filtered out static properties
				$arr[$custompropname] = $customprop->DefaultValue;
			}
		}
	}
	
	public static function createObject( Object $object, $user, $lock, $autonaming )
	{
		require_once BASEDIR.'/server/bizclasses/BizAccess.class.php';
		require_once BASEDIR.'/server/bizclasses/BizEmail.class.php';
		require_once BASEDIR.'/server/bizclasses/BizWorkflow.class.php';
		require_once BASEDIR.'/server/bizclasses/BizStorage.php';
		require_once BASEDIR.'/server/bizclasses/BizPage.class.php';
		require_once BASEDIR.'/server/bizclasses/BizTarget.class.php';
		require_once BASEDIR.'/server/bizclasses/BizUser.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
		
		// BZ#9827 $targets can now be null, if this is the case:
		// for now: DO NOT CHANGE EXISTING BEHAVIOR
		if ($object->Targets == null) {
			$object->Targets = array();
		}
		
		// Validate targets count, layouts can be assigned to one issue only
		BizWorkflow::validateMultipleTargets( $object->MetaData, $object->Targets );
		
		// Validate (and correct,fill in) workflow properties
		BizWorkflow::validateWorkflowData( $object->MetaData, $object->Targets, $user );
				
		// Validate and fill in name and meta data
		// adjusts $object and returns flattened meta data
		$arr = self::validateForSave( $user, $object, $autonaming );
		self::addDefaultsToArr($arr);		
		
		// determine new version nr for the new object
		require_once BASEDIR.'/server/bizclasses/BizVersion.class.php';
		require_once BASEDIR.'/server/bizclasses/BizAdmStatus.class.php';
		$status = BizAdmStatus::getStatusWithId( $object->MetaData->WorkflowMetaData->State->Id );
		$object->MetaData->WorkflowMetaData->Version = BizVersion::determineNextVersionNr( $status, $arr );
		$arr['version'] = $object->MetaData->WorkflowMetaData->Version;

		// Check authorization
		$rights = 'W'; // check 'Write' access (W)
		if( $arr['type'] == 'Dossier' || $arr['type'] == 'DossierTemplate' ) {
			$rights .= 'd'; // check the 'Create Dossier' access (d) (BZ#17051)
		} else if( $arr['type'] == 'Task' ) {
			$rights .= 't'; // check 'Create Task' access (t) (BZ#17119)
		}
		BizAccess::checkRightsMetaDataTargets( $object->MetaData, $object->Targets, $rights ); // BZ#17119

		// If possible (depends on DB) we get id for new object beforehand:
		$dbDriver = DBDriverFactory::gen();
		$id = $dbDriver->newid(DBPREFIX."objects",false);
		$storename = $id ? StorageFactory::storename($id, $arr) : '';

		if (!isset($arr['issue'])) {
			$arr['issue'] = BizTarget::getDefaultIssueId($arr['publication'], $object->Targets);
		}
		$issueids = BizTarget::getIssueIds($object->Targets);
		
		if ($autonaming === true) {
			$objectname = DBObject::getUniqueObjectName($issueids, $arr['type'], $arr['name']);
			if (empty($objectname)) {
				throw new BizException( 'ERR_NAME_EXISTS', 'Server', $arr['name']);
			}
			$arr['name'] = $objectname;
		}

		$now = date('Y-m-d\TH:i:s');
		
		$userfull = BizUser::resolveFullUserName($user);
		$object->MetaData->WorkflowMetaData->Creator = $userfull;
		$arr['creator'] = $user;
		$arr['created'] = $object->MetaData->WorkflowMetaData->Created = $now;
		$object->MetaData->WorkflowMetaData->Modifier = $userfull;
		$arr['modifier'] = $user;
		$arr['modified'] = $object->MetaData->WorkflowMetaData->Modified = $now;
		
		// Create object record in DB:
		$sth = DBObject::createObject( $storename, $id, $user, $now, $arr, $now );
		if (!$sth) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}

		// If we did not get an id from DB beforehand, we get it now and update storename
		// that is derived from object id
		if (!$id) {
			$id = $dbDriver->newid(DBPREFIX."objects",true);
			if (!$id) {
				throw new BizException( 'ERR_DATABASE', 'Server', 'No ID' );
			}
			// now we know id: generate storage name and store it
			$storename = StorageFactory::storename($id, $arr);
			$sth = DBObject::updateObject( $id, $user, array(), $now, $storename );
		}
		$object->MetaData->BasicMetaData->ID = $id;

		// The saveTargets() call below sends UpdateObjectTargets events and the saveExtended() call below 
		// sends CreateObjectsRelations event, which needs to be exposed until we have sent the CreateObjects
		// event, as done at the end of this service (BZ#15317). Note that the createDossierFromObject() call
		// does recursion(!) and so another queue is created. The SmartEventQueue does stack(!) the queues
		// to make sure that events do not get mixed up. Recursion causes queues to get stacked. The top most
		// queue is finalized first, by sending out events in its own context before getting back to caller (unstack).
		require_once BASEDIR.'/server/smartevent.php'; // also SmartEventQueue
		SmartEventQueue::createQueue();

		// Validate targets and store them at DB for this object
		BizTarget::saveTargets( $user, $id, $object->Targets, $object->MetaData );

		// Validate meta data and targets (including validation done by Server Plug-ins)
		BizObject::validateMetaDataAndTargets( $user, $object->MetaData, $object->Targets, false, $autonaming );

		// BZ#10526 If you want to create relations with the created object you cannot enter the parent or child
		// so do it here
		if (! is_null($object->Relations)) {
			foreach ($object->Relations as $relation){
				if (empty($relation->Parent)){
					$relation->Parent = $id;
				} elseif (empty($relation->Child)){
					$relation->Child = $id;
				}
				// don't check if parent/child has $id filled in by client because it has just been created => unlikely
				// BZ#10526 special case: create dossier when parent is -1 and type is Contained
				if ($relation->Parent == -1 && $relation->Type == 'Contained'){
					$dossierObject = self::createDossierFromObject($object);
					if (! is_null($dossierObject)){
						$relation->Parent = $dossierObject->MetaData->BasicMetaData->ID;
					}
					else {
					//TODO else delete relation?
					}	
				}		
				// BZ#16567 Delete object targets (if not a layout)
				// BZ#17915 Article created in dossier should remain without issues
				// BZ#18405 Delete object targets only if there are targets
				$objTypes = array('Layout', 'LayoutTemplate'); // BZ#20886
				if ($relation->Parent > 0 && $relation->Child == $id && $relation->Type == 'Contained' &&
					!in_array( $object->MetaData->BasicMetaData->Type, $objTypes ) && // BZ#20886
					isset($object->Targets) && count($object->Targets) > 0 ) {
						//See BZ#17852 After creating Dossier from Layout (Create) checkin Issue information on layout is wrong.
						BizTarget::deleteTargets($user, $id, $object->Targets);
				}
			}
		}

		// Object record is now created, now save other stuff elements, relations etc.
		self::saveExtended( $id, $object, $arr, $user, TRUE );
		// Set the deadline. Must be called after the object/relational targets are in place.
		// Handle deadline
		// First look for object-target issues
		$issueIdsDL = self::getTargetIssuesForDeadline( $object );
		// Image/Article without object-target issue can inherit issues from relational-targets. BZ#21218

		DBObject::objectSetDeadline( $id, $issueIdsDL, $arr["section"], $arr['state'] );
		
		// If requested we lock the object
		if ($lock)	{
			self::Lock( $id, $user );
			$lockedby = $user;
		} else {
			$lockedby = '';
		}
		
		// ==== So far it was DB only, now involve files:
		
		// Save object's files:
		self::saveFiles( $storename, $id, $object->Files, $object->MetaData->WorkflowMetaData->Version );
		
		// Save pages (both files and DB records)
		BizPage::savePages( $storename, $id, 'Production', $object->Pages, FALSE, null, $object->MetaData->WorkflowMetaData->Version );

		// === Saving done, now we do the after party:

		// Get object from DB to make sure we have it all complete for notifications as well to return to caller
		$object = self::getObject( $object->MetaData->BasicMetaData->ID, $user, false, null,
										  array('Relations', 'PagesInfo', 'Messages', 'Elements', 'Targets') );
		
		// Update object's 'link' files (htm) in <FileStore>/_BRANDS_ folder
		if (ATTACHSTORAGE == 'FILE') {
			require_once BASEDIR . '/server/bizclasses/BizLinkFiles.class.php';
			BizLinkFiles::createLinkFilesObj( $object, $storename );
		}

		// Add to search index:
		require_once BASEDIR . '/server/bizclasses/BizSearch.class.php';
		BizSearch::indexObjects( array( $object ) );
				
		// Do notifications
		$issueIds = $issueNames = $editionIds = $editionNames = '';
		self::listIssuesEditions( $object->Targets, $issueIds, $issueNames, $editionIds, $editionNames );
		require_once BASEDIR.'/server/dbclasses/DBLog.class.php';
		if (!array_key_exists('routeto',$arr)) {
			$arr['routeto'] = '';
		}
		DBlog::logService( $user, 'CreateObjects', $id, $arr['publication'], null, $arr['section'], $arr['state'],
									'', $lock, '', $arr['type'], $arr['routeto'], $editionNames, $arr['version'] );
		SmartEventQueue::startFire(); // let next coming CreateObjects event through directly (BZ#15317).
		new smartevent_createobjectEx( BizSession::getTicket(), $userfull, $object);
		SmartEventQueue::fireQueue(); // Typically fires postponed CreateObjectsRelations and UpdateObjectTargets events (BZ#15317).

		BizEmail::sendNotification( 'create object', $object, $arr['types'], null );
		if( MTP_SERVER_DEF_ID != '' ) {
			require_once BASEDIR.'/server/MadeToPrintDispatcher.class.php';
			MadeToPrintDispatcher::doPrint( $id, BizSession::getTicket() );
		}
		
		return $object;
	}

	public static function saveObject( Object $object, $user, $createVersion, $unlock )
	{
		require_once BASEDIR.'/server/bizclasses/BizEmail.class.php';
		require_once BASEDIR.'/server/bizclasses/BizVersion.class.php';
		require_once BASEDIR.'/server/bizclasses/BizWorkflow.class.php';
		require_once BASEDIR.'/server/bizclasses/BizStorage.php';
		require_once BASEDIR.'/server/bizclasses/BizPage.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObjectLock.class.php';
		require_once BASEDIR.'/server/dbclasses/DBWorkflow.class.php';
		require_once BASEDIR.'/server/bizclasses/BizTarget.class.php';
		require_once BASEDIR.'/server/bizclasses/BizUser.class.php';
		require_once BASEDIR.'/server/dbclasses/DBEdition.class.php';
		require_once BASEDIR.'/server/bizclasses/BizDeadlines.class.php';

		$id = $object->MetaData->BasicMetaData->ID;
		$dbDriver = DBDriverFactory::gen();

		// Next, check if we have an alien object (from content source, not in our database)
		require_once BASEDIR . '/server/bizclasses/BizContentSource.class.php';
		if( BizContentSource::isAlienObject( $id ) ) {
			// Check if we already have a shadow object for this alien. If so, change the id
			// to the shadow id
			$shadowID = BizContentSource::getShadowObjectID($id);
			if( $shadowID ) {
				$id = $shadowID;
			} else {
				LogHandler::Log('bizobject','DEBUG','No shadow found for alien object '.$id);
				throw new BizException( 'ERR_NOTFOUND', 'Client', $id );
			}
		}

		// get current record in db
		$sth = DBObject::getObject( $id );
		if (!$sth) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		$currRow = $dbDriver->fetch($sth);
		if (!$currRow) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', $id );
		}
		
		// BZ#8475: Oracle specific fix - on Oracle ID as well as id is set... 
		// this leads further code to believe it is a list of properties, not a databaserow...
		if (isset($currRow['id']) && isset($currRow['ID'])) {
			unset($currRow['ID']);
		}
		
		$curPub   = $currRow['publication'];
		$curSect  = $currRow['section'];
		$curState = $currRow['state'];
		
		// Publication, Category are crucial to have, but can be empty on save when they are not changed, so fill them in with current values:
		if( !$object->MetaData->BasicMetaData->Publication || !$object->MetaData->BasicMetaData->Publication->Id ) {
			$object->MetaData->BasicMetaData->Publication = new Publication( $curPub );
		}
		if( !$object->MetaData->BasicMetaData->Category || !$object->MetaData->BasicMetaData->Category->Id ) {
			$object->MetaData->BasicMetaData->Category = new Category( $curSect );
		}
		
		// Validate targets count, layouts can be assigned to one issue only
		BizWorkflow::validateMultipleTargets( $object->MetaData, $object->Targets );
		
		// Validate (and correct,fill in) workflow properties
		BizWorkflow::validateWorkflowData( $object->MetaData, $object->Targets, $user, $curState );
		
		// Validate and fill in name and meta data
		// adjusts $object and returns flattened meta data
		$newRow = self::validateForSave( $user, $object );
		$state = $newRow['state'];

		// Does the user has a lock for this file?
		$lockedby = DBObjectLock::checkLock( $id );
		if( !$lockedby ){
			// object not locked at all:
			$sErrorMessage = BizResources::localize("ERR_NOTLOCKED").' '.BizResources::localize("SAVE_LOCAL");
			throw new BizException( null, 'Client', $id, $sErrorMessage );
		} else if( strtolower($lockedby) != strtolower($user) ) {
			//locked by someone else
			throw new BizException( 'ERR_NOTLOCKED', 'Client', $id );
		}

		// Check authorization
		global $globAuth;
		if( !isset($globAuth) ) {
			require_once BASEDIR.'/server/authorizationmodule.php';
			$globAuth = new authorizationmodule( );
		}
		// First check against current values:
		// If state changes, we need to see if that is allowed:
		// When in personal state (-1), status change is always allowed
		if ($curState != $state && $curState != -1) {
			$curType  = $currRow['type'];
			$globAuth->getrights($user, $curPub, 0, $curSect, $curType);
			$sf = $globAuth->checkright('F', $curPub, 0, $curSect, $curType, $curState );
			$sc = $globAuth->checkright('C', $curPub, 0, $curSect, $curType, $curState );
			if ($state == DBWorkflow::nextState( $curState )) {
				// change forward
				if (!$sf && !$sc) {			// change state forward
					throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(F)" );
				}
			} else {
				// change to any status
				if (!$sc) {			// change state
					throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(C)" );
				}
			}
		}

		// Next, check if we have write access to the new status, for personal status (-1) it's always Ok
		if($state == -1){
		}else{
			$globAuth->getrights( $user, $newRow['publication'], array_key_exists('issue', $newRow) ? $newRow['issue'] : null, $newRow['section'], $newRow['type'] );
			if (!$globAuth->checkright('W', $newRow['publication'], array_key_exists('issue', $newRow) ? $newRow['issue'] : null, $newRow['section'], $newRow['type'], $state)){
				throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(W)" );
			}
		}

		// Do some DB clean-up before save, remove flags, MtP etc.
		self::cleanUpBeforeSave( $user, $object, $newRow, $curState );
		
		// Create version if needed, even if $createVersion is false a version might be generated
		BizVersion::createVersionIfNeeded( $id, $currRow, $newRow, $object->MetaData->WorkflowMetaData, $createVersion );

		$now = date('Y-m-d\TH:i:s');
				
		// Clear indexed flag, so object will be re-indexed when search engine used:
		// At Create it's initialized empty, SetProps does not modify this to prevent overkill 
		// of re-indexing again and again. It's assumed that the indexed meta properties don't 
		// change after creation.
		$newRow['indexed'] = '';

		// Save to DB:
		$sth = DBObject::updateObject($id, $user, $newRow, $now);
		if (!$sth)	{
			throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		// v4.2.4 patch for #5700, Save current time be able to send this later as modified time 
		$now = date('Y-m-d\TH:i:s');

		$userfull = BizUser::resolveFullUserName($user);

		// Set Modifier for return
		$object->MetaData->WorkflowMetaData->Modified = $now;
		$object->MetaData->WorkflowMetaData->Modifier = $userfull;

		// Delete object's 'link' files (htm) in <FileStore>/_BRANDS_ folder
		if (ATTACHSTORAGE == 'FILE') {
			$oldtargets = BizTarget::getTargets($user, $id);
			require_once BASEDIR.'/server/dbclasses/DBPublication.class.php';
			$pubName = DBPublication::getPublicationName($curPub);
			require_once BASEDIR . '/server/bizclasses/BizLinkFiles.class.php';
			BizLinkFiles::deleteLinkFiles( $curPub, $pubName, $id, $currRow['name'], $oldtargets );
		}
		
		// Validate targets and store them at DB for this object
		// BZ#9827 Only save targets if not null
		if ($object->Targets !== null) {
			BizTarget::saveTargets( $user, $id, $object->Targets, $object->MetaData );
			
			// Validate meta data and targets (including validation done by Server Plug-ins)
			BizObject::validateMetaDataAndTargets( $user, $object->MetaData, $object->Targets );
		}
		
		// Object record is now changed, now save other stuff elements, relations etc.
		self::saveExtended( $id, $object, $newRow, $user, false );
		
		// ===== Handle deadline.
		// Collect object-/relational-target issues from object
		$issueIdsDL = self::getTargetIssuesForDeadline( $object );
		// If deadline is set and object has issues check if the set deadline is not beyond earliest possible deadline
		if( $issueIdsDL && isset($newRow['deadline']) && $newRow['deadline'] ) {
			BizDeadlines::checkDeadline($issueIdsDL, $newRow['section'], $newRow['deadline']);
		}
		// If no deadline set, calculate deadline, else just store the deadline
		$deadlinehard = '';
		$oldDeadline = DBObject::getObjectDeadline( $id );
		if( isset($newRow['deadline']) && $newRow['deadline'] ) {
			$deadlinehard = $newRow['deadline'];
			if ( $oldDeadline !== $deadlinehard ) {
				DBObject::setObjectDeadline( $id, $deadlinehard );
				if ( BizDeadlines::canPassDeadlineToChild( $newRow['type'] ) ) {
					// Set the deadlines of children without own object-target issue.
					BizDeadlines::setDeadlinesIssuelessChilds( $id, $deadlinehard );
				}
			}
		}
		else {
			$deadlinehard = DBObject::objectSetDeadline( $id, $issueIdsDL, $newRow['section'], $newRow['state'] );
			if ( $oldDeadline !== $deadlinehard ) {
				if ( BizDeadlines::canPassDeadlineToChild( $newRow['type'] ) ) {
					// Recalculate the deadlines of children without own object-target issue.
					// This recalculation is limited to an issue change of the parent.
					// New issue of the parent results in new relational-target issue and so
					// a new deadline. If the category of the parent changes this has no effect
					// as the children do not inherit this change.
					BizDeadlines::recalcDeadlinesIssuelessChilds( $id );
				}
			}
		}

		//Broadcast (soft) deadline
		if ( $oldDeadline !== $deadlinehard ) {
			require_once BASEDIR.'/server/utils/DateTimeFunctions.class.php';
			$deadlinesoft = DateTimeFunctions::calcTime( $deadlinehard, -DEADLINE_WARNTIME );
			require_once BASEDIR . '/server/smartevent.php';
			new smartevent_deadlinechanged( null, $id, $deadlinehard, $deadlinesoft );
		}
		// ==== So far it was DB only, now involve files:
		
		// For shadow objects we now pass control to Content Source which may influence what to do with file storing
		// as it can modify $object
		if( trim($currRow['contentsource']) ) {
			require_once BASEDIR . '/server/bizclasses/BizContentSource.class.php';
			BizContentSource::saveShadowObject( trim($currRow['contentsource']), trim($currRow['documentid']), $object );
		}

		// Save object's files:
		self::saveFiles( $currRow['storename'], $id, $object->Files, $object->MetaData->WorkflowMetaData->Version );
		
		// Save pages (both files and DB records)
		BizPage::savePages( $currRow['storename'], $id, 'Production', $object->Pages, TRUE, $currRow['version'], $object->MetaData->WorkflowMetaData->Version );
		
		
		// === Saving done, now we do the after party:

		// Get object from DB to make sure we have it all complete for notifications as well to return to caller
		$object = self::getObject( $object->MetaData->BasicMetaData->ID, $user, false, null,
										  array('Relations', 'PagesInfo', 'Messages', 'Elements', 'Targets') );
				
		// Create object's 'link' files (htm) in <FileStore>/_BRANDS_ folder
		if (ATTACHSTORAGE == 'FILE') {
			require_once BASEDIR . '/server/bizclasses/BizLinkFiles.class.php';
			BizLinkFiles::createLinkFilesObj( $object, $currRow['storename'] );
		}
				
		// Unlock if needed - this is done 'as late as possible', hence it's behind the file operations
		if ($unlock) {
			self::unlockObject( $id, $user );
			$lockedby = '';
			$object->MetaData->WorkflowMetaData->LockedBy = ''; // used for notification below
		}
		
		// Update search index:
		require_once BASEDIR . '/server/bizclasses/BizSearch.class.php';
		BizSearch::updateObjects( array( $object ) );

		
		// Do notifications	
		$issueIds = $issueNames = $editionIds = $editionNames = '';
		self::listIssuesEditions( $object->Targets, $issueIds, $issueNames, $editionIds, $editionNames );
		require_once BASEDIR.'/server/dbclasses/DBLog.class.php';
		DBlog::logService( $user, 'SaveObjects', $id, $newRow['publication'], null, $newRow['section'], $newRow['state'],
						'', '', '', $newRow['type'], $newRow['routeto'], $editionNames, $newRow['version'] );
		if( MTP_SERVER_DEF_ID != '' ) {
			require_once BASEDIR.'/server/MadeToPrintDispatcher.class.php';
			MadeToPrintDispatcher::doPrint( $id, BizSession::getTicket() );
		}
		require_once BASEDIR.'/server/smartevent.php';

		new smartevent_saveobjectEx( BizSession::getTicket(), $userfull, $object, $currRow['routeto'] );

		BizEmail::sendNotification( 'save object' , $object, $newRow['types'], $currRow['routeto']);
		
		// Optionally send geo update for placed articles of a saved layout, but only if we are not using XMLGeo
		// XMLGeo would require a geo update file per article which we don't have
		if( isset($object->Relations) && $newRow['type'] == 'Layout' 
			&& strtolower(UPDATE_GEOM_SAVE) == strtolower('ON') ) {
			if (! BizSettings::isFeatureEnabled('UseXMLGeometry') ) {
				foreach ($object->Relations as $relation) {
					// If someone else has object lock, send notification
					if( strtolower(DBObjectLock::checkLock( $relation->Child )) !=  strtolower($user) ) {
						new smartevent_updateobjectrelation(BizSession::getTicket(), $relation->Child, 'placement', $id, $newRow['name']);
					}
				}				
			}
		}

		// Check if elements of an article are placed more than once on same/different layout
		if( $newRow['type'] == 'Layout' || $newRow['type'] == 'LayoutModule' ) {
			if( isset($object->Relations) && is_array($object->Relations) && count($object->Relations) > 0 ) {
				require_once BASEDIR.'/server/dbclasses/DBMessage.class.php';
				$messages = DBMessage::getMessages( $id );
				require_once BASEDIR.'/server/bizclasses/BizRelation.class.php';
				BizRelation::signalParentForDuplicatePlacements( $id, $object->Relations, $user, $messages, false );
				$object->Messages += $messages;
			}
		}
		
		return $object;
	}

	/**
	 * Get object from Enterprise or content source
	 *
	 * @param string $id Enterprise object id or Alien object id
	 * @param string $user
	 * @param bool $lock
	 * @param string $rendition
	 * @param array $requestInfo
	 * @param string $haveVersion
	 * @param string $editionId Optional. Used to get edition/device specific file renditions.
	 * @return Object
	 */
	public static function getObject( $id, $user, $lock, $rendition, $requestInfo = null, 
									$haveVersion = null, $checkRights = true, $editionId = null )
	{
		// if $requestInfo not set, we fallback on old (pre v6) defaults which depend on rendition
		// At the moment we cannot reliably see difference between empty array and nil, so we use
		// these defaults for both cases. To be finetuned for v7.0
		if (empty($requestInfo)) {
			$requestInfo = array();
			switch( $rendition ) {
				case 'thumb':
					break;
				default:
					$requestInfo[] = 'Pages';
					$requestInfo[] = 'Relations';
					$requestInfo[] = 'Messages';
					$requestInfo[] = 'Elements';
					$requestInfo[] = 'Targets';
					break;
			}
		}		
		
		//  work around PEAR bug that does not parse 1 element arrayofstring into an array....  JCM
		if( is_object($requestInfo) ) $requestInfo = array( $requestInfo->String );
		
		require_once BASEDIR.'/server/bizclasses/BizQuery.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
		require_once BASEDIR.'/server/dbclasses/DBUser.class.php';
    	require_once BASEDIR.'/server/bizclasses/BizUser.class.php';		

		// Validate input:
		// check for empty id
		if (!$id) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', 'SCEntError_ObjectNotFound: null' );
		}
		
		// Next, check if we have an alien object (from content source, not in our database)
		require_once BASEDIR . '/server/bizclasses/BizContentSource.class.php';
		if( BizContentSource::isAlienObject( $id ) ) {
			// Check if we already have a shadow object for this alien. If so, change the id
			// to the shadow id
			$shadowID = BizContentSource::getShadowObjectID($id);
			if( $shadowID ) {
				$id = $shadowID;
			} else {
				LogHandler::Log('bizobject','DEBUG','No shadow found for alien object '.$id);
				// Determine if we should pass request to Content Source or that we should first 
				// create a shadow object out of the alien object. 
				// We do the latter when placement renditon is asked which needs a relation and thus shadow
				// or when the object is locked.
				if( $rendition == 'placement' || $lock ) {
					// create shadow object for alien
					$shadowObject = BizContentSource::createShadowObject( $id, null );
					$shadowObject = self::createObject( $shadowObject, $user, false /*lock*/, empty($shadowObject->MetaData->BasicMetaData->Name) /*$autonaming*/ );
					// Change alien id into new shadow id, normal Get will continue taking care of getting
					// the new shadow
					$id = $shadowObject->MetaData->BasicMetaData->ID;
				}
				else {
					// get alien object, lock is requested by Properties dialog
					$a= BizContentSource::getAlienObject( $id, $rendition, $lock );
					return $a;
				}
			}
		}
		
		// Lock the object, might be premature
		if( $lock ) {
			self::Lock( $id, $user );
		}
		// Now enter in a try catch, so we can release the premature lock when something goes wrong:
		try {
			// get the object
			$objectProps = BizQuery::queryObjectRow($id );
			if (!$objectProps) { // check for not found
				throw new BizException( 'ERR_NOTFOUND', 'Client', 'SCEntError_ObjectNotFound: '.$id );
			}

			// get object's targets
			if( in_array('Targets', $requestInfo) ) {
				$targets = DBTarget::getTargetsByObjectId($id);
				foreach ($targets as $target) {
					if ($target->Issue->Id && $target->PubChannel->Id) {
						$objectProps['IssueId'] = $target->Issue->Id;
						$objectProps['Issue'] = $target->Issue->Name;
						break;
					}
				}
			} else {
				$targets = null;
			}
			
			$p 	= $objectProps['PublicationId'];
			$is = $objectProps['IssueId'];			
			$se = $objectProps['SectionId'];
			$t 	= $objectProps['Type'];
			$st = $objectProps['StateId'];
			$routeto = $objectProps['RouteTo'];		

			if ($checkRights) {
				self::checkAccesRights($id, $lock, $user, $p, $is, $se, $t, $st, $routeto);
			}
			
			if (!empty($objectProps['RouteToUser'])) {
				$objectProps['RouteTo'] = $objectProps['RouteToUser'];
			}elseif( !empty( $objectProps['RouteToGroup'] ) ) {
				$objectProps['RouteTo'] = $objectProps['RouteToGroup'];
			}

			$meta = self::queryRow2MetaData( $objectProps );

			$pages = array();
			if( in_array('Pages', $requestInfo) || in_array('PagesInfo', $requestInfo) ) {
				require_once BASEDIR.'/server/bizclasses/BizPage.class.php';
				// Since v5.0, first try to get planned pages... when not available, we fall back at produced pages.
				// We do this for native/none requests only; planned pages have NO renditions, and so we keep
				// the possibility open to retrieve output/preview page files (which is backwards compat with v4.2)
				// which is typically used by applications such as Smart Mover.
				if( $rendition == 'none' || $rendition == 'native' ) {
					$pages = BizPage::getPageFiles( $id, 'Planning', $objectProps['StoreName'], 
													in_array('Pages', $requestInfo) ? $rendition : 'none', 
													$objectProps['Version'] );
				}
				// Get produced pages
				if( count( $pages ) <= 0 ) {
					$pages = BizPage::getPageFiles( $id, 'Production', $objectProps['StoreName'], 
													in_array('Pages', $requestInfo) ? $rendition : 'none', 
													$objectProps['Version'] );
				}
			}
			
			if( in_array('Relations', $requestInfo) ) {
				require_once BASEDIR.'/server/bizclasses/BizRelation.class.php';
				// BZ#14481 only attach geo info when server feature "UseXMLGeometry" is on, object type is Article and rendition != none (BZ#8657)
				$attachGeo = false;
				if ($t == 'Article' && BizSettings::isFeatureEnabled('UseXMLGeometry') && $rendition != 'none'){
					$attachGeo = true;
				}
				$allTargets = false;
				if( in_array('Targets', $requestInfo) ) {
					$allTargets = true; //Targets from parent and children objects, otherwise only parent targets.
				}
				$relations = BizRelation::getObjectRelations( $id, $attachGeo, $allTargets);
			} else {
				$relations = array();
			}

			// Signal layout when elements of an article is placed more than once on same/different layout
			if( in_array('Messages', $requestInfo) ) {
				require_once BASEDIR.'/server/dbclasses/DBMessage.class.php';
				$messages = DBMessage::getMessages( $id );
				if( $rendition == 'native' && $objectProps['Type'] == 'Layout' || $objectProps['Type'] == 'LayoutModule' ) {
					require_once BASEDIR.'/server/bizclasses/BizRelation.class.php';
					BizRelation::signalParentForDuplicatePlacements( $id, $relations, $user, $messages, false );
				}
			} else {
				$messages = array();
			}
			if( in_array('Elements', $requestInfo) ) {
				require_once BASEDIR.'/server/dbclasses/DBElement.class.php';
				$elements = DBElement::getElements($id);
			}
			else {
				$elements = array();
			}

			// v7.5 Return rendition information.
			if( in_array('RenditionsInfo', $requestInfo) ) {
				require_once BASEDIR.'/server/dbclasses/DBObjectRenditions.class.php';
				$renditionsInfo = DBObjectRenditions::getEditionRenditionsInfo( $id );
			} else {
				$renditionsInfo = null;
			}

			$object = new Object( $meta,
						$relations, 
						$pages, 
						null, 
						$messages, 
						$elements,
						$targets, 
						$renditionsInfo );
			
												
			// If we are getting an shadow object, call content source provider to possibly fill in the files.
			// This way it's up to the Content Source provider to get the files from Enterprise or 
			// from the content source, which could be dependent on the rendition.
			// Also the content source can manipulate the meta data:
			if( trim($objectProps['ContentSource']) ) {
				try {
					require_once BASEDIR . '/server/bizclasses/BizContentSource.class.php';
					BizContentSource::getShadowObject( trim($objectProps['ContentSource']), 
							trim($objectProps['DocumentID']), $object, $objectProps, $lock, $rendition );
				} catch( BizException $e ) {
					// Let's be robust here; When the Content Source connector has been unplugged, an exception is thrown.
					// Nevertheless, when not asked for a rendition, we already have the metadata, so there is no reason
					// to panic. Logging an error is a more robust solution, as done below. This typically is needed to let
					// Solr indexing/unindexing process continue. An throwing an exception would disturb this badly.
					if( $rendition == 'none' && $e->getMessageKey() == 'ERR_NO_CONTENTSOURCE' ) {
						LogHandler::Log( 'bizobject', 'ERROR', 'Could not get shadow object for '.
							'Content Source "'.$objectProps['ContentSource'].'". But since no rendition '.
							'is requested, and for the sake of robustness, the Enterprise object is returned instead.' );
					} else {
						throw $e;
					}
				}
			} 
			
			// if files is null we get the files, in case of a shadow they could have been filled in
			// by the content source
			if( is_null($object->Files) ) {
				$attachment = null;
				// BZ#13297 don't get files for native and placement renditions when haveversion is same as object version
				if ($rendition && $rendition != 'none' 
					&& ! ( ($rendition == 'native' || $rendition == 'placement') && $haveVersion === $object->MetaData->WorkflowMetaData->Version ) ) {
					require_once BASEDIR.'/server/bizclasses/BizStorage.php';
					if( $editionId ) { // edition/device specific rendition
						require_once BASEDIR.'/server/dbclasses/DBObjectRenditions.class.php';
						$version = DBObjectRenditions::getEditionRenditionVersion( $id, $editionId, $rendition );
					} else { // object rendition
						$version = $objectProps['Version'];
					}
					if( !is_null($version) ) {
						$attachment = BizStorage::getFile( $objectProps, $rendition, $version, $editionId );
					}
				}
				if( $attachment ) {
					$object->Files = array( $attachment );
				} else {
					$object->Files = array();
				}
			}
		} catch ( BizException $e ) {
			// Remove premature lock and re-throw exception
			if( $lock ) {
				self::unlockObject( $id, $user, false );
			}
			throw($e);
		}
		
		// Do notfications:
		require_once BASEDIR.'/server/dbclasses/DBLog.class.php';
			
		DBlog::logService( $user, "GetObjects", $id, $p, $is, $se, $st, '', $lock, $rendition, $t, $routeto, '', $objectProps['Version'] );
		if ($lock) {
			$userfull = BizUser::resolveFullUserName($user);							
			require_once BASEDIR.'/server/smartevent.php';
			new smartevent_lockobject(BizSession::getTicket(), $id, $userfull);
		}
		
		if (!isset($object->MetaData->WorkflowMetaData->RouteTo)) {
			$object->MetaData->WorkflowMetaData->RouteTo = '';
		}
		
		return $object;
	}
	
	public static function unlockObject( $id, $user, $notify=true )
	{
		require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObjectLock.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObjectFlag.class.php';
		require_once BASEDIR.'/server/dbclasses/DBUser.class.php';
    	require_once BASEDIR.'/server/bizclasses/BizUser.class.php';		

		// Validate input
		if (!$id) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', $id );
		}
		
		// Check if we have alien object. This happens for properties dialog which when executed
		// will create an Enterprise object. So the subsequent lock can be ignored.
		require_once BASEDIR . '/server/bizclasses/BizContentSource.class.php';
		if( BizContentSource::isAlienObject( $id ) ) {
			// Check if we already have a shadow object for this alien. If so, change the id
			// to the shadow id
			$shadowID = BizContentSource::getShadowObjectID($id);
			if( $shadowID ) {
				$id = $shadowID;
			} else {
				return;
			}
		}

		// Get the object to unlock
		$dbDriver = DBDriverFactory::gen();
		$sth = DBObject::getObject( $id );
		if (!$sth) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', $id );
		}
		$curr_row = $dbDriver->fetch($sth);
		if (!$sth) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', $id );
		}
		
		// check if object has been locked in the first place (BZ#11254)
		$lockedByUser = DBObjectLock::checkLock( $id );
		if (! is_null($lockedByUser) ){
			// Check if unlock is allowed by user
			if( DBUser::isAdminUser( $user ) || // System Admin user?
				DBUser::isPubAdmin( $user, $curr_row['publication'] ) ) { // Brand Admin user?
				$effuser = null; // System/Brand Admin users may always unlock objects
			} else { // Normal user
				// Normal users are allowed to unlocked their own locks only (BZ#11160)
				if( strtolower($lockedByUser) != strtolower($user) ) { // Locked by this user?
					$lockedByUser = BizUser::resolveFullUserName($lockedByUser);							
					$msg = BizResources::localize('OBJ_LOCKED_BY') . ' ' . $lockedByUser; // TODO: Add user param in OBJ_LOCKED_BY resource
					throw new BizException( null, 'Client',  $id, $msg );
				}
				// Note: We do NOT check the "Abort Checkout" access feature here!
				// This is because UnlockObjects service is used by SOAP clients to close the document.
				// Without this access feature, users should be able to close their documents.
				// Client applications are responsible to show/hide the "Abort Checkout" action from GUI.
				$effuser = $user; 
			}
	
			// Now do the unlock
			DBObjectFlag::unlockObjectFlags($id);
			$sth = DBObjectLock::unlockObject( $id, $effuser );
			if (!$sth) {
				throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
			}
			
			// Do notifications		
			if( $notify ) {
				require_once BASEDIR.'/server/dbclasses/DBLog.class.php';
				DBlog::logService( $user, "UnlockObjects", $id, $curr_row['publication'], $curr_row['issue'], $curr_row['section'], 
											$curr_row['state'], '', '', '', $curr_row['type'], $curr_row['routeto'], '', $curr_row['version'] );
				$routetofull = BizUser::resolveFullUserName($curr_row['routeto']);							
				require_once BASEDIR.'/server/smartevent.php';
				new smartevent_unlockobject( BizSession::getTicket(), $id, '', false, $routetofull);
			}
		}
	}
	
	public static function setObjectProperties( $id, $user, MetaData $meta, $targets )
	{
		require_once BASEDIR.'/server/bizclasses/BizVersion.class.php';
		require_once BASEDIR.'/server/bizclasses/BizEmail.class.php';
		require_once BASEDIR.'/server/bizclasses/BizWorkflow.class.php';
		require_once BASEDIR.'/server/bizclasses/BizTarget.class.php';
		require_once BASEDIR.'/server/bizclasses/BizUser.class.php';
		require_once BASEDIR.'/server/bizclasses/BizDeadlines.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObjectLock.class.php';
		require_once BASEDIR.'/server/dbclasses/DBWorkflow.class.php';

		// TODO v6.0: Handle targets (expect one, return many)
		// TODO v6.0: Return saved/resolved meta data

		// ====> Validate and prepare
		if (!$id) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', $id );
		}

		// Check if we have an alien as source. If so, we first check if 
		// we have a shadow, if so this will be usedd. If there is no shadow
		// we need to import a he alien which is handled totally different
		require_once BASEDIR .'/server/bizclasses/BizContentSource.class.php';
		if( BizContentSource::isAlienObject($id) ){
			// It's an alien, do we have a shadow? If so, use that instead
			$shadowID = BizContentSource::getShadowObjectID($id);
			if( $shadowID ) {
				$id = $shadowID;
			} else {
				// An alien without shadow, we treat this as a create:
				$destObject = new Object( $meta,			// meta data
									  null, null, null,		// relations, pages, Files array of attachment
									  null, null, $targets	// messages, elements, targets
									 );
				$shadowObject = BizContentSource::createShadowObject( $id, $destObject );
				$shadowObject = self::createObject( $shadowObject, $user, false /*lock*/, empty($shadowObject->MetaData->BasicMetaData->Name) /*$autonaming*/ );
				
				require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectPropertiesResponse.class.php';
				return new WflSetObjectPropertiesResponse( $shadowObject->MetaData, $targets );
			}
		}
		
		// Check if locked or we are the locker
		$lockedby = DBObjectLock::checkLock( $id );
		if( $lockedby && strtolower($lockedby) != strtolower($user) ) {
				throw new BizException( 'ERR_NOTLOCKED', 'Client', $id );
		}
		
		// Get object's current properties
		$dbDriver = DBDriverFactory::gen();
		$sth = DBObject::getObject( $id );
		if (!$sth) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		$currRow = $dbDriver->fetch($sth);
		if (!$currRow) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', $id );
		}
		$curPub		= $currRow['publication'];
		$curSection	= $currRow['section'];
		$curType	= $currRow['type'];
		$curState 	= $currRow['state'];

		// Key props, if not set use the current values, needed for auth check etc.
		if( !isset($meta->BasicMetaData->ID))	$meta->BasicMetaData->ID = $id;
		if( !isset($meta->BasicMetaData->Name))	$meta->BasicMetaData->Name = $currRow['name'];
		if( !isset($meta->BasicMetaData->Type))	$meta->BasicMetaData->Type = $curType;
		if( !isset($meta->BasicMetaData->Publication))	$meta->BasicMetaData->Publication = new Publication( $curPub );
		if( !isset($meta->BasicMetaData->Category))	$meta->BasicMetaData->Category = new Category( $curSection );
		if( !$meta->WorkflowMetaData )	$meta->WorkflowMetaData = new WorkflowMetaData();
		
		// Validate workflow meta data and adapt if needed
		BizWorkflow::validateWorkflowData( $meta, $targets, $user, $curState );
		
		// Validate targets count, layouts can be assigned to one issue only
		BizWorkflow::validateMultipleTargets( $meta, $targets );

		// Validate the properties and adjust (defaults) if needed, returned flattened meta data:
		$newRow = self::validateMetaDataAndTargets( $user, $meta, $targets, true );
		$state = $newRow['state'];
		
		// Handle authorization
		global $globAuth;
		if( !isset($globAuth) ) {
			require_once BASEDIR.'/server/authorizationmodule.php';
			$globAuth = new authorizationmodule( );
		}
		$globAuth->getrights($user, $curPub, 0, $curSection, $curType);
		// If state changes, we need to check if we are allowed to move out of old status
		if ($curState != $state && $state != -1 && $curState != -1)
		{
			$sf = $globAuth->checkright('F', $curPub, 0, $curSection, $curType, $curState);
			$sc = $globAuth->checkright('C', $curPub, 0, $curSection, $curType, $curState);
			if( $state == DBWorkflow::nextState( $curState ) ) {
				// change forward
				if (!$sf && !$sc) {			// change state forward
					throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(F)" );
				}
			} elseif (!$sc) {		// change state
				throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(C)" );
			}
		}
		// Next check if we are allowed to modify the object in its existing place (open for edit access)
		// for personal status we can forget this
		// When there is no E-access it may still be that we have W-access (BZ#5519). In that case don't fail.
		if($curState != -1){
			if (!$globAuth->checkright('E', $curPub, 0, $curSection, $curType, $curState) )	{
				if (!$globAuth->checkright('W', $curPub, 0, $curSection, $curType, $curState) )	{
					throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(E)" );
				}
			}
		}
		
		// Next, check if we have access to destination. For personal status (-1) we can forget this)
		if($state != -1){
			$globAuth->getrights($user, $newRow['publication'], 0, $newRow['section'], $newRow['type']);
			if (!$globAuth->checkright('W', $newRow['publication'], 0, $newRow['section'], $newRow['type'], $newRow['state'])) {		// write
					throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(W)" );
			}
		}
		
		// Check if user is allowed to change the object's location (ChangePIS)
		if($meta->BasicMetaData->Publication->Id != $curPub ||
		   $meta->BasicMetaData->Category->Id != $curSection ){
			$globAuth->getrights($user, $curPub, 0, $curSection, $curType);
			if (!$globAuth->checkright('P', $curPub, 0, $curSection, $curType, $curState)) {
					throw new BizException( 'ERR_AUTHORIZATION', 'Client', "$id(P)" );
			}
		}

		// ====> Execute the SetProps action:
		
		// Depending on configuration, setting props could lead to new version:
		BizVersion::createVersionIfNeeded( $id, $currRow, $newRow, $meta->WorkflowMetaData, false, null, true );

		// Keep MtP in the loop:
		if( MTP_SERVER_DEF_ID != '' ) {
			require_once BASEDIR.'/server/MadeToPrintDispatcher.class.php';
			MadeToPrintDispatcher::clearSentObject( $id, $newRow['publication'], $newRow['state'], $curState );
		}
		
		// Save properties to DB:
		$sth = DBObject::updateObject( $id, null, $newRow, '' );
		if (!$sth) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		
		// Delete object's 'link' files (htm) in <FileStore>/_BRANDS_ folder
		if (ATTACHSTORAGE == 'FILE') {
			$oldtargets = BizTarget::getTargets($user, $id);
			require_once BASEDIR.'/server/dbclasses/DBPublication.class.php';
			$pubName = DBPublication::getPublicationName($curPub);
			require_once BASEDIR . '/server/bizclasses/BizLinkFiles.class.php';
			BizLinkFiles::deleteLinkFiles( $curPub, $pubName, $id, $currRow['name'], $oldtargets );
		}
		
		// Validate targets and store them at DB for this object
		// BZ#9827 But only if $targets !== null (!!!)
		if ($targets !== null) {
			BizTarget::saveTargets( $user, $id, $targets, $meta );
			
			// Validate meta data and targets (including validation done by Server Plug-ins)
			BizObject::validateMetaDataAndTargets( $user, $meta, $targets );
		}
		
		$targets = BizTarget::getTargets($user, $id);
		// Targets in the database are updated (if needed). Get the targets from
		// the database and pass it to subsequent methods to determine deadlines
		// and editions.

		$issueid = BizTarget::getDefaultIssueId($currRow['publication'],$targets);
		$newRow['issue'] = $issueid;

		// ==== Handle deadline
		// First look for object-target issues
		$issueIdsDL = BizTarget::getIssueIds($targets); // Object-target issues
		// Image/Article without object-target issue can inherit issues from relational-targets. BZ#21218
		if (!$issueIdsDL && ($curType == 'Article' || $curType == 'Image')) {
			$issueIdsDL = BizTarget::getRelationalTargetIssuesForChildObject( $id );
		}
		
		// If state or category are changed the deadline is recalculated.
		$recalcdeadline = ($curState != $state || $curSection != $meta->BasicMetaData->Category->Id );
		$deadlinehard = '';

		// If deadline is set and object has issues check if the set deadline is not beyond earliest possible deadline.
		if( !$recalcdeadline && $issueIdsDL && isset($newRow['deadline']) && $newRow['deadline'] ) {
			$deadlinehard = $newRow['deadline'];
			BizDeadlines::checkDeadline($issueIdsDL, $newRow['section'], $newRow['deadline']);
		}

		// In case state/category are changed a deadline set by hand is ignored
		// (always recalculate).
		// This behavior is different from the saveObject() where a deadline set
		// by hand always has primacy on status/category changes.
		if ( $recalcdeadline || empty( $deadlinehard ) ) {
			$deadlinehard = DBObject::objectSetDeadline( $id, $issueIdsDL, $newRow['section'], $newRow['state'] );
			if ( BizDeadlines::canPassDeadlineToChild( $curType ) ) {
				// Recalculate the deadlines of children without own object-target issue.
				// This recalculation is limited to an issue change of the parent.
				// New issue of the parent results in new relational-target issue and so
				// a new deadline. If the category of the parent changes this has no effect
				// as the children do not inherit this change.
				BizDeadlines::recalcDeadlinesIssuelessChilds($id);
			}
		} else {
			DBObject::setObjectDeadline( $id, $deadlinehard );
			if ( BizDeadlines::canPassDeadlineToChild( $curType ) ) {
				// Set the deadlines of children without own object-target issue.
				BizDeadlines::setDeadlinesIssuelessChilds($id, $deadlinehard);
			}
		}

		require_once BASEDIR.'/server/utils/DateTimeFunctions.class.php';
		$deadlinesoft = DateTimeFunctions::calcTime( $deadlinehard, -DEADLINE_WARNTIME );
		require_once BASEDIR.'/server/smartevent.php';
		new smartevent_deadlinechanged(null, $id, $deadlinehard, $deadlinesoft);

		//self::saveMetaDataExtended( $id, $newRow, ($curState != $state || $curSection != $meta->BasicMetaData->Category->Id ), $issueIdsDL );
			
		// BZ#10308 Copy task objects to dossier
		if ($meta->BasicMetaData->Type == 'Task'){
			require_once BASEDIR.'/server/bizclasses/BizRelation.class.php';
			BizRelation::copyTaskRelationsToDossiers($id, $meta->WorkflowMetaData->State->Id, $user);
		}
		
		#// ====> Do notifications:		
		$issueIds2 = $issueNames = $editionIds = $editionNames = '';
		self::listIssuesEditions( $targets, $issueIds2, $issueNames, $editionIds, $editionNames );
		
		// Use old values for those that are not set
		foreach (array_keys($currRow) as $k) {
			if (!isset($newRow[$k])) $newRow[$k] = $currRow[$k];
		}
		require_once BASEDIR.'/server/dbclasses/DBLog.class.php';
		DBlog::logService( $user, 'SetObjectProperties', $id, $newRow['publication'], null, $newRow['section'], 
									$newRow['state'], '', '', '', $newRow['type'], $newRow['routeto'], '', $newRow['version'] );
		
		require_once BASEDIR.'/server/smartevent.php';
		
		// Retrieve fresh object from DB to make sure we return correct data (instead of mutated client data!)
		// Relations are needed because otherwise relational targets get lost during re-indexing (BZ#18050)
		$modifiedobj = self::getObject( $id, $user, false, null, array('Targets','Relations') ); // no lock, no rendition

		// Add to search index:
		require_once BASEDIR . '/server/bizclasses/BizSearch.class.php';
		BizSearch::indexObjects( array( $modifiedobj) );		
		
		new smartevent_setobjectpropertiesEx( BizSession::getTicket(), BizUser::resolveFullUserName($user), $modifiedobj, BizUser::resolveFullUserName($currRow['routeto']));

		BizEmail::sendNotification('set objectprops', $modifiedobj, $newRow['types'], $currRow['routeto']);

		if( MTP_SERVER_DEF_ID != '' ) {
			require_once BASEDIR.'/server/MadeToPrintDispatcher.class.php';
			MadeToPrintDispatcher::doPrint( $id, BizSession::getTicket() );
		}
		
		// Create object's 'link' files (htm) in <FileStore>/_BRANDS_ folder
		if (ATTACHSTORAGE == 'FILE') {
			BizLinkFiles::createLinkFilesObj( $modifiedobj, $newRow['storename'] );
		}
		
		// return info
		require_once BASEDIR.'/server/interfaces/services/wfl/WflSetObjectPropertiesResponse.class.php';
	    return new WflSetObjectPropertiesResponse( $modifiedobj->MetaData, $modifiedobj->Targets );
	}

	public static function copyObject( $srcid, $meta, $user, $targets, $pages )
	{
		require_once BASEDIR.'/server/bizclasses/BizWorkflow.class.php';
		require_once BASEDIR.'/server/bizclasses/BizAccess.class.php';
		require_once BASEDIR.'/server/bizclasses/BizStorage.php';
		require_once BASEDIR.'/server/bizclasses/BizTarget.class.php';
		require_once BASEDIR.'/server/bizclasses/BizUser.class.php';
		require_once BASEDIR.'/server/bizclasses/BizPage.class.php';
		require_once BASEDIR."/server/utils/NumberUtils.class.php";
		require_once BASEDIR.'/server/dbclasses/DBObjectLock.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObject.class.php';

		// TODO v6.0: Handle targets (expect one, return many)
		// BZ#9827 $targets can now be null, if this is the case:
		// for now: DO NOT CHANGE EXISTING BEHAVIOR, but for future implementation, if:
		// $targets = array -> save these targets accordingly, even if empty
		// $targets = null -> save targets from the object to be copied... !?!? unspecified behavior
		if ($targets == null) {
			$targets = array();
		}
		
		// ===> 1. Check source access:

		// check for empty source id
		if (!$srcid) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', "$srcid" );
		}
		
		// meta shouldn't have id yet (but CS sends the source id BZ#12304)
		$meta->BasicMetaData->ID = '';

		// Check if we have an alien as source. if so we need to import a copy 
		// of the alien which is handled totally different
		// Note that we don't look for a possible shadow here, we always want
		// to get a copy of the original.
		require_once BASEDIR .'/server/bizclasses/BizContentSource.class.php';
		if( BizContentSource::isAlienObject($srcid) ){
			// It's an alien, do we have a shadow? If so, use that instead
			$shadowID = BizContentSource::getShadowObjectID($srcid);
			if( $shadowID ) {
				$srcid = $shadowID;
			} else {
				// An alien without shadow, we treat this as a create:
				$destObject = new Object( $meta,				// meta data
									  null, $pages,			// relations, pages
									  null, 				// Files array of attachment
									  null, null, $targets	// messages, elements, targets
									 );
				$shadowObject = BizContentSource::createShadowObject( $srcid, $destObject );
				$shadowObject = self::createObject( $shadowObject, $user, false /*lock*/, empty($shadowObject->MetaData->BasicMetaData->Name) /*$autonaming*/ );
				
				require_once BASEDIR.'/server/interfaces/services/wfl/WflCopyObjectResponse.class.php';
				return new WflCopyObjectResponse( $shadowObject->MetaData, $targets );
			}
		}
		
		require_once BASEDIR . '/server/bizclasses/BizQuery.class.php';
		//TODO shouldn't we do self::getObject with rendition='none'?
		$objProps = BizQuery::queryObjectRow($srcid );
		if( !$objProps ) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', "$srcid" );
		}

		// check authorizations on source object for read
		BizAccess::checkRights( $objProps, 'R' );

		// ===> 2. Validate destination data
		
		// If type not filled in, put source type info dest, needed for validation
		if( !$meta->BasicMetaData->Type ) {
			$meta->BasicMetaData->Type = $objProps['Type'];
		}
		
		// If shadow object call content source to copy object BZ#11509
		$shadowObject = null;
		if ( trim($objProps['ContentSource']) ) {
			// create source object, probably self::getObject is better (see comment above) but we don't
			// want to read the object twice
			$srcMeta = self::queryRow2MetaData( $objProps );
			$srcObject = new Object( $srcMeta );
			
			$destObject = new Object( $meta, null, $pages, null, null, null, $targets );
			$shadowObject = BizContentSource::copyShadowObject( trim($objProps['ContentSource']), trim($objProps['DocumentID']), $srcObject, $destObject );
			// copy metadata, targets and pages from content source, it may have been changed
			$meta = $shadowObject->MetaData;
			$targets = $shadowObject->Targets;
			$pages = $shadowObject->Pages;
		}
		
		// Validate targets count, layouts can be assigned to one issue only
		BizWorkflow::validateMultipleTargets( $meta, $targets );
		
		// Validate (and correct,fill in) workflow properties of destination
		BizWorkflow::validateWorkflowData( $meta, $targets, $user );

		// Validate meta data
		$arr = self::validateMetaDataAndTargets( $user, $meta, $targets );
		
		// ===> 3. Create new object in DB:

		// Set RouteTo field as destination data RouteTo
		if( isset($objProps['RouteTo']) && $meta->WorkflowMetaData ) {
			$objProps['RouteTo'] = $meta->WorkflowMetaData->RouteTo;
		}

		// Convert objProp to db row:
		$objRow = BizProperty::objPropToRowValues( $objProps );
		
		//BZ#10709 Also convert custom properties read from db...
		foreach ($objProps as $propName => $propValue) {
			if (DBProperty::isCustomPropertyName($propName)) {
				$lowercasekey = strtolower($propName);
				if (!isset($objRow[$lowercasekey])) {
					$objRow[$lowercasekey] = $propValue;
				}
			}
		}
		
		// Create array of meta data for destination object:
		if ($arr) foreach (array_keys($arr) as $key)
			$objRow[$key] = $arr[$key];
			
		// remove system values:
		unset( $objRow['id'] );
		unset( $objRow['created'] );
		unset( $objRow['creator'] );
		unset( $objRow['modified'] );
		unset( $objRow['modifier'] );
		unset( $objRow['lockedby'] );
		unset( $objRow['storename'] );
		unset( $objRow['indexed'] );
		unset( $objRow['version'] );
		unset( $objRow['majorversion'] );
		unset( $objRow['minorversion'] ) ;

		
		$objformat = $objRow['format'];
		
		// Check authorizations on destination
		$rights = 'W'; // check 'Write' access (W)
		if( $arr['type'] == 'Dossier' || $arr['type'] == 'DossierTemplate' ) {
			$rights .= 'd'; // check the 'Create Dossier' access (d) (BZ#17051)
		} else if( $arr['type'] == 'Task' ) {
			$rights .= 't'; // check 'Create Task' access (t) (BZ#17119)
		}
		BizAccess::checkRightsMetaDataTargets( $meta, $targets, $rights ); // BZ#17119
		
		// If possible (depends on DB) we get id for new object beforehand:
		$dbDriver = DBDriverFactory::gen();
		$id = $dbDriver->newid(DBPREFIX."objects",false);
		if ($id) {
			$storename = StorageFactory::storename($id, $objProps);
		} else {
			$storename = '';
		}

		$now = date('Y-m-d\TH:i:s');

		// determine new version nr for the new object
		require_once BASEDIR.'/server/bizclasses/BizVersion.class.php';
		require_once BASEDIR.'/server/bizclasses/BizAdmStatus.class.php';
		$status = BizAdmStatus::getStatusWithId( $objRow['state'] );
		$newVerNr = BizVersion::determineNextVersionNr( $status, $objRow );
		$arr['version'] = $newVerNr;
			
		// Create object record in DB:
		$sth = DBObject::createObject( $storename, $id, $user, $now, $objRow, $now );
		if (!$sth) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		// v4.2.4 patch for #5700, Save current time be able to send this later as modified time 
		$now = date('Y-m-d\TH:i:s');

		// If we did not get an id from DB beforehand, we get it now and update storename
		// that is derived from object id
		if (!$id) {
			$id = $dbDriver->newid(DBPREFIX."objects",true);
			// now we know id: generate storage name and store it
			$storename = StorageFactory::storename($id, $objRow);
			$sth = DBObject::updateObject( $id, null, array(), '', $storename );
		}
		if (!$id) {
			throw new BizException( 'ERR_DATABASE', 'Server', 'No ID' );
		}
		$meta->BasicMetaData->ID = $id;
		
		$issueid = BizTarget::getDefaultIssueId($arr['publication'],$targets);
		$arr['issue'] = $issueid;

		$issueids = BizTarget::getIssueIds($targets);
		
		// Save extended meta data (not in smart_objects table):
		BizTarget::saveTargets( $user, $id, $targets, $meta );

		// ==== Handle deadline
		$issueIdsDL = $issueids;

		require_once BASEDIR.'/server/bizclasses/BizDeadlines.class.php';
		if ( !$issueIdsDL && ( BizDeadlines::canInheritParentDeadline( $arr['type'])) ) { // BZ#21218
			$issueIdsDL = BizTarget::getRelationalTargetIssuesForChildObject( $id );
		}

		DBObject::objectSetDeadline( $id, $issueIdsDL, $arr['section'], $arr['state'] );

		// Validate meta data and targets (including validation done by Server Plug-ins)
		BizObject::validateMetaDataAndTargets( $user, $meta, $targets );

		// ===> 4. Copy files
		
		$replaceguids = array();
		$verNr = $objProps['Version'];
		
		// if content source has provided files in shadow object, save them else let Enterprise handle it
		if ( ! is_null($shadowObject) && ! is_null($shadowObject->Files) ){
			self::saveFiles( $storename, $id, $shadowObject->Files, $newVerNr );
		} else {
			$types = unserialize($objProps['Types']);
				
			$formats = array( 
				'application/incopy' => true,
				'application/incopyinx' => true,
				'application/incopyicml' => true,
				'application/incopyicmt' => true,
				'text/wwea' => true );
			foreach (array_keys($types) as $tp) {
				$attachobj = StorageFactory::gen( $objProps['StoreName'], $srcid, $tp, $types[$tp], $verNr );
				
				if( $tp == 'native' && $types[$tp] == $objformat && isset($formats[$objformat]) ) {
					$succes = $attachobj->copyFile($newVerNr, $id, $storename, null, null, $replaceguids, $types[$tp]);
				}
				else {
					$dummy = null;
					$succes = $attachobj->copyFile($newVerNr, $id, $storename, null, null, $dummy, $types[$tp]);
				}			
				if (!$succes) {
					throw new BizException( 'ERR_ATTACHMENT', 'Server', $attachobj->getError() );
				}
			}
		}

		require_once BASEDIR.'/server/dbclasses/DBPage.class.php';
		if( is_null( $pages ) ) { // no pages given; just copy the pages
			
			$sth = DBPage::getPages( $srcid, 'Production' );
			
			$orgEditions = array();
			$lowEdition = 1e10;				// first edition (not being the empty one)
			
			// get all current pages in prows
			$prows = array();
			while (($prow = $dbDriver->fetch($sth)) ) {
				$thisedition = $prow['edition'];
				$prow['oldedition'] = $thisedition;
				$orgEditions[$thisedition][] = $prow;
				if ($thisedition && $thisedition < $lowEdition) {
					$lowEdition = $thisedition;
				}
				$prows[] = $prow;
			}
		
			// make list of prowsNew in case of editions
			$hasEditions = false;
			$prowsNew = array();
			
			if( !empty($targets) ) foreach( $targets as $target ) {
				if( !empty($target->Editions) ) foreach( $target->Editions as $edition ) {
					$hasEditions = true;
					$thisedition = $edition->Id;
					if (isset($orgEditions[$thisedition]) && is_array($orgEditions[$thisedition])) {
						// handle same edition
						foreach ($orgEditions[$thisedition] as $prow) {
							$prowsNew[] = $prow;
						}
						// add generic pages (if thisedition is not generic)
						if ($thisedition && isset($orgEditions[0]) && is_array($orgEditions[0])) foreach ($orgEditions[0] as $prow) {
							$prow['edition'] = $thisedition;
							$prowsNew[] = $prow;
						}
					}
					elseif (isset($orgEditions[$lowEdition]) && is_array($orgEditions[$lowEdition])) {
						// handle lowest edition
						foreach ($orgEditions[$lowEdition] as $prow) {
							$prow['edition'] = $thisedition;
							$prowsNew[] = $prow;
						}
						// add generic pages
						if (isset($orgEditions[0]) && is_array($orgEditions[0])) {
							foreach ($orgEditions[0] as $prow) {
								$prow['edition'] = $thisedition;
								$prowsNew[] = $prow;
							}
						}
					}
				}
				//BZ#6294: pages with edition 0 were not copied... now they are.
			//	foreach ($prows as $pagerow) {
			//		if (empty($pagerow['edition'])) {
			//			$prowsNew[] = $pagerow;
			//		}
			//	}
			}
			if (!$hasEditions) {
				// add lowest edition for all
				if( isset($orgEditions[$lowEdition]) && is_array($orgEditions[$lowEdition])) foreach ($orgEditions[$lowEdition] as $prow) {
						$prow['edition'] = 0;
						$prowsNew[] = $prow;
				}
				// add generic pages
				if( isset($orgEditions[0]) && is_array($orgEditions[0])) foreach ($orgEditions[0] as $prow) {
						$prow['edition'] = 0;
						$prowsNew[] = $prow;
					}
			}

			foreach ($prowsNew as $prow) {
				$sthins = DBPage::insertPage($id, $prow['width'], $prow['height'], $prow['pagenumber'], $prow['pageorder'], $prow['pagesequence'], 
										$prow['edition'], $prow['master'], $prow['instance'], $prow['nr'], $prow['types']);
				if (!$sthins) {
					throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
				}
				// copy all pages
				for ($nr=1; $nr <= $prow['nr']; $nr++)
				{
					// copy all attach-types
					foreach (unserialize($prow['types']) as $tp) {
						$pagenrval = preg_replace('/[*"<>?\\\\|:]/i', '', $prow['pagenumber']);
						$pageobj = StorageFactory::gen( $objProps['StoreName'], $srcid, 'page', $tp[2], $verNr, $pagenrval."-$nr", $prow['oldedition']);
						$dummy = null;
						if( !$pageobj->copyFile( $newVerNr, $id, $storename, $pagenrval."-$nr", $prow['edition'], $dummy ) ) {
							throw new BizException( 'ERR_ATTACHMENT', 'Server', $pageobj->getError() );
						}
					}
				}
			}			
		}
		else { // Pages given, typically done to create layout based on layout templates (planning interface)
			// Update pagerange, copy layout from template uses this to influence pages in new object:
			$pagenumberarray = array();
			if( isset($pages) ) foreach( $pages as $new_pag ) {
				$pagenumberarray[] = $new_pag->PageOrder;
			}
			$range = BizPage::calcPageRange($pages);
			DBObject::updatePageRange( $id, $range, 'Planning' ); //Pages created here are supposed to be planned
	
			// get all source pages
			$sth = DBPage::getPages( $srcid, 'Production' );
			$prows = array();
			while (($prow = $dbDriver->fetch($sth)) ) {
				$prows[] = $prow;
			}

			// transform single page object into array of one page object to smooth code after this point
			if( gettype( $pages ) == 'object' && isset( $pages->Page ) && gettype( $pages->Page ) == 'object' ) {
				$pages = array( $pages->Page );
			}
			// copy page renditions and find lowest pagenumber
			for( $i = 0; $i < count($pages); $i++ ) {
				$r = min( $i, count($prows)-1 );
				$prow = $prows[$r];
	
				$iPageOrder = $pages[$i]->PageOrder;
				if( isset( $pages[$i]->PageNumber ) && $pages[$i]->PageNumber ) { // not defined for plannings interface!
					$sPageNumber = $pages[$i]->PageNumber;
				} else {
					$sPageNumber = $iPageOrder;
				}
				$editionId = isset($pages[$i]->Edition->Id) ? $pages[$i]->Edition->Id : 0;
				$sthins = DBPage::insertPage($id, $prow['width'], $prow['height'], $sPageNumber, $iPageOrder, $pages[$i]->PageSequence,
										$editionId, $prow['master'], $prow['instance'], $prow['nr'], $prow['types'] );
				if (!$sthins) {
					throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
				}
				// copy all pages
				for ($nr=1; $nr <= $prow['nr']; $nr++)
				{
					// copy all attach-types
					foreach (unserialize($prow['types']) as $tp) {
						$pagenrval = preg_replace('/[*"<>?\\\\|:]/i', '', $prow['pagenumber']);
						$pageobj = StorageFactory::gen( $objProps['StoreName'], $srcid, 'page', $tp[2], $verNr, $pagenrval."-$nr", $prow['edition']);
						$dummy = null;
						if( !$pageobj->copyFile( $newVerNr, $id, $storename, "$iPageOrder-$nr", null, $dummy ) ) {
							throw new BizException( 'ERR_ATTACHMENT', 'Server', $pageobj->getError() );
						}
					}
				}
			}
		}
		
		// ===> 5. Copy also all relations to image-objects

		// >>> BZ#20917 Determine which object targets are removed and which are added
		$objTargetsRemoved = array();
		$orgObjTargets = BizTarget::getTargets( $user, $srcid );
		foreach( $orgObjTargets as $orgObjTarget ) {
			$targetFound = false;
			foreach( $targets as $newObjTarget ) {
				if( $orgObjTarget->Issue->Id == $newObjTarget->Issue->Id ) {
					$targetFound = true;
					break;
				}
			}
			if( !$targetFound ) {
				$objTargetsRemoved[$orgObjTarget->Issue->Id] = $orgObjTarget;
			}
		}
		$objTargetsAdded = array();
		foreach( $targets as $newObjTarget ) {
			$targetFound = false;
			foreach( $orgObjTargets as $orgObjTarget ) {
				if( $orgObjTarget->Issue->Id == $newObjTarget->Issue->Id ) {
					$targetFound = true;
					break;
				}
			}
			if( !$targetFound ) {
				$objTargetsAdded[$newObjTarget->Issue->Id] = $newObjTarget;
			}
		} // <<<
		
		require_once BASEDIR.'/server/dbclasses/DBObjectRelation.class.php';
		require_once BASEDIR.'/server/dbclasses/DBPlacements.class.php';
		$relations = DBObjectRelation::getObjectRelations( $srcid, 'childs' );
		foreach($relations as $relation) {
			// get child object
			$sth = DBObject::getObject( $relation['child'] );
			if (!$sth) {
				throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
			}
			$childrow = $dbDriver->fetch($sth);

			// Determine if we need to include article relations/placements to the copy procedure (BZ#9937)
			$isArticleAndAllowed = ($childrow['type'] == 'Article') && ($relation['type'] == 'Placed');
			if( $isArticleAndAllowed ) { // is placed article, let's see if we are allowed
				$isArticleAndAllowed = ($arr['state'] == -1); // personal status; all allowed
				if( !$isArticleAndAllowed ) { // not personal, let's check AllowMultipleArticlePlacements access rights against status
					global $globAuth;
					if( !isset($globAuth) ) {
						require_once BASEDIR.'/server/authorizationmodule.php';
						$globAuth = new authorizationmodule( );
					}
					$globAuth->getrights( $user, $arr['publication'], array_key_exists('issue', $arr) ? $arr['issue'] : null, $arr['section'], $arr['type'], $arr['state'] );
					$isArticleAndAllowed = $globAuth->checkright('M', $arr['publication'], array_key_exists('issue', $arr) ? $arr['issue'] : null, $arr['section'], $arr['type'], $arr['state'] );
				}
			}

			// check childobject as being image or article
			if ($childrow['type'] == 'Image' || $childrow['type'] == 'LayoutModule' || $isArticleAndAllowed || $relation['type'] == 'Contained') {
				// copy relation (childType is parent-id for images)
				require_once BASEDIR.'/server/dbclasses/DBObjectRelation.class.php';
				$objRelId = DBObjectRelation::createObjectRelation( $id, $relation['child'], $relation['type'], $id, $relation['pagerange'], $relation['rating'] );
				if( is_null($objRelId) ) {
					throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
				}
				// copy placements
				if( !DBPlacements::copyPlacements( $srcid, $relation['child'], $id ) ) {
					throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
				}
			}

			// Copy relational targets of DossierTemplate/Dossier (source) to Dossier (copy) BZ#16453/BZ#20917
			if( $relation['type'] == 'Contained' ) {
				require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
				$parObjType = DBObject::getObjectType( $relation['parent'] );
				if( $parObjType == 'DossierTemplate' || $parObjType == 'Dossier' ) {
					// >>> BZ#20917 When obj target is removed, repair with rel target with one that is added by user
					require_once BASEDIR.'/server/dbclasses/DBTarget.class.php';
					$orgRelTargets = DBTarget::getTargetsbyObjectrelationId( $relation['id'] );
					$newRelTargets = array();
					if( $orgRelTargets ) foreach( $orgRelTargets as $orgRelTarget ) {
						if( isset( $objTargetsRemoved[$orgRelTarget->Issue->Id] ) ) {
							if( count( $objTargetsAdded ) > 0 ) {
								$newRelTargets[] = reset($objTargetsAdded); // just take first (to keep it simple)
							} // else: forget the rel target, since we have nothing to repair it
						} else {
							$newRelTargets[] = $orgRelTarget;
						}
					} // <<<
					if( $newRelTargets ) {
						BizTarget::createObjectrelationTargets( $user, $objRelId, $newRelTargets );
					}
				}
			}			
		}
		
		// ===> 6. Copy elements
		require_once BASEDIR.'/server/dbclasses/DBElement.class.php';
		$elements = DBElement::getElements($srcid); // get elements from source object
		if ( is_array( $elements ) ) { // typically for articles in IC format or HTML format
			// make sure GUIDs are unique
			if (count($replaceguids) > 0) {
				foreach ($elements as &$element) {
					$oldguid = $element->ID;
					if (isset($replaceguids[$oldguid])) {
						$element->ID = $replaceguids[$oldguid];
					}
				}
			}
			// complete the copy (by saving alle elements for the destination object)
			DBElement::saveElements($id, $elements );
		}

		// ===> 7. Do notifications
		$issueIds = $issueNames = $editionIds = $editionNames = '';
		self::listIssuesEditions( $targets, $issueIds, $issueNames, $editionIds, $editionNames );
		require_once BASEDIR.'/server/smartevent.php';
		$userfull     = BizUser::resolveFullUserName( $user );

		// Retrieve fresh object from DB to make sure we return correct data (instead of mutated client data!)
		// Relations are needed because otherwise relational targets get lost during re-indexing (BZ#18050)
		$newObject = self::getObject( $id, $user, false, null, array('Targets', 'Relations' ) ); // no lock, no rendition
		
		// Add to search index:
		require_once BASEDIR . '/server/bizclasses/BizSearch.class.php';
		BizSearch::indexObjects( array( $newObject ) );


		new smartevent_createobjectEx( BizSession::getTicket(), $userfull, $newObject );

		require_once BASEDIR.'/server/bizclasses/BizEmail.class.php';
		BizEmail::sendNotification( 'copy object', $newObject, $objProps['Types'], null);

		if( MTP_SERVER_DEF_ID != '' ) {
			require_once BASEDIR.'/server/MadeToPrintDispatcher.class.php';
			MadeToPrintDispatcher::doPrint( $id, BizSession::getTicket() );
		}

		// Create object's 'link' files (htm) in <FileStore>/_BRANDS_ folder
		if (ATTACHSTORAGE == 'FILE') {
			require_once BASEDIR . '/server/bizclasses/BizLinkFiles.class.php';
			BizLinkFiles::createLinkFilesObj( $newObject, $storename );
		}
		
		require_once BASEDIR.'/server/interfaces/services/wfl/WflCopyObjectResponse.class.php';
	    return new WflCopyObjectResponse( $newObject->MetaData, $newObject->Targets );
	}

	/**
	 * Validates changed object meta data and its new targets.
	 * It runs the connectors to allow them doing validation as well.
	 * It resolves the RouteTo and it can apply a new name (autonaming).
	 * Validation means that the data could change so $meta and $targets are passed by reference!
	 * It returns all meta data in flattened structure.
	 *
	 * @param string $user Short name (=id) of user.
	 * @param MetaData $meta The MetaData struture of an object
	 * @param array $targets List of Target to be applied to object (list that has been sent by client app, which does not have to be complete!)
	 * @param boolean $checkID Wether or not to check the object id for emptyness
	 * @param boolean $autonaming Wether or not to make up an unique name for the object
	 * @throws BizException when validation failed
	 * @returns array Key-value pairs of all validated properties given through $meta (MetaData)
	 */
	public static function validateMetaDataAndTargets( $user, MetaData &$meta, &$targets, $checkID = false, $autonaming = false)
	{
		// 1. Check ID if we need to:
		$id = $meta->BasicMetaData->ID;
		if( $checkID && !$id) {
			throw new BizException( 'ERR_NOTFOUND', 'Client', 'ID empty' );
		}
		
		// 2a. Apply custom validation for name conventions and meta data filtering
		require_once BASEDIR.'/server/bizclasses/BizServerPlugin.class.php';
		$connRetVals = array(); // not used
		BizServerPlugin::runDefaultConnectors( 'NameValidation', null, 'validateMetaDataAndTargets', array($user, &$meta, &$targets), $connRetVals );

		$basicMeta 	= &$meta->BasicMetaData;
		$workflowMeta = &$meta->WorkflowMetaData;
		$pub 		= $basicMeta->Publication->Id;
		$type 		= $basicMeta->Type;
		$name		= $basicMeta->Name;
		if( isset( $workflowMeta->RouteTo ) ) {
			$routeto 	= $workflowMeta->RouteTo;
		} else {
			$routeto 	= null;
		}
		
		// if routeto-field is not empty, be sure to allways set it to the 'short' username, see BZ#4866.
		if (!empty($routeto)) {
			require_once BASEDIR.'/server/dbclasses/DBUser.class.php';
			$routetouserrow = DBUser::findUser(0, $routeto, $routeto);
			if ($routetouserrow) {
				$routetousername = $routetouserrow['user'];
				$meta->WorkflowMetaData->RouteTo = $routetouserrow['fullname'];
			}
			else {
				$routetogrouprow = BizUser::findUserGroup($routeto);
				if ($routetogrouprow) {
					$meta->WorkflowMetaData->RouteTo = $routeto;
				}
			}
		}

		// 2b. Validate the name
		$name = trim($name);
		if( empty($name) ) {
			throw new BizException( 'ERR_NOT_EMPTY', 'Client', $id );
		}
		if (!self::validName($name)) {
			throw new BizException( 'ERR_NAME_INVALID', 'Client', $name );
		}
		
		// 2c. Check if name (for given type) is unique for this publication and issue(s)
		if( $autonaming !== true ) { // ignore this rule when we'll make up the name ourself
			static $uniqueIssueTypes = array('Advert' => true, 'AdvertTemplate' => true, 'Layout' => true, 'LayoutTemplate' => true);
			if( !$id || isset($uniqueIssueTypes[$type])) { // only for new objects; create and copy or for special "print" types (BZ#9554)
				$issueIds = array();
				foreach( $targets as $target ) { // preparation: collect the issue ids
					if( isset($target->Issue->Id) && $target->Issue->Id ) {
						$issueIds[] = $target->Issue->Id;
					}
				}
				if( count($issueIds) > 0 ) { // no targets, no validation
					require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
					if( DBObject::objectNameExists( $pub, $issueIds, $name, $type, $id ) ) {
						throw new BizException( 'ERR_NAME_EXISTS', 'Client', $name );
					}
				}
			} 
		}
		
		// 3. Validate meta data
		
		// 3a. Handle high-res path; Make it relative to high-res store.
		// This is done by removing HighResStore[Mac/Win] base path setting (for adverts)
		// or by removing HighResImageStore[Mac/Win] base path settings (for images)
		// from the HighResFile object property before storing it into db.
		$highresfile = isset($meta->ContentMetaData->HighResFile) ? trim($meta->ContentMetaData->HighResFile) : '';
		if( $highresfile != '' ) {
			require_once BASEDIR.'/server/bizclasses/HighResHandler.class.php';
			$highresfile = HighResHandler::stripHighResBasePath( $highresfile, $type );
			$meta->ContentMetaData->HighResFile = $highresfile;
		}

		// 4. Flatten meta data for return:
		$arr = self::getFlatMetaData( $meta );
		
		return $arr;
	}
	
	// =============== PRIVATE FUNCTIONS:
	 
	private static function Lock( $id, $user )
	{
		require_once BASEDIR.'/server/dbclasses/DBObjectFlag.class.php';
		require_once BASEDIR.'/server/dbclasses/DBObjectLock.class.php';
		DBObjectFlag::lockObjectFlags( $id );
		DBObjectLock::lockObject( $id, $user );
	}
	
	private static function validateForSave( $user, Object &$object, $autonaming = false)
	{
		// 1. Read metadata from file
		require_once BASEDIR.'/server/bizclasses/BizMetaDataPreview.class.php';
		$bizMetaPreview = new BizMetaDataPreview();
		$bizMetaPreview->parseAndGenerate( $object );		

		// Get meta data, for perfromance reasons we use $meta and $basicMeta
		$meta = &$object->MetaData;
				
		// 2. validate meta data
		$arr = self::validateMetaDataAndTargets( $user, $meta, $object->Targets, false, $autonaming );
				
		// 3. Validate files: like native not empty for layout/article
		self::validateFiles( $object );
			
		// 4. Serialize the types of renditions that this object has.
		// This is added to flattened meta data only, it's not a property of class Object, but goes with object's db recored
		$arr['types'] = self::serializeFileTypes( $object->Files );

		return $arr;
	}

	private static function validateFiles( Object &$object )
	{
		$md = $object->MetaData;
		$type = $md->BasicMetaData->Type;
		$name = $md->BasicMetaData->Name;
		$nativefile = self::getRendition( $object->Files, 'native');

		if( $type == 'Article' || $type == 'Layout' || $type == 'ArticleTemplate' || $type == 'LayoutTemplate' || 
			$type == 'AdvertTemplate' || $type == 'LayoutModule' || $type == 'LayoutModuleTemplate' ) {
			if( empty($nativefile->Content) && 
				$nativefile->Type != 'text/plain' ) { // BZ#19901 plain text can be zero bytes
				LogHandler::Log('bizobjects', 'ERROR', 'File has no content for object "'.$name.'"' );
				throw new BizException('ERR_UPLOAD_FILE_ATT', 'Client', $name );
			}
		} elseif( $type == 'Audio' || $type == 'Video' ) {
			$outputfile  = self::getRendition( $object->Files, 'output');
			$trailer     = self::getRendition( $object->Files, 'trailer');
			$highresfile = isset($object->MetaData->ContentMetaData->HighResFile) ? trim($object->MetaData->ContentMetaData->HighResFile) : '';
			if( empty($nativefile->Content) && empty($outputfile->Content) && empty($highresfile) && empty($trailer->Content) ) {
				LogHandler::Log('bizobjects', 'ERROR', 'No file given for audio or video object "'.$name.'"' );
				throw new BizException('ERR_UPLOAD_FILE_ATT', 'Client', $name );
			}
		} // dossier(template), tasks and hyperlinks don't have content. Images and adverts can be planned, so they could have NO content
	}
	
	private static function validName( $name )
	{
		// Max length is 63 to prevent file name issues with 4-byte Unicode strings
		$nameLen = mb_strlen($name, "UTF8");
		if( $nameLen > 63) return false;
	
		$sDangerousCharacters = "`~!@#$%^*\\|;:'<>/?";
		$sDangerousCharacters .= '"'; // add double quote to dangerous charaters
	
		$sSubstringStartingWithInvalidCharacter = strpbrk($sDangerousCharacters, $name);
		return empty($sSubstringStartingWithInvalidCharacter); // true if no invalid character
	}
	
	private static function cleanUpBeforeSave( $user, $object, $arr, $curState )
	{
		$user = $user; // keep analyzer happy
		$id = $object->MetaData->BasicMetaData->ID;

		// Delete object flags
		require_once BASEDIR.'/server/dbclasses/DBObjectFlag.class.php';
		DBObjectFlag::deleteObjectFlags( $id );

		// MtP clean:
		if( MTP_SERVER_DEF_ID != '' ) {
			require_once BASEDIR.'/server/MadeToPrintDispatcher.class.php';
			MadeToPrintDispatcher::clearSentObject( $id, $arr['publication'], $arr['state'], $curState );
		}
	}
	
	private static function saveExtended( $id, Object $object, $arr, $user, $create )
	{
		$dbDriver = DBDriverFactory::gen();
		
		// 1. Save object's elements:
		$objectelements = array();
		if (isset($object->Elements)) {
			if (is_array($object->Elements)) {
				$objectelements = $object->Elements;	
			}
		}
		
		require_once BASEDIR.'/server/dbclasses/DBElement.class.php';
		if( !DBElement::saveElements($id, $objectelements)) {
			throw new BizException( 'ERR_DATABASE', 'Server', $dbDriver->error() );
		}
		
		// 2. Create object relations
		// For Save (!create) we first delete all placed relations
		// If the current relations is null do not delete placed relations (BZ #17159)
		if( !$create && !is_null($object->Relations) ) {
			require_once BASEDIR."/server/bizclasses/BizRelation.class.php";
			// BZ#18888 - Get object childs relation only without parents relation, else parent relation will lost
			$placedOldDeletedRelations = BizRelation::getDeletedPlacedRelations($id, $object->Relations, 'childs');
			//First delete 'placed' relations that has been removed.
			if (!empty($placedOldDeletedRelations)) {
				// This will also take care of removing objects with no content which are no
				// related to other objects. 
				BizRelation::deleteObjectRelations($user, $placedOldDeletedRelations, true);
			}	
			
			//Second delete other relations.
			require_once BASEDIR."/server/dbclasses/DBObjectRelation.class.php";
			DBObjectRelation::deleteObjectRelation($id, null, 'Placed');
		}
		if (isset($object->Relations)) {
			require_once BASEDIR."/server/bizclasses/BizRelation.class.php";
			BizRelation::createObjectRelations( $object->Relations, $user, $id, $create ); 
				// => Added $create param => BZ#15317 Broadcast event when implicitly creating 
				//    dossier for CreateObjects... (do we need this for SaveObjects too?)
		}
		
		// 3. Save edition/device specific renditions (types)
		require_once BASEDIR.'/server/dbclasses/DBObjectRenditions.class.php';
		$version = $object->MetaData->WorkflowMetaData->Version;
		if( $object->Files ) foreach( $object->Files as $file ) {
			if( $file->EditionId ) {
				if( !self::isStorageRendition( $file->Rendition ) ) {
					LogHandler::Log( 'bizobject', 'ERROR', 'Saving unsupported file rendition "'.$file->Rendition.'".' );
				}
				DBObjectRenditions::saveEditionRendition( 
					$id, $file->EditionId, $file->Rendition, $file->Type, $version );
			}
		}
	}
	/**
	 * Saves all given object files at file storage.
	 *
	 * @param string $storeName   Object storage name used at file store
	 * @param string $objId       Object ID
	 * @param array $files        Collection of Attachment objects (files / renditions)
	 * @param string $objVersion  Object version in major.minor notation
	 */
	public static function saveFiles( $storeName, $objId, $files, $objVersion )
	{
		if( $files ) foreach( $files as $file ) {
			if( self::isStorageRendition( $file->Rendition ) ) {
				$storage = StorageFactory::gen( $storeName, $objId, $file->Rendition, $file->Type, 
								$objVersion, null, $file->EditionId, true );
				if( !$storage->saveFile( $file->Content ) ) {
					throw new BizException( 'ERR_ATTACHMENT', 'Server', $storage->getError() );
				}
			} else {
				LogHandler::Log( 'bizobject', 'ERROR', 'Tried to save unsupported file rendition "'.$file->Rendition.'".' );
			}
		}
		clearstatcache(); // Make sure unlink calls above are reflected!
	}

	/**
	 * Derives a rendition-format map from given object files and serializes it.
	 * This is typically stored at the 'types' field of smart_object table to be able
	 * to lookup files (renditions) in the filestore that belong to the stored object.
	 *
	 * @param array $files List of Attachment objects.
	 * return string Serialized rendition-format map. 
	 */
	public static function serializeFileTypes( $files )
	{
		$types = array();
		if( $files ) foreach( $files as $file ) {
			if( self::isStorageRendition( $file->Rendition ) ) {
				if( !$file->EditionId ) {
					$types[ $file->Rendition ] = $file->Type;
				}
			} else {
				LogHandler::Log( 'bizobject', 'ERROR', 'Tried to serialize unsupported file rendition "'.$file->Rendition.'".' );
			}
		}
		return serialize( $types );
	}

	/**
	 * Searches through given attachments for a certain rendition.
	 *
	 * @param array $files List of Attachment objects to search through.
	 * @param string $rendition Rendition to lookup in $files.
	 * @return Attachment Returns null when rendition was not found or when unsupported rendition was requested.
	 */
	public static function getRendition( $files, $rendition )
	{
		if( self::isStorageRendition( $rendition ) ) {
			if( $files ) foreach( $files as $file ) {
				if( is_object( $file ) && $file->Rendition == $rendition ) {
					return $file;
				}
			}
		} else {
			LogHandler::Log( 'bizobject', 'ERROR', 'Requested for unsupported file rendition "'.$rendition.'".' );
		}
		return null;
	}
	
	/**
	 * Tells if the given object file rendition is used for file storage.
	 * Note that 'none' and 'placement' are valid renditions at WSDL, but NOT stored at DB,
	 * for which FALSE is returned by this function.
	 *
	 * @param string $rendition
	 * @return boolean
	 */
	private static function isStorageRendition( $rendition )
	{
		$renditions = array( 'thumb', 'preview', 'native', 'output', 'trailer' );
		return in_array( $rendition, $renditions );
	}

	private static function getFlatMetaData( MetaData $meta, $objID = null )
	{
		// Get all property paths used in MetaData and object fields used in DB
		require_once BASEDIR.'/server/bizclasses/BizProperty.class.php';
		$objFields = BizProperty::getMetaDataObjFields();
		$propPaths = BizProperty::getMetaDataPaths();

		// Walk through all DB object fields and take over the values provided by MetaData tree
		$arr = array();
		foreach( $objFields as $propName => $objField ) {
			$propPath = $propPaths[$propName];
			if( !is_null($objField) && !is_null($propPath) && 
					// Don't accept system determined fields from outside world
					!in_array( $objField, array( 'id', 'created', 'creator', 'modified', 'modifier', 'lockedby', 'majorversion', 'minorversion' ) )) {
				eval( 'if( isset( $meta->'.$propPath.' ) && ($meta->'.$propPath.' !== null) ) $arr["'.$objField.'"] = $meta->'.$propPath.';');
			}
		}
		
		$contentMetaData = $meta->ContentMetaData;
		if (isset($contentMetaData->Keywords)) {
			$kw = $contentMetaData->Keywords;
			if(is_object($kw)){
				$kw = $kw->String;
			}
			if(!is_array($kw)){
				$kw = array($kw);
			}
			$arr['keywords'] = implode ("/", $kw);
		}

		// handle extra metadata
		$extraMetaData = $meta->ExtraMetaData;
		if ($extraMetaData /*&& isset($extraMetaData->ExtraMetaData)*/) { // BZ#6704
			// Object type might be specified with incoming meta data. If not, get it from DB
			@$objType = $arr['type'];
			if( !$objType || $objType == "" )
			{
				// get obj type from DB:
				require_once BASEDIR.'/server/dbclasses/DBObject.class.php';
				$sth = DBObject::getObject( $objID );
				$dbDriver = DBDriverFactory::gen();
				$row = $dbDriver->fetch($sth);

				$objType = $row['type'];
			}

			require_once BASEDIR.'/server/dbclasses/DBProperty.class.php';
			$extradata = DBProperty::getProperties( $meta->BasicMetaData->Publication->Id, $objType, true );
			if( $extradata ) foreach ( $extradata as $extra ) {
				foreach( $extraMetaData as $md ) {
					// Note: Single ExtraMetaData elements are given as objects,
					// but multiple elements are given as array!
					// Because ExtraMetaData elements have parent with very same name
					// (also "ExtraMetaData"), due to the WW SOAP hack in PEAR lib,
					// BOTH elements will have Property and Values defined (see BizDataClasses).
					// So, for the parent (container), we have to skip Name and Values
					// elements which mean nothing.
					
					// look for corresponding extrametadata
					// >>> Bugfix: Some clients give bad namespaces and so a single value array can become an object (PEAR issue)
					$mdNodes = array();
					if( is_object($md) ) { // change single object into array of one object
						$mdNodes[] = $md;
					} elseif( is_array( $md ) ) {
						$mdNodes = $md;
					} // <<< else, skip Values and Property elements of parent/container
					
					if (count($mdNodes) > 0 ) foreach( $mdNodes as $mdNode ) { 
						// Bugfix: some clients give mixed case (instead of uppercase), so now using strcasecmp
						if( strcasecmp( $mdNode->Property, $extra->Name ) == 0 ) { // configured?
							// >>> Bugfix: Some clients give bad namespaces and so a single value array can become an object (PEAR issue)
							$mdValues = array();
							if( is_object( $mdNode->Values ) ) { // change single object into array of one object
								$mdValues[] = $mdNode->Values->String;
							} elseif( is_array( $mdNode->Values ) ) {
								$mdValues = $mdNode->Values;
							} else {
								$mdValues[] = $mdNode->Values;
							}// <<<
							if( substr($extra->Type, 0, 5) == 'multi' && $extra->Type != 'multiline' && is_array($mdValues)) { // BZ#13545 exclude multiline!
								$value = implode( $mdValues, '/' ); // typically for multilist and multistring
							} else { // single value
								$value = $mdValues[0];
							}
							//BZ#10854 always lowercase in DB fields
							$arr[strtolower($extra->Name)] = $value;
						}
					}
				}
			}
		}
		
		//BZ#10272 $arr should always contain short user names!
		$userkeys = array('creator','modifier','routeto');
		foreach ($userkeys as $userkey) {
			if (array_key_exists($userkey, $arr)) {
				$userrow = DBUser::getUser($arr[$userkey]);
				if ($userrow) {
					$arr[$userkey] = $userrow['user'];
				}
			}
		}
		return $arr;
	}
	
	// TO DO for v7: this is application stuff, has nothing to do with business logic
	// needs to move to apps folder:
	public static function getTypeIcon($typename)
	{
		$icondir = '../../config/images/';

		$result = '';
		switch ($typename)
		{
			case 'Article':
				{$result = 'Obj_Article.gif';
				 break;}
			case 'ArticleTemplate':
				{$result = 'Obj_ArticleTemplate.gif';
				 break;}
			case 'Layout':
				{$result = 'Obj_Layout.gif';
				 break;}
			case 'LayoutTemplate':
				{$result = 'Obj_LayoutTemplate.gif';
				 break;}
			case 'Video':
				{$result = 'Obj_Video.gif';
				 break;}
			case 'Audio':
				{$result = 'Obj_Audio.gif';
				 break;}
			case 'Library':
				{$result = 'Obj_Library.gif';
				 break;}
			case 'Dossier':
				{$result = 'Obj_Dossier.gif';
				 break;}
			case 'DossierTemplate':
				{$result = 'Obj_DossierTemplate.gif';
				 break;}
			case 'Task':
				{$result = 'Obj_Task.gif';
				 break;}
			case 'Hyperlink':
				{$result = 'Obj_Hyperlink.gif';
				 break;}
			case 'LayoutModule':	
				{$result = 'Obj_LayoutModule.gif';
				 break;}
			case 'LayoutModuleTemplate':
				{$result = 'Obj_LayoutModuleTemplate.gif';
				 break;}
			case 'Image':
			case 'Advert':
			case 'AdvertTemplate':
			case 'Plan':
			default:
				{$result = 'Obj_Image.gif'; break;}
				
		}
		return $icondir . $result;
	}
	
	/**
	  * Tries to lock the given object when the given user (still) has no longer the lock.
	  * 
	  * @param string $user short user name
	  * @param string $objId Unique object ID
	  * @param bool $checkAccess check user access right
	  * @return bool User has lock.
	  * @throw BizException when locked by someone else or lock fails
	  */
	public static function restoreLock( $user, $objId, $checkAccess = true)
	{
		require_once BASEDIR.'/server/dbclasses/DBObjectLock.class.php';

		$lockUser = DBObjectLock::checkLock( $objId );
		if( !$lockUser ) { // no-one else has the lock, so we try taking it back...
			self::getObject( $objId, $user, true, 'none', null, null, $checkAccess ); // lock, no content, BZ#17253 - checkAccess false when article created from template
			// Note: We use getObject to trigger lock events, etc
		} else {
			if( strtolower($lockUser) != strtolower($user) ) { // someone else has the lock
				throw new BizException( 'OBJ_LOCKED_BY', 'Client',  $lockUser['usr'] );
			}
		}
	}

	private static function queryRow2MetaData( $row )
	{
		// make keywords into array of strings
		$keywords = explode("/", $row['Keywords']);

		// handle extra metadata
		$extramd = array();
		require_once BASEDIR.'/server/dbclasses/DBProperty.class.php';
		$extradata = DBProperty::getProperties( $row['PublicationId'], $row['Type'], true );
		if ($extradata){
			foreach ($extradata as $extra) {
				$name = $extra->Name;
				if( substr( $extra->Type, 0, 5 ) == 'multi' && $extra->Type != 'multiline' ) { // BZ#13545 exclude multiline!
					// typically for multilist and multistring
					if (DBTYPE == 'oracle') {
						$values = explode('/', $row[strtolower($name)]); // EKL: don't use strtolower or else mixed case breaks!
					}
					else {
						$values = explode('/', $row[$name]); // EKL: don't use strtolower or else mixed case breaks!
					}
				} else {
					if (DBTYPE == 'oracle') {
						$theVal = $row[strtolower($name)];             // EKL: same as above
					}
					else {
						$theVal = $row[$name];             // EKL: same as above
					}
					settype( $theVal, 'string' ); // BZ#4930: Force <String> elems (to avoid <item> for booleans)
					$values = array( $theVal );
				}
				// since v6 we return custom props including "c_" prefix!
				$extramd[] = new ExtraMetaData( $name, $values );
			}
		}

		// Get the complete collection of standard property path for MetaData struct
		require_once BASEDIR.'/server/bizclasses/BizProperty.class.php';
		$propPaths = BizProperty::getMetaDataPaths();
		$meta = new MetaData();
		
		// Build full MetaData tree and fill in with data from $row
		foreach( $propPaths as $propName => $propPath ) {
			
			$proptype = BizProperty::getStandardPropertyType($propName);
			switch ($proptype) {
				case 'datetime':
				case 'int':
				case 'list':
				case 'date':
				case 'double':
					{
						if (isset($row[$propName]) && trim($row[$propName]) == '') {
							$row[$propName] = null;
						}
						break;
					}
				case 'bool':
					{
						if (isset($row[$propName])){
							$trimVal = trim(strtolower($row[$propName]));
							if ($trimVal == 'on' || // Indexed, Closed, Flag, LockForOffline (on/<empty>)
								$trimVal == 'y' || // DeadlineChanged (Y/N)
								$trimVal == 'true' || // CopyrightMarked (true/false) -> Fixed for BZ#10541
								$trimVal == '1' ) { // repair old boolean fields that were badly casted/stored in the past
								$row[$propName] = true;
							}
							else {
								$row[$propName] = false;
							}
						}
						break;
					}
			}
			
			
			if( !is_null($propPath) && isset($row[$propName])) {
				// build MetaData tree on-the-fly (only intermediate* nodes are created)
				$pathParts = explode( '->', $propPath );
				array_pop( $pathParts ); // remove leafs, see*
				$path = '';
				foreach( $pathParts as $pathPart ) {
					$path .= $pathPart;
					eval( 'if( !isset( $meta->'.$path.' ) ) {
								$meta->'.$path.' = new $pathPart();
							}');
					$path .= '->';
				}
				eval('$placeholder = &$meta->'.$propPath.';'); // Creating reference to array element
				$placeholder = $row[$propName]; // Filling the reference and thereby the array element
			}
		}
		// Complete the MetaData tree
		$meta->ContentMetaData->Keywords = $keywords;
		$meta->ContentMetaData->PlainContent = $row['PlainContent'];
		$meta->WorkflowMetaData->State->Type = $meta->BasicMetaData->Type;
		$meta->ExtraMetaData = $extramd;
		$meta->TargetMetaData = null; // clear obsoleted tree!
		return $meta;
	} 

	/**
	 * Creates comma separated strings of all issues and editions of a given list of targets.
	 * This is done for ids and names, which is typically used for logging and broadcasting
	 * to inform users and admins about the current targets of the object that undertakes action.
	 *
	 * @param array  $targets      List of Target objects
	 * @param string $issueIds     Comma separated list of issue ids
	 * @param string $issueNames   Comma separated list of issue names
	 * @param string $editionIds   Comma separated list of edition ids
	 * @param string $editionNames Comma separated list of edition names
	 */
	static private function listIssuesEditions( $targets, &$issueIds, &$issueNames, &$editionIds, &$editionNames )
	{
		$arrIssueIds = array();
		$arrIssueNames = array();
		$arrEditionIds = array();
		$arrEditionNames = array();
		if( !empty($targets) ) foreach( $targets as $target ) {
			if( !empty($target->Issue) ) {
				$arrIssueIds[] = $target->Issue->Id;
				$arrIssueNames[] = $target->Issue->Name;
			}
			if( !empty($target->Editions) ) foreach( $target->Editions as $edition ) {
				$arrEditionIds[] = $edition->Id;
				$arrEditionNames[] = $edition->Name;
			}
		}
		$issueIds     = join(',', $arrIssueIds);
		$issueNames   = join(',', $arrIssueNames);
		$editionIds   = join(',', $arrEditionIds);
		$editionNames = join(',', $arrEditionNames);
	}
	
	/**
	 * Create a Dossier object from an other object.
	 * Personal State and autonaming are not supported.
	 *
	 * @param string $user short user name
	 * @param Object $object
	 * @return Object
	 */
	static protected function createDossierFromObject($object)
	{
		$dossierObject = null;
		// only support one target
		if (isset($object->Targets[0]->Issue) && $object->Targets[0]->Issue->OverrulePublication){
			// overrule brand
			// $states are ordered on code ( = order in UI)
			$states = DBWorkflow::listStatesCached($object->MetaData->BasicMetaData->Publication->Id, $object->Targets[0]->Issue->Id, 0, 'Dossier');
			// we get all publication issues, so select the first correct one
			foreach ($states as $state){
				if ($state['issue'] ==  $object->Targets[0]->Issue->Id){
					break;
				}
			}
		} else {
			// $states are ordered on code ( = order in UI)
			$states = DBWorkflow::listStatesCached($object->MetaData->BasicMetaData->Publication->Id, 0, 0, 'Dossier');
			// First dossier state is default state (BZ#14644)
			$state = $states[0];
		}
		
		if ($state){
			$basicMD = new BasicMetaData(null, '', $object->MetaData->BasicMetaData->Name, 'Dossier', $object->MetaData->BasicMetaData->Publication, $object->MetaData->BasicMetaData->Category, '');
			$workflowMD = new WorkflowMetaData();
			// the first state should always be default state
			$workflowMD->State = new State($state['id']);
			$workflowMD->RouteTo = $object->MetaData->WorkflowMetaData->RouteTo; // BZ#17368
			//TODO extra metadata
			$md = new MetaData($basicMD, null, null, null, null, $workflowMD);
			$dossierObject = new Object($md, null, null, null, null, null, $object->Targets);
			LogHandler::Log(__CLASS__, 'DEBUG', 'Create new dossier');
			// Watchout, recursion!
			// call service layer
			require_once BASEDIR.'/server/services/wfl/WflCreateObjectsService.class.php';
			require_once BASEDIR.'/server/interfaces/services/wfl/WflCreateObjectsRequest.class.php';
			$request = new WflCreateObjectsRequest(BizSession::getTicket(), false, array($dossierObject), null, null);
			$service = new WflCreateObjectsService();
			//TODO Should we catch exceptions?
			$response = $service->execute($request);
			if (isset($response->Objects[0])){
				$dossierObject = $response->Objects[0];
			}
			LogHandler::Log(__CLASS__, 'DEBUG', 'Created new dossier with id: ' . $dossierObject->MetaData->BasicMetaData->ID);
		} else {
			LogHandler::Log(__CLASS_, 'ERROR', 'Could not find first dossier state!');
			// No BizException, all other things should be created
		}
		
		return $dossierObject;
	}
	
	/**
	 * Checks if user has read and edit (only when locking an object) rights 
	 * on an object.
	 *
	 * @param integer 	$id 		Object Id
	 * @param boolean 	$lock 		Object is locked
	 * @param string 	$user		Short User Name
	 * @param integer 	$pubId		Publication Id
	 * @param integer 	$issueId	Issue Id
	 * @param integer 	$sectionId	Section Id
	 * @param string 	$type		Object Type
	 * @param integer 	$stateId	State Id
	 * @param string 	$routeto	Route User(group)
	 */
	static private function checkAccesRights($id, $lock, $user, $pubId, $issueId, $sectionId, $type, $stateId, $routeto)
	{
		require_once BASEDIR.'/server/bizclasses/BizUser.class.php';
		
		// For personal state of this user we don't have to check authorization
		// BZ#5754 neither if routeto=user, so removed the personal condition
		$routetouser = false;
		$usergroups = DBUser::getMemberships(BizSession::getUserInfo('id'));
		foreach ($usergroups as $usergroup) {
			$groupname = $usergroup['name'];
			if (strtolower(trim($routeto)) == strtolower(trim($groupname))) {
				$routetouser = true;
				break;
			}
		}
		//BZ#6468 user needs 'E'-access to lock
		if ($lock === true) {
			if (! (($stateId == - 1) && ($routeto == $user || $routetouser))) {
				// Do not check authorization in case of 'Personal' state and user is 'route to
				// user' or in the 'route to group'.
				$check = 'E';
				global $globAuth;
				if( !isset($globAuth) ) {
					require_once BASEDIR.'/server/authorizationmodule.php';
					$globAuth = new authorizationmodule( );
				}
				$globAuth->getrights($user, $pubId, $issueId, $sectionId, $type, $stateId);
				if (! $globAuth->checkright($check, $pubId, $issueId, $sectionId, $type, $stateId)) {
					// Authorization failed, bail out:
					throw new BizException('ERR_AUTHORIZATION', 'Client', "$id($check)");
				}
			}
		} else if ($routeto == $user || $routetouser) {
			;
		} else {
			$check = 'R';
			global $globAuth;
			if( !isset($globAuth) ) {
				require_once BASEDIR.'/server/authorizationmodule.php';
				$globAuth = new authorizationmodule( );
			}
			$globAuth->getrights($user, $pubId, $issueId, $sectionId, $type, $stateId);
			if (! $globAuth->checkright($check, $pubId, $issueId, $sectionId, $type, $stateId)) {
				// Authorization failed, bail out:
				throw new BizException('ERR_AUTHORIZATION', 'Client', "$id($check)");
			}
		}
	}
	
	/**
	 * Returns all issues (relational and object) of the object needed for deadline calculation.
	 * First look if an object has an object-target (always the case for layout/dossier).
	 * If the object is an image or article and has no object-target the issues of relational-targets
	 * are returned.
	 * Duplicate issues are removed.
	 * @param Object $object
	 * @return array with the unique issues of the object to calculate the deadline.
	 */
	static private function getTargetIssuesForDeadline(Object $object )
	{
		require_once BASEDIR.'/server/bizclasses/BizTarget.class.php';
		require_once BASEDIR.'/server/bizclasses/BizDeadlines.class.php';
		
		$type = $object->MetaData->BasicMetaData->Type;
		$issueIds = array(); 
		$issueIds = BizTarget::getIssueIds($object->Targets); // Object-target issues
		
		if ( !$issueIds && ( BizDeadlines::canInheritParentDeadline( $type) )) { //BZ#21218
			if ( $object->Relations) foreach ($object->Relations as $relation ) {
				$issueIds = array_merge( $issueIds, BizTarget::getIssueIds($relation->Targets )); // Relational-target issues
			}
		}	
		
		return array_unique( $issueIds ); // Remove double entries
	}
}

