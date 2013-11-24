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

	public function estimateRowCount( $table, $vars = '*', $conds = '', $fname = __METHOD__, $options = array() ) {
		return DatabaseBase::estimateRowCount( $table, $vars, $conds, $fname, $options );
	}
}
