<?php
/**
 * G4b — integration test stub: Composite_Inbound primary→fallback chain.
 *
 * Skipped until wp-env Docker network configuration with mock-server
 * routing lands.
 *
 * Test target: `test_composite_inbound_falls_back_on_primary_failure`.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class G4bCompositeInboundIntegrationTest extends TestCase {

	/**
	 * Primary fetch fails (mock server returns 503 for the primary URL),
	 * fallback succeeds, composite returns the fallback's payload.
	 *
	 * Steps for the future wp-env-backed run:
	 *   1. Bootstrap wp-env + mock server.
	 *   2. Configure mock: primary URL → 503; fallback URL → 200 with
	 *      a Recipe fixture.
	 *   3. Call `Outpost_Composite_Inbound::fetch( 'https://example.test/x',
	 *      [ ['id' => 'p', 'role' => 'primary', 'callback' => ... ],
	 *        ['id' => 'f', 'role' => 'fallback', 'callback' => ... ] ] )`.
	 *   4. Assert response is array, not WP_Error.
	 *   5. Assert `_composite_meta.primary` === 'f' (fallback ran).
	 *   6. Assert `_composite_meta.sources.p` carries the WP_Error result.
	 *   7. Assert `_composite_meta.sources.f` carries a successful result.
	 *
	 * @test
	 */
	public function composite_inbound_falls_back_on_primary_failure(): void {
		$this->markTestSkipped(
			'wp-env setup with Docker mock-server routing lands in a later ' .
			'session. Integration assertions are documented in the test ' .
			'method body.'
		);
	}

	/**
	 * Wall-clock cap: when total elapsed time hits 15 seconds, the
	 * primitive returns whatever it has so far with `_composite_meta.elapsed_ms`
	 * close to 15000 and the unfinished sources marked timed-out.
	 *
	 * @test
	 */
	public function composite_inbound_returns_partial_results_on_wall_clock_cap(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Enricher failure swallowed at debug level: primary success returns
	 * the primary's payload merged with the SUCCESSFUL enrichers only;
	 * failed enrichers do not block, do not appear in the merged shape,
	 * and produce a debug-level error_log entry.
	 *
	 * @test
	 */
	public function composite_inbound_swallows_enricher_failure(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * All primaries + fallbacks fail: WP_Error with code
	 * outpost_composite_all_failed, error data carrying the meta block
	 * with each source's individual failure recorded.
	 *
	 * @test
	 */
	public function composite_inbound_returns_error_when_all_sources_fail(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}
}
