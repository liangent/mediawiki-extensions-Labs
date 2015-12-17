<?php

class LBFactory_Labs extends LBFactoryMulti {
	# To use a customized LoadBalancer class
	function newLoadBalancer( $template, $loads, $groupLoads ) {
		global $wgMasterWaitTimeout;
		$servers = $this->makeServerArray( $template, $loads, $groupLoads );
		$lb = new LoadBalancerLabs( array(
			'servers' => $servers,
			'masterWaitTimeout' => $wgMasterWaitTimeout
		));
		return $lb;
	}

	function newExternalLB( $cluster, $wiki = false ) {
		return $this->getMainLB( $wiki );
	}
}
