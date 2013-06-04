<?php

class RemoteUtils {
	static function newSection( $title, $sectiontitle = null, $text = '', $summary = null, $flags = 0 ) {
		global $wgLabs;

		$resp = $wgLabs->apiRequest( array(
			'action' => 'edit',
			'title' => $title->getPrefixedText(),
			'section' => 'new',
			'sectiontitle' => is_null( $sectiontitle ) ? '' : $sectiontitle,
			'text' => $text,
			'token' => array(
				'type' => 'token',
				'token' => 'edit',
			),
			'summary' => is_null( $summary ) ? '' : $summary,
			( $flags & EDIT_MINOR ? 'minor' : 'notminor' ) => '',
			( $flags & EDIT_SUPPRESS_RC ? 'bot' : 'notbot' ) => '',
			( $flags & EDIT_NEW ? 'createonly' : 'notcreateonly' ) => '',
			( $flags & EDIT_UPDATE ? 'nocreate' : 'notnocreate' ) => '',
			'md5' => md5( $text ),
		) );
		$status = new Status();
		if ( !$resp ) {
			$status->fatal( 'ru-newsection-network' );
			return $status;
		}
		if ( isset( $resp->error ) ) {
			$status->fatal( "ru-newsection-{$resp->error->code}" );
			return $status;
		}
		if ( $resp->edit->result !== 'Success' ) {
			$status->fatal( 'ru-newsection-remote' );
		}
		$status->setResult( true, $resp->edit );
		return $status;
	}

	static function insertText( $title, $prependtext = '', $appendtext = '', $summary = null, $flags = 0, $section = null ) {
		global $wgLabs;

		$resp = $wgLabs->apiRequest( array(
			'action' => 'edit',
			'title' => $title->getPrefixedText(),
			( is_null( $section ) ? 'notsection' : 'section' ) => $section,
			'prependtext' => $prependtext,
			'appendtext' => $appendtext,
			'token' => array(
				'type' => 'token',
				'token' => 'edit',
			),
			'summary' => is_null( $summary ) ? '' : $summary,
			( $flags & EDIT_MINOR ? 'minor' : 'notminor' ) => '',
			( $flags & EDIT_SUPPRESS_RC ? 'bot' : 'notbot' ) => '',
			( $flags & EDIT_NEW ? 'createonly' : 'notcreateonly' ) => '',
			( $flags & EDIT_UPDATE ? 'nocreate' : 'notnocreate' ) => '',
			'md5' => md5( $prependtext . $appendtext ),
		) );
		$status = new Status();
		if ( !$resp ) {
			$status->fatal( 'ru-inserttext-network' );
			return $status;
		}
		if ( isset( $resp->error ) ) {
			$status->fatal( "ru-inserttext-{$resp->error->code}" );
			return $status;
		}
		if ( $resp->edit->result !== 'Success' ) {
			$status->fatal( 'ru-inserttext-remote' );
		}
		$status->setResult( true, $resp->edit );
		return $status;
	}

	static function parseTitle( $title, $options = null ) {
		return self::parse( array(
			'page' => $title->getPrefixedText(),
		), $options );
	}

	static function parseRevision( $rev, $options = null ) {
		return self::parse( array(
			'oldid' => $rev->getId(),
		), $options );
	}

	static function parsePage( $wikiPage, $options = null ) {
		return self::parse( array(
			'pageid' => $wikiPage->getId(),
		), $options );
	}

