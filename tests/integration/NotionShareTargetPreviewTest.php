<?php
/**
 * G8b — integration test stub: Notion API in share-target preview.
 *
 * Skipped until wp-env Docker network configuration with mock-server
 * routing lands. Documenting the assertions here so the wp-env-backed
 * run knows exactly what to flesh out.
 *
 * The G8b production code path (`Outpost_Preview_Endpoint::handle_via_authenticated_source`)
 * detects `auth_required` capability on the matched source, dispatches
 * Notion URLs to `Outpost_Source_Notion::fetch_page` when the current
 * user has connected Notion, and falls through to the legacy og:title
 * path otherwise. These integration tests exercise the four paths
 * end-to-end through the REST endpoint.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class NotionShareTargetPreviewTest extends TestCase {

	/**
	 * Connected user shares a Notion URL whose page IS shared with the
	 * Outpost integration → preview returns the structured Notion shape
	 * (title, icon, cover, block-count).
	 *
	 * Steps for the future wp-env-backed run:
	 *   1. Bootstrap wp-env with Outpost active and the Notion mock
	 *      server reachable from inside the Docker network.
	 *   2. Create a WP user with `edit_posts` capability.
	 *   3. Persist a Notion access token for that user via
	 *      `Outpost_Credentials_Store::set( 'notion', [ 'access_token' => 'mock' ], $user_id )`.
	 *   4. Configure the mock server: GET /v1/pages/{id} → 200 with a
	 *      page object carrying `properties.title.title[].plain_text`,
	 *      `icon.emoji`, `cover.external.url`, `last_edited_time`.
	 *   5. Configure the mock: GET /v1/blocks/{id}/children → 200 with
	 *      a list of paragraph + heading blocks.
	 *   6. POST `/wp-json/outpost/v1/preview` with body `{ "url":
	 *      "https://www.notion.so/Workspace-abc123def456..." }` as the
	 *      authenticated user.
	 *   7. Assert response is 200.
	 *   8. Assert `authenticated_source === 'notion'`.
	 *   9. Assert `authenticated_status === 'ok'`.
	 *  10. Assert `extracted['p-name']` matches the title from the mock.
	 *  11. Assert `extracted['notion-icon']` matches the emoji.
	 *  12. Assert `extracted['notion-block-count'] >= 1`.
	 *
	 * @test
	 */
	public function connected_user_gets_structured_notion_preview(): void {
		$this->markTestSkipped(
			'wp-env setup with Docker mock-server routing lands in a later ' .
			'session. Integration assertions are documented in the test ' .
			'method body.'
		);
	}

	/**
	 * Connected user shares a Notion page that the Outpost integration
	 * has NOT been granted access to → preview returns 200 with
	 * `authenticated_status === 'page_not_shared'` and a
	 * `fallback_message` the composer can render verbatim.
	 *
	 * Steps:
	 *   1. Bootstrap as above with creds persisted.
	 *   2. Configure the mock: GET /v1/pages/{id} → 404 with
	 *      `{"object":"error","status":404,"code":"object_not_found"}`.
	 *   3. POST /preview with the Notion URL.
	 *   4. Assert response is 200 (not 4xx — page-not-shared is a
	 *      first-class user-facing condition, not a server error).
	 *   5. Assert `authenticated_status === 'page_not_shared'`.
	 *   6. Assert `authenticated_message` contains "shared with Outpost".
	 *   7. Assert `warnings[0].code === 'outpost_notion_page_not_shared'`.
	 *
	 * @test
	 */
	public function connected_user_unshared_page_surfaces_notice_and_falls_through(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Disconnected user shares a Notion URL → preview falls through to
	 * the legacy og:title path. Response shape matches the pre-G8b
	 * legacy `{ html, finalUrl, contentType }` shape (the og_tags
	 * extractor returns the extracted shape for Source_Notion via
	 * the standard path; either way no `authenticated_*` fields appear).
	 *
	 * Steps:
	 *   1. Bootstrap WITHOUT persisting Notion creds for the test user.
	 *   2. Configure mock: GET notion.so/Workspace-abc123 → 200 HTML
	 *      with `<meta property="og:title" content="Test Page | Notion">`.
	 *   3. POST /preview with the Notion URL.
	 *   4. Assert response is 200.
	 *   5. Assert `authenticated_source` field is NOT present (or is
	 *      empty / null).
	 *   6. Assert legacy fields populate: title pulled from og:title.
	 *
	 * @test
	 */
	public function disconnected_user_falls_through_to_og_title(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Anonymous (no logged-in user) request shares a Notion URL → falls
	 * through to legacy. The endpoint's permission_callback already
	 * gates on `edit_posts` so anonymous requests typically don't reach
	 * here, but the auth-required dispatch is defensive against
	 * unexpected `get_current_user_id() <= 0` returns.
	 *
	 * Steps:
	 *   1. Bootstrap with NO authenticated user.
	 *   2. POST /preview with bypassed cap check (filter the cap to
	 *      true for the test) and the Notion URL.
	 *   3. Assert response shape matches the disconnected-user case
	 *      (no `authenticated_*` fields, legacy og:title path).
	 *
	 * @test
	 */
	public function anonymous_request_falls_through_to_og_title(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Non-Notion URL (e.g. a Spotify track URL) → routes unchanged
	 * through the existing Source_Spotify extractor path. G8b's
	 * auth-required branch only fires for sources whose capabilities
	 * include `auth_required: true`; Source_Spotify doesn't.
	 *
	 * Steps:
	 *   1. Bootstrap with Notion creds persisted (irrelevant for this URL).
	 *   2. Configure mock for Spotify oEmbed.
	 *   3. POST /preview with a Spotify track URL.
	 *   4. Assert response is 200.
	 *   5. Assert `source_id === 'spotify'`.
	 *   6. Assert `authenticated_*` fields NOT present.
	 *   7. Assert extracted shape matches Spotify mapping.
	 *
	 * @test
	 */
	public function non_notion_url_routes_unchanged(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Notion fetch_page transport failure (network down, Notion API
	 * 5xx) → falls through to the legacy og:title path. The composer
	 * sees a generic preview rather than an error; the user can
	 * retry or proceed with the URL alone.
	 *
	 * @test
	 */
	public function notion_transport_failure_falls_through(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}
}
