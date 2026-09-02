<?php
/**
 * Integration test: preview-endpoint SSRF ceiling (item 5) over the real REST
 * dispatch, with mocked DNS + HTTP transports. No real network request is made.
 *
 * `outpost_resolve_host_ips` injects what a host "resolves" to (the DNS seam),
 * and `pre_http_request` is the HTTP seam: a spy that records every URL the HTTP
 * API is asked to fetch. The security assertion is that when a host resolves to
 * an internal address (link-local metadata, CGNAT, IPv6 loopback), the endpoint
 * fails closed and `pre_http_request` is NEVER reached — the request is refused
 * before any packet, and the error is generic (no resolved address, transport
 * message, or response fragment).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class PreviewSsrfTest extends TestCase {

	private int $editor_id = 0;

	/** @var string[] URLs the HTTP transport was asked to fetch. */
	private array $fetched = array();

	/** @var array<string,string[]> host => resolved IPs */
	private array $dns = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Preview_Endpoint' ) ) {
			$this->markTestSkipped( 'Run via `npm run test:integration`.' );
		}
		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 'ssrf_editor_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'ssrf_editor_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->editor_id );

		$this->fetched = array();
		$this->dns     = array();

		add_filter( 'outpost_resolve_host_ips', array( $this, 'resolve' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'spy_http' ), 10, 3 );
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		remove_filter( 'outpost_resolve_host_ips', array( $this, 'resolve' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'spy_http' ), 10 );
		delete_transient( 'outpost_preview_rl_' . $this->editor_id );
		if ( $this->editor_id ) {
			wp_delete_user( $this->editor_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** DNS seam. */
	public function resolve( array $ips, string $host ): array {
		return $this->dns[ $host ] ?? $ips;
	}

	/** HTTP seam: record the URL and return a canned 200 HTML response. */
	public function spy_http( $pre, $args, $url ) {
		$this->fetched[] = (string) $url;
		return array(
			'headers'  => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'     => '<html><head><title>Fetched</title></head><body><p>ok</p></body></html>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function dispatch( string $url ) {
		$req = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$req->set_body_params( array( 'url' => $url ) );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * @test
	 * @dataProvider internal_targets
	 */
	public function host_resolving_to_an_internal_address_is_refused_before_any_fetch( string $host, array $ips ): void {
		$this->dns[ $host ] = $ips;

		$response = $this->dispatch( 'https://' . $host . '/latest/meta-data/' );

		$this->assertCount( 0, $this->fetched, 'No HTTP request may be attempted for an internal destination.' );
		$this->assertTrue( $response->is_error(), 'The endpoint must return an error.' );
		$data = $response->get_data();
		// Generic: no resolved address, no transport detail.
		$encoded = wp_json_encode( $data );
		$this->assertStringNotContainsString( '169.254', (string) $encoded );
		$this->assertStringNotContainsString( '10.0.0', (string) $encoded );
		$this->assertArrayNotHasKey( 'detail', is_array( $data ) ? $data : array() );
	}

	/**
	 * @return array<string, array{0:string,1:string[]}>
	 */
	public function internal_targets(): array {
		return array(
			'aws metadata'    => array( 'metadata.attacker.test', array( '169.254.169.254' ) ),
			'cgnat'           => array( 'internal.attacker.test', array( '100.64.0.1' ) ),
			'ipv6 loopback'   => array( 'v6.attacker.test', array( '::1' ) ),
			'dns rebind mix'  => array( 'rebind.attacker.test', array( '93.184.216.34', '169.254.169.254' ) ),
		);
	}

	/**
	 * @test
	 * Control: a genuinely public host IS fetched (the guard does not over-block).
	 */
	public function public_host_is_fetched(): void {
		// A public IP literal — no DNS needed, and neither core's
		// wp_http_validate_url nor the guard blocks it.
		$response = $this->dispatch( 'https://93.184.216.34/post' );

		$this->assertCount( 1, $this->fetched, 'A public host must be fetched exactly once.' );
		$this->assertFalse( $response->is_error() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', is_array( $data ) ? $data : array() );
	}

	/**
	 * @test
	 * A redirect to an internal host is not followed.
	 */
	public function redirect_to_internal_host_is_not_followed(): void {
		// First hop redirects to the internal host; the guard must reject the
		// second hop before fetching it. IP literals avoid fake-host DNS. The
		// redirect spy is stored so it can be removed — an anonymous closure
		// would leak onto pre_http_request and pollute every later test.
		remove_filter( 'pre_http_request', array( $this, 'spy_http' ), 10 );
		$redirect_spy = function ( $pre, $args, $url ) {
			$this->fetched[] = (string) $url;
			if ( false !== strpos( (string) $url, '93.184.216.34' ) ) {
				return array(
					'headers'  => array( 'location' => 'https://169.254.169.254/latest/' ),
					'body'     => '',
					'response' => array( 'code' => 302, 'message' => 'Found' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return array(
				'headers'  => array( 'content-type' => 'text/html' ),
				'body'     => '<title>should not reach</title>',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $redirect_spy, 10, 3 );

		try {
			$response = $this->dispatch( 'https://93.184.216.34/post' );
		} finally {
			remove_filter( 'pre_http_request', $redirect_spy, 10 );
		}

		$this->assertContains( 'https://93.184.216.34/post', $this->fetched, 'First hop is fetched.' );
		foreach ( $this->fetched as $u ) {
			$this->assertStringNotContainsString( '169.254.169.254', $u, 'The internal redirect target must never be fetched.' );
		}
		$this->assertTrue( $response->is_error() );
	}
}
