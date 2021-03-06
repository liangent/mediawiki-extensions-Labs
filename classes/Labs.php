<?php

class Labs {
	static function extractWiki() {
		# Extract wiki to use
		$wiki = '';
		if ( defined( 'MW_DB' ) ) {
			$wiki = MW_DB;
		}
		if ( isset( $_SERVER['PATH_INFO'] ) && $wiki === '' ) {
			$wiki = ltrim( $_SERVER['PATH_INFO'], '/' );
		}
		return $wiki;
	}

	static function setup( $wiki ) {
		global $wgLabs, $wgToolserver, $wgUsePathInfo, $wgDBuser, $wgDBpassword, $wgLabsDBprefix,
			$wgScriptPath, $wgScript, $wgLoadScript;

		# Database user
		$pw = posix_getpwuid( posix_getuid() );
		$mycnf = parse_ini_file( $pw['dir'] . '/replica.my.cnf' );
		$wgDBuser = $mycnf['user'];
		if ( $wgLabsDBprefix === null ) {
			$wgLabsDBprefix = "{$wgDBuser}__wikidb_";
		}
		$wgDBpassword = $mycnf['password'];

		# Create
		$wgLabs = self::factory( $wiki );
		if ( !$wgLabs ) {
			exit;
		}

		# Alias for Toolserver scripts
		$wgToolserver = $wgLabs;

		# Configure
		$wgLabs->configure();

		# Path setup
		$wgUsePathInfo = false;
		unset( $_SERVER['REQUEST_URI'] );
		$wgScript = "$wgScriptPath/index.php/$wiki";
		$wgLoadScript = "$wgScriptPath/load.php/$wiki";

		# Hooks
		$wgLabs->installHooks();
	}

	static function factory( $wiki ) {
		global $IP, $wgLabsConfigDir, $wgLabsUsers, $wgDBuser, $wgDBpassword, $wgLabsAcceptedSettings;

		if ( $wiki === '' ) {
			header( 'Content-type: text/plain' );
			echo "Wiki not specified.\n";
			return false;
		}

		$parts = explode( '~', $wiki );
		$dbName = $parts[0];

		# Try to load configurations
		$wmfConfigDir = "$wgLabsConfigDir/wmf-config";
		if ( !function_exists( 'getRealmSpecificFilename' ) ) {
			function getRealmSpecificFilename( $file ) {
				global $IP, $wgLabsConfigDir;

				if ( dirname( $file ) === "$IP/.." ) {
					return $wgLabsConfigDir . '/' . basename( $file );
				}
				return $file;
			}
		}
		require "$wmfConfigDir/wgConf.php";
		$conf = $wgConf;
		unset( $wgConf );
		unset( $wgLocalDatabases );
		$conf->fullLoadCallback = function() use ( $wmfConfigDir ) {
			require "$wmfConfigDir/InitialiseSettings.php";
			unset( $wgConf->settings['wgShowExceptionDetails'] );
			unset( $wgConf->settings['wgParserCacheType'] );
			unset( $wgConf->settings['wgLanguageConverterCacheType'] );
			unset( $wgConf->settings['wgDebugLogFile'] );
			unset( $wgConf->settings['wgDebugLogGroups'] );
			unset( $wgConf->settings['wgArticlePath'] );
			unset( $wgConf->settings['wgVariantArticlePath'] );
		};

		# Check whether this is a known wiki
		if ( array_search( $dbName, $conf->wikis ) === false ) {
			header( 'Content-type: text/plain' );
			echo "Unknown wiki: $dbName.\n";
			return false;
		}

		# Get user info
		if ( isset( $parts[1] ) ) {
			$userKey = $parts[1];
		} else {
			$userKey = '';
		}

		if ( isset( $wgLabsUsers[$userKey] ) ) {
			$userInfo = $wgLabsUsers[$userKey];
		} else {
			header( 'Content-type: text/plain' );
			echo "Unknown user: $userKey.\n";
			return false;
		}

		# Get configurations
		if ( isset( $parts[2] ) ) {
			$acceptedSettings = array();
			$settings = explode( '!', $parts[2] );
			foreach ( $settings as $settingPiece ) {
				$settingPieces = explode( '=', $settingPiece, 2 );
				if ( count( $settingPieces ) == 1 ) {
					if ( substr( $settingPieces[0], 0, 1 ) === '-' ) {
						$setting = substr( $settingPieces[0], 1 );
						$value = false;
					} else {
						$setting = $settingPieces[0];
						$value = true;
					}
				} else {
					list( $setting, $value ) = $settingPieces;
				}
				if ( isset( $wgLabsAcceptedSettings[$setting] ) ) {
					switch ( $wgLabsAcceptedSettings[$setting] ) {
					case 'int':
						$value = (int)$value;
						break;
					case 'bool':
						$value = (bool)$value;
						break;
					case 'float':
						$value = (float)$value;
						break;
					case 'string':
						$value = (string)$value;
						break;
					case 'array':
						$value = (array)$value;
						break;
					default:
						header( 'Content-type: text/plain' );
						echo "Invalid setting type: {$wgLabsAcceptedSettings[$setting]}.\n";
						return false;
					}
					$acceptedSettings[$setting] = $value;
				}
			}
		} else {
			$acceptedSettings = array();
		}

		$labs = new self( $dbName, $userInfo, $conf, $acceptedSettings );
		return $labs;
	}

