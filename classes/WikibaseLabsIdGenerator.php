<?php

namespace Wikibase;

class LabsIdGenerator implements IdGenerator {

	public function getNewId( $type ) {
		global $wgLabs;

		$resp = $wgLabs->apiRequest( array(
			'action' => 'wbeditentity',
			'data' => \FormatJson::encode( array() ),
			'token' => array(
				'type' => 'token',
				'token' => 'edit',
			),
			'bot' => '',
			'new' => \ContentHandler::getForModelId( $type )->getEntityType(),
		) );

		if ( !isset( $resp->entity->id ) ) {
			// Known issue of random failure.
			return $this->getNewId( $type );
		}

		return (int)substr( $resp->entity->id, 1 );
	}
}
