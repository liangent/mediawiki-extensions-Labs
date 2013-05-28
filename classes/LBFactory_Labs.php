<?php

class LBFactory_Labs extends LBFactory_Multi {
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
}