	function __construct( $dbName, $userInfo, $conf, $settings ) {
		$this->dbName = $dbName;
		if ( php_sapi_name() === 'cli' ) {
			$this->userInfo = $userInfo;
		} else {
			$this->user = null;
			$this->userInfo = array();
		}
		$this->conf = $conf;
		$this->settings = $settings;
		$this->cookieJar = null;
		$this->token = array();
	}

	function configure() {
		global $wgDBname, $wgConf, $wgLocalDatabases, $wgLabsConfigDir, $wgLBFactoryConf, $wgDBuser, $wgDBpassword,
			$wgSharedDB, $wgSharedTables, $wgDisableCounters, $wgDisableAnonTalk, $wgSpecialPageCacheUpdates,
			$wgServer, $wgCanonicalServer, $wgWMFServer, $wgWMFCanonicalServer, $wgWMFScriptPath, $IP,
			$wgForeignFileRepos, $wgUploadDirectory, $wgGenerateThumbnailOnParse, $wgLanguageConverterCacheType,
			$wgRevisionCacheExpiry, $wgMaxMsgCacheEntrySize, $wgMessageCacheType, $wgEnableSidebarCache,
			$wgExtensionFunctions, $wgCookiePrefix, $wgLabsDBprefix, $wgMainStash, $wgUseLocalMessageCache,
			$wgMainWANCache;

		# Basic stuff
		$wgDBname = $this->dbName;
		$wgCookiePrefix = $wgDBname;
		$wgConf = $this->conf;
		$wgLocalDatabases = $wgConf->getLocalDatabases(); # Setting as reference doesn't work?

		# Extract stuff from $wgConf
		list( $site, $lang ) = $wgConf->siteFromDB( $wgDBname );
		$wikiTags = array();
		foreach ( array(
			'private', 'fishbowl', 'special', 'closed', 'flaggedrevs',
			'small', 'medium', 'large', 'wikimania',
			'wikidata', 'wikidataclient',
			'visualeditor-default', 'commonsuploads', 'nonbetafeatures',
			'group0', 'wikipedia', 'arbitraryaccess', 'nonglobal',
		) as $tag ) {
			$dblist = array_map( 'trim', file( "$wgLabsConfigDir/$tag.dblist" ) );
			if ( in_array( $wgDBname, $dblist ) ) {
				$wikiTags[] = $tag;
			}
		}

		$dbSuffix = ( $site === 'wikipedia' ) ? 'wiki' : $site;
		$wgConf->loadFullData();
		$wgConf->extractAllGlobals( $wgDBname, $dbSuffix, array(
			'lang'    => $lang,
			'docRoot' => $_SERVER['DOCUMENT_ROOT'],
			'site'    => $site,
			'stdlogo' => "//upload.wikimedia.org/$site/$lang/b/bc/Wiki.png",
		), $wikiTags );

		# Database
		$wgLBFactoryConf = array(
			'class' => 'LBFactory_Labs',
			'serverTemplate' => array(
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => 'labs',
				'flags' => DBO_DEFAULT,
			),
		);
		foreach ( array_merge( $wgConf->wikis, array( 'centralauth' ) ) as $wiki ) {
			if ( $wiki == $wgDBname ) {
				$wgLBFactoryConf['sectionLoads']['DEFAULT']["$wiki.labsdb"] = 0;
				$wgLBFactoryConf['templateOverridesByServer']["$wiki.labsdb"] = array(
					'dbname' => "{$wgLabsDBprefix}{$wgDBname}",
				);
			} else {
				$wgLBFactoryConf['sectionsByDB'][$wiki] = $wiki;
				$wgLBFactoryConf['sectionLoads'][$wiki]["$wiki.labsdb"] = 0;
				$wgLBFactoryConf['templateOverridesByServer']["$wiki.labsdb"] = array(
					'dbname' => "{$wiki}_p",
				);
			}
		}
		$wgSharedDB = "{$wgDBname}_p";
		$wgSharedTables = array(
			'category', 'categorylinks', 'change_tag', 'externallinks', 'image', 'imagelinks', 'interwiki',
			'ipblocks_ipindex' => 'ipblocks', 'iwlinks', 'langlinks', 'links', 'logging_userindex' => 'logging',
			'oldimage', 'page', 'page_props', 'page_restrictions', 'pagelinks', 'protected_titles',
			'recentchanges', 'redirect', 'revision_userindex' => 'revision',
			'site_identifiers', 'site_stats', 'sites', 'tag_summary', 'templatelinks', 'updatelog',
			'user', 'user_former_groups', 'user_groups', 'user_properties', 'valid_tag',
			/* Wikibase: */ 'wb_changes', 'wb_changes_dispatch', 'wb_property_info',
			'wb_entity_per_page', 'wb_id_counters', 'wb_items_per_site', 'wb_terms',
			/* Extensions: */ 'global_block_whitelist', 'math',
		);

		# Disable some database operations
		$wgDisableCounters = true;
		$wgDisableAnonTalk = true;
		unset( $wgSpecialPageCacheUpdates['Statistics'] );
		# Caching fails because rev_text_id is always 0
		$wgRevisionCacheExpiry = 0;
		$wgMaxMsgCacheEntrySize = -1;
		# Nobody clears cache on message change
		$wgMessageCacheType = CACHE_NONE;
		$wgUseLocalMessageCache = false;
		$wgMainWANCache = CACHE_NONE;
		$wgLanguageConverterCacheType = CACHE_NONE;
		$wgEnableSidebarCache = false;
		# Use DB objectcache
		$wgMainStash = 'db-replicated';

		# Remote
		$wgWMFServer = $wgServer; # $wgServer was set by $wgConf
		$wgWMFCanonicalServer = $wgCanonicalServer; # Set by $wgConf
		$wgServer = WebRequest::detectServer();
		$wgCanonicalServer = false;
		$wgWMFScriptPath = '/w';

		# Media
		$wgForeignFileRepos[] = array(
			'class' => 'ForeignDBRepo',
			'name' => 'shared',
			'url' => "//upload.wikimedia.org/wikipedia/commons",
			'hashLevels' => 2,
			'thumbScriptUrl' => false,
			'transformVia404' => true,
			'hasSharedCache' => false,
			'descBaseUrl' => "//commons.wikimedia.org/wiki/File:",
			'scriptDirUrl' => "//commons.wikimedia.org/w",
			'fetchDescription' => true,
			'dbType' => 'labs',
			'dbServer' => 'commonswiki.labsdb',
			'dbUser' => $wgDBuser,
			'dbPassword' => $wgDBpassword,
			'dbName' => 'commonswiki_p',
			'dbFlags' => DBO_DEFAULT,
			'tablePrefix' => '',
			'initialCapital' => true,
			'abbrvThreshold' => 160,
			'directory' => "$IP/images/commonswiki",
		);
		$wgUploadDirectory = "$IP/images/$wgDBname";
		$wgUploadPath = "//upload.wikimedia.org/$site/$lang";
		$wgGenerateThumbnailOnParse = false;

		// Wikibase
		$wgExtensionFunctions[] = function() {
			if ( class_exists( 'Wikibase\Settings' ) ) {
				Wikibase\Settings::singleton()->setSetting( 'sharedCacheType', false );
			}
		};

		foreach ( $this->settings as $setting => $value ) {
			$GLOBALS[$setting] = $value;
		}
	}

