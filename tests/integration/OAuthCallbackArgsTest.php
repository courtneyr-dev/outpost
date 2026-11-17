<?php
/**
 * The OAuth callback declares its arguments (F-63).
 *
 * The other Outpost routes give every argument a `type`, so WordPress
 * validates and casts it (rest_parse_request_arg) before the handler runs.
 * The OAuth routes declared none: `?code[]=x` reached the handler as an
 * array and was cast to the string "Array" with a PHP warning.
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
final class OAuthCallbackArgsTest extends TestCase {

	/** @var array<int, string> */
	private array $outbound = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_OAuth_Controller' ) || ! function_exists( 'rest_get_server' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->outbound = array();
		add_filter( 'pre_http_request', array( $this, 'record_outbound' ), 0, 3 );
		rest_get_server();
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'record_outbound' ), 0 );
		parent::tearDown();
	}

	/**
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return mixed
	 */
	public function record_outbound( $pre, $args, $url ) {
		$this->outbound[] = (string) $url;
		return $pre;
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function malformed_params(): array {
		return array(
			'code as an array'  => array( array( 'code' => array( 'x' ), 'state' => 'abc' ) ),
			'state as an array' => array( array( 'code' => 'abc', 'state' => array( 'x' ) ) ),
		);
	}

	/**
	 * @dataProvider malformed_params
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	public function test_an_array_where_a_string_belongs_is_refused_before_the_handler( array $params ): void {
		$request = new WP_REST_Request( 'GET', '/outpost/v1/oauth/oura/callback' );
		$request->set_query_params( $params );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		$this->assertSame( array(), $this->outbound, 'No token exchange may start.' );
	}

	public function test_string_parameters_still_reach_the_handler(): void {
		$request = new WP_REST_Request( 'GET', '/outpost/v1/oauth/oura/callback' );
		$request->set_query_params( array( 'code' => 'abc', 'state' => 'not-a-real-state' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertNotSame( 400, $response->get_status(), 'A well-formed request reaches the handler, which redirects on a bad state.' );
		$this->assertSame( array(), $this->outbound, 'An invalid state stops before the token exchange.' );
	}
}
