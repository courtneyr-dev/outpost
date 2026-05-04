<?php
/**
 * Integration test stubs for the FX iOS Shortcut bridge full flow.
 *
 * Skipped until wp-env is wired up; documents the assertions the
 * future session will flesh out.
 *
 * Pipeline being verified:
 *
 *   1. Admin opens Settings → Outpost iOS Shortcut Bridge
 *   2. Clicks "Generate token" → token rendered
 *   3. Synthetic POST to /wp-json/outpost/v1/shortcut with Bearer token
 *   4. Authenticator resolves token → records first-seen
 *   5. REST controller dispatches to Source_Detector → returns
 *      JSON { redirect_url, source_id, route_type, mode }
 *   6. Admin re-opens settings page → status = "Connected"
 *   7. Admin clicks "Revoke" → first-seen meta cleared, token gone
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class IosShortcutConnectionFlowTest extends TestCase {

	/**
	 * @test
	 */
	public function settings_page_renders_for_admin_with_manage_options(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps for the future run: ' .
			'log in as admin; GET /wp-admin/options-general.php?page=outpost-ios-shortcut. ' .
			'Assert response 200 + body contains "iOS Shortcut Bridge" heading.'
		);
	}

	/**
	 * @test
	 */
	public function generate_token_via_admin_post_creates_user_meta(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /wp-admin/admin-post.php with ' .
			'action=outpost_ios_shortcut_regenerate_token + nonce. Assert ' .
			'redirect with notice=regenerated; user-meta outpost_ios_shortcut_token ' .
			'now contains a 32-char alphanumeric value.'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_with_valid_bearer_returns_redirect_url(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Generate token; POST /wp-json/outpost/v1/shortcut ' .
			'with Authorization: Bearer <token> and JSON body { url: "https://open.spotify.com/track/0000000000000000000000" }. ' .
			'Assert response 200 + JSON contains redirect_url, source_id="spotify", ' .
			'route_type="auto", mode="listen". Assert outpost_ios_shortcut_first_seen ' .
			'meta now set on the user.'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_with_invalid_bearer_returns_401(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /wp-json/outpost/v1/shortcut with ' .
			'a synthetic non-token Bearer value. Assert response 401 ' .
			'(no current_user set; permission_callback rejects).'
		);
	}

	/**
	 * @test
	 */
	public function bearer_token_rejected_on_other_endpoints(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Generate token. POST /wp-json/outpost/v1/preview ' .
			'with Authorization: Bearer <shortcut-token>. Assert 401 (scope ' .
			'enforcement). Critical security boundary: a leaked Shortcut ' .
			'token must NOT authenticate Micropub /media or any other route.'
		);
	}

	/**
	 * @test
	 */
	public function bearer_token_rejected_on_micropub_media(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Generate token. POST /wp-json/micropub/1.0/media ' .
			'with Authorization: Bearer <shortcut-token>. Assert 401. ' .
			'This is the most security-critical scope-violation case ' .
			'(Micropub /media accepts file uploads).'
		);
	}

	/**
	 * @test
	 */
	public function settings_page_status_updates_after_first_successful_post(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Sequence: generate token → first-seen ' .
			'is null → settings page shows "Not connected yet" → POST ' .
			'with Bearer succeeds → first-seen set → re-render settings ' .
			'page → shows "Connected" + timestamp.'
		);
	}

	/**
	 * @test
	 */
	public function regenerate_clears_first_seen_marker(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Sequence: generate token → POST → ' .
			'first-seen set → regenerate token → first-seen cleared → ' .
			'settings page shows "Not connected yet" again.'
		);
	}

	/**
	 * @test
	 */
	public function revoke_removes_token_and_first_seen(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Generate token → POST (first-seen set) → ' .
			'POST admin-post revoke action → user-meta token cleared → ' .
			'first-seen cleared → settings page shows "No token issued yet".'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_with_no_url_returns_400(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /wp-json/outpost/v1/shortcut with ' .
			'valid Bearer + body { shared_text: "no URL here" }. Assert ' .
			'response 400 with code outpost_ios_shortcut_no_url.'
		);
	}
}
