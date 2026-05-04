<?php
/**
 * Unit tests for Outpost_Manual_Share_Pending_Capture_Detector (F12).
 *
 * Uses the test-only candidate-resolver injection to avoid touching
 * WP_Query during unit tests.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Pending_Capture_Detector;
use Outpost_Manual_Share_Audit_Log;
use WP_Mock;

final class PendingCaptureDetectorTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();
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
		WP_Mock::userFunction( 'get_post' )->andReturnUsing(
			static fn ( int $post_id ) => new \WP_Post( array(
				'ID'         => $post_id,
				'post_title' => 'Post ' . $post_id,
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

	private function inject_candidates( array $post_ids ): void {
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => $post_ids
		);
	}

	private function seed_log( int $post_id, array $entries ): void {
		$this->meta_store[ $post_id ]['outpost_manual_share_log'] = $entries;
	}

	private function entry( array $overrides = array() ): array {
		$now = time();
		return array_merge(
			array(
				'id'           => 'eid-' . bin2hex( random_bytes( 4 ) ),
				'version'      => 1,
				'platform_id'  => 'instagram-feed',
				'fired_at'     => gmdate( 'c', $now - 120 ), // 2 minutes ago
				'strategy'     => 'navigator_share',
				'outcome'      => 'unknown',
				'completed_at' => null,
				'silo_url'     => null,
			),
			$overrides
		);
	}

	// =====================================================================
	// Empty-input handling
	// =====================================================================

	public function test_returns_empty_for_user_id_zero(): void {
		$this->inject_candidates( array( 42 ) );
		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 0 );
		$this->assertSame( array(), $result );
	}

	public function test_returns_empty_when_resolver_returns_no_candidates(): void {
		$this->inject_candidates( array() );
		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( array(), $result );
	}

	// =====================================================================
	// Date-boundary filtering
	// =====================================================================

	public function test_grace_period_excludes_recently_fired_entries(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry( array(
			'fired_at' => gmdate( 'c', time() - 5 ), // 5 seconds ago
		) ) ) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( array(), $result );
	}

	public function test_retention_window_excludes_old_entries(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry( array(
			'fired_at' => gmdate( 'c', time() - ( 31 * DAY_IN_SECONDS ) ),
		) ) ) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( array(), $result );
	}

	public function test_completed_entries_excluded(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry( array(
			'completed_at' => gmdate( 'c' ),
			'silo_url'     => 'https://example.com/posts/abc',
		) ) ) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( array(), $result );
	}

	public function test_pending_entry_within_window_returned(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry() ) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['post_id'] );
		$this->assertCount( 1, $result[0]['entries'] );
	}

	public function test_post_with_mixed_completed_and_pending_returns_only_pending(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array(
			$this->entry( array(
				'platform_id' => 'instagram-feed',
				'completed_at' => gmdate( 'c' ),
			) ),
			$this->entry( array(
				'platform_id' => 'facebook',
			) ),
		) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertCount( 1, $result );
		$this->assertCount( 1, $result[0]['entries'] );
		$this->assertSame( 'facebook', $result[0]['entries'][0]['platform_id'] );
	}

	public function test_post_record_includes_title_and_permalink(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry() ) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( 'Post 42', $result[0]['post_title'] );
		$this->assertSame( 'https://example.com/posts/42', $result[0]['permalink'] );
	}

	public function test_results_sorted_by_most_recent_pending_descending(): void {
		$this->inject_candidates( array( 100, 200 ) );
		$this->seed_log( 100, array( $this->entry( array(
			'fired_at' => gmdate( 'c', time() - 3600 ), // 1 hour ago
		) ) ) );
		$this->seed_log( 200, array( $this->entry( array(
			'fired_at' => gmdate( 'c', time() - 600 ), // 10 minutes ago
		) ) ) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( 200, $result[0]['post_id'] );
		$this->assertSame( 100, $result[1]['post_id'] );
	}

	public function test_post_with_no_audit_log_meta_dropped(): void {
		$this->inject_candidates( array( 42, 100 ) );
		$this->seed_log( 100, array( $this->entry() ) );
		// 42 has no audit log → dropped silently.

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertCount( 1, $result );
		$this->assertSame( 100, $result[0]['post_id'] );
	}

	public function test_custom_grace_seconds_override(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry( array(
			'fired_at' => gmdate( 'c', time() - 10 ), // 10 seconds ago
		) ) ) );

		$with_default = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( array(), $with_default ); // 10s < 30s default grace

		$with_short = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7, 5 );
		$this->assertCount( 1, $with_short ); // 10s > 5s custom grace
	}

	public function test_custom_retention_days_override(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry( array(
			'fired_at' => gmdate( 'c', time() - ( 5 * DAY_IN_SECONDS ) ),
		) ) ) );

		$with_default = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertCount( 1, $with_default );

		$with_short = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7, 30, 1 );
		$this->assertSame( array(), $with_short );
	}

	public function test_malformed_fired_at_dropped(): void {
		$this->inject_candidates( array( 42 ) );
		$this->seed_log( 42, array( $this->entry( array(
			'fired_at' => 'not-a-timestamp',
		) ) ) );

		$result = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( 7 );
		$this->assertSame( array(), $result );
	}
}
