<?php

$errors = array();

// Check if publication name is set and not empty 
if( trim($_REQUEST['publicationid']) == '' ) {
	$errors[] = 'No publication id specified. Please set parameter "publicationid"';
}

// Check if dossier state for is set and not empty 
if( trim($_REQUEST['state']) == '' ) {
	$errors[] = 'No dossier state specified. Please set parameter "state"';
}

if( !empty($errors) ) {
	die( implode("\n", $errors) );
}

set_time_limit(3600);

require_once'../../config.php';
require_once BASEDIR.'/config/plugins/AddWebChannel/config.php';
require_once BASEDIR.'/server/protocols/soap/WflClient.php';

$client = new WW_SOAP_WflClient();

try {
	require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnRequest.class.php';
	require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOnResponse.class.php';
	$logonReq = new WflLogOnRequest( AWC_USER, AWC_PASSWORD, null, null, '', null,
			'AddWebChannelDispatcher', 'v1.0b', null, null, true );
	$logonResp = $client->LogOn($logonReq);
} catch ( BizException $e ) {
	echo 'Failed to logon the configured AddWebChannel user. Please check AWC_USER and AWC_PASSWORD options.'
	.' Error returned from server: '.$e->getMessage()."\n";
}

if( $logonResp->Ticket ) {

	try {
		require_once BASEDIR.'/server/interfaces/services/wfl/WflQueryObjectsRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflQueryObjectsResponse.class.php';
		$ArrayOfQueryParams = array();
		$ArrayOfQueryParams[] = new QueryParam ('PublicationId', '=', $_REQUEST['publicationid']);
		$ArrayOfQueryParams[] = new QueryParam ('IssueId', '=', 'Current', true);
		$ArrayOfQueryParams[] = new QueryParam ('State', '=', $_REQUEST['state']);
		#$ArrayOfQueryParams[] = new QueryParam ('Type', '=', 'Dossier');
		$queryObjectsReq = new WflQueryObjectsRequest( $logonResp->Ticket, $ArrayOfQueryParams,
					1, 0, false, null, null, array('ID') );
		$queryObjectsResp = $client->QueryObjects( $queryObjectsReq );
	
	} catch(BizException $e) {
		echo 'Failed to get dossiers for publication "' . $_REQUEST['publication'] . '" in current issue for state "' . $_REQUEST['state'] . '". Error returned from server: '.$e->getMessage()."\n";
	}
	
	$dossierIDs = array();
	foreach( $queryObjectsResp->Rows as $row ) {
		$dossierIDs[] = $row[0];
	}

	if( empty($dossierIDs) ) {
		die( 'No dossiers found for publication with ID ' . $_REQUEST['publicationId'] . ' in current issue for state "' . $_REQUEST['state'] . '".');
	}		
				
	try{
		require_once BASEDIR.'/server/interfaces/services/wfl/WflGetObjectsRequest.class.php';
        require_once BASEDIR.'/server/interfaces/services/wfl/WflGetObjectsResponse.class.php';
        $getObjectsReq = new WflGetObjectsRequest( $logonResp->Ticket, $dossierIDs, false, 'none', array('Targets'));
        $getObjectsResp = $client->GetObjects($getObjectsReq);
        $dossiers = $getObjectsResp->Objects;
    } catch(BizException $e){
        echo 'Failed to get dossiers info. Error returned from server: '.$e->getMessage()."\n";
    }
		
	if( empty($dossiers) ) {
		die( 'No dossiers found for publication with ID ' . $_REQUEST['publicationId'] . ' in current issue for state "' . $_REQUEST['state'] . '".');
	}	
		
	foreach( $dossiers as $dossier ) {
		$dossierID = $dossier->MetaData->BasicMetaData->ID;
		
		try {
			require_once BASEDIR.'/server/interfaces/services/wfl/WflGetObjectRelationsRequest.class.php';
			require_once BASEDIR.'/server/interfaces/services/wfl/WflGetObjectRelationsResponse.class.php';
			$getObjectRelationsReq = new WflGetObjectRelationsRequest( $logonResp->Ticket,  $dossierID);
			$getObjectRelationsResp = $client->GetObjectRelations( $getObjectRelationsReq );
								
		} catch( BizException $e ) {
			echo 'Failed to get object relations for dossier with ID '. $dossierID .'. Error returned from server: '.$e->getMessage()."\n";
		}
			
		$relations = $getObjectRelationsResp->Relations;
			
		if( empty($relations) ) {
			echo 'No relations found for dossier with ID ' . $dossierID . ".\n";
		} else {
			$updatedRelations = array();
			
			foreach( $relations as &$relation ) {
				$childType = $relation->ChildInfo->Type;
				if ( $childType == 'Article' || $childType == 'Image' ) {
					$relation->Geometry = null;
					$relation->Targets = $dossier->Targets;
					$updatedRelations[] = $relation;
				}
			}
				
			if( empty($updatedRelations) ) {
				echo 'No relations to update for dossier with ID ' . $dossierID . ".\n";
			} else {
				try {
					require_once BASEDIR.'/server/interfaces/services/wfl/WflUpdateObjectRelationsRequest.class.php';
					require_once BASEDIR.'/server/interfaces/services/wfl/WflUpdateObjectRelationsResponse.class.php';
					$updateObjectRelationsReq = new WflUpdateObjectRelationsRequest( $logonResp->Ticket,  $updatedRelations);
					$updateObjectRelationsResp = $client->UpdateObjectRelations( $updateObjectRelationsReq );
						
					foreach( $updateObjectRelationsResp->Relations as $updatedRelation ) {
						echo 'Updated relation for dossier with ID ' . $updatedRelation->Parent . ' and article/image with ID '. $updatedRelation->Child ."\n";
					}
						
				} catch( BizException $e ) {
					echo 'Failed to update object relations for dossier with ID '. $dossierID . '. Error returned from server: '.$e->getMessage()."\n";
				}
			}
		}
			
	}

	try {
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffRequest.class.php';
		require_once BASEDIR.'/server/interfaces/services/wfl/WflLogOffResponse.class.php';
		$logOffReq = new WflLogOffRequest( $logonResp->Ticket, false, null, null );
		$logoffResp = $client->LogOff( $logOffReq );
	} catch(BizException $e) {
		echo 'Failed to logoff the configured ImageOriginals user. Please check IO_USER and IO_PASSWORD options.'
		.' Error returned from server: '.$e->getMessage()."\n";
	}
}

?>