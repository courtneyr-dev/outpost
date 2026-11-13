<?php
/**
 * Regression guards for the WordPress.org plugin review of 2026-09-14.
 *
 * Each test scans the shipped PHP source with PHP's own tokenizer, so a
 * mention in a comment or docblock never trips it; only code does.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WporgReviewGuardsTest extends TestCase {

	/**
	 * Every shipped PHP file: the main file, uninstall.php, and includes/.
	 *
	 * @return array<string, array<int, mixed>> Path relative to the plugin root => tokens.
	 */
	private static function shipped_tokens(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/outpost.php', $root . '/uninstall.php' );
		$iter  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		$out = array();
		foreach ( $files as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $path ) ),
					static fn( $t ) => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
				)
			);
			$out[ substr( $path, strlen( $root ) + 1 ) ] = $tokens;
		}
		return $out;
	}

	private static function text( $token ): string {
		return is_array( $token ) ? $token[1] : $token;
	}

	public function test_scan_covers_the_plugin_source(): void {
		$files = array_keys( self::shipped_tokens() );
		$this->assertContains( 'includes/trait-bearer-auth.php', $files );
		$this->assertContains( 'includes/class-preview-endpoint.php', $files );
		$this->assertGreaterThan( 50, count( $files ) );
	}

	public function test_plugin_never_switches_the_current_user(): void {
		$calls = array();
		foreach ( self::shipped_tokens() as $file => $tokens ) {
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && T_STRING === $token[0] && 'wp_set_current_user' === strtolower( $token[1] ) && '(' === self::text( $tokens[ $i + 1 ] ?? '' ) ) {
					$calls[] = $file . ':' . $token[2];
				}
			}
		}
		$this->assertSame( array(), $calls, 'wp_set_current_user() must not be called; WordPress resolves the user through determine_current_user.' );
	}

	/**
	 * 2026-09-14 review, "Creating / login users": the plugin must not run its
	 * own login. No hook on the user-resolution or authentication filters, and
	 * none of core's login/user-creation functions. (The iOS Shortcut uses a
	 * core application password; its route limit hooks
	 * wp_authenticate_application_password_errors, which only refuses.)
	 */
	public function test_plugin_never_resolves_or_logs_in_users_itself(): void {
		$forbidden_hooks = array( 'determine_current_user', 'authenticate', 'rest_authentication_errors', 'wp_authenticate_user' );
		$forbidden_calls = array( 'wp_set_auth_cookie', 'wp_signon', 'wp_create_user', 'wp_insert_user' );
		$found           = array();
		foreach ( self::shipped_tokens() as $file => $tokens ) {
			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] || '(' !== self::text( $tokens[ $i + 1 ] ?? '' ) ) {
					continue;
				}
				$name = strtolower( $token[1] );
				if ( in_array( $name, $forbidden_calls, true ) ) {
					$found[] = $file . ':' . $token[2] . ' ' . $name . '()';
				}
				if ( in_array( $name, array( 'add_filter', 'add_action' ), true ) ) {
					$hook = trim( self::text( $tokens[ $i + 2 ] ?? '' ), "'\"" );
					if ( in_array( $hook, $forbidden_hooks, true ) ) {
						$found[] = $file . ':' . $token[2] . ' ' . $name . '( ' . $hook . ' )';
					}
				}
			}
		}
		$this->assertSame( array(), $found, 'Login belongs to WordPress core; the Plugins Team rejects plugin-defined login paths.' );
	}

	/**
	 * T1 and T9: json_decode() of a request body read raw from php://input.
	 * REST routes get their body from WP_REST_Request instead.
	 */
	public function test_no_raw_request_body_reads(): void {
		$found = array();
		foreach ( self::shipped_tokens() as $file => $tokens ) {
			foreach ( $tokens as $token ) {
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && str_contains( $token[1], 'php://input' ) ) {
					$found[] = $file . ':' . $token[2];
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * Plugin Check runs its own ruleset and ignores phpcs.xml.dist exclusions,
	 * so every direct curl_*() call needs an inline phpcs:ignore that names
	 * the sniff and says why.
	 */
	public function test_every_curl_call_carries_an_inline_justification(): void {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		foreach ( array_keys( self::shipped_tokens() ) as $file ) {
			foreach ( (array) file( $root . '/' . $file ) as $n => $line ) {
				if ( preg_match( '/\bcurl_[a-z_]+\s*\(/', (string) $line ) && ! preg_match( '/phpcs:ignore WordPress\.WP\.AlternativeFunctions\.curl_[a-z_]+ -- \S/', (string) $line ) ) {
					$found[] = $file . ':' . ( $n + 1 );
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	public function test_no_json_unescaped_flags(): void {
		$uses = array();
		foreach ( self::shipped_tokens() as $file => $tokens ) {
			foreach ( $tokens as $token ) {
				if ( is_array( $token ) && T_STRING === $token[0] && str_starts_with( $token[1], 'JSON_UNESCAPED_' ) ) {
					$uses[] = $file . ':' . $token[2] . ' ' . $token[1];
				}
			}
		}
		$this->assertSame( array(), $uses );
	}

	public function test_every_echoed_wp_json_encode_hex_escapes_markup(): void {
		$found  = 0;
		$failed = array();
		foreach ( self::shipped_tokens() as $file => $tokens ) {
			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || T_ECHO !== $token[0] || 'wp_json_encode' !== self::text( $tokens[ $i + 1 ] ?? '' ) ) {
					continue;
				}
				++$found;
				$statement = '';
				for ( $j = $i + 1; isset( $tokens[ $j ] ) && ';' !== self::text( $tokens[ $j ] ); $j++ ) {
					$statement .= self::text( $tokens[ $j ] );
				}
				foreach ( array( 'JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT' ) as $flag ) {
					if ( ! str_contains( $statement, $flag ) ) {
						$failed[] = $file . ':' . $token[2] . ' lacks ' . $flag;
					}
				}
			}
		}
		$this->assertGreaterThan( 0, $found, 'Expected the manifest echo.' );
		$this->assertSame( array(), $failed );
	}

	public function test_superglobals_are_never_passed_whole_to_a_helper(): void {
		$passed = array();
		foreach ( self::shipped_tokens() as $file => $tokens ) {
			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || ! in_array( $token[1], array( '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_COOKIE' ), true ) ) {
					continue;
				}
				$prev = self::text( $tokens[ $i - 1 ] ?? '' );
				$next = self::text( $tokens[ $i + 1 ] ?? '' );
				// `helper( $_POST, 'key' )` or `helper( $_GET )`: a whole
				// superglobal handed off unsanitized. `empty( $_FILES )`
				// style checks are not in the list above.
				if ( in_array( $prev, array( '(', ',' ), true ) && in_array( $next, array( ',', ')' ), true ) ) {
					$passed[] = $file . ':' . $token[2] . ' ' . $token[1];
				}
			}
		}
		$this->assertSame( array(), $passed, 'Read single keys inside wp_unslash() and a sanitizer instead.' );
	}
}
