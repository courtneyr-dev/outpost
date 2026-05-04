<?php
/**
 * Unit tests for Outpost_Syndication_Capture_Controller (F12).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Syndication_Capture_Controller;
use Outpost_Manual_Share_Audit_Log;
use Outpost_Manual_Share_Pending_Capture_Detector;
use Outpost_Manual_Share_Syndication_Writeback;
use WP_Error;
use WP_Mock;
use WP_REST_Request;

final class SyndicationCaptureControllerTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	private bool $user_can_edit_post = true;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store         = array();
		$this->user_can_edit_post = true;
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );

		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static fn ( string $u ) => parse_url( $u )
		);
		WP_Mock::userFunction( 'wp_http_validate_url' )->andReturnUsing(
			static function ( string $url ) {
				$parts = parse_url( $url );
				if ( false === $parts || empty( $parts['host'] ) ) {
					return false;
				}
				$host = strtolower( (string) $parts['host'] );
				if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
					return false;
				}
				return $url;
			}
		);
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta_store[ $post_id ][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, $value ): bool {
				$this->meta_store[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			function ( string $cap, int $post_id = 0 ): bool {
				return $this->user_can_edit_post;
			}
		);
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_post' )->andReturnUsing(
			static fn ( int $post_id ) => new \WP_Post( array(
				'ID'          => $post_id,
				'post_title'  => 'Post ' . $post_id,
				'post_author' => 7,
			) )
		);
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing(
			static fn ( int $post_id ): string => 'https://example.com/posts/' . $post_id
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );
	}

	private function build_capture_request( array $params ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static fn ( string $key ) => $params[ $key ] ?? null
		);
		return $request;
	}

	private function seed_audit_entry( int $post_id, string $platform_id ): array {
		// Use the Audit_Log API to write a real entry so the controller's
		// lookup logic runs against persisted data.
		$entry = Outpost_Manual_Share_Audit_Log::add_entry(
			$post_id,
			$platform_id,
			Outpost_Manual_Share_Audit_Log::STRATEGY_NAVIGATOR_SHARE
		);
		return $entry;
	}

	// =====================================================================
	// Capture endpoint
	// =====================================================================

	public function test_capture_records_url_and_updates_audit_log(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$response = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'https://www.instagram.com/p/abc',
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'recorded', $payload['status'] );
		$this->assertSame( 'instagram-feed', $payload['platform_id'] );
		$this->assertCount( 1, $payload['syndication_links'] );

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertSame( 'https://www.instagram.com/p/abc', $entries[0]['silo_url'] );
		$this->assertNotNull( $entries[0]['completed_at'] );
		$this->assertSame( 'fired', $entries[0]['outcome'] );
	}

	public function test_capture_returns_400_for_invalid_url(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'javascript:alert(1)',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	public function test_capture_returns_400_for_zero_post_id(): void {
		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 0,
				'audit_log_id' => 'whatever',
				'silo_url'     => 'https://example.com/p/abc',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
	}

	public function test_capture_returns_403_for_user_without_edit_permission(): void {
		$this->user_can_edit_post = false;
		$entry                    = $this->seed_audit_entry( 42, 'instagram-feed' );

		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'https://www.instagram.com/p/abc',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_post', $result->get_error_code() );
	}

	public function test_capture_returns_404_when_audit_entry_missing(): void {
		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => 'totally-fake-id',
				'silo_url'     => 'https://example.com/p/abc',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'audit_log_entry_not_found', $result->get_error_code() );
	}

	public function test_capture_soft_warns_on_platform_mismatch(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$response = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'https://twitter.com/user/status/1',
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'mismatch_warning', $payload['status'] );
		// Audit log NOT updated yet — user must confirm.
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertNull( $entries[0]['silo_url'] );
		$this->assertNull( $entries[0]['completed_at'] );
	}

	public function test_capture_proceeds_when_mismatch_confirmed(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$response = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'          => 42,
				'audit_log_id'     => $entry['id'],
				'silo_url'         => 'https://twitter.com/user/status/1',
				'confirm_mismatch' => true,
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'recorded', $payload['status'] );
		$this->assertTrue( $payload['mismatch_confirmed'] );
	}

	// =====================================================================
	// Pending endpoint
	// =====================================================================

	public function test_pending_returns_user_results(): void {
		// Seed one pending entry old enough to clear the grace period.
		$this->meta_store[42]['outpost_manual_share_log'] = array(
			array(
				'id'           => 'pending-1',
				'version'      => 1,
				'platform_id'  => 'instagram-feed',
				'fired_at'     => gmdate( 'c', time() - 120 ),
				'strategy'     => 'navigator_share',
				'outcome'      => 'unknown',
				'completed_at' => null,
				'silo_url'     => null,
			),
		);
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array( 42 )
		);

		$response = Outpost_Syndication_Capture_Controller::handle_pending_request(
			$this->createMock( WP_REST_Request::class )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertCount( 1, $payload['pending'] );
		$this->assertSame( 42, $payload['pending'][0]['post_id'] );
	}

	public function test_pending_returns_empty_when_user_has_none(): void {
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array()
		);
		$response = Outpost_Syndication_Capture_Controller::handle_pending_request(
			$this->createMock( WP_REST_Request::class )
		);

		$payload = $response->get_data();
		$this->assertSame( array(), $payload['pending'] );
	}
}
