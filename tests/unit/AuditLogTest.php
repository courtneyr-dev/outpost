<?php
/**
 * Unit tests for Outpost_Manual_Share_Audit_Log (F10).
 *
 * Verifies the audit-log read/write helper:
 *
 *   - `add_entry` returns a versioned shape with id + fired_at + outcome
 *     ='unknown', writes to post-meta.
 *   - `update_entry` patches `outcome` / `completed_at` / `silo_url`,
 *     ignores unknown keys, sanitizes inputs.
 *   - `get_entries` returns valid entries only (drops malformed shapes).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Audit_Log;
use WP_Mock;

final class AuditLogTest extends \WP_Mock\Tools\TestCase {

	/**
	 * Per-test in-memory post meta. Lets us simulate update/get round-trips
	 * without touching real WP. Keyed by post_id.
	 *
	 * @var array<int, mixed>
	 */
	private array $meta_store;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();

		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'test-uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing(
			static fn ( string $url ): string => $url
		);
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
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// =====================================================================
	// add_entry
	// =====================================================================

	public function test_add_entry_returns_versioned_shape(): void {
		$entry = Outpost_Manual_Share_Audit_Log::add_entry(
			42,
			'instagram-feed',
			Outpost_Manual_Share_Audit_Log::STRATEGY_NAVIGATOR_SHARE
		);

		$this->assertArrayHasKey( 'id', $entry );
		$this->assertNotEmpty( $entry['id'] );
		$this->assertSame( 1, $entry['version'] );
		$this->assertSame( 'instagram-feed', $entry['platform_id'] );
		$this->assertSame( 'navigator_share', $entry['strategy'] );
		$this->assertSame( 'unknown', $entry['outcome'] );
		$this->assertNull( $entry['completed_at'] );
		$this->assertNull( $entry['silo_url'] );
		// fired_at is an ISO 8601 timestamp.
		$this->assertNotEmpty( $entry['fired_at'] );
		$this->assertNotFalse( strtotime( $entry['fired_at'] ) );
	}

	public function test_add_entry_appends_to_post_meta(): void {
		Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		Outpost_Manual_Share_Audit_Log::add_entry( 42, 'facebook', 'intent_url' );

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertCount( 2, $entries );
		$this->assertSame( 'instagram-feed', $entries[0]['platform_id'] );
		$this->assertSame( 'facebook', $entries[1]['platform_id'] );
	}

	public function test_add_entry_for_separate_posts_does_not_cross_contaminate(): void {
		Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		Outpost_Manual_Share_Audit_Log::add_entry( 100, 'facebook', 'intent_url' );

		$this->assertCount( 1, Outpost_Manual_Share_Audit_Log::get_entries( 42 ) );
		$this->assertCount( 1, Outpost_Manual_Share_Audit_Log::get_entries( 100 ) );
	}

	// =====================================================================
	// update_entry
	// =====================================================================

	public function test_update_entry_patches_outcome(): void {
		$entry = Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		$ok    = Outpost_Manual_Share_Audit_Log::update_entry(
			42,
			$entry['id'],
			array( 'outcome' => 'fired' )
		);

		$this->assertTrue( $ok );
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertSame( 'fired', $entries[0]['outcome'] );
	}

	public function test_update_entry_unknown_outcome_falls_back_to_unknown(): void {
		$entry = Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		Outpost_Manual_Share_Audit_Log::update_entry(
			42,
			$entry['id'],
			array( 'outcome' => 'totally-fake-outcome' )
		);

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertSame( 'unknown', $entries[0]['outcome'] );
	}

	public function test_update_entry_patches_completed_at_and_silo_url(): void {
		$entry = Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		Outpost_Manual_Share_Audit_Log::update_entry(
			42,
			$entry['id'],
			array(
				'completed_at' => '2026-05-04T18:32:11+00:00',
				'silo_url'     => 'https://example.com/posts/123',
			)
		);

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertSame( '2026-05-04T18:32:11+00:00', $entries[0]['completed_at'] );
		$this->assertSame( 'https://example.com/posts/123', $entries[0]['silo_url'] );
	}

	public function test_update_entry_silo_url_must_be_http_or_https(): void {
		$entry = Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		Outpost_Manual_Share_Audit_Log::update_entry(
			42,
			$entry['id'],
			array( 'silo_url' => 'javascript:alert(1)' )
		);

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertNull( $entries[0]['silo_url'] );
	}

	public function test_update_entry_invalid_completed_at_persists_as_null(): void {
		$entry = Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		Outpost_Manual_Share_Audit_Log::update_entry(
			42,
			$entry['id'],
			array( 'completed_at' => 'not-a-date' )
		);

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertNull( $entries[0]['completed_at'] );
	}

	public function test_update_entry_with_unknown_id_returns_false(): void {
		Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		$ok = Outpost_Manual_Share_Audit_Log::update_entry(
			42,
			'nonexistent-id',
			array( 'outcome' => 'fired' )
		);

		$this->assertFalse( $ok );
	}

	public function test_update_entry_drops_unknown_patch_keys(): void {
		$entry = Outpost_Manual_Share_Audit_Log::add_entry( 42, 'instagram-feed', 'navigator_share' );
		Outpost_Manual_Share_Audit_Log::update_entry(
			42,
			$entry['id'],
			array(
				'outcome'      => 'fired',
				'evil_payload' => 'malicious',
				'platform_id'  => 'attempted-overwrite',
			)
		);

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertSame( 'fired', $entries[0]['outcome'] );
		$this->assertSame( 'instagram-feed', $entries[0]['platform_id'] );
		$this->assertArrayNotHasKey( 'evil_payload', $entries[0] );
	}

	// =====================================================================
	// get_entries
	// =====================================================================

	public function test_get_entries_returns_empty_for_post_with_no_log(): void {
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 999 );
		$this->assertSame( array(), $entries );
	}

	public function test_get_entries_drops_malformed_shapes(): void {
		// Simulate post-meta with a mix of good entries + corrupted shapes.
		$this->meta_store[42] = array(
			array(
				'id'           => 'good-entry',
				'version'      => 1,
				'platform_id'  => 'instagram-feed',
				'fired_at'     => '2026-05-04T00:00:00+00:00',
				'strategy'     => 'navigator_share',
				'outcome'      => 'fired',
				'completed_at' => null,
				'silo_url'     => null,
			),
			'not-an-array',
			array( 'missing-keys' => true ),
			null,
		);

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'good-entry', $entries[0]['id'] );
	}
}
