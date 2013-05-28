<?php

class LoadBalancerLabs extends LoadBalancer {
	# Ignore $dbNameOverride. We've already specified dbname per server
	function reallyOpenConnection( $server, $dbNameOverride = false ) {
		return parent::reallyOpenConnection( $server );
	}
}
