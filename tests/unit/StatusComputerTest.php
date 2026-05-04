<?php
/**
 * Unit tests for Outpost_Manual_Share_Status_Computer (F13).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Status_Computer;
use Outpost_Manual_Share_Audit_Log;
use WP_Mock;

final class StatusComputerTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function entry( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                       => 'eid-' . bin2hex( random_bytes( 4 ) ),
				'version'                  => 1,
				'platform_id'              => 'instagram-feed',
				'fired_at'                 => '2026-05-04T12:00:00+00:00',
				'strategy'                 => 'navigator_share',
				'outcome'                  => 'fired',
				'completed_at'             => null,
				'silo_url'                 => null,
				'reminder_dismissed_until' => null,
			),
			$overrides
		);
	}

	// =====================================================================
	// Status branches
	// =====================================================================

	public function test_no_syndication_for_empty_entries(): void {
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( array() );
		$this->assertSame( 'no_syndication', $status );
	}

	public function test_complete_when_all_entries_have_completed_at(): void {
		$entries = array(
			$this->entry( array( 'completed_at' => '2026-05-04T13:00:00+00:00' ) ),
			$this->entry( array( 'completed_at' => '2026-05-04T13:05:00+00:00' ) ),
		);
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$this->assertSame( 'complete', $status );
	}

	public function test_pending_when_no_entries_completed(): void {
		$entries = array( $this->entry(), $this->entry() );
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$this->assertSame( 'pending', $status );
	}

	public function test_partial_when_some_entries_completed(): void {
		$entries = array(
			$this->entry( array( 'completed_at' => '2026-05-04T13:00:00+00:00' ) ),
			$this->entry(),
		);
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$this->assertSame( 'partial', $status );
	}

	public function test_abandoned_when_all_entries_marked_abandoned(): void {
		$entries = array(
			$this->entry( array(
				'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			) ),
			$this->entry( array(
				'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			) ),
		);
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$this->assertSame( 'abandoned', $status );
	}

	public function test_short_snooze_does_not_count_as_abandoned(): void {
		// reminder_dismissed_until set 1 week out → still pending, not abandoned.
		$entries = array(
			$this->entry( array(
				'reminder_dismissed_until' => gmdate( 'c', time() + ( 7 * DAY_IN_SECONDS ) ),
			) ),
		);
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$this->assertSame( 'pending', $status );
	}

	public function test_partial_takes_precedence_over_abandoned_when_mixed(): void {
		// One completed + one abandoned = partial (NOT abandoned, since
		// abandoned status requires every entry abandoned).
		$entries = array(
			$this->entry( array( 'completed_at' => '2026-05-04T13:00:00+00:00' ) ),
			$this->entry( array(
				'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			) ),
		);
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$this->assertSame( 'partial', $status );
	}

	public function test_pending_when_some_pending_and_some_abandoned_no_complete(): void {
		// Mixed pending + abandoned with no completion = pending
		// (status reflects "user can still act on this").
		$entries = array(
			$this->entry(),
			$this->entry( array(
				'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			) ),
		);
		$status = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$this->assertSame( 'pending', $status );
	}

	// =====================================================================
	// summarize_entries
	// =====================================================================

	public function test_summarize_counts(): void {
		$entries = array(
			$this->entry( array( 'completed_at' => '2026-05-04T13:00:00+00:00' ) ),
			$this->entry(),
			$this->entry( array(
				'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			) ),
		);
		$summary = Outpost_Manual_Share_Status_Computer::summarize_entries( $entries );
		$this->assertSame( 3, $summary['total'] );
		$this->assertSame( 1, $summary['complete'] );
		$this->assertSame( 1, $summary['pending'] );
		$this->assertSame( 1, $summary['abandoned'] );
	}

	public function test_summarize_empty_entries_zeros(): void {
		$summary = Outpost_Manual_Share_Status_Computer::summarize_entries( array() );
		$this->assertSame( 0, $summary['total'] );
		$this->assertSame( 0, $summary['complete'] );
		$this->assertSame( 0, $summary['pending'] );
		$this->assertSame( 0, $summary['abandoned'] );
	}

	// =====================================================================
	// is_abandoned
	// =====================================================================

	public function test_is_abandoned_true_for_far_future_timestamp(): void {
		$entry = $this->entry( array(
			'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
		) );
		$this->assertTrue( Outpost_Manual_Share_Status_Computer::is_abandoned( $entry ) );
	}

	public function test_is_abandoned_false_for_short_snooze(): void {
		$entry = $this->entry( array(
			'reminder_dismissed_until' => gmdate( 'c', time() + DAY_IN_SECONDS ),
		) );
		$this->assertFalse( Outpost_Manual_Share_Status_Computer::is_abandoned( $entry ) );
	}

	public function test_is_abandoned_false_for_null_reminder(): void {
		$entry = $this->entry();
		$this->assertFalse( Outpost_Manual_Share_Status_Computer::is_abandoned( $entry ) );
	}
}
