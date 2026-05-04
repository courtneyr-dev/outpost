<?php
/**
 * Unit tests for Outpost_Syndication_Admin_Column (F13).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Syndication_Admin_Column;
use Outpost_Manual_Share_Audit_Log;
use WP_Mock;

final class SyndicationAdminColumnTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static fn ( string $s ): string => $s );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( static fn ( string $s ): string => $s );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta_store[ $post_id ][ $key ] ?? '';
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// =====================================================================
	// add_column
	// =====================================================================

	public function test_add_column_appends_syndication_key(): void {
		$columns = Outpost_Syndication_Admin_Column::add_column( array(
			'cb'    => '',
			'title' => 'Title',
		) );
		$this->assertArrayHasKey( 'outpost_syndication', $columns );
		$this->assertSame( 'Syndication', $columns['outpost_syndication'] );
	}

	// =====================================================================
	// render_badge_html — per status
	// =====================================================================

	public function test_render_badge_no_syndication_for_post_without_log(): void {
		$html = Outpost_Syndication_Admin_Column::render_badge_html( 42 );

		$this->assertStringContainsString( 'data-status="no_syndication"', $html );
		$this->assertStringContainsString( 'aria-label="No syndication"', $html );
		$this->assertStringContainsString( '—', $html );
	}

	public function test_render_badge_complete_when_all_entries_completed(): void {
		$this->meta_store[42]['outpost_manual_share_log'] = array(
			array(
				'id'                       => 'a',
				'version'                  => 1,
				'platform_id'              => 'instagram-feed',
				'fired_at'                 => '2026-05-04T12:00:00+00:00',
				'strategy'                 => 'navigator_share',
				'outcome'                  => 'fired',
				'completed_at'             => '2026-05-04T13:00:00+00:00',
				'silo_url'                 => 'https://example.com/p/abc',
				'reminder_dismissed_until' => null,
			),
		);

		$html = Outpost_Syndication_Admin_Column::render_badge_html( 42 );

		$this->assertStringContainsString( 'data-status="complete"', $html );
		$this->assertStringContainsString( 'Syndication complete', $html );
		$this->assertStringContainsString( '✓', $html );
		$this->assertStringContainsString( '1/1', $html );
	}

	public function test_render_badge_partial_when_some_completed(): void {
		$this->meta_store[42]['outpost_manual_share_log'] = array(
			array(
				'id'                       => 'a',
				'version'                  => 1,
				'platform_id'              => 'instagram-feed',
				'fired_at'                 => '2026-05-04T12:00:00+00:00',
				'strategy'                 => 'navigator_share',
				'outcome'                  => 'fired',
				'completed_at'             => '2026-05-04T13:00:00+00:00',
				'silo_url'                 => 'https://example.com/p/abc',
				'reminder_dismissed_until' => null,
			),
			array(
				'id'                       => 'b',
				'version'                  => 1,
				'platform_id'              => 'facebook',
				'fired_at'                 => '2026-05-04T12:01:00+00:00',
				'strategy'                 => 'navigator_share',
				'outcome'                  => 'unknown',
				'completed_at'             => null,
				'silo_url'                 => null,
				'reminder_dismissed_until' => null,
			),
		);

		$html = Outpost_Syndication_Admin_Column::render_badge_html( 42 );

		$this->assertStringContainsString( 'data-status="partial"', $html );
		$this->assertStringContainsString( '1 of 2', $html );
		$this->assertStringContainsString( '⏳', $html );
	}

	public function test_render_badge_pending_when_none_completed(): void {
		$this->meta_store[42]['outpost_manual_share_log'] = array(
			array(
				'id'                       => 'a',
				'version'                  => 1,
				'platform_id'              => 'instagram-feed',
				'fired_at'                 => '2026-05-04T12:00:00+00:00',
				'strategy'                 => 'navigator_share',
				'outcome'                  => 'unknown',
				'completed_at'             => null,
				'silo_url'                 => null,
				'reminder_dismissed_until' => null,
			),
		);

		$html = Outpost_Syndication_Admin_Column::render_badge_html( 42 );

		$this->assertStringContainsString( 'data-status="pending"', $html );
		$this->assertStringContainsString( 'Syndication pending', $html );
		$this->assertStringContainsString( '0/1', $html );
	}

	public function test_render_badge_abandoned_when_all_marked_abandoned(): void {
		$this->meta_store[42]['outpost_manual_share_log'] = array(
			array(
				'id'                       => 'a',
				'version'                  => 1,
				'platform_id'              => 'tiktok',
				'fired_at'                 => '2026-05-04T12:00:00+00:00',
				'strategy'                 => 'app_url_scheme',
				'outcome'                  => 'unknown',
				'completed_at'             => null,
				'silo_url'                 => null,
				'reminder_dismissed_until' => Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL,
			),
		);

		$html = Outpost_Syndication_Admin_Column::render_badge_html( 42 );

		$this->assertStringContainsString( 'data-status="abandoned"', $html );
		$this->assertStringContainsString( '⚠', $html );
		$this->assertStringContainsString( 'abandoned', $html );
	}

	// =====================================================================
	// Aria-label conveyance (no-color-only)
	// =====================================================================

	public function test_every_status_has_text_aria_label_independent_of_glyph(): void {
		$cases = array(
			'no_syndication' => array( 'label' => 'No syndication', 'entries' => array() ),
			'complete'       => array(
				'label'   => 'Syndication complete',
				'entries' => array( $this->entry_for_status( 'complete' ) ),
			),
			'partial'        => array(
				'label'   => 'Syndication partial',
				'entries' => array(
					$this->entry_for_status( 'complete' ),
					$this->entry_for_status( 'pending' ),
				),
			),
			'pending'        => array(
				'label'   => 'Syndication pending',
				'entries' => array( $this->entry_for_status( 'pending' ) ),
			),
			'abandoned'      => array(
				'label'   => 'Syndication abandoned',
				'entries' => array( $this->entry_for_status( 'abandoned' ) ),
			),
		);

		foreach ( $cases as $status => $case ) {
			$this->meta_store = array();
			if ( ! empty( $case['entries'] ) ) {
				$this->meta_store[42]['outpost_manual_share_log'] = $case['entries'];
			}
			$html = Outpost_Syndication_Admin_Column::render_badge_html( 42 );
			$this->assertStringContainsString(
				$case['label'],
				$html,
				sprintf( 'Status %s missing aria-label "%s"', $status, $case['label'] )
			);
		}
	}

	private function entry_for_status( string $status ): array {
		$base = array(
			'id'                       => 'a',
			'version'                  => 1,
			'platform_id'              => 'instagram-feed',
			'fired_at'                 => '2026-05-04T12:00:00+00:00',
			'strategy'                 => 'navigator_share',
			'outcome'                  => 'unknown',
			'completed_at'             => null,
			'silo_url'                 => null,
			'reminder_dismissed_until' => null,
		);
		switch ( $status ) {
			case 'complete':
				$base['completed_at'] = '2026-05-04T13:00:00+00:00';
				break;
			case 'abandoned':
				$base['reminder_dismissed_until'] = Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL;
				break;
			case 'partial':
				// Partial requires multiple entries; this helper only
				// produces single-entry shapes. Caller should instead
				// pass a tailored entries array for partial.
				$base['completed_at'] = '2026-05-04T13:00:00+00:00';
				break;
			case 'pending':
			default:
				// Already pending by default.
		}
		return $base;
	}
}
