<?php

namespace Wikibase;

class LabsClientStore extends DirectSqlStore {

	protected function newEntityLookup() {
		$lookup = new LabsWikiPageEntityLookup( $this->repoWiki );
		return new CachingEntityLoader( $lookup );
	}

}
