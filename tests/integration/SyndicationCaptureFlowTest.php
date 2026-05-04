<?php
/**
 * Integration test stubs for the F12 phase-2 capture flow.
 *
 * Skipped until wp-env is wired up; documents the wp-env-pending
 * paths the future session will fill in.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SyndicationCaptureFlowTest extends TestCase {

	/**
	 * @test
	 */
	public function full_capture_loop_writes_audit_log_and_renders_u_syndication(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: log in as a test user; create a Photo post via Micropub; ' .
			'fire manual-share intent (Android UA mocked) → audit_log_id returned; sleep past grace; ' .
			'GET /manual-share/pending → entry returned; POST /manual-share/capture with ' .
			'silo_url=https://example.com/p/abc → status=recorded; fetch the post HTML → ' .
			'<div class="outpost-syndication-links" hidden> with one <a class="u-syndication" ' .
			'href="https://example.com/p/abc" rel="syndication"> present; audit log entry now has ' .
			'completed_at + silo_url + outcome=fired.'
		);
	}

	/**
	 * @test
	 */
	public function capture_endpoint_rejects_non_authenticated_request(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /manual-share/capture without auth → 401 rest_forbidden.'
		);
	}

	/**
	 * @test
	 */
	public function pending_endpoint_only_returns_users_own_posts(): void {
		$this->markTestSkipped(
			'wp-env setup pending. User A creates post + fires intent. User B logs in. ' .
			'GET /manual-share/pending as user B → empty (does not surface user A\'s pending).'
		);
	}

	/**
	 * @test
	 */
	public function rest_api_exposes_outpost_syndication_links_post_meta(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Capture URL on post 42; GET /wp-json/wp/v2/posts/42 → ' .
			'response includes meta.outpost_syndication_links array with the recorded entry.'
		);
	}

	/**
	 * @test
	 */
	public function admin_post_edit_screen_shows_pending_notice(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Fire intent on post 42; visit /wp-admin/post.php?post=42&action=edit; ' .
			'assert HTML includes notice with "pending syndication" + platform name.'
		);
	}

	/**
	 * @test
	 */
	public function the_excerpt_filter_also_includes_syndication_links(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Capture URL on post 42; render the_excerpt() → output includes ' .
			'the u-syndication anchor (Bridgy feed-scanning paths benefit).'
		);
	}
}
