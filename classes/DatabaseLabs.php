<?php

class DatabaseLabs extends DatabaseMysql {
	function getType() {
		return 'labs';
	}

	function useIndexClause( $index ) {
		return '';
	}

	function getMasterPos() {
		return DatabaseBase::getMasterPos();
	}

	function getSlavePos() {
		return DatabaseBase::getSlavePos();
	}

	function getDBname() {
		$dbName = parent::getDBname();
		return preg_replace( '/_p$/', '', $dbName );
	}
}
