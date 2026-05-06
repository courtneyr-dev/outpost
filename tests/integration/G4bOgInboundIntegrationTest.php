<?php
/**
 * G4b — integration test stub: Og_Inbound external-fetch path.
 *
 * Skipped until wp-env Docker network configuration with mock-server
 * routing lands. Documenting the assertions here so the wp-env-backed
 * run knows exactly what to flesh out.
 *
 * Why integration vs unit: Og_Inbound's full pipeline includes
 * wp_safe_remote_get against external URLs. Unit tests in
 * G4PrimitivesTest cover the parse/dispatch logic with
 * `parse_html_to_response()` directly; this stub covers the network
 * path end-to-end.
 *
 * Test target: `test_og_inbound_fetches_from_external_url`.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class G4bOgInboundIntegrationTest extends TestCase {

	/**
	 * Steps for the future wp-env-backed run:
	 *   1. Bootstrap wp-env with Outpost + a mock HTTP server reachable
	 *      from inside the Docker network. Configure the mock to serve
	 *      one of `tests/fixtures/og-inbound/*.html` at a known path.
	 *   2. Call `Outpost_Og_Inbound::fetch( $mock_url )`.
	 *   3. Assert response is array, not WP_Error.
	 *   4. Assert the 9-key response shape — title, description, image,
	 *      site_name, type, schema_org_data, raw_meta, fetched_at, source_url.
	 *   5. Assert schema_org_data was extracted by the matching
	 *      registered extractor (e.g., article fixture → Article extractor's
	 *      shape with `headline` + `author` + `date_published` keys).
	 *   6. Make a second `fetch()` call and assert the mock server's hit
	 *      count is 1 (cache served the second call).
	 *   7. Pass `force_refresh => true`, assert hit count incremented to 2.
	 *
	 * @test
	 */
	public function og_inbound_fetches_from_external_url(): void {
		$this->markTestSkipped(
			'wp-env setup with Docker mock-server routing lands in a later ' .
			'session. Integration assertions are documented in the test ' .
			'method body.'
		);
	}

	/**
	 * 404 handling end-to-end: external URL returns HTTP 404, fetch
	 * returns WP_Error with code outpost_og_fetch_failed and status 404
	 * in error data. Cache is NOT populated for failed fetches.
	 *
	 * @test
	 */
	public function og_inbound_returns_wp_error_on_404(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Multiple JSON-LD blocks present, only the first matching a
	 * registered extractor's @type wins. Priority ordering tested in
	 * the unit suite; this confirms the dispatch wires through the
	 * full HTML parse path.
	 *
	 * @test
	 */
	public function og_inbound_dispatches_first_matching_extractor(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}
}
