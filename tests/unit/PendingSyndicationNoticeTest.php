<?php
/**
 * Unit tests for Outpost_Pending_Syndication_Notice (F12).
 *
 * The `admin_notices` hook is structurally invisible in the block
 * editor: core prints its output inside
 * `<div class="wrap hide-if-js block-editor-no-js">`, which is the
 * no-JS fallback container and is hidden whenever the editor loads.
 * These tests pin the block-editor surface — an inline payload
 * attached to the sidebar bundle, which the bundle turns into a
 * `core/notices` notice — and pin that the classic-editor
 * `admin_notices` path still renders.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Pending_Syndication_Notice;
use Outpost_Sidebar_Assets;
use WP_Mock;

/**
 * Stand-in for WP_Screen. The SUT only reads `base` and calls
 * `is_block_editor()`.
 */
final class Fake_Editor_Screen {
	public string $base = 'post';
	public bool $block_editor = true;

	public function is_block_editor(): bool {
		return $this->block_editor;
	}
}

final class PendingSyndicationNoticeTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	private Fake_Editor_Screen $screen;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();
		$this->screen     = new Fake_Editor_Screen();
		$_GET             = array();
		unset( $GLOBALS['post'] );
		wp_scripts()->reset();
		outpost_test_reset_hooks();

		WP_Mock::userFunction( 'get_current_screen' )->andReturnUsing(
			fn () => $this->screen
		);
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta_store[ $post_id ][ $key ] ?? '';
			}
		);
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
		WP_Mock::userFunction( 'home_url' )->andReturnUsing(
			static fn ( string $path = '' ): string => 'https://example.com' . $path
		);
		WP_Mock::userFunction( 'human_time_diff' )->andReturn( '2 hours' );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( static fn ( string $s ): string => $s );

		\Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array( 42 )
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );
		wp_scripts()->reset();
		outpost_test_reset_hooks();
		$_GET = array();
		unset( $GLOBALS['post'] );
	}

	/**
	 * @param array<int, array<string, mixed>> $overrides
	 */
	private function seed_pending( int $post_id, array $overrides = array() ): void {
		$defaults = array(
			array( 'platform_id' => 'instagram-feed' ),
			array( 'platform_id' => 'linkedin' ),
		);
		$entries  = array();
		foreach ( ( $overrides ?: $defaults ) as $i => $override ) {
			$entries[] = array_merge(
				array(
					'id'           => 'eid-' . $i,
					'version'      => 1,
					'platform_id'  => 'instagram-feed',
					'fired_at'     => gmdate( 'c', time() - 7200 ),
					'strategy'     => 'navigator_share',
					'outcome'      => 'unknown',
					'completed_at' => null,
					'silo_url'     => null,
				),
				$override
			);
		}
		$this->meta_store[ $post_id ]['outpost_manual_share_log'] = $entries;
	}

	/** @return array<string, string> handle => inline payload */
	private function inline_scripts(): array {
		return wp_scripts()->inline;
	}

	private function decoded_payload(): array {
		$inline = $this->inline_scripts();
		$this->assertArrayHasKey(
			Outpost_Sidebar_Assets::HANDLE,
			$inline,
			'Expected the payload attached to the block-editor bundle handle.'
		);
		// 'before' matters: the bundle reads the global as it evaluates.
		$this->assertSame(
			'before',
			wp_scripts()->inline_positions[ Outpost_Sidebar_Assets::HANDLE ]
		);

		$data = $inline[ Outpost_Sidebar_Assets::HANDLE ];
		$this->assertStringStartsWith( 'window.outpostPendingSyndication = ', $data );

		$json = null;
		if ( 1 === preg_match( '/=\s*(\{.*\})\s*;\s*$/s', $data, $m ) ) {
			$json = json_decode( $m[1], true );
		}
		$this->assertIsArray( $json, 'Inline payload should assign a JSON object.' );
		return $json;
	}

	// =====================================================================
	// Hook registration
	// =====================================================================

	public function test_register_hooks_the_block_editor_surface(): void {
		Outpost_Pending_Syndication_Notice::register();

		$this->assertSame(
			array( array( Outpost_Pending_Syndication_Notice::class, 'maybe_render' ) ),
			array_column( outpost_test_actions_for( 'admin_notices' ), 'callback' )
		);

		$editor = outpost_test_actions_for( 'enqueue_block_editor_assets' );
		$this->assertCount(
			1,
			$editor,
			'register() must wire a block-editor surface — admin_notices alone is invisible there.'
		);
		$this->assertSame(
			array( Outpost_Pending_Syndication_Notice::class, 'enqueue_editor_notice' ),
			$editor[0]['callback']
		);
		// After Outpost_Sidebar_Assets::enqueue() at the default 10, so
		// the handle exists when wp_add_inline_script() runs.
		$this->assertSame( 20, $editor[0]['priority'] );
	}

	// =====================================================================
	// Block-editor payload
	// =====================================================================

	public function test_editor_notice_payload_reaches_the_block_editor(): void {
		$this->seed_pending( 42 );
		$_GET['post'] = '42';

		Outpost_Pending_Syndication_Notice::enqueue_editor_notice();

		$payload = $this->decoded_payload();
		$this->assertSame( 42, $payload['postId'] );
		$this->assertSame( 2, $payload['count'] );
		$this->assertSame( 'https://example.com/post/', $payload['composerUrl'] );
		$this->assertSame(
			array( 'Instagram', 'LinkedIn' ),
			array_column( $payload['platforms'], 'label' )
		);
		$this->assertSame( 'fired 2 hours ago', $payload['platforms'][0]['firedHuman'] );
		$this->assertSame( 'navigator_share', $payload['platforms'][0]['strategy'] );
		$this->assertStringContainsString( '2 pending syndications', $payload['message'] );
	}

	public function test_no_payload_when_the_post_has_nothing_pending(): void {
		$_GET['post'] = '42';

		Outpost_Pending_Syndication_Notice::enqueue_editor_notice();

		$this->assertSame( array(), $this->inline_scripts() );
	}

	public function test_no_payload_outside_the_post_editor(): void {
		$this->seed_pending( 42 );
		$_GET['post']       = '42';
		$this->screen->base = 'site-editor';

		Outpost_Pending_Syndication_Notice::enqueue_editor_notice();

		$this->assertSame( array(), $this->inline_scripts() );
	}

	// =====================================================================
	// Classic editor keeps the admin_notices path
	// =====================================================================

	public function test_admin_notices_still_renders_for_the_classic_editor(): void {
		$this->seed_pending( 42 );
		$_GET['post']               = '42';
		$this->screen->block_editor = false;

		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static fn ( string $s ): string => $s );

		ob_start();
		Outpost_Pending_Syndication_Notice::maybe_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'outpost-pending-syndication-notice', $html );
		$this->assertStringContainsString( 'Instagram', $html );
	}

	public function test_admin_notices_skips_the_hidden_block_editor_container(): void {
		$this->seed_pending( 42 );
		$_GET['post']               = '42';
		$this->screen->block_editor = true;

		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static fn ( string $s ): string => $s );

		ob_start();
		Outpost_Pending_Syndication_Notice::maybe_render();
		$html = (string) ob_get_clean();

		$this->assertSame(
			'',
			$html,
			'admin_notices output lands in .hide-if-js.block-editor-no-js and is never visible; '
				. 'the block editor gets the notice through enqueue_editor_notice() instead.'
		);
	}
}