	function replag() {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->selectField( 'recentchanges', 'UNIX_TIMESTAMP() - UNIX_TIMESTAMP( MAX( rc_timestamp ) )' );
	}

	function request( $postData = null, $cookieJar = null, $method = 'POST', $format = 'json', $path = 'api.php' ) {
		global $wgWMFCanonicalServer, $wgWMFScriptPath;

		if ( $path === 'api.php' && $format === 'json' ) {
			$path .= '?format=json';
		}
		$url = "$wgWMFCanonicalServer$wgWMFScriptPath/$path";
		$debug = "WMF server request: $url";
		$req = MWHttpRequest::factory( $url, array( 'method' => $method ) );
		if ( isset( $this->userInfo['oauth'] ) ) {
			$api_req = OAuthRequest::from_consumer_and_token(
				$this->userInfo['oauth'],
				$this->userInfo['oauth_ac'],
				$method, $url, $postData
			);
			$api_req->sign_request(
				$this->userInfo['oauth_method'],
				$this->userInfo['oauth'],
				$this->userInfo['oauth_ac']
			);
			$header = explode( ': ', $api_req->to_header(), 2 );
			$req->setHeader( $header[0], $header[1] );
			$debug .= "; OAuth header: {$header[0]}: {$header[1]}";
			if ( is_array( $postData ) ) {
				$postData = wfArrayToCgi( $postData );
			}
		}
		if ( !is_null( $postData ) ) {
			$req->setData( $postData );
			$debug .= '; POSTDATA: ' . ( is_array( $postData ) ? wfArrayToCgi( $postData ) : $postData );
		}
		if ( !is_null( $cookieJar ) ) {
			$req->setCookieJar( $cookieJar );
		}
		wfDebug( $debug );

		$status = $req->execute();
		if ( !$status->isOK() ) {
			wfDebug( "; failed.\n" );
			return null;
		}

		$content = $req->getContent();
		wfDebug( "; response: $content\n" );
		if ( $format === 'json' ) {
			$content = FormatJson::decode( $content );
		}
		return $content;
	}

