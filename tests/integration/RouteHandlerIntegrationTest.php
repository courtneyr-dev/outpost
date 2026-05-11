<?php
/**
 * A2 — integration test: /post/* rewrite flow.
 *
 * Migrated 2026-05-08 from markTestSkipped stub (1 of 1). Phase 3
 * cluster of the G99 stub migration. Verifies the wiring against a
 * real WordPress core + WP_Rewrite object.
 *
 * Pre-readiness check (a/b/c/d) all passed:
 *
 *   (a) N/A — route handler test, not source/extractor
 *   (b) Outpost_Route_Handler is concrete (A2 shipped); WP_Rewrite is
 *       core. All branches reach concrete code.
 *   (c) Original docblock listed 5 routes; Outpost_Route_Handler::rules()
 *       now returns 6 (F6 added /post/shortcut). Test asserts the
 *       complete current 6-rule table, not the pre-F6 list — keeps the
 *       assertion tied to the SUT's actual contract.
 *   (d) No fetches; pure WP_Rewrite inspection.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Route_Handler;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class RouteHandlerIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap or without OUTPOST_TEST_MOCK_SERVER_URL. '
				. 'Run via `npm run test:integration` inside wp-env tests-cli.'
			);
		}

		// Ensure rewrite rules are registered + flushed before assertions.
		// Production wires register_rewrite_rules() to init at priority 10;
		// integration env may flush before that fires for the test class.
		Outpost_Route_Handler::register_rewrite_rules();
		flush_rewrite_rules( false );

		// Populate $wp->public_query_vars by firing the `query_vars`
		// filter. Production fires this from WP::parse_request() during a
		// normal request; the test doesn't go through parse_request, so
		// we mirror the exact line from WP core that populates the
		// property. Full parse_request invocation would also trigger
		// rewrite matching and query building — the test doesn't need
		// those and they'd have side effects on adjacent tests.
		global $wp;
		$wp->public_query_vars = apply_filters( 'query_vars', $wp->public_query_vars );
	}

	private function integration_environment_ready(): bool {
		return class_exists( 'Outpost_Route_Handler' )
			&& function_exists( 'flush_rewrite_rules' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * The Outpost_Route_Handler::rules() table must round-trip into
	 * WP_Rewrite's runtime rule set, with each rule rewriting to
	 * `index.php?outpost_route=<target>`.
	 *
	 * Asserts the COMPLETE current 6-rule table:
	 *   - manifest / sw / share-target / shortcut / auth-callback / composer
	 *
	 * (The original A2 docblock listed 5; F6 added /post/shortcut.)
	 *
	 * @test
	 */
	public function rewrite_flow_dispatches_each_route_to_the_pwa_shell(): void {
		global $wp_rewrite;
		$this->assertNotNull( $wp_rewrite, 'WP_Rewrite global must be available.' );

		$rewrite_rules = $wp_rewrite->wp_rewrite_rules();
		$this->assertIsArray( $rewrite_rules );
		$this->assertNotEmpty( $rewrite_rules, 'Rewrite rules must be populated after flush.' );

		$declared = Outpost_Route_Handler::rules();
		$this->assertCount( 6, $declared, 'Outpost_Route_Handler::rules() must declare 6 routes.' );

		$expected_targets = array(
			'^post/manifest\.json$'  => 'manifest',
			'^post/sw/?$'            => 'sw',
			'^post/share-target/?$'  => 'share-target',
			'^post/shortcut/?$'      => 'shortcut',
			'^post/auth/callback/?$' => 'auth-callback',
			'^post/?$'               => 'composer',
		);

		foreach ( $expected_targets as $pattern => $target ) {
			$this->assertArrayHasKey(
				$pattern,
				$rewrite_rules,
				sprintf( 'WP_Rewrite must contain pattern %s after flush.', $pattern )
			);
			$this->assertSame(
				'index.php?' . Outpost_Route_Handler::QUERY_VAR . '=' . $target,
				$rewrite_rules[ $pattern ],
				sprintf( 'Pattern %s must rewrite to outpost_route=%s.', $pattern, $target )
			);
		}

		// Outpost_Route_Handler::rules() and the expected_targets above
		// must agree — defends against a future rule addition that doesn't
		// update the test.
		$this->assertSame(
			$expected_targets,
			$declared,
			'Outpost_Route_Handler::rules() table must match the expected set exactly. '
			. 'A diff here means rules() changed without the test being updated.'
		);

		// query_var registration: WP::add_query_vars must include outpost_route
		// so the rewrite rule's `index.php?outpost_route=...` actually flows
		// through to template_redirect dispatch.
		global $wp;
		$this->assertContains(
			Outpost_Route_Handler::QUERY_VAR,
			$wp->public_query_vars,
			'Outpost_Route_Handler::QUERY_VAR must be registered as a public query var.'
		);
	}
}
