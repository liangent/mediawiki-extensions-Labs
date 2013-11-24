<?php

class SpecialLabsOAuth extends SpecialPage {
	function __construct() {
		global $IP, $wgWMFServer, $wgWMFScriptPath;
                parent::__construct( 'LabsOAuth' );
		require_once "$IP/extensions/OAuth/lib/OAuth.php";
		$this->server = wfExpandUrl( "$wgWMFServer$wgWMFScriptPath/index.php", PROTO_HTTPS );
        }

        function execute( $par ) {
		$this->setHeaders();

		wfSetupSession();
		if ( $this->getRequest()->getVal( 'oauth_verifier' ) === null ) {
			$this->executeInit();
		} else {
			$this->executeAuth();
		}
	}

	function executeInit() {
		global $wgLabsOAuthConsumerToken, $wgLabsOAuthSecretToken;
		$consumer = new OAuthConsumer( $wgLabsOAuthConsumerToken, $wgLabsOAuthSecretToken );

		$initiateParams = array( 'title' => 'Special:OAuth/initiate', 'format' => 'json', 'oauth_callback' => 'oob' );
		$initiateUrl = wfAppendQuery( $this->server, $initiateParams );
		$req_req = OAuthRequest::from_consumer_and_token( $consumer, NULL, "GET", $initiateUrl, $initiateParams );
		$hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
		$sig_method = $hmac_method;
		$req_req->sign_request( $sig_method, $consumer, NULL );
		$header = explode( ': ', $req_req->to_header(), 2 );
		$req = MWHttpRequest::factory( $initiateUrl );
		$req->setHeader( $header[0], $header[1] );
		if ( !( $req->execute()->isOK() ) ) {
			$this->getOutput()->addWikiMsg( 'labs-oauth-initiate-error' );
			return;
		}

		$token = FormatJson::decode( $req->getContent() );
		if ( !isset( $token->key ) || !isset( $token->secret ) ) {
			$this->getOutput()->addWikiMsg( 'labs-oauth-value-missing' );
			$this->getOutput()->addHTML( Html::element( 'pre', array(),
				FormatJson::encode( $token, true, FormatJson::ALL_OK )
		       	) );
			return;
		}
		$this->getRequest()->setSessionData( 'wsLabsOAuthTemporaryToken', $token );
		$authorizeParams = array(
			'title' => 'Special:OAuth/authorize',
			'oauth_token' => $token->key,
			'oauth_consumer_key' => $wgLabsOAuthConsumerToken,
		);
		$authorizeUrl = wfAppendQuery( $this->server, $authorizeParams );
		$returnTo = $this->getRequest()->getVal( 'return_to' );
		$this->getRequest()->setSessionData( 'wsLabsOAuthReturnTo', $returnTo );
		$this->getOutput()->redirect( $authorizeUrl );
	}

	function executeAuth() {
		global $wgLabsOAuthConsumerToken, $wgLabsOAuthSecretToken;
		$consumer = new OAuthConsumer( $wgLabsOAuthConsumerToken, $wgLabsOAuthSecretToken );
		$token = $this->getRequest()->getSessionData( 'wsLabsOAuthTemporaryToken' );
		if ( !isset( $token->key ) || !isset( $token->secret ) ) {
			$this->getOutput()->addWikiMsg( 'sessionfailure' );
			$this->getOutput()->addReturnTo( $this->getTitle() );
			return;
		}
		$rc = new OAuthConsumer( $token->key, $token->secret );

		$tokenParams = array( 'title' => 'Special:OAuth/token', 'format' => 'json' );
		$tokenUrl = wfAppendQuery( $this->server, $tokenParams );
		$tokenParams['oauth_verifier'] = $this->getRequest()->getVal( 'oauth_verifier' );
		$acc_req = OAuthRequest::from_consumer_and_token( $consumer, $rc, "GET", $tokenUrl, $tokenParams );
		$hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
		$sig_method = $hmac_method;
		$acc_req->sign_request( $sig_method, $consumer, $rc );
		$header = explode( ': ', $acc_req->to_header(), 2 );
		$req = MWHttpRequest::factory( $tokenUrl );
		$req->setHeader( $header[0], $header[1] );
		if ( !( $req->execute()->isOK() ) ) {
			$this->getOutput()->addWikiMsg( 'labs-oauth-token-error' );
			return;
		}

		$token = FormatJson::decode( $req->getContent() );
		if ( !isset( $token->key ) || !isset( $token->secret ) ) {
			$this->getOutput()->addWikiMsg( 'labs-oauth-value-missing' );
			$this->getOutput()->addHTML( Html::element( 'pre', array(),
				FormatJson::encode( $token, true, FormatJson::ALL_OK )
		       	) );
			return;
		}

		$tokenKey = $token->key;
		$tokenSecret = $token->secret;

		$returnTo = $this->getRequest()->getSessionData( 'wsLabsOAuthReturnTo' );
		if ( $returnTo !== null ) {
			$cookieOptions = array( 'prefix' => '' );
			$this->getRequest()->response()->setCookie( 'labsOAuthToken', $tokenKey, 0, $cookieOptions );
			$this->getRequest()->response()->setCookie( 'labsOAuthSecret', $tokenSecret, 0, $cookieOptions );
			$this->getOutput()->redirect( $returnTo );
		} else {
			$this->getOutput()->addWikiMsg( 'labs-oauth-done' );
			$this->getOutput()->addHTML( Html::element( 'pre', array(),
				"oauth_token = $tokenKey\noauth_secret = $tokenSecret"
			) );
		}
	}
}
