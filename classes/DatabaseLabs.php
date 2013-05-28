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
}