	function apiRequest( $origdata, $loggedIn = true ) {
		if ( $loggedIn ) {
			if ( is_null( $this->cookieJar ) ) {
				$this->login();
			}
		}
		while ( true ) {
			$data = $origdata;
			if ( is_array( $data ) ) {
				foreach ( $data as $key => &$value ) {
					if ( is_array( $value ) ) {
						switch ( $value['type'] ) {
						case 'token':
							$value = $this->token( $value['token'], $value );
							if ( $value === false ) {
								return null;
							}
							break;
						default:
							unset( $data[$key] );
						}
					}
				}
			}
			$resp = $this->request( $data, $this->cookieJar );
			if ( isset( $resp->error ) && $resp->error->code === 'badtoken' ) {
				$this->login();
				continue;
			}
			return $resp;
		}
	}

	function login( $webInit = false ) {
		$cookieJar = new CookieJar();

		if ( isset( $this->userInfo['password'] ) ) {
			# Login token
			$lgresp = $this->request( array(
				'action' => 'login',
				'lgname' => $this->userInfo['username'],
				'lgpassword' => $this->userInfo['password'],
			), $cookieJar );

			# Real login
			$lgresp = $this->request( array(
				'action' => 'login',
				'lgname' => $this->userInfo['username'],
				'lgpassword' => $this->userInfo['password'],
				'lgtoken' => $lgresp->login->token,
			), $cookieJar );

			# Confirm we've already logged in
			if ( $lgresp->login->result !== 'Success' ) {
				throw new MWException( 'API login failed.' );
			}
		} elseif ( isset( $this->userInfo['oauth_token'] ) && isset( $this->userInfo['oauth_secret'] ) ) {
			global $IP, $wgLabsOAuthConsumerToken, $wgLabsOAuthSecretToken, $wgLabs;
			require_once "$IP/extensions/OAuth/lib/OAuth.php";
			$this->userInfo['oauth'] = new OAuthConsumer( $wgLabsOAuthConsumerToken, $wgLabsOAuthSecretToken );
			$this->userInfo['oauth_ac'] = new OAuthConsumer(
				$this->userInfo['oauth_token'], $this->userInfo['oauth_secret']
			);
			$hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
			$this->userInfo['oauth_method'] = $hmac_method;

			$resp = $this->apiRequest( array(
				'action' => 'query',
				'meta' => 'userinfo',
			), false );

			if ( $webInit ) {
				if ( isset( $resp->query->userinfo->id ) && $resp->query->userinfo->id !== 0 ) {
					$this->user = User::newFromId( $resp->query->userinfo->id );
					$this->userInfo['username'] = $resp->query->userinfo->name;
				} else {
					unset( $this->userInfo['oauth'] );
					unset( $this->userInfo['oauth_ac'] );
					unset( $this->userInfo['oauth_method'] );
				}
			} elseif ( !isset( $resp->query->userinfo->name ) || $resp->query->userinfo->name !== $wgLabs->user->getName() ) {
				unset( $this->userInfo['oauth'] );
				unset( $this->userInfo['oauth_ac'] );
				unset( $this->userInfo['oauth_method'] );
				throw new MWException( 'Incorrect OAuth data.' );
			}
		} else {
			throw new MWException( 'No valid authencation method configured.' );
		}
		$this->cookieJar = $cookieJar;
		$this->token = array();
	}

