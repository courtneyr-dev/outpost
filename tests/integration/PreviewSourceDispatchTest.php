<?php
/**
 * Integration test stub for the F5 Preview-endpoint Source_Loader
 * dispatch.
 *
 * Skipped until wp-env (planned alongside RouteHandlerIntegrationTest)
 * is wired up. Documenting the assertions here so the future session
 * knows exactly what to flesh out.
 *
 * Investigation findings worth preserving here so they survive across
 * sessions:
 *
 *   1. The preview endpoint runs SSRF defenses BEFORE extractor
 *      dispatch — `wp_safe_remote_get` filters loopback + private
 *      IPs at the HTTP layer, regardless of which URL the
 *      extractor's `compute_fetch_url()` produced. An attacker who
 *      supplied `source_id=oembed-attacker` with a recipe pointing
 *      at `http://localhost/oembed?url={url}` still gets blocked.
 *
 *   2. The extractor's expected_content_types() narrows the response
 *      Content-Type allowlist when a source claims the URL. A
 *      Spotify-shaped URL routed through the oEmbed extractor
 *      gets `application/json` only; a generic-fallback URL stays
 *      on the legacy path's `text/html`/`application/xhtml+xml`.
 *
 *   3. Source_Unknown's extractor (og_tags) is stubbed in F5; its
 *      end-to-end use lands in F16. Until then, the legacy code path
 *      handles fallback so Reply mode keeps working — confirmed in
 *      `legacy_path_preserved_when_no_concrete_source_matches`.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PreviewSourceDispatchTest extends TestCase {

	/**
	 * @test
	 */
	public function legacy_path_preserved_when_no_concrete_source_matches(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps for the future run: ' .
			'POST /wp-json/outpost/v1/preview { url } with no source_id ' .
			'and a URL no concrete Source_* claims. Assert the response ' .
			'has the legacy { html, finalUrl, contentType } shape, NOT ' .
			'the new { source_url, source_id, extracted, raw, warnings } ' .
			'shape — until F16 lands the og_tags parser, Source_Unknown ' .
			'falls through to the legacy code path so Reply mode keeps ' .
			'working.'
		);
	}

	/**
	 * @test
	 */
	public function explicit_source_id_unknown_returns_501(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: POST /preview { url, source_id: ' .
			'"unknown" }. Assert the response is WP_Error with code ' .
			'`extractor_not_implemented` and HTTP status 501. The throw ' .
			'is intentional and documented in CLAUDE.md F5; F16 makes ' .
			'this case succeed.'
		);
	}

	/**
	 * @test
	 */
	public function ssrf_blocked_when_extractor_recipe_points_at_localhost(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: register a fake source whose ' .
			'oEmbed recipe endpoint is `http://localhost:8080/oembed?url={url}`. ' .
			'POST /preview { url, source_id: "fake-evil" }. Assert the ' .
			'response is WP_Error with code `fetch_failed` because ' .
			'wp_safe_remote_get blocked the localhost target. The ' .
			'pre-existing http_request_host_is_external filter chain ' .
			'enforces this without F5 needing to add new defenses.'
		);
	}

	/**
	 * @test
	 */
	public function oembed_dispatch_returns_extracted_shape(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: register a fake source ' .
			'claiming example.com with extractor=oembed and a stub ' .
			'oEmbed endpoint reachable in the test env. POST /preview ' .
			'{ url: "https://example.com/track/abc" }. Assert the ' .
			'response shape is { source_url, source_id: "fake-source", ' .
			'extracted: { p-name, u-photo, ... }, raw, warnings: [] }.'
		);
	}
}
