<?php

$IP = strval( getenv( 'MW_INSTALL_PATH' ) ) !== ''
        ? getenv( 'MW_INSTALL_PATH' )
        : realpath( dirname( __FILE__ ) . '/../../' );

require_once( "$IP/maintenance/Maintenance.php" );

define( 'MW_CONFIG_FILE', "$IP/extensions/Labs/InstallerSettings.php" );
define( 'MEDIAWIKI_INSTALL', true );

class LabsCommandLineInstaller extends Maintenance {
	function execute() {
		global $wgDBname, $wgUploadDirectory;

		$dbServer = wfGetLB()->getServerInfo( 0 );
		$db = DatabaseBase::factory( $dbServer['type'], array(
			'foreign' => true,
			'dbname' => false,
		) + $dbServer );

		if ( !$db->selectDB( $dbServer['dbname'] ) ) {
			$db->query( "CREATE DATABASE " . $db->addIdentifierQuotes( $dbServer['dbname'] ), __METHOD__ );
			$db->selectDB( $dbServer['dbname'] );
		}

		$db->begin( __METHOD__ );
		$error = $db->sourceFile( $db->getSchemaPath() );
		if ( $error !== true ) {
			$db->reportQueryError( $error, 0, '', __METHOD__ );
			$db->rollback( __METHOD__ );
		} else {
			$this->output( "$wgDBname installed successfully.\n" );
			$db->commit( __METHOD__ );
		}

		mkdir( $wgUploadDirectory );
	}
}

$maintClass = "LabsCommandLineInstaller";

require_once( RUN_MAINTENANCE_IF_MAIN );
