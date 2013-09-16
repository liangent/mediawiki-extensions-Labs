<?php

namespace Wikibase;

class LabsStore extends SqlStore {

	public function newIdGenerator() {
		return new LabsIdGenerator();
	}

	protected function newEntityLookup() {
		$lookup = new LabsWikiPageEntityLookup();
		return new CachingEntityLoader( $lookup );
	}

}
