<?php
/**
 * Unit tests for Outpost_Syndicate_Targets_Endpoint (F2).
 *
 * The endpoint exposes the per-mode chip filter at
 * `/wp-json/outpost/v1/syndicate-targets` so the composer can fetch a
 * mode-narrowed chip list. Mode validation is fail-OPEN: unknown modes
 * return every detected chip rather than zero.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Syndicate_Targets_Endpoint;
use Outpost_Companion_Registry;
use Outpost_ActivityPub_Adapter;
use WP_Mock;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/fixtures/companion-restricted-modes.php';

final class SyndicateTargetsEndpointTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Companion_Registry::reset_for_tests();
		// WP_Mock filtersWithAnyArgs leak workaround (CLAUDE.md A2 #8).
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Companion_Registry::reset_for_tests();
	}

	private function make_request( ?string $mode ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->with( 'mode' )->willReturn( $mode );
		return $request;
	}

	public function test_handle_request_with_photo_mode_returns_activitypub_chip(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$response = Outpost_Syndicate_Targets_Endpoint::handle_request(
			$this->make_request( 'photo' )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();

		$this->assertSame( 'photo', $payload['mode_requested'] );
		$this->assertSame( 'photo', $payload['mode_applied'] );
		$this->assertTrue( $payload['mode_recognized'] );

		$ids = array_column( $payload['chips'], 'id' );
		$this->assertContains( 'activitypub', $ids );
	}

	public function test_handle_request_with_unknown_mode_fails_open(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$response = Outpost_Syndicate_Targets_Endpoint::handle_request(
			$this->make_request( 'invalid_mode' )
		);

		$payload = $response->get_data();
		$this->assertSame( 'invalid_mode', $payload['mode_requested'] );
		$this->assertNull( $payload['mode_applied'] );
		$this->assertFalse( $payload['mode_recognized'] );

		$ids = array_column( $payload['chips'], 'id' );
		$this->assertContains( 'activitypub', $ids );
	}

	public function test_handle_request_without_mode_returns_all_chips(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$response = Outpost_Syndicate_Targets_Endpoint::handle_request(
			$this->make_request( null )
		);

		$payload = $response->get_data();
		$this->assertNull( $payload['mode_requested'] );
		$this->assertNull( $payload['mode_applied'] );
		$this->assertTrue( $payload['mode_recognized'] );

		$ids = array_column( $payload['chips'], 'id' );
		$this->assertContains( 'activitypub', $ids );
	}

	public function test_handle_request_filters_restricted_companion_by_mode(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		// Inject the photo-only fixture so we have something to filter
		// out on note mode.
		WP_Mock::onFilter( 'outpost_companion_adapters' )->withAnyArgs()->reply(
			array(
				Outpost_ActivityPub_Adapter::class,
				\Outpost_F2TestRestricted_Adapter::class,
			)
		);

		$response = Outpost_Syndicate_Targets_Endpoint::handle_request(
			$this->make_request( 'note' )
		);
		$ids      = array_column( $response->get_data()['chips'], 'id' );

		$this->assertContains( 'activitypub', $ids );
		$this->assertNotContains( 'f2-test-restricted', $ids );
	}
}
