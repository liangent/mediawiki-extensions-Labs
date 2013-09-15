<?php

namespace Wikibase;

class LabsStore extends SqlStore {

	public function newIdGenerator() {
		return new LabsIdGenerator();
	}
}
