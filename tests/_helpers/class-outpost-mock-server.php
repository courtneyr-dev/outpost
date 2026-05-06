<?php
/**
 * Outpost_Mock_Server (G99-mock-server).
 *
 * Test helper that wraps WireMock's admin API. Integration tests use
 * this to:
 *   - Register stubs (request matcher → response shape) before exercising
 *     the SUT.
 *   - Reset all stubs between tests.
 *   - Inspect what the SUT actually sent (assertion target).
 *
 * Lives at `tests/_helpers/` so it's only loaded by the integration
 * suite (production code never sees it). Operates against the WireMock
 * admin API at `OUTPOST_TEST_MOCK_SERVER_URL` + `/__admin/...`.
 *
 * Stub fixtures live at `tests/fixtures/mock-server/<provider>/<scenario>.json`
 * in WireMock's native stub format — no Outpost-specific wrapper.
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Mock_Server {

	private const ADMIN_PATH_MAPPINGS = '/__admin/mappings';
	private const ADMIN_PATH_REQUESTS = '/__admin/requests';

	/**
	 * Reset the mock server: delete all stubs + journaled requests.
	 * Called from each integration test's setUp() so tests don't share
	 * state.
	 *
	 * @since 0.1.79
	 */
	public static function reset(): void {
		self::ensure_configured();
		self::admin_request( 'DELETE', self::ADMIN_PATH_MAPPINGS );
		self::admin_request( 'DELETE', self::ADMIN_PATH_REQUESTS );
	}

	/**
	 * Register a stub directly. Method + URL are the matcher; the body
	 * array is WireMock's response shape (status, headers, jsonBody, etc.).
	 *
	 * @since 0.1.79
	 *
	 * @param string              $method   HTTP verb (GET/POST/etc.).
	 * @param string              $url_path Path the SUT will request.
	 * @param array<string,mixed> $response WireMock response object.
	 */
	public static function stub( string $method, string $url_path, array $response ): void {
		self::ensure_configured();
		$mapping = array(
			'request'  => array(
				'method' => strtoupper( $method ),
				'url'    => $url_path,
			),
			'response' => $response,
		);
		self::admin_request( 'POST', self::ADMIN_PATH_MAPPINGS, $mapping );
	}

	/**
	 * Register a stub from a fixture file. Path is relative to
	 * `tests/fixtures/mock-server/`.
	 *
	 * @since 0.1.79
	 *
	 * @param string $relative_path e.g. 'oauth/token-exchange-success.json'.
	 */
	public static function stub_from_fixture( string $relative_path ): void {
		self::ensure_configured();
		$mapping = self::load_fixture( $relative_path );
		self::admin_request( 'POST', self::ADMIN_PATH_MAPPINGS, $mapping );
	}

	/**
	 * Return all requests the mock server has received that match
	 * (method, url_path). Useful for asserting the SUT sent the
	 * expected upstream request.
	 *
	 * @since 0.1.79
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function received_requests( string $method, string $url_path ): array {
		self::ensure_configured();
		$body = self::admin_request( 'GET', self::ADMIN_PATH_REQUESTS );
		if ( ! is_array( $body ) ) {
			return array();
		}
		$entries = isset( $body['requests'] ) && is_array( $body['requests'] )
			? $body['requests']
			: array();
		$method  = strtoupper( $method );
		$matches = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$req = isset( $entry['request'] ) && is_array( $entry['request'] )
				? $entry['request']
				: array();
			$entry_method = isset( $req['method'] ) ? strtoupper( (string) $req['method'] ) : '';
			$entry_url    = isset( $req['url'] ) ? (string) $req['url'] : '';
			if ( $entry_method !== $method ) {
				continue;
			}
			// Strip query string for path-prefix comparison.
			$entry_path = strtok( $entry_url, '?' );
			if ( false === $entry_path ) {
				$entry_path = $entry_url;
			}
			if ( $entry_path !== $url_path ) {
				continue;
			}
			$matches[] = $req;
		}
		return $matches;
	}

	/**
	 * Load a fixture from disk into a WireMock mapping array.
	 *
	 * @since 0.1.79
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the fixture is missing or invalid JSON.
	 */
	public static function load_fixture( string $relative_path ): array {
		$base = self::fixtures_base_path();
		$full = $base . '/' . ltrim( $relative_path, '/' );
		if ( ! is_readable( $full ) ) {
			throw new \RuntimeException( 'Mock server fixture not readable: ' . $full );
		}
		$contents = file_get_contents( $full );
		if ( false === $contents ) {
			throw new \RuntimeException( 'Mock server fixture unreadable: ' . $full );
		}
		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Mock server fixture is not valid JSON: ' . $full );
		}
		return $decoded;
	}

	private static function fixtures_base_path(): string {
		return defined( 'OUTPOST_PLUGIN_DIR' )
			? rtrim( (string) constant( 'OUTPOST_PLUGIN_DIR' ), '/' ) . '/tests/fixtures/mock-server'
			: dirname( __DIR__, 1 ) . '/fixtures/mock-server';
	}

	private static function ensure_configured(): void {
		if ( ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
			throw new \RuntimeException(
				'Outpost_Mock_Server requires OUTPOST_TEST_MOCK_SERVER_URL to be defined. See tests/integration/bootstrap.php.'
			);
		}
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed>|null
	 */
	private static function admin_request( string $method, string $path, ?array $body = null ): ?array {
		$url = rtrim( (string) constant( 'OUTPOST_TEST_MOCK_SERVER_URL' ), '/' ) . $path;
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		// Bypass our own filter: admin requests target the mock server
		// directly, not an upstream that needs rewriting.
		remove_filter( 'pre_http_request', array( 'Outpost_Mock_Server_Filter', 'maybe_rewrite' ), 10 );
		$response = wp_remote_request( $url, $args );
		add_filter( 'pre_http_request', array( 'Outpost_Mock_Server_Filter', 'maybe_rewrite' ), 10, 3 );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				'Mock server admin request failed: ' . $response->get_error_message()
			);
		}
		$body_text = wp_remote_retrieve_body( $response );
		if ( '' === $body_text ) {
			return null;
		}
		$decoded = json_decode( $body_text, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
