<?php

$wgAutoloadClasses['Labs'] = dirname( __FILE__ ) . '/classes/Labs.php';
$wgAutoloadClasses['DatabaseLabs'] = dirname( __FILE__ ) . '/classes/DatabaseLabs.php';
$wgAutoloadClasses['LBFactory_Labs'] = dirname( __FILE__ ) . '/classes/LBFactory_Labs.php';
$wgAutoloadClasses['LoadBalancerLabs'] = dirname( __FILE__ ) . '/classes/LoadBalancerLabs.php';
$wgAutoloadClasses['RemoteUtils'] = dirname( __FILE__ ) . '/classes/RemoteUtils.php';
$wgAutoloadClasses['Wikibase\LabsStore'] = dirname( __FILE__ ) . '/classes/WikibaseLabsStore.php';
$wgAutoloadClasses['Wikibase\LabsIdGenerator'] = dirname( __FILE__ ) . '/classes/WikibaseLabsIdGenerator.php';

$wgExtensionFunctions[] = function() {
	global $wgLabs;
	$wgLabs->user = User::newFromName( $wgLabs->userInfo['username'] );
};

function wfReplag() {
	global $wgLabs;
	return $wgLabs->replag();
}

$wgLabsConfigDir = dirname( __FILE__ ) . '/config';
$wgLabsUsers = array();
$wgLabsExtraMessageNS = NS_SPECIAL;
$wgLabsExtraMessagePrefix = '';
$wgLabsAcceptedSettings = array(
	'wgUseDatabaseMessages' => 'bool',
	'wgDisabledVariants' => 'array',
);