	function loadWebUser() {
		$context = RequestContext::getMain();
		$request = $context->getRequest();
		$tokenKey = $request->getCookie( 'labsOAuthToken', '' );
		$tokenSecret = $request->getCookie( 'labsOAuthSecret', '' );
		if ( $tokenKey !== null && $tokenSecret !== null ) {
			$this->userInfo['oauth_token'] = $tokenKey;
			$this->userInfo['oauth_secret'] = $tokenSecret;
			$this->login( true );
		}
	}

	function onUserLoadFromSession( $user, &$result ) {
		$this->loadWebUser();
		if ( $this->user !== null ) {
			$user->setId( $this->user->getId() );
			$result = true;
		}
		return true;
	}

	function onUserLoginForm( &$template ) {
		global $wgLabsOAuthScript;
		$request = RequestContext::getMain()->getRequest();
		$returnTo = $request->getVal( 'returnto', '' );
		$returnToTitle = Title::newFromText( $returnTo );
		if ( !$returnToTitle || $returnToTitle->isSpecial( 'Userlogout' ) ) {
			$returnToTitle = Title::newMainPage();
		}
		$returnToUrl = $returnToTitle->getFullURL( $request->getVal( 'returntoquery', '' ) );
		RequestContext::getMain()->getOutput()->redirect(
			wfAppendQuery( $wgLabsOAuthScript, array( 'return_to' => $returnToUrl ) )
		);
		return true;
	}

	function onUserLogoutComplete( &$user, &$injected_html, $oldName ) {
		$response = RequestContext::getMain()->getRequest()->response();
		$cookieOptions = array( 'prefix' => '' );
		$response->setCookie( 'labsOAuthToken', '', -1, $cookieOptions );
		$response->setCookie( 'labsOAuthSecret', '', -1, $cookieOptions );
	}

	function token( $type = 'edit', $data = array() ) {
		static $anon = null;
		if ( !$anon ) {
			$anon = new User();
		}
		if ( !isset( $this->token[$type] ) ) {
			$rbretry = false;
			while ( true ) {
				if ( $type == 'rollback' ) {
					$resp = $this->apiRequest( array(
						'action' => 'query',
						'prop' => 'revisions',
						'rvtoken' => 'rollback',
						'titles' => $data['page']->getTitle()->getPrefixedText(),
						'indexpageids' => '',
					) );
					if ( isset( $resp->query ) ) {
						$query = $resp->query;
						$page = $query->pages->{$query->pageids[0]};
						if ( isset( $page->revisions ) ) {
							$rev = $page->revisions[0];
							if ( $rev->user !== $data['user']->getName() ) {
								return false;
							}
							if ( isset( $rev->rollbacktoken ) ) {
								if ( $anon->matchEditToken( $rev->rollbacktoken ) ) {
									$rbretry = false;
									$this->login();
									continue;
								}
								return $rev->rollbacktoken;
							}
							if ( $rbretry ) {
								# no permission?
								return false;
							} else {
								# not logged in?
								$rbretry = true;
								$this->login();
								continue;
							}
						} else {
							# no such page?
							return false;
						}
					} else {
						continue;
					}
				}
				if ( $anon->matchEditToken(
					$this->token[$type] = $this->apiRequest( array(
						'action' => 'tokens',
						'type' => $type,
					) )->tokens->{"{$type}token"}
				) ) {
					$this->login();
				} else {
					break;
				}
			}
		}
		return $this->token[$type];
	}

