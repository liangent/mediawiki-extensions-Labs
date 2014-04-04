<?php

namespace Wikibase;

class LabsWikiPageEntityLookup extends WikiPageEntityLookup {

	public function __construct( $wiki = false ) {
		parent::__construct( $wiki, false );
	}

	/**
	 * Selects revision information from the page and revision tables.
	 *
	 * @since 0.4
	 *
	 * @param EntityID $entityId The entity to query the DB for.
	 * @param int      $revision The desired revision id, 0 means "current".
	 *
	 * @throws \DBQueryError If the query fails.
	 * @return object|null a raw database row object, or null if no such entity revision exists.
	 */
	protected function selectRevisionRow( EntityID $entityId, $revision = 0 ) {
		wfProfileIn( __METHOD__ );
		$db = $this->getConnection( DB_READ );

		$opt = array();

		$tables = array(
			'page',
			'revision',
		);

		$pageTable = $db->tableName( 'page' );
		$revisionTable = $db->tableName( 'revision' );

		$vars = "$pageTable.*, $revisionTable.*";

		$where = array();
		$join = array();

		if ( $revision > 0 ) {
			// pick revision by id
			$where['rev_id'] = $revision;

			// pick page via rev_page
			$join['page'] = array( 'INNER JOIN', 'page_id=rev_page' );

			wfDebugLog( __CLASS__, __FUNCTION__ . ": Looking up revision $revision of " . $entityId );
		} else {
			// entity to page mapping
			$tables[] = 'wb_entity_per_page';

			// pick entity by id
			$where['epp_entity_id'] = $entityId->getNumericId();
			$where['epp_entity_type'] = $entityId->getEntityType();

			// pick page via epp_page_id
			$join['page'] = array( 'INNER JOIN', 'epp_page_id=page_id' );

			// pick latest revision via page_latest
			$join['revision'] = array( 'INNER JOIN', 'page_latest=rev_id' );

			wfDebugLog( __CLASS__, __FUNCTION__ . ": Looking up latest revision of " . $entityId );
		}

		$res = $db->select( $tables, $vars, $where, __METHOD__, $opt, $join );

		if ( !$res ) {
			// this can only happen if the DB is set to ignore errors, which shouldn't be the case...
			$error = $db->lastError();
			$errno = $db->lastErrno();
			throw new DBQueryError( $db, $error, $errno, '', __METHOD__ );
		}

		$this->releaseConnection( $db );

		if ( $row = $res->fetchObject() ) {
			wfProfileOut( __METHOD__ );
			return $row;
		} else {
			wfProfileOut( __METHOD__ );
			return null;
		}
	}

	/**
	 * Construct an EntityRevision object from a database row from the revision and text tables.
	 *
	 * This calls Revision::getRevisionText to resolve any additional indirections in getting
	 * to the actual blob data, like the "External Store" mechanism used by Wikipedia & co.
	 *
	 * @param Object $row a row object as expected \Revision::getRevisionText(), that is, it
	 *        should contain the relevant fields from the revision and/or text table.
	 * @param String $entityType The entity type ID, determines what kind of object is constructed
	 *        from the blob in the database.
	 *
	 * @return EntityRevision|null
	 */
	protected function loadEntity( $entityType, $row ) {
		wfProfileIn( __METHOD__ );

		wfDebugLog( __CLASS__, __FUNCTION__ . ": calling getRevisionText() on rev " . $row->rev_id );

		//NOTE: $row contains revision fields from another wiki. This SHOULD not
		//      cause any problems, since getRevisionText should only look at the old_flags
		//      and old_text fields. But be aware.
		if ( $this->wiki === false ) {
			// Labs: It's safe here as the first argument to the constructor of the parent class is forced to be false.
			$blob = \Revision::newFromRow( $row )->getSerializedData();
		} else {
			// Oh no let's run getText from the source wiki, which must be the repo.
			global $IP;
			$namespaces = Settings::singleton()->getSetting( 'repoNamespaces' );
			$namespaceIndex = "wikibase-$entityType";
			$titleText = "{$namespaces[$namespaceIndex]}:{$row->page_title}";
			$cmd = wfShellWikiCmd( "$IP/maintenance/getText.php", array(
				'--wiki', $this->wiki, $titleText,
			) );
			$retVal = -1;
			$blob = wfShellExec( $cmd, $retVal );
			if ( $retVal !== 0 ) {
				$blob = false;
			}
		}

		if ( $blob === false ) {
			// oops. something went wrong.
			wfWarn( "Unable to load raw content blob for rev " . $row->rev_id );
			wfProfileOut( __METHOD__ );
			return null;
		}

		$format = $row->rev_content_format;
		$entity = EntityFactory::singleton()->newFromBlob( $entityType, $blob, $format );
		$entityRev = new EntityRevision( $entity, (int)$row->rev_id, $row->rev_timestamp );

		wfDebugLog( __CLASS__, __FUNCTION__ . ": Created entity object from revision blob: "
			. $entity->getId() );

		wfProfileOut( __METHOD__ );
		return $entityRev;
	}
}
