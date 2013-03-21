<?php

namespace li3_zendserver;

use lithium\core\Environment;
use lithium\net\http\Router;
use lithium\action\Request;

class ZendServer extends \lithium\core\Adaptable
{
	/**
	 * Stores configurations for cache adapters.
	 *
	 * @var object `Collection` of logger configurations.
	 */
	protected static $_configurations = array();
	
	static protected function _generateBaseDebugUrl(Request $request, $options = array()) {
		$request = clone $request;
		$url = Router::match($request->params, $request, array('absolute' => true));
		
		$config = static::config(Environment::get());
		
		if(!isset($config['debugger']) || !isset($config['debugger']['host'])) {
			throw new \Exception("You must provide a debugger.host value");
		}
		
		$debugger_host = $config['debugger']['host'];
		$debugger_port = isset($config['debugger']['port']) ? $config['debugger']['port'] : 10137;
		
		return $url . "?" .  http_build_query(array(
			'start_debug' => 1,
			'debug_host' => $debugger_host,
			'debug_port' => $debugger_port
		));
	}
	
	static public function generateProfilerUrl(Request $request, $options = array()) {
		
		$default = array(
			'start_profile' => 1,
			'debug_coverage' => 1,
			'debug_fastfile' => 1,
		);
		
		$baseUrl = static::_generateBaseDebugUrl($request, $options);
		
		$options += $default;
		return $baseUrl . "&" . http_build_query($options);
	}
	
	static public function generateDebugUrl(Request $request, $options = array()) {
		$default = array(
			'debug_fastfile' => 1,
			'debug_stop' => 1,
			'use_ssl' => 1,
			'no_remote' => 1,
		);
		
		$baseUrl = static::_generateBaseDebugUrl($request, $options);
		
		$options += $default;
		return $baseUrl . "&" . http_build_query($options);
	}
}