	function installHooks() {
		global $wgHooks;

		if ( defined( 'MEDIAWIKI_INSTALL' ) ) {
			return;
		}

		$wgHooks['PageContentSave'][] = $this;
		$wgHooks['ArticlePurge'][] = $this;
		$wgHooks['LinksUpdate'][] = $this;
		$wgHooks['ArticleDelete'][] = $this;
		$wgHooks['MessagesPreLoad'][] = $this;
		$wgHooks['TitleMove'][] = $this;
		$wgHooks['ArticleRollback'][] = $this;
		$wgHooks['UserEffectiveGroups'][] = $this;
		$wgHooks['SkinTemplateToolboxEnd'][] = $this;
		$wgHooks['UserLoadFromSession'][] = $this;
		$wgHooks['UserLoginForm'][] = $this;
		$wgHooks['UserLogoutComplete'][] = $this;
		$wgHooks['TestCanonicalRedirect'][] = $this;
		$wgHooks['PageArchive::undelete'][] = array( $this, 'onPageArchiveUndelete' );
		$wgHooks['ResourceLoaderGetConfigVars'][] = $this;
	}

	function getTags() {
		global $maintClass;
		return isset( $maintClass ) && in_array( $maintClass, ChangeTags::listExplicitlyDefinedTags() ) ? $maintClass : '';
	}

	function onPageContentSave( &$article, &$user, &$content, &$summary, $minor,
		$watchthis, $sectionanchor, &$flags, &$status, &$baseRevId = false
	) {
		global $wmgUseWikibaseRepo;
		if ( $wmgUseWikibaseRepo && $content instanceof Wikibase\EntityContent ) {
			return $this->wikibaseEntityContentSave( $article, $user, $content, $summary, $minor,
				$watchthis, $sectionanchor, $flags, $status, $baseRevId
			);
		}
		$baseRev = $baseRevId ? Revision::newFromId( $baseRevId ) : false;
		$text = $content->serialize();
		$resp = $this->apiRequest( array(
			'action' => 'edit',
			'title' => $article->getTitle()->getPrefixedText(),
			'text' => $text,
			'token' => array(
				'type' => 'token',
				'token' => 'edit',
			),
			'summary' => $summary,
			( $minor ? 'minor' : 'notminor' ) => '',
			( $flags & EDIT_SUPPRESS_RC ? 'bot' : 'notbot' ) => '',
			( $flags & EDIT_NEW ? 'createonly' : 'notcreateonly' ) => '',
			( $flags & EDIT_UPDATE ? 'nocreate' : 'notnocreate' ) => '',
			'basetimestamp' => $baseRev ? $baseRev->getTimestamp() : '',
			'starttimestamp' => $baseRev ? $baseRev->getTimestamp() : '',
			'md5' => md5( $text ),
			'tags' => $this->getTags(),
		) );
		if ( $resp === null ) {
			$status->fatal( "edit-api-server-error" );
			return false;
		}
		if ( isset( $resp->error ) ) {
			$status->fatal( "edit-api-{$resp->error->code}" );
			return false;
		}
		if ( $resp->edit->result !== 'Success' ) {
			$status->fatal( 'edit-api-remote' );
			$status->setResult( false, $resp->edit );
		} else {
			$status->setResult( true, $resp->edit );
		}
		return false;
	}

