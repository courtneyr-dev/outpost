<?php
/**
 * Outpost_Mock_Server_Filter (G99-mock-server).
 *
 * Test-time HTTP rewriter. When the `OUTPOST_TEST_MOCK_SERVER_URL`
 * constant is defined (typically by integration-test bootstrap),
 * outbound HTTP to a hardcoded list of upstream API hosts gets
 * rewritten to point at the mock server instead. The mock server
 * (WireMock running alongside wp-env) returns fixture-defined
 * responses, so integration tests assert real WP-side roundtrip
 * behavior without hitting production APIs.
 *
 * Production safety:
 *   - The constant is NEVER defined in production environments.
 *     Setting it would require an explicit `define()` in wp-config.php.
 *   - The rewrite list is hardcoded (NOT a filter / option). Adding
 *     a new upstream means editing this file — intentional, prevents
 *     accidental rewriting from a third-party plugin.
 *   - When the constant IS defined and a request matches a rewrite
 *     host, the request always rewrites. There's no "fall through to
 *     real network" mode — if the mock server isn't running, tests
 *     fail loudly instead of silently making real API calls.
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Mock_Server_Filter {

	/**
	 * Hosts whose outbound HTTP gets rewritten when
	 * OUTPOST_TEST_MOCK_SERVER_URL is defined. Adding a new upstream
	 * (new OAuth provider, new RSS source) requires adding it here.
	 *
	 * @var string[]
	 */
	private const REWRITABLE_HOSTS = array(
		// G3.5a OAuth providers.
		'api.notion.com',
		'api.ouraring.com',
		'ridewithgps.com',
		'api.ravelry.com',
		'api.prod.whoop.com',
		'polarremote.com',
		'www.polaraccesslink.com',
		// G4 inbound (Apple Music + iTunes).
		'itunes.apple.com',
		// G10 scripture (placeholders for upcoming providers).
		'api.scripture.bible',
		// F-phase inbound — sources that hit upstreams directly.
		'open.spotify.com',
		'www.youtube.com',
		'youtube.com',
		'youtu.be',
	);

	/**
	 * Hook the rewrite filter. Called from outpost.php during plugins_loaded.
	 *
	 * @since 0.1.79
	 */
	public static function register(): void {
		add_filter( 'pre_http_request', array( __CLASS__, 'maybe_rewrite' ), 10, 3 );
	}

	/**
	 * Public for testing. The filter callback that pre_http_request
	 * dispatches to.
	 *
	 * @since 0.1.79
	 *
	 * @param mixed              $preempt Existing short-circuit value.
	 * @param array<string,mixed> $args    Request args.
	 * @param string             $url     Outbound URL.
	 * @return mixed Either the original $preempt or a wp_remote_request response.
	 */
	public static function maybe_rewrite( $preempt, array $args, string $url ) {
		if ( ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
			return $preempt;
		}
		// Defensive: another filter may have already preempted; respect it.
		if ( false !== $preempt && null !== $preempt ) {
			return $preempt;
		}

		$rewritten = self::rewrite_url_if_eligible( $url );
		if ( null === $rewritten ) {
			return $preempt;
		}

		// Recurse via wp_remote_request so the rewritten URL goes through
		// the normal HTTP pipeline (timeouts, headers, redirect handling).
		// Removing this filter prevents infinite recursion when the mock
		// server's URL ever appears in the rewrite list (defensive).
		remove_filter( 'pre_http_request', array( __CLASS__, 'maybe_rewrite' ), 10 );
		$response = wp_remote_request( $rewritten, $args );
		add_filter( 'pre_http_request', array( __CLASS__, 'maybe_rewrite' ), 10, 3 );
		return $response;
	}

	/**
	 * Compute the rewritten URL for an upstream URL, or null if not
	 * eligible. Public for testability — passing $mock_base_override
	 * lets tests exercise the matching logic without defining the
	 * production constant.
	 *
	 * @since 0.1.79
	 *
	 * @param string  $url                 Outbound URL.
	 * @param ?string $mock_base_override  When null, reads from the
	 *                                     OUTPOST_TEST_MOCK_SERVER_URL
	 *                                     constant; when string, uses
	 *                                     it directly (test seam).
	 */
	public static function rewrite_url_if_eligible( string $url, ?string $mock_base_override = null ): ?string {
		$mock_base_source = $mock_base_override;
		if ( null === $mock_base_source ) {
			if ( ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
				return null;
			}
			$mock_base_source = (string) constant( 'OUTPOST_TEST_MOCK_SERVER_URL' );
		}
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parsed['host'] );
		if ( ! in_array( $host, self::REWRITABLE_HOSTS, true ) ) {
			return null;
		}
		$mock_base = rtrim( $mock_base_source, '/' );
		$path      = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
		$query     = isset( $parsed['query'] ) ? '?' . (string) $parsed['query'] : '';
		return $mock_base . $path . $query;
	}

	/**
	 * Test introspection: the canonical list of hosts the filter rewrites.
	 *
	 * @since 0.1.79
	 *
	 * @return string[]
	 */
	public static function rewritable_hosts(): array {
		return self::REWRITABLE_HOSTS;
	}
}
