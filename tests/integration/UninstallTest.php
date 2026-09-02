<?php
/**
 * Integration test: uninstall deletes Outpost's data and preserves everything
 * it does not own.
 *
 * Item 9 — the old uninstall.php deleted one option and left the rest (keys,
 * credentials, tokens, post meta, transients, the POSSE cron) behind. This
 * seeds every category of persistent state the census identified, runs
 * `Outpost_Uninstaller::clean_current_site()`, and asserts positive deletion
 * AND negative preservation (core/Yoast post meta, real posts, categories).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class UninstallTest extends TestCase {

	private int $user_id = 0;
	private int $post_id = 0;
	private int $attachment_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Uninstaller' ) ) {
			$this->markTestSkipped( 'Run via `npm run test:integration`.' );
		}
		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'uninstall_u_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'uninstall_u_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		$this->post_id       = (int) wp_insert_post(
			array(
				'post_title'  => 'uninstall-post-' . uniqid(),
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);
		$this->attachment_id = (int) wp_insert_post(
			array(
				'post_title'     => 'uninstall-att-' . uniqid(),
				'post_status'    => 'inherit',
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $this->post_id,
			)
		);
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( $this->post_id ) {
			wp_delete_post( $this->post_id, true );
		}
		if ( $this->attachment_id ) {
			wp_delete_post( $this->attachment_id, true );
		}
		if ( $this->user_id ) {
			wp_delete_user( $this->user_id );
		}
		foreach ( array( 'category' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'name' => 'Uninstall Keep' ) );
			foreach ( (array) $terms as $t ) {
				wp_delete_term( $t->term_id, $tax );
			}
		}
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function deletes_owned_state_and_preserves_the_rest(): void {
		// --- Seed OWNED state (must be gone after) ---
		update_option( 'outpost_rewrite_version', '1.0.4' );
		update_option( 'outpost_encryption_key', base64_encode( 'k' ) );
		update_option( 'outpost_settings', array( 'x' => 1 ) );
		update_option( 'outpost_settings_api_keys', array( 'beehiiv_api_key' => array( 'encrypted' => 'z' ) ) );
		update_option( 'outpost_bridgy_silos_enabled', array( 'bluesky' => true ) );
		update_option( 'outpost_creds_notion', 'cipher' );
		update_option( 'outpost_telegraph_author_name', 'Me' );

		update_user_meta( $this->user_id, 'outpost_appearance_mode', 'night' );
		update_user_meta( $this->user_id, 'outpost_appearance_overrides', array( 'a' => 'b' ) );
		update_user_meta( $this->user_id, 'outpost_ios_shortcut_token', 'plain-token' );
		update_user_meta( $this->user_id, 'outpost_ios_shortcut_first_seen', '2026-09-01' );
		update_user_meta( $this->user_id, 'outpost_dismissed_encryption_key_notice_1.0.4', '1' );
		update_user_meta( $this->user_id, 'outpost_creds_whoop', 'cipher' );
		update_user_meta( $this->user_id, 'outpost_telegraph_access_token_user_' . $this->user_id, 'legacy-plain' );

		update_post_meta( $this->post_id, '_outpost_place_name', 'A Cafe' );
		update_post_meta( $this->post_id, '_outpost_posse_targets', array( 'x' ) );
		update_post_meta( $this->post_id, 'outpost_syndication_links', array( array( 'url' => 'https://x' ) ) );
		update_post_meta( $this->post_id, 'outpost_manual_share_log', array( 'e' ) );

		set_transient( 'outpost_preview_rl_' . $this->user_id, 3, HOUR_IN_SECONDS );
		set_transient( 'outpost_notion_page_abc123', array( 'p' => 1 ), HOUR_IN_SECONDS );
		set_transient( 'outpost_oauth_state_deadbeef', array( 's' => 1 ), 600 );

		wp_schedule_single_event( time() + 3600, 'outpost_posse_dispatch', array( $this->post_id, 'bluesky', 1 ) );

		// --- Seed FOREIGN state (must survive) ---
		update_option( 'siteurl_like_but_not_ours', 'keep' );
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'keep this alt' );
		update_post_meta( $this->post_id, '_thumbnail_id', (string) $this->attachment_id );
		update_post_meta( $this->post_id, '_yoast_wpseo_focuskw', 'keep keyword' );
		update_user_meta( $this->user_id, 'nickname', 'Keep Nick' );
		$term = wp_insert_term( 'Uninstall Keep', 'category' );
		$term_id = is_array( $term ) ? (int) $term['term_id'] : 0;

		// --- Run the uninstaller ---
		\Outpost_Uninstaller::clean_current_site();
		wp_cache_flush();

		// --- OWNED state gone ---
		$this->assertFalse( get_option( 'outpost_rewrite_version', false ) );
		$this->assertFalse( get_option( 'outpost_encryption_key', false ) );
		$this->assertFalse( get_option( 'outpost_settings', false ) );
		$this->assertFalse( get_option( 'outpost_settings_api_keys', false ) );
		$this->assertFalse( get_option( 'outpost_bridgy_silos_enabled', false ) );
		$this->assertFalse( get_option( 'outpost_creds_notion', false ) );
		$this->assertFalse( get_option( 'outpost_telegraph_author_name', false ) );

		$this->assertSame( '', get_user_meta( $this->user_id, 'outpost_appearance_mode', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'outpost_appearance_overrides', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'outpost_ios_shortcut_token', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'outpost_ios_shortcut_first_seen', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'outpost_dismissed_encryption_key_notice_1.0.4', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'outpost_creds_whoop', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'outpost_telegraph_access_token_user_' . $this->user_id, true ) );

		$this->assertSame( '', get_post_meta( $this->post_id, '_outpost_place_name', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_outpost_posse_targets', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, 'outpost_syndication_links', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, 'outpost_manual_share_log', true ) );

		$this->assertFalse( get_transient( 'outpost_preview_rl_' . $this->user_id ) );
		$this->assertFalse( get_transient( 'outpost_notion_page_abc123' ) );
		$this->assertFalse( get_transient( 'outpost_oauth_state_deadbeef' ) );

		$this->assertFalse( wp_next_scheduled( 'outpost_posse_dispatch', array( $this->post_id, 'bluesky', 1 ) ), 'POSSE cron must be cleared.' );

		// --- FOREIGN state preserved ---
		$this->assertSame( 'keep', get_option( 'siteurl_like_but_not_ours' ) );
		$this->assertSame( 'keep this alt', get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true ), 'Attachment alt text must survive.' );
		$this->assertSame( (string) $this->attachment_id, get_post_meta( $this->post_id, '_thumbnail_id', true ), 'Featured image must survive.' );
		$this->assertSame( 'keep keyword', get_post_meta( $this->post_id, '_yoast_wpseo_focuskw', true ), 'Yoast meta must survive.' );
		$this->assertSame( 'Keep Nick', get_user_meta( $this->user_id, 'nickname', true ), 'Core user meta must survive.' );
		$this->assertSame( 'publish', get_post_status( $this->post_id ), 'The post itself must survive.' );
		$this->assertGreaterThan( 0, $term_id );
		$this->assertInstanceOf( \WP_Term::class, get_term( $term_id, 'category' ), 'Category terms must survive.' );

		delete_option( 'siteurl_like_but_not_ours' );
	}
}
