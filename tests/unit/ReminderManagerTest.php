<?php
/**
 * Unit tests for Outpost_Manual_Share_Reminder_Manager (F13).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Reminder_Manager;
use Outpost_Manual_Share_Audit_Log;
use WP_Mock;

final class ReminderManagerTest extends \WP_Mock\Tools\TestCase {

	/** @var array<string, array{value: mixed, expires_at: int}> */
	private array $transient_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->transient_store = array();
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
		WP_Mock::userFunction( 'delete_transient' )->andReturnUsing(
			function ( string $key ): bool {
				unset( $this->transient_store[ $key ] );
				return true;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// =====================================================================
	// is_snoozed
	// =====================================================================

	public function test_is_snoozed_false_for_null_reminder(): void {
		$entry = array( 'reminder_dismissed_until' => null );
		$this->assertFalse( Outpost_Manual_Share_Reminder_Manager::is_snoozed( $entry ) );
	}

	public function test_is_snoozed_false_for_past_timestamp(): void {
		$entry = array(
			'reminder_dismissed_until' => gmdate( 'c', time() - 3600 ),
		);
		$this->assertFalse( Outpost_Manual_Share_Reminder_Manager::is_snoozed( $entry ) );
	}

	public function test_is_snoozed_true_for_future_timestamp(): void {
		$entry = array(
			'reminder_dismissed_until' => gmdate( 'c', time() + 3600 ),
		);
		$this->assertTrue( Outpost_Manual_Share_Reminder_Manager::is_snoozed( $entry ) );
	}

	public function test_is_snoozed_true_for_abandoned_sentinel(): void {
		$entry = array(
			'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
		);
		$this->assertTrue( Outpost_Manual_Share_Reminder_Manager::is_snoozed( $entry ) );
	}

	public function test_is_snoozed_false_for_malformed_timestamp(): void {
		$entry = array( 'reminder_dismissed_until' => 'not-a-timestamp' );
		$this->assertFalse( Outpost_Manual_Share_Reminder_Manager::is_snoozed( $entry ) );
	}

	// =====================================================================
	// visible_entries
	// =====================================================================

	public function test_visible_entries_drops_snoozed(): void {
		$entries = array(
			array( 'id' => 'a', 'reminder_dismissed_until' => null ),
			array( 'id' => 'b', 'reminder_dismissed_until' => gmdate( 'c', time() + 3600 ) ),
			array( 'id' => 'c', 'reminder_dismissed_until' => null ),
		);
		$visible = Outpost_Manual_Share_Reminder_Manager::visible_entries( $entries );
		$this->assertCount( 2, $visible );
		$this->assertSame( 'a', $visible[0]['id'] );
		$this->assertSame( 'c', $visible[1]['id'] );
	}

	public function test_visible_entries_drops_abandoned(): void {
		$entries = array(
			array(
				'id'                       => 'a',
				'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			),
			array( 'id' => 'b', 'reminder_dismissed_until' => null ),
		);
		$visible = Outpost_Manual_Share_Reminder_Manager::visible_entries( $entries );
		$this->assertCount( 1, $visible );
		$this->assertSame( 'b', $visible[0]['id'] );
	}

	// =====================================================================
	// resolve_until
	// =====================================================================

	public function test_resolve_until_handles_iso8601_string(): void {
		$result = Outpost_Manual_Share_Reminder_Manager::resolve_until( '2026-12-25T00:00:00+00:00' );
		$this->assertNotNull( $result );
		$this->assertSame( '2026-12-25T00:00:00+00:00', $result );
	}

	public function test_resolve_until_handles_p1d_shorthand(): void {
		$result = Outpost_Manual_Share_Reminder_Manager::resolve_until( 'P1D' );
		$this->assertNotNull( $result );
		// Roughly 1 day from now (allow 60s tolerance for test runtime).
		$diff = strtotime( $result ) - time();
		$this->assertGreaterThanOrEqual( DAY_IN_SECONDS - 60, $diff );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS + 60, $diff );
	}

	public function test_resolve_until_handles_p7d_shorthand(): void {
		$result = Outpost_Manual_Share_Reminder_Manager::resolve_until( 'P7D' );
		$this->assertNotNull( $result );
		$diff = strtotime( $result ) - time();
		$this->assertGreaterThanOrEqual( ( 7 * DAY_IN_SECONDS ) - 60, $diff );
	}

	public function test_resolve_until_forever_returns_abandoned_sentinel(): void {
		$result = Outpost_Manual_Share_Reminder_Manager::resolve_until( 'forever' );
		$this->assertSame(
			Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			$result
		);
	}

	public function test_resolve_until_invalid_returns_null(): void {
		$result = Outpost_Manual_Share_Reminder_Manager::resolve_until( 'totally-not-a-date' );
		$this->assertNull( $result );
	}

	public function test_resolve_until_empty_returns_null(): void {
		$result = Outpost_Manual_Share_Reminder_Manager::resolve_until( '' );
		$this->assertNull( $result );
	}

	// =====================================================================
	// can_snooze_all + record_snooze_all (rate limit)
	// =====================================================================

	public function test_can_snooze_all_true_initially(): void {
		$this->assertTrue( Outpost_Manual_Share_Reminder_Manager::can_snooze_all( 7 ) );
	}

	public function test_can_snooze_all_false_after_record(): void {
		Outpost_Manual_Share_Reminder_Manager::record_snooze_all( 7 );
		$this->assertFalse( Outpost_Manual_Share_Reminder_Manager::can_snooze_all( 7 ) );
	}

	public function test_can_snooze_all_per_user_isolated(): void {
		Outpost_Manual_Share_Reminder_Manager::record_snooze_all( 7 );
		// User 8 hasn't used snooze-all → still allowed.
		$this->assertTrue( Outpost_Manual_Share_Reminder_Manager::can_snooze_all( 8 ) );
	}

	public function test_can_snooze_all_false_for_invalid_user_id(): void {
		$this->assertFalse( Outpost_Manual_Share_Reminder_Manager::can_snooze_all( 0 ) );
		$this->assertFalse( Outpost_Manual_Share_Reminder_Manager::can_snooze_all( -1 ) );
	}

	public function test_reset_rate_limit_for_tests(): void {
		Outpost_Manual_Share_Reminder_Manager::record_snooze_all( 7 );
		$this->assertFalse( Outpost_Manual_Share_Reminder_Manager::can_snooze_all( 7 ) );
		Outpost_Manual_Share_Reminder_Manager::reset_rate_limit_for_tests( 7 );
		$this->assertTrue( Outpost_Manual_Share_Reminder_Manager::can_snooze_all( 7 ) );
	}
}
