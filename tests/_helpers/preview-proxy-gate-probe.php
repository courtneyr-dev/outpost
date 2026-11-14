<?php
/**
 * Separate-process probe for T10-S4-29: runs Outpost_Preview_Endpoint's
 * safe_fetch() in a fresh PHP process so WordPress proxy constants can be
 * defined before WordPress loads, without leaking into the test run.
 *
 * Usage: php preview-proxy-gate-probe.php <wordpress-dir> <outpost.php> <proxy|control>
 * Prints one JSON object: proxy_enabled, http_request_args (calls into
 * WP_Http::request), transport_attempts (pre_http_request), result.
 *
 * @package Outpost
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput

if ( 'cli' !== PHP_SAPI || $argc < 4 ) {
	exit( 2 );
}
list( , $wordpress_dir, $plugin_file, $mode ) = $argv;

if ( 'proxy' === $mode ) {
	define( 'WP_PROXY_HOST', '127.0.0.1' );
	define( 'WP_PROXY_PORT', '9' );
}
$_SERVER['HTTP_HOST'] = 'localhost';
define( 'WP_USE_THEMES', false );
require rtrim( $wordpress_dir, '/' ) . '/wp-load.php';
require_once $plugin_file;

$counts = array(
	'http_request_args'  => 0,
	'transport_attempts' => 0,
);
add_filter(
	'http_request_args',
	static function ( $args ) use ( &$counts ) {
		++$counts['http_request_args'];
		return $args;
	},
	0
);
add_filter(
	'pre_http_request',
	static function () use ( &$counts ) {
		++$counts['transport_attempts'];
		return array(
			'headers'  => array( 'content-type' => 'text/html' ),
			'body'     => '<html><head><title>x</title></head></html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	0
);

$method = new ReflectionMethod( 'Outpost_Preview_Endpoint', 'safe_fetch' );
$method->setAccessible( true );
$result = $method->invoke( null, 'https://example.org/', array( 'text/html' ) );

echo wp_json_encode(
	array(
		'proxy_enabled'      => ( new WP_HTTP_Proxy() )->is_enabled(),
		'http_request_args'  => $counts['http_request_args'],
		'transport_attempts' => $counts['transport_attempts'],
		'result'             => is_wp_error( $result ) ? 'error:' . $result->get_error_code() : 'response:' . wp_remote_retrieve_response_code( $result ),
	)
);