	function wikibaseEntityContentSave( &$article, &$user, &$content, &$summary, $minor,
		$watchthis, $sectionanchor, &$flags, &$status, &$baseRevId = false
	) {
		if ( $content->isRedirect() ) {
			# wbcreateredirect works on empty entities only.
			# Go through this function to clear it first.
			$factory = Wikibase\Repo\WikibaseRepo::getDefaultInstance()->getEntityFactory(); # WARNING: deprecated!
			$entity = $factory->newEmpty( $content->getEntityId()->getEntityType() );
			$entity->setId( $content->getEntityId() );
		} else {
			$entity = $content->getEntity();
		}
		$serializer = Wikibase\Repo\WikibaseRepo::getDefaultInstance()->getInternalEntitySerializer();
		$serialized = $serializer->serialize( $entity );
		# https://bugzilla.wikimedia.org/show_bug.cgi?id=54146
		unset( $serialized['id'] );
		unset( $serialized['type'] );
		if ( isset( $serialized['claims'] ) ) {
			foreach ( $serialized['claims'] as &$claims ) {
				foreach ( $claims as &$claim ) {
					unset( $claim['id'] );
				}
			}
		}
		$resp = $this->apiRequest( array(
			'action' => 'wbeditentity',
			'id' => $entity->getId()->getSerialization(),
			( $baseRevId !== false ? 'baserevid' : 'notbaserevid' ) => $baseRevId,
			'data' => FormatJson::encode( $serialized ),
			'token' => array(
				'type' => 'token',
				'token' => 'edit',
			),
			'summary' => $summary,
			'clear' => '',
			( $flags & EDIT_SUPPRESS_RC ? 'bot' : 'notbot' ) => '',
		) );
		if ( $resp === null ) {
			$status->fatal( "edit-api-server-error" );
			$status->setResult( false, $resp );
			return false;
		}
		if ( isset( $resp->error ) ) {
			$status->fatal( "edit-api-{$resp->error->code}" );
			$status->setResult( false, $resp );
			return false;
		}
		if ( !isset( $resp->success ) ) {
			$status->fatal( 'edit-api-remote' );
			$status->setResult( false, $resp );
			return false;
		}
		if ( $content->isRedirect() ) {
			return $this->wikibaseEntityRedirectContentSave( $article, $user, $content, $summary,
				$minor, $watchthis, $sectionanchor, $flags, $status, $baseRevId
			);
		}
		$status->setResult( true, array(
			'revision' => Revision::newFromId( $resp->entity->lastrevid ),
		) );
		return false;
	}

	function wikibaseEntityRedirectContentSave( &$article, &$user, &$content, &$summary, $minor,
		$watchthis, $sectionanchor, &$flags, &$status, &$baseRevId = false
	) {
		$redirect = $content->getEntityRedirect();
		$resp = $this->apiRequest( array(
			'action' => 'wbcreateredirect',
			'from' => $redirect->getEntityId()->getSerialization(),
			'to' => $redirect->getTargetId()->getSerialization(),
			'token' => array(
				'type' => 'token',
				'token' => 'edit',
			),
			( $flags & EDIT_SUPPRESS_RC ? 'bot' : 'notbot' ) => '',
		) );
		if ( $resp === null ) {
			$status->fatal( "edit-api-server-error" );
			$status->setResult( false, $resp );
			return false;
		}
		if ( isset( $resp->error ) ) {
			$status->fatal( "edit-api-{$resp->error->code}" );
			$status->setResult( false, $resp );
			return false;
		}
		if ( !isset( $resp->success ) ) {
			$status->fatal( 'edit-api-remote' );
			$status->setResult( false, $resp );
			return false;
		}
		$status->setResult( true );
		return false;
	}

	function onArticlePurge( $article, $title = null, $linksupdate = false ) {
		if ( is_null( $title ) ) {
			$title = $article->getTitle();
		}
		$resp = $this->apiRequest( array(
			'action' => 'purge',
			'titles' => $title->getPrefixedText(),
			( $linksupdate ? 'forcelinkupdate' : 'notforcelinkupdate' ) => '',
		# Log in and do this or we may hit the rate limiter
		), true );
		return false;
	}

	function onLinksUpdate( &$linksUpdate ) {
		$this->onArticlePurge( null, $linksUpdate->getTitle(), true );
		return false;
	}

	function onArticleDelete( &$article, &$user, &$reason, &$error, &$status ) {
		$resp = $this->apiRequest( array(
			'action' => 'delete',
			'title' => $article->getTitle()->getPrefixedText(),
			'token' => array(
				'type' => 'token',
				'token' => 'delete',
			),
			'reason' => $reason,
		) );
		if ( isset( $resp->error ) ) {
			$status->fatal( "delete-api-{$resp->error->code}" );
		}
		return false;
	}

	function onMessagesPreLoad( $title, &$message ) {
		global $wgLabsExtraMessageNS, $wgLabsExtraMessagePrefix;

		if ( $wgLabsExtraMessageNS < 0 ) {
			return true;
		}

		$title = Title::makeTitleSafe( $wgLabsExtraMessageNS, $wgLabsExtraMessagePrefix . lcfirst( $title ) );
		if ( $title->exists() ) {
			$content = Revision::newFromTitle( $title )->getContent();
			if ( $content instanceof TextContent ) {
				$message = $content->serialize();
			}
		}

		return true;
	}

