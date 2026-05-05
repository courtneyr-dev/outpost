<?php
/**
 * Integration test stubs for the FY-Theming appearance flow.
 *
 * Skipped until wp-env is wired up; documents the assertions the
 * future session will flesh out.
 *
 * Pipeline being verified:
 *
 *   1. User opens Settings → Outpost → Appearance
 *   2. Page renders with stored mode preference + theme.json-derived
 *      defaults
 *   3. User overrides a single color to a low-contrast value
 *   4. Server-side resolver auto-adjusts; settings page renders
 *      "Contrast adjustment applied" warning
 *   5. User accepts auto-adjust (default behavior); save persists
 *      the user's stored value but NOT the bypass flag
 *   6. Re-resolved tokens reflect the auto-adjusted color, not the
 *      user's failing value
 *   7. User re-edits, this time clicks "Override anyway" checkbox;
 *      save persists the bypass flag AND the failing value
 *   8. Re-resolved tokens reflect the failing value (bypass honored)
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AppearanceSettingsFlowTest extends TestCase {

	/**
	 * @test
	 */
	public function settings_page_renders_for_user_with_edit_posts_capability(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps for the future run: '
			. 'log in as Editor; GET /wp-admin/admin.php?page=outpost-appearance. '
			. 'Assert response 200 + body contains "Outpost Appearance" heading.'
		);
	}

	/**
	 * @test
	 */
	public function rest_get_returns_resolved_tokens_for_authenticated_user(): void {
		$this->markTestSkipped(
			'wp-env setup pending. GET /wp-json/outpost/v1/appearance/tokens?mode=day '
			. 'with cookie auth. Assert response 200 + JSON has colors, fonts, '
			. 'sizes, sources, adjusted, mode_preference fields.'
		);
	}

	/**
	 * @test
	 */
	public function rest_get_returns_401_for_unauthenticated(): void {
		$this->markTestSkipped(
			'wp-env setup pending. GET /wp-json/outpost/v1/appearance/tokens '
			. 'without auth. Assert response 401.'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_persists_color_override(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with body { day: { colors: { bg: "#abcdef" } } }. '
			. 'Assert 200 + saved=true. GET subsequent request returns bg=#abcdef '
			. 'with sources.colors.bg=override.'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_with_invalid_color_returns_400(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with body { day: { colors: { bg: "javascript:alert(1)" } } }. '
			. 'Assert 400 with code outpost_appearance_invalid_color_value. '
			. 'GET returns the prior value, not the failed write.'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_with_invalid_font_returns_400(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with body { day: { fonts: { body: "Inter; <script>" } } }. '
			. 'Assert 400 with code outpost_appearance_invalid_font_value.'
		);
	}

	/**
	 * @test
	 */
	public function settings_form_save_via_admin_post_writes_user_meta(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /wp-admin/admin-post.php with action=outpost_appearance_save_settings, '
			. 'nonce, mode_preference=night, colors_day[bg]=#abcdef. '
			. 'Assert redirect to settings page with notice=saved. '
			. 'Verify outpost_appearance_overrides + outpost_appearance_mode '
			. 'user-meta were written.'
		);
	}

	/**
	 * @test
	 */
	public function contrast_warning_renders_for_low_contrast_user_override(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Pre-seed user-meta with day.colors.text=#cccccc + '
			. 'day.colors.surface=#ffffff. GET settings page. Assert "Contrast adjustment applied" '
			. 'warning text appears + bypass_contrast[text] checkbox renders unchecked.'
		);
	}

	/**
	 * @test
	 */
	public function bypass_contrast_preserves_failing_value_on_save(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST settings save with colors_day[text]=#cccccc + '
			. 'bypass_contrast[text]=1. GET subsequent /appearance/tokens?mode=day. '
			. 'Assert colors.text=#cccccc (NOT auto-adjusted) + adjusted has no text key.'
		);
	}

	/**
	 * @test
	 */
	public function settings_save_invalidates_resolved_tokens_transient(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Pre-resolve tokens (caches in transient). '
			. 'Save a color override. Re-resolve. Assert the override appears in '
			. 'the new resolution (i.e. cache was invalidated; not stale).'
		);
	}

	/**
	 * @test
	 */
	public function mode_preference_change_persists_and_reflects_in_root_class(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST settings save with mode_preference=night. '
			. 'Visit /post/. Assert composer root element has class="outpost-mode-night".'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_rejected_for_invalid_token_slug(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with body { day: { colors: { "../etc/passwd": "#000" } } }. '
			. 'Assert 400 with code outpost_appearance_invalid_color_slug. '
			. 'No partial write — overrides storage unchanged.'
		);
	}

	/**
	 * @test
	 */
	public function rest_post_clears_token_when_value_blank(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Pre-seed override with day.colors.bg=#abcdef. '
			. 'POST { day: { colors: { bg: "" } } }. Assert subsequent GET '
			. 'returns bg from theme/default fallback, source != override.'
		);
	}
}
