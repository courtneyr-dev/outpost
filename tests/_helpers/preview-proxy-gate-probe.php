<?php
/**
 * Separate-process probe for T10-S4-29: sends a real preview request
 * (POST /outpost/v1/preview through REST dispatch, as an administrator's
 * cookie session with a valid REST nonce) in a fresh PHP process, so
 * WordPress proxy constants can be defined before WordPress loads without
 * leaking into the test run.
 *
 * Outpost and its required plugins load as active plugins through a
 * preinitialized `option_active_plugins` filter, so plugins_loaded and
 * rest_api_init run as they do on a site.
 *
 * Usage: php preview-proxy-gate-probe.php <wordpress-dir> <plugin-basename> <proxy|control>
 * Prints one JSON object: proxy_enabled, http_request_args (calls into
 * WP_Http::request), transport_attempts (pre_http_request), status, code.
 *
 * @package Outpost
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput

if ( 'cli' !== PHP_SAPI || $argc < 4 ) {
	exit( 2 );
}
list( , $wordpress_dir, $plugin_basename, $mode ) = $argv;

if ( 'proxy' === $mode ) {
	define( 'WP_PROXY_HOST', '127.0.0.1' );
	define( 'WP_PROXY_PORT', '9' );
}
$_SERVER['HTTP_HOST'] = 'localhost';
define( 'WP_USE_THEMES', false );
$GLOBALS['wp_filter'] = array(
	'option_active_plugins' => array(
		10 => array(
			array(
				'function'      => static function ( $active ) use ( $plugin_basename ) {
					return array_values( array_unique( array_merge( (array) $active, array( 'indieauth/indieauth.php', 'micropub/micropub.php', $plugin_basename ) ) ) );
				},
				'accepted_args' => 1,
			),
		),
	),
);
require rtrim( $wordpress_dir, '/' ) . '/wp-load.php';

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

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
$GLOBALS['wp_rest_auth_cookie'] = true;
$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );

$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
$request->set_param( 'url', 'https://example.org/' );
$response = rest_do_request( $request );
$data     = $response->get_data();

echo wp_json_encode(
	array(
		'proxy_enabled'      => ( new WP_HTTP_Proxy() )->is_enabled(),
		'outpost_loaded'     => class_exists( 'Outpost_Preview_Endpoint' ),
		'http_request_args'  => $counts['http_request_args'],
		'transport_attempts' => $counts['transport_attempts'],
		'status'             => $response->get_status(),
		'code'               => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
	)
);
