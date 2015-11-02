<?php

$IP = strval( getenv( 'MW_INSTALL_PATH' ) ) !== ''
	? getenv( 'MW_INSTALL_PATH' )
	: realpath( dirname( __FILE__ ) . '/../../' );

require_once( "$IP/maintenance/Maintenance.php" );

class DispatchRecentChanges extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->batch = 100;
		$this->maxlag = 0;
		$this->maxrclag = 0;
		$this->from = false;
		$this->mDescription = "Call every new recentchange entry with the some hook";
		$this->addOption( 'batch', 'Maximum size of every batch fetched from DB, default ' . $this->batch, false, true );
		$this->addOption( 'maxlag', 'Stop working when replication lag reaches this value', false, true );
		$this->addOption( 'maxrclag', 'Throw away all unprocessed RC entries when processing lag reaches this value', false, true );
		$this->addOption( 'from', 'Starting RC ID. All lag-skipping options will be ignored', false, true );
		$this->addOption( 'state', 'File to write RC ID to when processing of one RC entry is finished.', false, true );
		$this->addOption( 'delay', 'Delay processing of one RC entry until the next arrives. Resolves replication isolation issues.'  );
		$this->addOption( 'hook', 'Hook name to use to report RC entries. RecentChangeLabs by default.', false, true );
	}

	public function run() {
		global $wgLabs;

		$dbr = wfGetDB( DB_SLAVE );

		if ( $this->from !== false ) {
			$lastRcId = $this->from;
		} else if ( ( $lastRcId = $dbr->selectField( 'recentchanges', 'MAX(rc_id)' ) ) === false ) {
			$lastRcId = 0;
		}
		$this->output( "Starting from #$lastRcId:\n" );
		$outputContinue = false;
		$currRow = false;
		$prevRow = false;
		while ( true ) {
			if ( $outputContinue ) {
				$this->output( "Continuing from #$lastRcId:\n" );
			}
			$outputContinue = false;
			$res = $dbr->select( 'recentchanges', '*', "rc_id > $lastRcId", __METHOD__, array(
				'LIMIT' => $this->batch,
				'ORDER BY' => 'rc_id',
			) );
			while ( $currRow = $dbr->fetchObject( $res ) ) {
				$this->output( '[' . wfTimestamp( TS_DB ) . '] ' );
				if ( $this->delay ) {
					$row = $prevRow;
					$prevRow = $currRow;
					$this->output( "({$currRow->rc_id}) " );
				} else {
					$row = $currRow;
				}
				if ( !$row ) {
					$this->output( "skipped.\n" );
					continue;
				}
				$outputContinue = true;
				$this->cleanup();
				$this->output( "# {$row->rc_id}" );
				$rc = RecentChange::newFromRow( $row );
				$replag = $wgLabs->replag();
				if ( $this->maxlag > 0 && $replag > $this->maxlag && $this->from === false ) {
					$this->output( " ... Shutting down temporarily due to high replag $replag > maxlag {$this->maxlag}.\n" );
					return;
				}
				$rclag = wfTimestamp() - $replag - wfTimestamp( TS_UNIX, $row->rc_timestamp );
				if ( $this->maxrclag > 0 && $rclag > $this->maxrclag && $this->from === false ) {
					$this->output( " ... Restarting due to high rclag $rclag > maxrclag {$this->maxrclag}.\n" );
					return;
				}
				if ( $rc->getAttribute( 'rc_namespace' ) == NS_MEDIAWIKI ) {
					# I imagine this is the only sane way to clear cache, even for just one title?
					MessageCache::destroyInstance();
				}
				$this->output( ' @ ' . wfTimestamp( TS_DB, $row->rc_timestamp ) . ' ...' );
				if ( $this->hasOption( 'hook' ) ) {
					wfRunHooks( $this->getOption( 'hook' ), array( $rc, $this ) );
				} else {
					wfRunHooks( 'RecentChangeLabs', array( $rc, $this ) );
					wfRunHooks( 'RecentChangeTs', array( $rc, $this ) );
				}
				$this->output( " done.\n" );
				if ( $this->hasOption( 'state' ) ) {
					file_put_contents( $this->getOption( 'state' ), $row->rc_id );
				}
				$lastRcId = $currRow->rc_id;
			}
		}
	}

	public function execute() {
		global $wgLabs;

		foreach ( array( 'batch', 'maxlag', 'maxrclag' ) as $option ) {
			if ( $this->hasOption( $option ) ) {
				$optvalue = intval( $this->getOption( $option ) );
				if ( $optvalue > 0 ) {
					$this->$option = $optvalue;
				}
			}
		}
		if ( $this->hasOption( 'from' ) ) {
			$this->from = $this->getOption( 'from' );
		}
		$this->delay = $this->hasOption( 'delay' );
		while ( true ) {
			$replag = $wgLabs->replag();
			if ( $this->maxlag > 0 && $replag > $this->maxlag && $this->from === false ) {
				$this->output( "Hold on because current replag $replag > maxlag {$this->maxlag}.\n" );
				sleep( 1 );
			} else {
				$this->run();
			}
		}
	}

	public function output( $out, $channel = null ) {
		parent::output( $out, $channel );
	}

	public function cleanup() {
		MessageCache::singleton()->getParserOptions()->setTimestamp( null );
		LinkCache::destroySingleton();
		Title::clearCaches();
	}
}

$maintClass = "DispatchRecentChanges";
require_once( RUN_MAINTENANCE_IF_MAIN );

