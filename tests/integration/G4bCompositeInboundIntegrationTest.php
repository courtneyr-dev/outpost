<?php
/**
 * G4b — integration test: Composite_Inbound primary→fallback chain.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs to real WireMock-backed
 * + closure-driven assertions. Second per-cluster migration consuming
 * the wp-env CI infrastructure shipped in PR #64.
 *
 * Migration shape: test 1 uses WireMock (real HTTP roundtrip — exercises
 * the bootstrap SSRF whitelist + the actual fetch); tests 2-4 use
 * inline closures since they test primitive logic (wall-clock cap,
 * enricher failure handling, all-failed error shape) rather than the
 * network layer.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Composite_Inbound;
use Outpost_Mock_Server;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @coversNothing
 */
final class G4bCompositeInboundIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap or without OUTPOST_TEST_MOCK_SERVER_URL. '
				. 'Run via `composer test:integration` inside wp-env tests-cli + WireMock sidecar.'
			);
		}
		Outpost_Mock_Server::reset();
		// Composite caches per (url, source signature); we pass force_refresh
		// in every test to bypass cache rather than wiping per-test.
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_safe_remote_get' )
			&& class_exists( 'Outpost_Composite_Inbound' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	private function mock_url( string $path ): string {
		return rtrim( (string) constant( 'OUTPOST_TEST_MOCK_SERVER_URL' ), '/' ) . $path;
	}

	/**
	 * Primary fetch fails (mock server returns 503), fallback succeeds,
	 * composite returns the fallback's payload merged with metadata.
	 *
	 * @test
	 */
	public function composite_inbound_falls_back_on_primary_failure(): void {
		Outpost_Mock_Server::stub_from_fixture( 'composite-inbound/primary-503.json' );
		Outpost_Mock_Server::stub_from_fixture( 'composite-inbound/fallback-200.json' );

		$primary_url  = $this->mock_url( '/composite-inbound/primary-503' );
		$fallback_url = $this->mock_url( '/composite-inbound/fallback-200' );

		$sources = array(
			array(
				'id'       => 'primary-source',
				'role'     => 'primary',
				'callback' => static function () use ( $primary_url ) {
					$response = wp_safe_remote_get( $primary_url, array( 'timeout' => 5 ) );
					if ( is_wp_error( $response ) ) {
						return $response;
					}
					$status = (int) wp_remote_retrieve_response_code( $response );
					if ( $status < 200 || $status >= 300 ) {
						return new WP_Error( 'fixture_http_status', 'HTTP ' . $status );
					}
					return array( 'source' => 'primary' );
				},
			),
			array(
				'id'       => 'fallback-source',
				'role'     => 'fallback',
				'callback' => static function () use ( $fallback_url ) {
					$response = wp_safe_remote_get( $fallback_url, array( 'timeout' => 5 ) );
					if ( is_wp_error( $response ) ) {
						return $response;
					}
					$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
					return is_array( $decoded ) ? $decoded : new WP_Error( 'fixture_parse_failed', 'Bad JSON' );
				},
			),
		);

		$result = Outpost_Composite_Inbound::fetch(
			'https://example.com/composite-fallback-test',
			$sources,
			array( 'force_refresh' => true )
		);

		$this->assertIsArray( $result, 'Expected array result, got: ' . ( is_wp_error( $result ) ? $result->get_error_code() : gettype( $result ) ) );
		$this->assertSame( 'Fallback Recipe', $result['title'] );
		$this->assertSame( 4, $result['servings'] );
		$this->assertSame( 'fallback', $result['source'] );

		// _composite_meta envelope.
		$this->assertArrayHasKey( '_composite_meta', $result );
		$meta = $result['_composite_meta'];
		$this->assertSame( 'fallback-source', $meta['primary'], 'meta.primary should name the source that succeeded.' );
		$this->assertArrayHasKey( 'primary-source', $meta['sources'] );
		$this->assertArrayHasKey( 'fallback-source', $meta['sources'] );
		$this->assertTrue(
			is_wp_error( $meta['sources']['primary-source']['result'] ),
			'Primary source result should be a WP_Error (503 → fixture_http_status).'
		);
		$this->assertIsArray(
			$meta['sources']['fallback-source']['result'],
			'Fallback source result should be the parsed JSON array.'
		);
	}

	/**
	 * Wall-clock cap: when total elapsed time hits the cap, the primitive
	 * stops running additional sources and returns whatever it has so
	 * far. The cap is filterable via `outpost_composite_wall_clock_cap`.
	 *
	 * @test
	 */
	public function composite_inbound_returns_partial_results_on_wall_clock_cap(): void {
		// Set the cap to 1 second for this test.
		add_filter(
			'outpost_composite_wall_clock_cap',
			static function () {
				return 1;
			}
		);

		$sources = array(
			array(
				'id'       => 'slow-primary',
				'role'     => 'primary',
				'callback' => static function () {
					// Sleeps 2 seconds — exceeds the 1s cap when checked
					// for any subsequent source's iteration.
					sleep( 2 );
					return array( 'value' => 'primary-payload' );
				},
			),
			array(
				'id'       => 'enricher-after-cap',
				'role'     => 'enrich',
				'callback' => static function () {
					// Should never run — cap fires before this iteration.
					return array( 'enricher_ran' => true );
				},
			),
		);

		$start  = microtime( true );
		$result = Outpost_Composite_Inbound::fetch(
			'https://example.com/composite-wall-clock-test',
			$sources,
			array( 'force_refresh' => true )
		);
		$elapsed_seconds = microtime( true ) - $start;

		$this->assertIsArray( $result );
		$this->assertSame( 'primary-payload', $result['value'] );
		$this->assertArrayNotHasKey(
			'enricher_ran',
			$result,
			'Enricher should NOT have run — wall-clock cap fires before it gets a turn.'
		);

		$meta = $result['_composite_meta'];
		$this->assertSame( 'slow-primary', $meta['primary'] );
		$this->assertArrayHasKey( 'slow-primary', $meta['sources'] );
		$this->assertArrayNotHasKey(
			'enricher-after-cap',
			$meta['sources'],
			'Skipped sources are absent from meta.sources (timed-out per docblock).'
		);
		$this->assertGreaterThanOrEqual(
			1000,
			$meta['elapsed_ms'],
			'elapsed_ms should reflect actual wall-clock at or above the 1000ms cap.'
		);

		// Sanity: the test itself completed within ~3.5 seconds (cap + slack).
		$this->assertLessThan(
			3.5,
			$elapsed_seconds,
			'Composite did not exit promptly after cap — wall-clock guard may be broken.'
		);
	}

	/**
	 * Enricher failure swallowed at debug level: primary success returns
	 * primary's payload merged with successful enrichers; failed
	 * enrichers do not block, do not appear in the merged result, and
	 * are recorded in _composite_meta.sources as WP_Error.
	 *
	 * @test
	 */
	public function composite_inbound_swallows_enricher_failure(): void {
		$sources = array(
			array(
				'id'       => 'primary-success',
				'role'     => 'primary',
				'callback' => static function () {
					return array(
						'title'       => 'Primary Title',
						'description' => 'From primary',
					);
				},
			),
			array(
				'id'       => 'good-enricher',
				'role'     => 'enrich',
				'callback' => static function () {
					return array(
						'extra_field' => 'from-enricher',
					);
				},
			),
			array(
				'id'       => 'failing-enricher',
				'role'     => 'enrich',
				'callback' => static function () {
					return new WP_Error( 'enricher_boom', 'Enricher deliberately failed.' );
				},
			),
		);

		$result = Outpost_Composite_Inbound::fetch(
			'https://example.com/composite-enricher-failure-test',
			$sources,
			array( 'force_refresh' => true )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Primary Title', $result['title'] );
		$this->assertSame( 'From primary', $result['description'] );
		$this->assertSame( 'from-enricher', $result['extra_field'], 'Successful enricher fields should merge in.' );

		$meta = $result['_composite_meta'];
		$this->assertSame( 'primary-success', $meta['primary'] );
		$this->assertArrayHasKey( 'good-enricher', $meta['sources'] );
		$this->assertArrayHasKey(
			'failing-enricher',
			$meta['sources'],
			'Failing enricher should still appear in meta.sources (with WP_Error result) — that is the audit trail.'
		);
		$this->assertTrue(
			is_wp_error( $meta['sources']['failing-enricher']['result'] ),
			'Failing enricher result should be a WP_Error.'
		);
	}

	/**
	 * All primaries + fallbacks fail: WP_Error with code
	 * outpost_composite_all_failed, error data carries the meta block
	 * with each source's individual failure recorded.
	 *
	 * @test
	 */
	public function composite_inbound_returns_error_when_all_sources_fail(): void {
		$sources = array(
			array(
				'id'       => 'failed-primary',
				'role'     => 'primary',
				'callback' => static function () {
					return new WP_Error( 'primary_boom', 'Primary fail.' );
				},
			),
			array(
				'id'       => 'failed-fallback',
				'role'     => 'fallback',
				'callback' => static function () {
					return new WP_Error( 'fallback_boom', 'Fallback fail.' );
				},
			),
		);

		$result = Outpost_Composite_Inbound::fetch(
			'https://example.com/composite-all-failed-test',
			$sources,
			array( 'force_refresh' => true )
		);

		$this->assertTrue( is_wp_error( $result ), 'Expected WP_Error when all sources fail.' );
		$this->assertSame( 'outpost_composite_all_failed', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'meta', $error_data );
		$meta = $error_data['meta'];
		$this->assertNull( $meta['primary'], 'meta.primary should be null when no source succeeded.' );
		$this->assertArrayHasKey( 'failed-primary', $meta['sources'] );
		$this->assertArrayHasKey( 'failed-fallback', $meta['sources'] );
		$this->assertTrue( is_wp_error( $meta['sources']['failed-primary']['result'] ) );
		$this->assertTrue( is_wp_error( $meta['sources']['failed-fallback']['result'] ) );
	}
}
