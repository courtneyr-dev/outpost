<?php
/**
 * G4b — integration test: Og_Inbound external-fetch path.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs to real WireMock-backed
 * assertions. First per-cluster migration consuming the wp-env CI
 * infrastructure shipped in PR #64. Pattern proven here applies to the
 * remaining 94 stubs catalogued in
 * `docs/dev/g99-stub-migration-inventory.md`.
 *
 * Why integration vs unit: Og_Inbound's full pipeline includes
 * `wp_safe_remote_get` against external URLs. Unit tests in
 * G4PrimitivesTest cover the parse/dispatch logic with
 * `parse_html_to_response()` directly; this test covers the network
 * path end-to-end via the WireMock sidecar.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Mock_Server;
use Outpost_Og_Inbound;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class G4bOgInboundIntegrationTest extends TestCase {

	/** @var string[] Fixture paths whose response cache is wiped between tests. */
	private array $cache_url_paths = array(
		'/og-inbound/article-fixture',
		'/og-inbound/missing-fixture',
		'/og-inbound/multi-jsonld-fixture',
	);

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap or without OUTPOST_TEST_MOCK_SERVER_URL. '
				. 'Run via `composer test:integration` inside wp-env tests-cli + WireMock sidecar.'
			);
		}
		Outpost_Mock_Server::reset();
		// Og_Inbound caches successful fetches as transients keyed on
		// md5(strtolower($url)). Wipe each potential cache entry so tests
		// don't share state.
		foreach ( $this->cache_url_paths as $path ) {
			delete_transient( 'outpost_og_inbound_' . md5( $this->mock_url( $path ) ) );
		}
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_safe_remote_get' )
			&& class_exists( 'Outpost_Og_Inbound' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * Build the URL the SUT shares: Outpost_Mock_Server_Filter rewrites
	 * `example.test` (in REWRITABLE_HOSTS) to OUTPOST_TEST_MOCK_SERVER_URL,
	 * so the SUT-visible URL is just `https://example.test<path>`.
	 */
	private function mock_url( string $path ): string {
		return 'https://example.test' . $path;
	}

	/**
	 * Steps for the wp-env-backed run:
	 *   1. WireMock stubs GET /og-inbound/article-fixture → HTML with OG
	 *      tags + Article JSON-LD.
	 *   2. Outpost_Og_Inbound::fetch( https://example.test/og-inbound/... )
	 *      — the mock-server filter rewrites example.test to the WireMock
	 *      base, the request lands at WireMock, returns the fixture body.
	 *   3. Assert response is array, not WP_Error.
	 *   4. Assert the 9-key response shape — title, description, image,
	 *      site_name, type, schema_org_data, raw_meta, fetched_at, source_url.
	 *   5. Assert schema_org_data was extracted by the Article extractor
	 *      with `headline` + `author` + `date_published` keys present.
	 *   6. Make a second `fetch()` call and assert the WireMock journal
	 *      hit count is 1 (cache served the second call).
	 *   7. Pass `force_refresh => true`, assert hit count incremented to 2.
	 *
	 * @test
	 */
	public function og_inbound_fetches_from_external_url(): void {
		Outpost_Mock_Server::stub_from_fixture( 'og-inbound/article-fetch-success.json' );

		$url    = $this->mock_url( '/og-inbound/article-fixture' );
		$result = Outpost_Og_Inbound::fetch( $url );

		$this->assertIsArray( $result, 'fetch returned WP_Error or non-array.' );
		$this->assertSame( 'Sample Article Title', $result['title'] );
		$this->assertSame( 'A short description.', $result['description'] );
		$this->assertSame( 'Sample Site', $result['site_name'] );
		$this->assertSame( 'article', $result['type'] );
		$this->assertArrayHasKey( 'image', $result );
		$this->assertArrayHasKey( 'raw_meta', $result );
		$this->assertArrayHasKey( 'fetched_at', $result );
		$this->assertArrayHasKey( 'source_url', $result );
		$this->assertArrayHasKey( 'schema_org_data', $result );

		// Article extractor's shape.
		$schema = $result['schema_org_data'];
		$this->assertIsArray( $schema );
		$this->assertSame( 'Sample Article Title', $schema['headline'] );
		$this->assertSame( 'Article', $schema['type'] );
		$this->assertSame( 'Jane Author', $schema['author'] );
		$this->assertSame( '2026-05-01T10:00:00+00:00', $schema['date_published'] );

		// Cache hit on second call: WireMock should still see exactly 1 request.
		$result_cached = Outpost_Og_Inbound::fetch( $url );
		$this->assertSame( $result, $result_cached, 'Second fetch should return cached payload.' );
		$received = Outpost_Mock_Server::received_requests( 'GET', '/og-inbound/article-fixture' );
		$this->assertCount(
			1,
			$received,
			'WireMock should have received exactly 1 request — second fetch served from cache.'
		);

		// force_refresh bypasses cache → second WireMock hit.
		$result_forced = Outpost_Og_Inbound::fetch( $url, array( 'force_refresh' => true ) );
		$this->assertIsArray( $result_forced );
		$received_after_force = Outpost_Mock_Server::received_requests( 'GET', '/og-inbound/article-fixture' );
		$this->assertCount(
			2,
			$received_after_force,
			'force_refresh should bypass cache and trigger a second WireMock hit.'
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
		Outpost_Mock_Server::stub_from_fixture( 'og-inbound/article-not-found.json' );

		$url    = $this->mock_url( '/og-inbound/missing-fixture' );
		$result = Outpost_Og_Inbound::fetch( $url );

		$this->assertTrue( is_wp_error( $result ), 'Expected WP_Error for 404 response.' );
		$this->assertSame( 'outpost_og_fetch_failed', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertSame( 404, $error_data['status'] );

		// Cache NOT populated: a second fetch hits WireMock again rather
		// than returning a stale cached failure.
		$second = Outpost_Og_Inbound::fetch( $url );
		$this->assertTrue( is_wp_error( $second ) );
		$received = Outpost_Mock_Server::received_requests( 'GET', '/og-inbound/missing-fixture' );
		$this->assertCount(
			2,
			$received,
			'Failed fetches must not cache — second call should hit WireMock again.'
		);
	}

	/**
	 * Multiple JSON-LD blocks present, only the first matching a
	 * registered extractor's @type wins. Priority ordering tested in
	 * the unit suite; this confirms the dispatch wires through the
	 * full HTML parse path on a real network roundtrip.
	 *
	 * @test
	 */
	public function og_inbound_dispatches_first_matching_extractor(): void {
		Outpost_Mock_Server::stub_from_fixture( 'og-inbound/multi-jsonld-article.json' );

		$url    = $this->mock_url( '/og-inbound/multi-jsonld-fixture' );
		$result = Outpost_Og_Inbound::fetch( $url );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'schema_org_data', $result );
		$schema = $result['schema_org_data'];
		$this->assertIsArray( $schema, 'schema_org_data should be an array — fixture has matching JSON-LD.' );
		// First matching block (the second JSON-LD; first is WebSite, no
		// extractor registered for that). Article wins, second Article is
		// ignored per first-match-wins semantics.
		$this->assertSame( 'First-Matching Article Wins', $schema['headline'] );
		$this->assertSame( 'Sam Author', $schema['author'] );
		$this->assertSame( 'Article', $schema['type'] );
	}
}
