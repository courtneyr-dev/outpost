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

// WordPress time constants used by Phase F detectors.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Suppress the post-render `exit` in Outpost_PWA_Shell::halt() so unit tests
// can capture render output via ob_start without aborting PHPUnit.
if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
	define( 'OUTPOST_TESTING_PWA_SHELL', true );
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
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ): string {
		// Test stub matching WP's two-arg form: add_query_arg($key, $value, $url).
		// Real WP supports many shapes; we only need the simple shape Outpost calls.
		if ( count( $args ) >= 3 ) {
			$key   = (string) $args[0];
			$value = (string) $args[1];
			$url   = (string) $args[2];
			$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';
			return $url . $separator . $key . '=' . rawurlencode( $value );
		}
		return (string) ( $args[0] ?? '' );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		// Test stub. Real WP uses random_bytes; we only need a stable
		// shape (8-4-4-4-12 hex) so CSP nonce assertions can match.
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
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
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		// WordPress's wp_parse_url is a thin wrapper around parse_url with
		// edge-case handling we don't exercise in unit tests; parse_url is
		// enough for the validate_url path.
		return parse_url( $url, $component );
	}
}

// Minimal WP_Error stub for unit tests. Real WP supplies the full class via
// wp-includes/class-wp-error.php; here we just need code/message/data round-trip.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors     = array();
		private array $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );
			return (string) ( $codes[0] ?? '' );
		}

		public function get_error_message( $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			$messages = $this->errors[ $code ] ?? array();
			return (string) ( $messages[0] ?? '' );
		}

		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return $this->error_data[ $code ] ?? null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// Minimal WP_REST_Request stub so PHPUnit's mock machinery (createMock) has a
// class to reflect against.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function get_param( $key ) {
			return null;
		}
	}
}

// Minimal WP_Post stub for unit tests. Real WP supplies the full class; F10
// IntentPayloadBuilder + future post-meta tests need a constructable object
// whose properties round-trip through `instanceof WP_Post` checks.
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID                = 0;
		public string $post_title     = '';
		public string $post_content   = '';
		public string $post_status    = '';
		public string $post_type      = '';
		public int $post_parent       = 0;
		public string $post_mime_type = '';

		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}

// Minimal WP_Term stub for unit tests. Real WP supplies the full class; bridge
// + composer-config tests only need a constructable object whose properties
// round-trip through `instanceof WP_Term` checks.
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id   = 0;
		public string $name   = '';
		public string $slug   = '';
		public int $count     = 0;
		public string $taxonomy = '';

		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}

// Minimal WP_REST_Response stub. The real class extends WP_HTTP_Response with
// status code, headers, and data accessors; tests only need round-trip of data
// + status.
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private mixed $data;
		private int $status;
		private array $headers = array();

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( string $key, string $value, bool $replace = true ): void {
			if ( $replace || ! isset( $this->headers[ $key ] ) ) {
				$this->headers[ $key ] = $value;
			}
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

// Load the bootstrap. This pulls in the constant block, the detector class,
// the companion-base class, and every procedural helper outpost.php defines.
require_once dirname( __DIR__ ) . '/outpost.php';

WP_Mock::bootstrap();
