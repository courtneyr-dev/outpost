<?php
/**
 * Outpost_IOS_Shortcut_REST_Controller unit tests — the REST twin of the
 * Shortcut bridge must carry a shared link byte-for-byte.
 *
 * Core runs each registered `sanitize_callback` as
 * `call_user_func( $callback, $value, $request, $param )` before the
 * route callback. These tests capture the registered args and do the same,
 * so the callbacks under test are the ones the route actually registers.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost\Tests\Helpers\CoreSanitizerMocks;
use Outpost_IOS_Shortcut_REST_Controller;
use Outpost_Source_Detector;
use Outpost_Source_Registry;
use WP_Mock;
use WP_Mock\Tools\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/source-test-fakes.php';

final class IosShortcutRestControllerTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		Outpost_Source_Detector::reset_cache_for_tests();

		CoreSanitizerMocks::register();
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		// T10-S3-01: Shortcut intake keeps no server-side state.
		WP_Mock::userFunction( 'set_transient' )->never();
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://site.test' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static fn( $url ) => parse_url( $url ) );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( static fn( $hook, $value ) => $value );
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $text ) => $text );
	}

	public function tearDown(): void {
		Outpost_Source_Registry::reset_for_tests();
		Outpost_Source_Detector::reset_cache_for_tests();
		WP_Mock::tearDown();
	}

	/**
	 * Register the route, then dispatch a body the way core does: sanitize
	 * each param with its registered callback, then run the route callback.
	 *
	 * @param array<string, mixed> $body JSON body params.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( array $body ) {
		$registered = array();
		WP_Mock::userFunction( 'register_rest_route' )->andReturnUsing(
			static function ( $route_namespace, $route, $args ) use ( &$registered ): bool {
				$registered = $args;
				return true;
			}
		);
		Outpost_IOS_Shortcut_REST_Controller::register_route();

		$request = new \WP_REST_Request( 'POST', '/outpost/v1/shortcut' );
		foreach ( $body as $param => $value ) {
			$this->assertArrayHasKey( $param, $registered['args'], "The route must declare the {$param} arg." );
			$callback = $registered['args'][ $param ]['sanitize_callback'];
			$this->assertIsCallable( $callback );
			$request->set_param( $param, call_user_func( $callback, $value, $request, $param ) );
		}

		return call_user_func( $registered['callback'], $request );
	}

	/**
	 * @return array<string, array{0:array<string,string>, 1:string}> JSON body, URL the composer must receive
	 */
	public function encoded_link_payloads(): array {
		return array(
			'url: bare link'                        => array(
				array( 'url' => 'https://example.com/a%20b/caf%C3%A9?q=hello%20world&x=1' ),
				'https://example.com/a%20b/caf%C3%A9?q=hello%20world&x=1',
			),
			'url: text blob with an encoded link'   => array(
				array( 'url' => "\"A good line.\"\n\n— Some Book\nhttps://example.com/a%20b/caf%C3%A9" ),
				'https://example.com/a%20b/caf%C3%A9',
			),
			'shared_text: link inside a sentence'   => array(
				array( 'shared_text' => 'Check this out https://example.com/a%20b/caf%C3%A9?q=hello%20world' ),
				'https://example.com/a%20b/caf%C3%A9?q=hello%20world',
			),
			'shared_text: unclosed less-than first' => array(
				array( 'shared_text' => 'I <3 this https://example.com/a%20b?x=1&y=2' ),
				'https://example.com/a%20b?x=1&y=2',
			),
			'title: bare link'                      => array(
				array( 'title' => 'https://example.com/caf%C3%A9' ),
				'https://example.com/caf%C3%A9',
			),
		);
	}

	/**
	 * @dataProvider encoded_link_payloads
	 * @param array<string,string> $body     JSON body params.
	 * @param string               $expected URL the composer must receive.
	 */
	public function test_shared_link_reaches_the_composer_byte_for_byte( array $body, string $expected ): void {
		$response = $this->dispatch( $body );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		parse_str( (string) parse_url( (string) $data['redirect_url'], PHP_URL_QUERY ), $query );
		$this->assertSame( $expected, $query['url'] ?? null );
	}

	/**
	 * @return array<string, array{0:array<string,mixed>}>
	 */
	public function non_string_payloads(): array {
		return array(
			'array url'         => array( array( 'url' => array( 'https://example.com/a%20b' ) ) ),
			'array shared_text' => array( array( 'shared_text' => array( 'https://example.com/a%20b' ) ) ),
			'array title'       => array( array( 'title' => array( 'https://example.com/a%20b' ) ) ),
			'every field'       => array(
				array(
					'url'         => array( 'https://example.com/a%20b' ),
					'shared_text' => array( 'x' => 'y' ),
					'title'       => 12345,
				),
			),
		);
	}

	/**
	 * A custom sanitize_callback replaces core's type check, so an array
	 * reaches it. wp_kses() and esc_url_raw() are a fatal TypeError on one.
	 *
	 * @dataProvider non_string_payloads
	 * @param array<string,mixed> $body JSON body params.
	 */
	public function test_non_string_fields_are_a_400_not_a_fatal( array $body ): void {
		WP_Mock::userFunction( 'update_user_meta' )->never();

		$response = $this->dispatch( $body );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'outpost_ios_shortcut_no_url', $response->get_error_code() );
		$this->assertSame( array(), CoreSanitizerMocks::$esc_url_raw_calls, 'esc_url_raw() must never see a non-string.' );
	}

	/**
	 * T10-S3-01: an auto-routed (unambiguous) Shortcut share returns its
	 * composer redirect and writes nothing on the server: no transient, no
	 * preview request. setUp() makes set_transient() a never() expectation.
	 */
	public function test_auto_routed_share_writes_no_server_state(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::userFunction( 'wp_remote_post' )->never();
		WP_Mock::userFunction( 'wp_safe_remote_post' )->never();
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub(
				array(
					'id'            => 'fake-auto',
					'host_patterns' => array( 'example.com' ),
					'ambiguity'     => 'unambiguous',
					'mode'          => 'listen',
				)
			)
		);

		$response = $this->dispatch( array( 'url' => 'https://example.com/track/1' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'auto', $data['route_type'] );
		$this->assertStringContainsString( 'mode=listen', (string) $data['redirect_url'] );
		$this->assertStringNotContainsString( 'cached_for=', (string) $data['redirect_url'], 'No server-side cache exists for the composer to look up.' );
	}
}
