<?php
/**
 * Unit tests for Outpost_Manual_Share_Controller (F9).
 *
 * Covers two routes registered under the controller:
 *
 *   POST /wp-json/outpost/v1/manual-share/intent
 *        — F9 stub handler returns {status: stub, message, platform_id,
 *          post_id}; F10 Android / F11 iOS replace it with real intent
 *          firing.
 *   GET  /wp-json/outpost/v1/manual-share-chips?mode=...
 *        — Returns per-mode platform chips, filtered to entries that
 *          carry the manual_share extension key. Mirrors the F2
 *          syndicate-targets endpoint shape. Mode validation is
 *          fail-OPEN.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Controller;
use Outpost_Manual_Share_Platform_Registry;
use Outpost_Companion_Registry;
use WP_Mock;
use WP_REST_Request;
use WP_Error;

final class ManualShareControllerTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		Outpost_Companion_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		Outpost_Companion_Registry::reset_for_tests();
	}

	/**
	 * Build a request mock for the intent endpoint.
	 *
	 * @param mixed $post_id     Post ID to bind to the request.
	 * @param mixed $platform_id Platform ID to bind to the request.
	 */
	private function make_intent_request( $post_id, $platform_id ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static function ( string $key ) use ( $post_id, $platform_id ) {
				if ( 'post_id' === $key ) {
					return $post_id;
				}
				if ( 'platform_id' === $key ) {
					return $platform_id;
				}
				return null;
			}
		);
		return $request;
	}

	/**
	 * Build a request mock for the chips endpoint.
	 */
	private function make_chips_request( ?string $mode ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->with( 'mode' )->willReturn( $mode );
		return $request;
	}

	// =====================================================================
	// Intent endpoint (POST /manual-share/intent) — F9 stub
	// =====================================================================

	public function test_intent_returns_stub_response_for_known_platform(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$response = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, 'instagram-feed' )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'stub', $payload['status'] );
		$this->assertSame( 'instagram-feed', $payload['platform_id'] );
		$this->assertSame( 42, $payload['post_id'] );
		$this->assertStringContainsString( 'instagram-feed', $payload['message'] );
		$this->assertStringContainsString( 'F10', $payload['message'] );
	}

	public function test_intent_rejects_zero_post_id_with_400(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$result = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 0, 'instagram-feed' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	public function test_intent_rejects_negative_post_id_with_400(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$result = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( -5, 'instagram-feed' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
	}

	public function test_intent_rejects_empty_platform_id(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$result = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, '' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_platform_id', $result->get_error_code() );
	}

	public function test_intent_rejects_unknown_platform_id_with_known_ids_in_data(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$result = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, 'totally-fake-platform' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_platform_id', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		// The returned error data lists known IDs so the client can
		// surface the right list to the user. F9 ships 10 platforms.
		$this->assertContains( 'instagram-feed', $data['known_ids'] );
		$this->assertCount( 10, $data['known_ids'] );
	}

	public function test_intent_trims_whitespace_around_platform_id(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$response = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, '  facebook  ' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'facebook', $response->get_data()['platform_id'] );
	}

	// =====================================================================
	// Chips endpoint (GET /manual-share-chips)
	// =====================================================================

	public function test_chips_request_for_photo_returns_only_manual_share_chips(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$response = Outpost_Manual_Share_Controller::handle_chips_request(
			$this->make_chips_request( 'photo' )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'photo', $payload['mode_requested'] );
		$this->assertSame( 'photo', $payload['mode_applied'] );
		$this->assertTrue( $payload['mode_recognized'] );
		// All 10 platforms accept photo mode.
		$this->assertCount( 10, $payload['chips'] );
		// Every chip has the manual_share extension key (server-filtered).
		foreach ( $payload['chips'] as $chip ) {
			$this->assertArrayHasKey( 'manual_share', $chip );
			$this->assertIsArray( $chip['manual_share'] );
		}
	}

	public function test_chips_request_for_listen_returns_empty_array(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$response = Outpost_Manual_Share_Controller::handle_chips_request(
			$this->make_chips_request( 'listen' )
		);

		$payload = $response->get_data();
		$this->assertSame( array(), $payload['chips'] );
	}

	public function test_chips_request_unknown_mode_fails_open_returns_all_chips(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$response = Outpost_Manual_Share_Controller::handle_chips_request(
			$this->make_chips_request( 'totally-made-up-mode' )
		);

		$payload = $response->get_data();
		$this->assertSame( 'totally-made-up-mode', $payload['mode_requested'] );
		$this->assertNull( $payload['mode_applied'] );
		$this->assertFalse( $payload['mode_recognized'] );
		$this->assertCount( 10, $payload['chips'] );
	}

	public function test_chips_request_with_no_mode_returns_all_chips(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$response = Outpost_Manual_Share_Controller::handle_chips_request(
			$this->make_chips_request( null )
		);

		$payload = $response->get_data();
		$this->assertNull( $payload['mode_requested'] );
		$this->assertNull( $payload['mode_applied'] );
		$this->assertTrue( $payload['mode_recognized'] );
		$this->assertCount( 10, $payload['chips'] );
	}
}
