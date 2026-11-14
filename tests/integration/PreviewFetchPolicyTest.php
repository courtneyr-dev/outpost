<?php
/**
 * The preview fetch policy, through the real WordPress HTTP transport (T10-S3-08, F-09).
 *
 * Requests go through wp_safe_remote_get() and Requests' curl transport to
 * the WireMock sidecar. The fixture host is WireMock's own hostname; the
 * `outpost_resolve_host_ips` seam gives the guard the answers each test
 * needs (a public address, a blocked one, a mix, or a different answer on
 * a later hop to simulate DNS rebinding). Outpost pins curl to the address
 * it vetted (CURLOPT_RESOLVE); a test-only `http_api_curl` hook at a later
 * priority then re-pins that host to WireMock's real address, so the test
 * never contacts the vetted public address. The same hook counts
 * connections: a destination the policy refuses must never reach it.
 *
 * Covers: public IPv4 and IPv6 controls; every newly blocked range as a
 * literal host and as a resolved answer; mixed answers; a redirect into
 * blocked space; rebinding between hops; the 5 MiB cap (exactly at the
 * limit, one byte over, chunked with no Content-Length, gzip-compressed
 * over the limit, and an oversized declared Content-Length); and that
 * `limit_response_size` reaches the WordPress HTTP call.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Mock_Server;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class PreviewFetchPolicyTest extends TestCase {

	private const LIMIT = 5 * 1024 * 1024;

	private int $editor_id = 0;

	private string $mock_host = '';

	private int $mock_port = 0;

	private string $mock_ip = '';

	/** @var array<string, array<int, string[]>> host => answers per lookup, in order. */
	private array $answers = array();

	/** @var array<string, int> host => lookups so far. */
	private array $lookups = array();

	/** Connections curl was about to make. */
	private int $connections = 0;

	/** @var array<int, array<string, mixed>> Args WordPress passed to each HTTP request. */
	private array $request_args = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) || ! class_exists( 'Outpost_Mock_Server' ) || ! class_exists( 'Outpost_Preview_Endpoint' ) ) {
			$this->markTestSkipped( 'Needs wp-env tests-cli and the WireMock sidecar.' );
		}
		Outpost_Mock_Server::reset();
		$this->mock_host = (string) wp_parse_url( OUTPOST_TEST_MOCK_SERVER_URL, PHP_URL_HOST );
		$this->mock_port = (int) wp_parse_url( OUTPOST_TEST_MOCK_SERVER_URL, PHP_URL_PORT );
		$this->mock_ip   = (string) gethostbyname( $this->mock_host );

		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 's308_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's308_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->editor_id );
		delete_transient( 'outpost_preview_rl_' . $this->editor_id );

		$this->answers      = array();
		$this->lookups      = array();
		$this->connections  = 0;
		$this->request_args = array();
		add_filter( 'outpost_resolve_host_ips', array( $this, 'resolve' ), 10, 2 );
		add_action( 'http_api_curl', array( $this, 'repin_to_wiremock' ), 99, 3 );
		add_filter( 'http_request_args', array( $this, 'record_args' ), 99, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'outpost_resolve_host_ips', array( $this, 'resolve' ), 10 );
		remove_action( 'http_api_curl', array( $this, 'repin_to_wiremock' ), 99 );
		remove_filter( 'http_request_args', array( $this, 'record_args' ), 99 );
		if ( $this->editor_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->editor_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ----- Seams --------------------------------------------------------

	/**
	 * @param string[] $ips  Real answers (ignored).
	 * @param string   $host Host being resolved.
	 * @return string[]
	 */
	public function resolve( $ips, $host ): array {
		unset( $ips );
		$n                      = $this->lookups[ $host ] ?? 0;
		$this->lookups[ $host ] = $n + 1;
		$plan                   = $this->answers[ $host ] ?? array( array() );
		return $plan[ min( $n, count( $plan ) - 1 ) ];
	}

	/**
	 * After Outpost pinned the host to the address it vetted, point the same
	 * host at WireMock instead, and count the connection. The mock server's
	 * own admin calls (stubs, the request journal) aren't preview fetches and
	 * aren't counted.
	 *
	 * @param resource|\CurlHandle  $handle curl handle.
	 * @param array<string, mixed> $args   Request args.
	 * @param string               $url    Request URL.
	 */
	public function repin_to_wiremock( $handle, $args = array(), $url = '' ): void {
		unset( $args );
		if ( 0 === strpos( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/__admin' ) ) {
			return;
		}
		++$this->connections;
		$entry = $this->mock_host . ':' . $this->mock_port;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- test-only: re-point the pinned host at the local WireMock sidecar.
		curl_setopt( $handle, CURLOPT_RESOLVE, array( '-' . $entry, $entry . ':' . $this->mock_ip ) );
	}

	/**
	 * Record the args of each preview fetch (not the mock server's admin calls).
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function record_args( $args, $url = '' ): array {
		if ( 0 !== strpos( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/__admin' ) ) {
			$this->request_args[] = (array) $args;
		}
		return (array) $args;
	}

	// ----- Helpers ------------------------------------------------------

	private function url( string $path ): string {
		return 'http://' . $this->mock_host . ':' . $this->mock_port . $path;
	}

	/** @param string[] ...$per_lookup Answers for each successive lookup. */
	private function answer( string $host, array ...$per_lookup ): void {
		$this->answers[ $host ] = $per_lookup;
	}

	private function preview( string $url ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_param( 'url', $url );
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	/** @param array<string, mixed> $response WireMock response. */
	private function stub( string $path, array $response ): void {
		Outpost_Mock_Server::stub( 'GET', $path, $response );
	}

	private function html( int $bytes ): string {
		$head = '<html><head><title>s308</title></head><body>';
		$tail = '</body></html>';
		return $head . str_repeat( 'a', max( 0, $bytes - strlen( $head ) - strlen( $tail ) ) ) . $tail;
	}

	private function served( string $path ): int {
		return count( Outpost_Mock_Server::received_requests( 'GET', $path ) );
	}

	private function assert_refused_without_connecting( \WP_REST_Response $response, string $why ): void {
		$this->assertGreaterThanOrEqual( 400, $response->get_status(), "$why: must be refused." );
		$this->assertSame( 0, $this->connections, "$why: no connection may be attempted." );
	}

	// ----- Positive controls --------------------------------------------

	public function test_a_public_ipv4_destination_is_fetched_through_the_transport(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34' ) );
		$this->stub( '/s308/ok', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( 200 ) ) );

		$response = $this->preview( $this->url( '/s308/ok' ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $this->served( '/s308/ok' ) );
		$this->assertSame( 1, $this->connections );
	}

	public function test_a_public_ipv6_destination_is_fetched_through_the_transport(): void {
		$this->answer( $this->mock_host, array( '2606:4700::1111' ) );
		$this->stub( '/s308/ok6', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( 200 ) ) );

		$response = $this->preview( $this->url( '/s308/ok6' ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $this->served( '/s308/ok6' ) );
	}

	// ----- Blocked destinations -----------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function blocked_addresses(): array {
		return array(
			'192.0.2/24'        => array( '192.0.2.10' ),
			'198.18/15'         => array( '198.19.1.1' ),
			'198.51.100/24'     => array( '198.51.100.7' ),
			'203.0.113/24'      => array( '203.0.113.9' ),
			'fec0::/10'         => array( 'fec0::1' ),
			'2002::/16'         => array( '2002:c0a8:101::1' ),
			'2002 public 6to4'  => array( '2002:5db8:d822::1' ),
			'Teredo'            => array( '2001:0:4136:e378::1' ),
			'v6 multicast'      => array( 'ff02::1' ),
			'link-local'        => array( '169.254.169.254' ),
			'loopback'          => array( '127.0.0.1' ),
			'private'           => array( '10.1.2.3' ),
			'mapped private'    => array( '::ffff:10.1.2.3' ),
		);
	}

	/**
	 * @dataProvider blocked_addresses
	 *
	 * @param string $ip Blocked address.
	 */
	public function test_a_literal_blocked_host_is_refused_without_connecting( string $ip ): void {
		$host = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		$this->assert_refused_without_connecting( $this->preview( 'http://' . $host . ':8080/s308/x' ), "literal $ip" );
	}

	/**
	 * @dataProvider blocked_addresses
	 *
	 * @param string $ip Blocked address.
	 */
	public function test_a_host_resolving_to_a_blocked_address_is_refused_without_connecting( string $ip ): void {
		$this->answer( $this->mock_host, array( $ip ) );
		$this->stub( '/s308/x', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( 200 ) ) );

		$this->assert_refused_without_connecting( $this->preview( $this->url( '/s308/x' ) ), "resolved $ip" );
		$this->assertSame( 0, $this->served( '/s308/x' ) );
	}

	public function test_mixed_safe_and_unsafe_answers_are_refused(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34', '192.0.2.10' ) );
		$this->stub( '/s308/x', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( 200 ) ) );

		$this->assert_refused_without_connecting( $this->preview( $this->url( '/s308/x' ) ), 'mixed answers' );
		$this->assertSame( 0, $this->served( '/s308/x' ) );
	}

	public function test_a_redirect_into_blocked_space_is_not_followed(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34' ) );
		$this->stub( '/s308/redirect', array( 'status' => 302, 'headers' => array( 'Location' => 'http://198.18.0.5:8080/s308/x' ) ) );

		$response = $this->preview( $this->url( '/s308/redirect' ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 1, $this->served( '/s308/redirect' ) );
		$this->assertSame( 1, $this->connections, 'Only the first hop may connect.' );
	}

	public function test_dns_rebinding_between_hops_is_refused_at_the_second_hop(): void {
		// Lookups 1 and 2 (validation, then the first hop): public. Lookup 3
		// (the redirect target, same host): blocked.
		$this->answer( $this->mock_host, array( '93.184.216.34' ), array( '93.184.216.34' ), array( '192.0.2.10' ) );
		$this->stub( '/s308/hop1', array( 'status' => 302, 'headers' => array( 'Location' => $this->url( '/s308/hop2' ) ) ) );
		$this->stub( '/s308/hop2', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( 200 ) ) );

		$response = $this->preview( $this->url( '/s308/hop1' ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 3, $this->lookups[ $this->mock_host ] ?? 0, 'Each hop is resolved and vetted again.' );
		$this->assertSame( 0, $this->served( '/s308/hop2' ) );
		$this->assertSame( 1, $this->connections );
	}

	// ----- The 5 MiB cap ------------------------------------------------

	public function test_limit_response_size_reaches_the_wordpress_http_call(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34' ) );
		$this->stub( '/s308/ok', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( 200 ) ) );

		$this->preview( $this->url( '/s308/ok' ) );

		$this->assertNotEmpty( $this->request_args );
		$this->assertSame( self::LIMIT + 1, $this->request_args[0]['limit_response_size'] ?? null );
	}

	public function test_a_body_exactly_at_the_limit_is_accepted(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34' ) );
		$this->stub( '/s308/at', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( self::LIMIT ) ) );

		$response = $this->preview( $this->url( '/s308/at' ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_a_body_one_byte_over_the_limit_is_refused_and_not_returned(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34' ) );
		$this->stub( '/s308/over', array( 'status' => 200, 'headers' => array( 'Content-Type' => 'text/html' ), 'body' => $this->html( self::LIMIT + 1 ) ) );

		$response = $this->preview( $this->url( '/s308/over' ) );

		$this->assertSame( 413, $response->get_status() );
		$this->assertArrayNotHasKey( 'html', (array) $response->get_data() );
	}

	public function test_a_chunked_body_with_no_content_length_over_the_limit_is_refused(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34' ) );
		$this->stub(
			'/s308/chunked',
			array(
				'status'              => 200,
				'headers'             => array( 'Content-Type' => 'text/html' ),
				'body'                => $this->html( self::LIMIT + 4096 ),
				'chunkedDribbleDelay' => array(
					'numberOfChunks' => 8,
					'totalDuration'  => 50,
				),
			)
		);

		$response = $this->preview( $this->url( '/s308/chunked' ) );

		$this->assertSame( 413, $response->get_status() );
		$this->assertArrayNotHasKey( 'html', (array) $response->get_data() );
	}

	public function test_a_compressed_body_that_expands_past_the_limit_is_refused(): void {
		$this->answer( $this->mock_host, array( '93.184.216.34' ) );
		$this->stub(
			'/s308/gzip',
			array(
				'status'     => 200,
				'headers'    => array(
					'Content-Type'     => 'text/html',
					'Content-Encoding' => 'gzip',
				),
				'base64Body' => base64_encode( (string) gzencode( $this->html( self::LIMIT + 1 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WireMock's binary body format.
			)
		);

		$response = $this->preview( $this->url( '/s308/gzip' ) );

		$this->assertSame( 413, $response->get_status() );
		$this->assertArrayNotHasKey( 'html', (array) $response->get_data() );
	}

	public function test_an_oversized_declared_content_length_is_refused_before_parsing(): void {
		// A synthetic transport answer: the header declares more than the cap
		// while the body handed back is small (as if truncated in transit).
		$this->answer( 'declared.outpost-test.example', array( '93.184.216.34' ) );
		$stub = static function ( $pre, $args, $url ) {
			unset( $pre, $args );
			if ( false === strpos( (string) $url, 'declared.outpost-test.example' ) ) {
				return false;
			}
			return array(
				'headers'  => array(
					'content-type'   => 'text/html',
					'content-length' => (string) ( self::LIMIT + 1 ),
				),
				'body'     => '<html><head><title>declared</title></head><body>small</body></html>',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $stub, 0, 3 );
		try {
			$response = $this->preview( 'https://declared.outpost-test.example/page' );
		} finally {
			remove_filter( 'pre_http_request', $stub, 0 );
		}

		$this->assertSame( 413, $response->get_status() );
		$this->assertArrayNotHasKey( 'html', (array) $response->get_data() );
	}
}
