<?php

class SpecialLabsOAuth extends SpecialPage {
	function __construct() {
		global $IP;
                parent::__construct( 'LabsOAuth' );
		require_once "$IP/extensions/OAuth/lib/OAuth.php";
        }

        function execute( $par ) {
                $this->setHeaders();
        }
}
