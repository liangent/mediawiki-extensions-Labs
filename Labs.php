<?php

$wgAutoloadClasses['Labs'] = dirname( __FILE__ ) . '/classes/Labs.php';
$wgAutoloadClasses['DatabaseLabs'] = dirname( __FILE__ ) . '/classes/DatabaseLabs.php';
$wgAutoloadClasses['LBFactory_Labs'] = dirname( __FILE__ ) . '/classes/LBFactory_Labs.php';
$wgAutoloadClasses['LoadBalancerLabs'] = dirname( __FILE__ ) . '/classes/LoadBalancerLabs.php';
$wgAutoloadClasses['RemoteUtils'] = dirname( __FILE__ ) . '/classes/RemoteUtils.php';
$wgAutoloadClasses['Wikibase\LabsIdGenerator'] = dirname( __FILE__ ) . '/classes/WikibaseLabsIdGenerator.php';
$wgAutoloadClasses['SpecialLabsOAuth'] = dirname( __FILE__ ) . '/specials/SpecialLabsOAuth.php';
$wgAutoloadClasses['LabsPageQueryPage'] = dirname( __FILE__ ) . '/classes/LabsPageQueryPage.php';

$wgExtensionMessagesFiles[ 'Labs' ] = __DIR__ . '/Labs.i18n.php';
$wgExtensionMessagesFiles[ 'LabsAlias' ] = __DIR__ . '/Labs.alias.php';

$wgSpecialPages[ 'LabsOAuth' ] = 'SpecialLabsOAuth';
$wgSpecialPageGroups[ 'LabsOAuth' ] = 'login';

$wgExtensionFunctions[] = function() {
	global $wgLabs;
	if ( php_sapi_name() === 'cli' ) {
		$wgLabs->user = User::newFromName( $wgLabs->userInfo['username'] );
	}
};

function wfReplag() {
	global $wgLabs;
	return $wgLabs->replag();
}

$wgLabsConfigDir = dirname( __FILE__ ) . '/config';
$wgLabsUsers = array();
$wgLabsExtraMessageNS = NS_SPECIAL;
$wgLabsExtraMessagePrefix = '';
$wgLabsDBprefix = null;
$wgLabsAcceptedSettings = array(
	'wgUseDatabaseMessages' => 'bool',
	'wgDisabledVariants' => 'array',
	'wgLabsOAuthConsumerToken' => 'string',
	'wgLabsOAuthSecretToken' => 'string',
);

$wgLabsOAuthConsumerToken = '';
$wgLabsOAuthSecretToken = '';
