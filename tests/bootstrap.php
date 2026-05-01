<?php
/**
 * PHPUnit bootstrap for Outpost.
 *
 * Loads Composer's autoloader, primes WP_Mock, defines the WP-side constants
 * the SUT expects to be present, stubs the file-load-time WordPress functions
 * outpost.php calls (add_action, register_activation_hook, plugin_dir_path,
 * etc.) as no-ops, and then `require`s outpost.php so its procedural helpers
 * (`outpost_dependency_presentation`, `outpost_is_ready`, ...) are available
 * to unit tests. Integration tests load WordPress core separately and skip
 * the stubs.
 *
 * @package Outpost
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/stubs/wordpress/' );
}

// Stubs for the WordPress functions outpost.php calls at file-load time. These
// run before outpost.php is `require`d, so the bootstrap doesn't error on
// missing-function. Their bodies are intentionally no-ops — anything a test
// cares about gets mocked through WP_Mock::userFunction inside the test.
//
// Functions whose return value matters at load time (plugin_dir_*, plugin_basename)
// return deterministic test-shaped strings.
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'http://example.test/wp-content/plugins/outpost/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return 'outpost/outpost.php';
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): bool {
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): bool {
		return true;
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( ...$args ): void {
		// no-op for unit tests.
	}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( ...$args ): void {
		// no-op for unit tests.
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( ...$args ): void {
		// no-op; tests that care assert via WP_Mock::userFunction explicitly.
	}
}

// Load the bootstrap. This pulls in the constant block, the detector class,
// the companion-base class, and every procedural helper outpost.php defines.
require_once dirname( __DIR__ ) . '/outpost.php';

WP_Mock::bootstrap();
