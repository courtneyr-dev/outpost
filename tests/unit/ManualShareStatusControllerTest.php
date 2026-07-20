<?php
/**
 * Unit tests for Outpost_Manual_Share_Status_Controller (F13).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Status_Controller;
use Outpost_Manual_Share_Audit_Log;
use Outpost_Manual_Share_Reminder_Manager;
use Outpost_Manual_Share_Pending_Capture_Detector;
use WP_Error;
use WP_Mock;
use WP_REST_Request;

final class ManualShareStatusControllerTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	/** @var array<string, array{value: mixed, expires_at: int}> */
	private array $transient_store = array();

	private bool $user_can_edit_post = true;

	private bool $user_logged_in = true;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store         = array();
		$this->transient_store    = array();
		$this->user_can_edit_post = true;
		$this->user_logged_in      = true;
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );

		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
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
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturnUsing(
			fn (): bool => $this->user_logged_in
		);
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
		WP_Mock::userFunction( 'get_transient' )->andReturnUsing(
			function ( string $key ) {
				if ( ! isset( $this->transient_store[ $key ] ) ) {
					return false;
				}
				if ( $this->transient_store[ $key ]['expires_at'] < time() ) {
					unset( $this->transient_store[ $key ] );
					return false;
				}
				return $this->transient_store[ $key ]['value'];
			}
		);
		WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function ( string $key, $value, int $ttl ): bool {
				$this->transient_store[ $key ] = array(
					'value'      => $value,
					'expires_at' => time() + $ttl,
				);
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );
		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION']
		);
	}

	/**
	 * @param int|false $determine_user User resolved by bearer validation.
	 */
	private function mock_filters( $determine_user ): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static function ( $hook, $value ) use ( $determine_user ) {
				if ( 'determine_current_user' === $hook ) {
					return $determine_user;
				}
				return $value;
			}
		);
	}

	public function test_permission_rejects_unvalidated_bearer_header(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x'; // outpost-lint:fixture-credential
		$this->user_can_edit_post        = false;
		$this->user_logged_in             = false;
		$this->mock_filters( false );

		$result = Outpost_Manual_Share_Status_Controller::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
		$this->assertSame( array(), $this->meta_store );
		$this->assertSame( array(), $this->transient_store );
	}

	public function test_permission_allows_validated_bearer_editor(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = false;
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		$this->mock_filters( 42 );

		$this->assertTrue( Outpost_Manual_Share_Status_Controller::check_permission() );
	}

	private function build_request( array $params ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static fn ( string $key ) => $params[ $key ] ?? null
		);
		return $request;
	}

	private function seed_entry( int $post_id, string $platform_id ): array {
		return Outpost_Manual_Share_Audit_Log::add_entry(
			$post_id,
			$platform_id,
			Outpost_Manual_Share_Audit_Log::STRATEGY_NAVIGATOR_SHARE
		);
	}

	// =====================================================================
	// /status/{post_id}
	// =====================================================================

	public function test_status_endpoint_returns_pending_for_uncompleted_entries(): void {
		$this->seed_entry( 42, 'instagram-feed' );
		$this->seed_entry( 42, 'facebook' );

		$response = Outpost_Manual_Share_Status_Controller::handle_status_request(
			$this->build_request( array( 'post_id' => 42 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 42, $payload['post_id'] );
		$this->assertSame( 'pending', $payload['status'] );
		$this->assertSame( 2, $payload['summary']['total'] );
		$this->assertSame( 0, $payload['summary']['complete'] );
	}

	public function test_status_endpoint_403_for_user_without_edit(): void {
		$this->user_can_edit_post = false;
		$result = Outpost_Manual_Share_Status_Controller::handle_status_request(
			$this->build_request( array( 'post_id' => 42 ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_post', $result->get_error_code() );
	}

	public function test_status_endpoint_400_for_zero_post_id(): void {
		$result = Outpost_Manual_Share_Status_Controller::handle_status_request(
			$this->build_request( array( 'post_id' => 0 ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
	}

	// =====================================================================
	// /pending-summary
	// =====================================================================

	public function test_pending_summary_returns_count_and_oldest(): void {
		// Two posts with old-enough pending entries.
		$old_iso = gmdate( 'c', time() - 3600 ); // 1 hour ago
		$older_iso = gmdate( 'c', time() - 7200 ); // 2 hours ago

		$this->meta_store[42]['outpost_manual_share_log'] = array(
			array(
				'id'                       => 'a1',
				'version'                  => 1,
				'platform_id'              => 'instagram-feed',
				'fired_at'                 => $old_iso,
				'strategy'                 => 'navigator_share',
				'outcome'                  => 'unknown',
				'completed_at'             => null,
				'silo_url'                 => null,
				'reminder_dismissed_until' => null,
			),
		);
		$this->meta_store[100]['outpost_manual_share_log'] = array(
			array(
				'id'                       => 'a2',
				'version'                  => 1,
				'platform_id'              => 'facebook',
				'fired_at'                 => $older_iso,
				'strategy'                 => 'navigator_share',
				'outcome'                  => 'unknown',
				'completed_at'             => null,
				'silo_url'                 => null,
				'reminder_dismissed_until' => null,
			),
		);
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array( 42, 100 )
		);

		$response = Outpost_Manual_Share_Status_Controller::handle_pending_summary_request(
			$this->createMock( WP_REST_Request::class )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 2, $payload['count'] );
		$this->assertSame( $older_iso, $payload['oldest_fired_at'] );
		$this->assertCount( 2, $payload['posts'] );
		$this->assertTrue( $payload['can_snooze_all'] );
	}

	public function test_pending_summary_count_zero_for_user_with_no_pending(): void {
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array()
		);
		$response = Outpost_Manual_Share_Status_Controller::handle_pending_summary_request(
			$this->createMock( WP_REST_Request::class )
		);
		$payload = $response->get_data();
		$this->assertSame( 0, $payload['count'] );
		$this->assertNull( $payload['oldest_fired_at'] );
	}

	// =====================================================================
	// /dismiss-reminder
	// =====================================================================

	public function test_dismiss_endpoint_writes_reminder_dismissed_until(): void {
		$entry = $this->seed_entry( 42, 'instagram-feed' );

		$response = Outpost_Manual_Share_Status_Controller::handle_dismiss_request(
			$this->build_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'until'        => 'P1D',
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'snoozed', $payload['status'] );
		$this->assertNotEmpty( $payload['reminder_dismissed_until'] );

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertNotNull( $entries[0]['reminder_dismissed_until'] );
	}

	public function test_dismiss_endpoint_handles_forever(): void {
		$entry = $this->seed_entry( 42, 'instagram-feed' );

		$response = Outpost_Manual_Share_Status_Controller::handle_dismiss_request(
			$this->build_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'until'        => 'forever',
			) )
		);

		$payload = $response->get_data();
		$this->assertSame(
			Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			$payload['reminder_dismissed_until']
		);
	}

	public function test_dismiss_endpoint_404_for_unknown_audit_id(): void {
		$result = Outpost_Manual_Share_Status_Controller::handle_dismiss_request(
			$this->build_request( array(
				'post_id'      => 42,
				'audit_log_id' => 'not-real',
				'until'        => 'P1D',
			) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'audit_log_entry_not_found', $result->get_error_code() );
	}

	public function test_dismiss_endpoint_400_for_invalid_until(): void {
		$entry = $this->seed_entry( 42, 'instagram-feed' );

		$result = Outpost_Manual_Share_Status_Controller::handle_dismiss_request(
			$this->build_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'until'        => 'gibberish',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_until', $result->get_error_code() );
	}

	// =====================================================================
	// /snooze-all (rate-limited)
	// =====================================================================

	public function test_snooze_all_first_call_succeeds(): void {
		$entry = $this->seed_entry( 42, 'instagram-feed' );
		// Make entry old enough to clear grace period.
		$this->meta_store[42]['outpost_manual_share_log'][0]['fired_at'] = gmdate( 'c', time() - 3600 );
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array( 42 )
		);

		$response = Outpost_Manual_Share_Status_Controller::handle_snooze_all_request(
			$this->build_request( array( 'until' => 'P1D' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'snoozed_all', $payload['status'] );
		$this->assertSame( 1, $payload['count'] );
	}

	public function test_snooze_all_second_call_rate_limited(): void {
		$this->seed_entry( 42, 'instagram-feed' );
		$this->meta_store[42]['outpost_manual_share_log'][0]['fired_at'] = gmdate( 'c', time() - 3600 );
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array( 42 )
		);

		// First call records the rate-limit transient.
		Outpost_Manual_Share_Status_Controller::handle_snooze_all_request(
			$this->build_request( array( 'until' => 'P1D' ) )
		);

		// Second call → 429.
		$result = Outpost_Manual_Share_Status_Controller::handle_snooze_all_request(
			$this->build_request( array( 'until' => 'P3D' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
	}
}
