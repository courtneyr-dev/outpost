<?php
/**
 * Unit tests for Outpost_Bridgy_Publish_Log (F14).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Bridgy_Publish_Log;
use WP_Mock;

final class BridgyPublishLogTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();
		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn ( string $s ): string => preg_replace( '/[^a-z0-9_]/', '', strtolower( $s ) ) );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn ( string $s ): string => trim( $s ) );
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
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// =====================================================================
	// add_entry
	// =====================================================================

	public function test_add_entry_returns_pending_entry(): void {
		$entry = Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-mastodon', 'mastodon' );

		$this->assertSame( 'bridgy-mastodon', $entry['silo_chip_id'] );
		$this->assertSame( 'mastodon', $entry['silo_id'] );
		$this->assertSame( 'pending', $entry['outcome'] );
		$this->assertNull( $entry['completed_at'] );
		$this->assertNull( $entry['silo_url'] );
		$this->assertNotEmpty( $entry['id'] );
	}

	public function test_add_entry_appends_to_meta(): void {
		Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-mastodon', 'mastodon' );
		Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-bluesky', 'bluesky' );

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertCount( 2, $entries );
	}

	// =====================================================================
	// update_entry — outcome + completed_at
	// =====================================================================

	public function test_update_to_success_sets_completed_at(): void {
		$entry = Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-mastodon', 'mastodon' );
		$ok    = Outpost_Bridgy_Publish_Log::update_entry(
			42,
			$entry['id'],
			array(
				'outcome'  => 'success',
				'silo_url' => 'https://mastodon.example/@user/123',
			)
		);

		$this->assertTrue( $ok );
		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'success', $entries[0]['outcome'] );
		$this->assertNotNull( $entries[0]['completed_at'] );
		$this->assertSame( 'https://mastodon.example/@user/123', $entries[0]['silo_url'] );
	}

	public function test_update_to_failure_records_error_fields(): void {
		$entry = Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-flickr', 'flickr' );
		Outpost_Bridgy_Publish_Log::update_entry(
			42,
			$entry['id'],
			array(
				'outcome'       => 'failure',
				'error_code'    => 'auth_required',
				'error_message' => 'Reauthorize at brid.gy.',
			)
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'failure', $entries[0]['outcome'] );
		$this->assertSame( 'auth_required', $entries[0]['error_code'] );
		$this->assertSame( 'Reauthorize at brid.gy.', $entries[0]['error_message'] );
		$this->assertNotNull( $entries[0]['completed_at'] );
	}

	public function test_update_drops_unknown_patch_keys(): void {
		$entry = Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-mastodon', 'mastodon' );
		Outpost_Bridgy_Publish_Log::update_entry(
			42,
			$entry['id'],
			array(
				'outcome'      => 'success',
				'evil_payload' => 'malicious',
				'silo_id'      => 'overwrite-attempt',
			)
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'mastodon', $entries[0]['silo_id'] ); // not overwritten
		$this->assertArrayNotHasKey( 'evil_payload', $entries[0] );
	}

	public function test_update_unknown_outcome_falls_back_to_pending(): void {
		$entry = Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-mastodon', 'mastodon' );
		Outpost_Bridgy_Publish_Log::update_entry(
			42,
			$entry['id'],
			array( 'outcome' => 'totally-fake' )
		);
		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'pending', $entries[0]['outcome'] );
	}

	public function test_update_silo_url_rejects_non_http_scheme(): void {
		$entry = Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-mastodon', 'mastodon' );
		Outpost_Bridgy_Publish_Log::update_entry(
			42,
			$entry['id'],
			array( 'silo_url' => 'javascript:alert(1)' )
		);
		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertNull( $entries[0]['silo_url'] );
	}

	public function test_update_unknown_id_returns_false(): void {
		Outpost_Bridgy_Publish_Log::add_entry( 42, 'bridgy-mastodon', 'mastodon' );
		$ok = Outpost_Bridgy_Publish_Log::update_entry(
			42,
			'nonexistent-id',
			array( 'outcome' => 'success' )
		);
		$this->assertFalse( $ok );
	}

	// =====================================================================
	// get_entries
	// =====================================================================

	public function test_get_entries_returns_empty_for_post_with_no_log(): void {
		$this->assertSame( array(), Outpost_Bridgy_Publish_Log::get_entries( 999 ) );
	}

	public function test_get_entries_drops_malformed_shapes(): void {
		$this->meta_store[42][ Outpost_Bridgy_Publish_Log::meta_key() ] = array(
			array(
				'id'           => 'good',
				'version'      => 1,
				'silo_id'      => 'mastodon',
				'silo_chip_id' => 'bridgy-mastodon',
				'fired_at'     => '2026-05-04T00:00:00+00:00',
				'outcome'      => 'pending',
				'completed_at' => null,
				'silo_url'     => null,
			),
			'not-an-array',
			array( 'no-id' => true ),
		);
		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'good', $entries[0]['id'] );
	}
}