	static function parse( $params, $options = null ) {
		global $wgLabs;

		$params['action'] = 'parse';
		if ( $options ) {
			if ( $options->getTargetLanguage() ) {
				$params['uselang'] = $options->getTargetLanguage()->getCode();
			}
			if ( !$options->getEnableLimitReport() ) {
				$params['disablepp'] = true;
			}
		}
		$resp = $wgLabs->apiRequest( $params );

		if ( !$resp || isset( $resp->error ) ) {
			return null;
		}

		$resp = $resp->parse;
		if ( $resp->revid == 0 ) {
			# Non-existent page
			return null;
		}

		$po = new ParserOutput();

		$po->setText( $resp->text->{'*'} );

		foreach ( $resp->langlinks as $langlink ) {
			$po->addLanguageLink( Title::makeTitle(
				NS_MAIN, $langlink->{'*'}, '', $langlink->lang
			)->getFullText() );
		}

		foreach ( $resp->categories as $category ) {
			$po->addCategory( $category->{'*'}, $category->sortkey );
		}

		foreach ( $resp->links as $link ) {
			$dbkey = $link->{'*'};
			if ( $link->ns != NS_MAIN ) {
				$dbkey = self::parseStripTitlePrefix( $dbkey );
			}
			$po->addLink( Title::makeTitle( $link->ns, $dbkey ), 0 );
		}

		foreach ( $resp->templates as $template ) {
			$dbkey = $template->{'*'};
			if ( $template->ns != NS_MAIN ) {
				$dbkey = self::parseStripTitlePrefix( $dbkey );
			}
			$po->addTemplate( Title::makeTitle( $template->ns, $dbkey ), 0, 0 );
		}

		foreach ( $resp->images as $image ) {
			$po->addImage( $image );
		}

		foreach ( $resp->externallinks as $externallink ) {
			$po->addExternalLink( $externallink );
		}

		$po->setSections( $resp->sections );

		$po->setDisplayTitle( $resp->displaytitle );

		foreach ( $resp->iwlinks as $iwlink ) {
			$dbkey = self::parseStripTitlePrefix( $iwlink->{'*'} );
			$po->addInterwikiLink( Title::makeTitle( NS_MAIN, $dbkey, '', $iwlink->prefix ) );
		}

		foreach ( $resp->properties as $property ) {
			$po->setProperty( $property->name, $property->{'*'} );
		}

		return $po;
	}

	static function parseStripTitlePrefix( $dbkey ) {
		$prefixRegexp = "/^(.+?)_*:_*(.*)$/S";
		$m = array();
		if ( preg_match( $prefixRegexp, $dbkey, $m ) ) {
			return $m[2];
		} else {
			throw new MWException( 'Expected title prefix not found.' );
		}
	}

	static function preprocessTitle( $title, $options = null ) {
		return self::preprocess( array(
			'titles' => $title->getPrefixedText(),
			'rvexpandtemplates' => '',
		), $options );
	}

	static function preprocessRevision( $rev, $options = null ) {
		return self::preprocess( array(
			'revids' => $rev->getId(),
			'rvexpandtemplates' => '',
		), $options );
	}

	static function preprocessPage( $wikiPage, $options = null ) {
		return self::preprocess( array(
			'pageids' => $wikiPage->getId(),
			'rvexpandtemplates' => '',
		), $options );
	}

	static function preprocessTitleToDom( $title, $options = null ) {
		return self::preprocess( array(
			'titles' => $title->getPrefixedText(),
			'rvgeneratexml' => '',
		), $options, 'parsetree' );
	}

	static function preprocessRevisionToDom( $rev, $options = null ) {
		return self::preprocess( array(
			'revids' => $rev->getId(),
			'rvgeneratexml' => '',
		), $options, 'parsetree' );
	}

	static function preprocessPageToDom( $wikiPage, $options = null ) {
		return self::preprocess( array(
			'pageids' => $wikiPage->getId(),
			'rvgeneratexml' => '',
		), $options, 'parsetree' );
	}

	static function preprocess( $params, $options = null, $key = '*' ) {
		global $wgLabs;

		$params = array(
			'action' => 'query',
			'prop' => 'revisions',
			'rvprop' => 'content',
			'indexpageids' => '',
		) + $params;
		$resp = $wgLabs->apiRequest( $params );

		if ( !$resp || isset( $resp->error ) ) {
			return null;
		}

		$respPage = $resp->query->pages->{$resp->query->pageids[0]};
		if ( !isset( $respPage->revisions ) ) {
			return null;
		}

		return $respPage->revisions[0]->{$key};
	}
}