	function onTitleMove( $title, $nt, &$auth, &$reason, &$createRedirect, &$success ) {
		$resp = $this->apiRequest( array(
			'action' => 'move',
			'from' => $title->getPrefixedText(),
			'to' => $nt->getPrefixedText(),
			'token' => array(
				'type' => 'token',
				'token' => 'move',
			),
			'reason' => $reason,
			( $createRedirect ? 'redirect' : 'noredirect' ) => '',
			'ignorewarnings' => '',
		) );
		if ( isset( $resp->error ) ) {
			$success = array(
				array( "move-api-{$resp->error->code}", $resp->error->info ),
			);
		} else {
			$success = true;
		}
		return false;
	}

	function onArticleRollback( $page, $fromP, $summary, $bot, &$result, &$resultDetails, $user ) {
		$resp = $this->apiRequest( array(
			'action' => 'rollback',
			'title' => $page->getTitle()->getPrefixedText(),
			'user' => $fromP,
			'token' => array(
				'type' => 'token',
				'token' => 'rollback',
				'page' => $page,
				'user' => User::newFromName( $fromP, false ),
			),
			'summary' => $summary,
			( $bot ? 'markbot' : 'nomarkbot' ) => '',
			'tags' => $this->getTags(),
		) );
		if ( isset( $resp->error ) ) {
			$result = array( array( $resp->error->code ) );
		} elseif ( isset( $resp->rollback ) ) {
			$result = array();
		} else {
			$result = array( array( 'unknown' ) );
		}
		return false;
	}

	function onUserEffectiveGroups( $user, &$aUserGroups ) {
		$msg = wfMessage( 'ts-user-groups-overrides' );
		if ( !$msg->exists() ) {
			return true;
		}
		$msg = $msg->inContentLanguage()->text();

		$aUserGroups = array_unique( $aUserGroups );
		foreach ( explode( "\n", $msg ) as $line ) {
			$pieces = explode( '|', ltrim( $line, '*' ) );
			$userId = intval( array_shift( $pieces ) );
			if ( $userId == $user->getId() ) {
				foreach ( $pieces as $groupOp ) {
					$groupOp = trim( $groupOp );
					if ( strlen( $groupOp ) < 2 ) {
						continue;
					}
					$groupOperator = $groupOp[0];
					$group = trim( substr( $groupOp, 1 ) );
					$pos = array_search( $group, $aUserGroups, true );
					switch ( $groupOperator ) {
					case '+':
						if ( $pos === false ) {
							$aUserGroups[] = $group;
						}
						break;
					case '-':
						if ( $pos !== false ) {
							unset( $aUserGroups[$pos] );
						}
						break;
					}
				}
			}
		}

		return true;
	}

	function onSkinTemplateToolboxEnd( $tpl ) {
		global $wgSitename, $wgWMFServer, $wgWMFScriptPath;

		echo $tpl->makeListItem( 'wmfserver', array(
			'id' => 't-wmfserver',
			'text' => $tpl->getSkin()->getContext()->getLanguage()->getArrow() . ' ' . $wgSitename,
			'href' => wfAppendQuery( $wgWMFServer . $wgWMFScriptPath,
				$tpl->getSkin()->getContext()->getRequest()->getValues() ),
		) );

		return true;
	}

	function onTestCanonicalRedirect( $request, $title, $output ) {
		return false;
	}

	function onPageArchiveUndelete( $pageArchive, $title, $timestamps, $comment, $fileVersions, $unsuppress, $user, &$result ) {
		$resp = $this->apiRequest( array(
			'action' => 'undelete',
			'title' => $title->getPrefixedText(),
			'reason' => $comment,
			( empty( $timestamps ) ? 'notimestamps' : 'timestamps' ) => implode( '|', $timestamps ),
			'token' => array(
				'type' => 'token',
				# https://bugzilla.wikimedia.org/show_bug.cgi?id=63475
				'token' => 'delete',
			),
		) );
		if ( isset( $resp->undelete ) ) {
			$result = array( $resp->undelete->revisions, $resp->undelete->fileversions, $resp->undelete->reason );
		}
		return false;
	}

	function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgWMFServer, $wgWMFCanonicalServer, $wgWMFScriptPath;

		$vars['wgWMFServer'] = $wgWMFServer;
		$vars['wgWMFCanonicalServer'] = $wgWMFCanonicalServer;
		$vars['wgWMFScriptPath'] = $wgWMFScriptPath;

		return true;
	}
}
