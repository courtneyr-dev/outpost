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

	private bool $user_can_edit_post = true;

	/** @var array<int, mixed> Shared meta-store for tests that need round-trip. */
	private array $meta_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		Outpost_Companion_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		// F10: per-post edit-permission check. Default: allow. Tests
		// that exercise the cross-user-rejection path flip
		// `$this->user_can_edit_post` to false.
		$this->user_can_edit_post = true;
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			function ( string $cap, int $post_id = 0 ): bool {
				return $this->user_can_edit_post;
			}
		);

		// F10 builder writes audit log entries on Android-UA requests;
		// stub the persistence helpers + WP utilities the builder calls.
		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static fn ( string $s ): string => strip_tags( $s ) );
		$this->meta_store = array();
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta_store[ $post_id ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, $value ): bool {
				$this->meta_store[ $post_id ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_post' )->andReturnUsing( static function ( int $post_id ) {
			return new \WP_Post( array(
				'ID'           => $post_id,
				'post_title'   => 'Sample post',
				'post_content' => '',
			) );
		} );
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing(
			static fn ( int $post_id ): string => 'https://example.com/posts/' . $post_id
		);
		WP_Mock::userFunction( 'get_attached_media' )->andReturn( array() );
		WP_Mock::userFunction( 'wp_get_attachment_url' )->andReturn( '' );
		WP_Mock::userFunction( 'get_post_mime_type' )->andReturn( 'image/jpeg' );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		Outpost_Companion_Registry::reset_for_tests();
		unset( $_SERVER['HTTP_USER_AGENT'] );
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

	public function test_intent_returns_stub_response_for_non_android_ua(): void {
		// No User-Agent → desktop bucket → F9 stub. F11 will route iOS;
		// desktop manual-share is a future concern.
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
	}

	// =====================================================================
	// F10: Android UA returns real intent payload
	// =====================================================================

	public function test_intent_returns_android_payload_for_android_ua(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36';

		$response = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, 'instagram-feed' )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'instagram-feed', $payload['platform'] );
		$this->assertSame( 'Instagram', $payload['platform_label'] );
		$this->assertArrayHasKey( 'audit_log_id', $payload );
		$this->assertArrayHasKey( 'fallback_url', $payload );
		$this->assertArrayHasKey( 'clipboard_text', $payload );
		$this->assertArrayHasKey( 'intent_strategy', $payload );
		$this->assertSame( 'navigator_share', $payload['intent_strategy'] );
		$this->assertArrayNotHasKey( 'status', $payload );
	}

	public function test_intent_returns_stub_for_iphone_ua(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';

		$response = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, 'instagram-feed' )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'stub', $payload['status'] );
	}

	public function test_intent_returns_stub_for_ipad_ua(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)';

		$response = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, 'instagram-feed' )
		);

		$payload = $response->get_data();
		$this->assertSame( 'stub', $payload['status'] );
	}

	public function test_intent_returns_403_when_user_cannot_edit_post(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		$this->user_can_edit_post = false;

		$result = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, 'instagram-feed' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_post', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	// =====================================================================
	// F10: detect_platform helper
	// =====================================================================

	public function test_detect_platform_classifies_user_agents(): void {
		$cases = array(
			'Mozilla/5.0 (Linux; Android 14; Pixel 8)'                            => 'android',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'              => 'ios',
			'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)'                       => 'ios',
			'Mozilla/5.0 (iPod touch; CPU iPhone OS 16_0 like Mac OS X)'          => 'ios',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'        => 'desktop',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1'   => 'desktop',
			''                                                                    => 'desktop',
		);

		foreach ( $cases as $ua => $expected ) {
			$this->assertSame(
				$expected,
				Outpost_Manual_Share_Controller::detect_platform( $ua ),
				sprintf( 'UA %s should classify as %s', $ua, $expected )
			);
		}
	}

	// =====================================================================
	// F10: Telemetry endpoint (POST /manual-share/intent/log)
	// =====================================================================

	public function test_telemetry_records_outcome_for_existing_audit_entry(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14; Pixel 8)';

		// Step 1 — fire intent to get an audit log ID. setUp's
		// shared meta_store mock persists the audit entry between
		// the intent and telemetry calls.
		$intent_response = Outpost_Manual_Share_Controller::handle_request(
			$this->make_intent_request( 42, 'instagram-feed' )
		);
		$payload         = $intent_response->get_data();
		$audit_log_id    = $payload['audit_log_id'];

		// Step 2 — POST telemetry referring to the same id.
		$telemetry_request = $this->createMock( WP_REST_Request::class );
		$telemetry_request->method( 'get_param' )->willReturnCallback(
			static function ( string $key ) use ( $audit_log_id ) {
				return array(
					'post_id'      => 42,
					'audit_log_id' => $audit_log_id,
					'outcome'      => 'fired',
				)[ $key ] ?? null;
			}
		);
		$telemetry_response = Outpost_Manual_Share_Controller::handle_log_request( $telemetry_request );

		$this->assertSame( 200, $telemetry_response->get_status() );
		$telemetry_payload = $telemetry_response->get_data();
		$this->assertSame( 'recorded', $telemetry_payload['status'] );
		$this->assertSame( $audit_log_id, $telemetry_payload['audit_log_id'] );
		$this->assertSame( 'fired', $telemetry_payload['outcome'] );
	}

	public function test_telemetry_returns_404_for_unknown_audit_log_id(): void {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static function ( string $key ) {
				return array(
					'post_id'      => 42,
					'audit_log_id' => 'totally-fake-id',
					'outcome'      => 'fired',
				)[ $key ] ?? null;
			}
		);

		$result = Outpost_Manual_Share_Controller::handle_log_request( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'audit_log_entry_not_found', $result->get_error_code() );
	}

	public function test_telemetry_rejects_invalid_post_id(): void {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static function ( string $key ) {
				return array(
					'post_id'      => 0,
					'audit_log_id' => 'whatever',
					'outcome'      => 'fired',
				)[ $key ] ?? null;
			}
		);

		$result = Outpost_Manual_Share_Controller::handle_log_request( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
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
