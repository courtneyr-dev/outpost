<?php
/**
 * Integration test stub for the /post/* rewrite flow.
 *
 * Skipped until wp-env (Phase D) is wired up. Documenting the assertions here
 * so the next session knows exactly what to flesh out.
 *
 *  - GET /post/                 → outpost_route=composer, template_redirect dispatches to PWA shell.
 *  - GET /post/manifest.json    → outpost_route=manifest, response Content-Type is application/manifest+json.
 *  - GET /post/sw.js            → outpost_route=sw, response Content-Type is application/javascript.
 *  - GET /post/share-target/    → outpost_route=share-target.
 *  - GET /post/auth/callback/   → outpost_route=auth-callback.
 *  - register_activation_hook fires Outpost_Route_Handler::register_rewrite_rules then flush_rewrite_rules.
 *  - The Outpost_Route_Handler::rules() table matches the entries WP_Rewrite holds after init fires.
 *
 * Unit coverage of these assertions sits in tests/unit/RouteHandlerTest.php; the
 * integration layer's job is to verify the wiring against a real WordPress
 * core and a real WP_Rewrite object.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class RouteHandlerIntegrationTest extends TestCase {

	/** @test */
	public function rewrite_flow_dispatches_each_route_to_the_pwa_shell(): void {
		$this->markTestSkipped(
			'wp-env setup lands in a later session. Integration assertions are documented at the top of this file.'
		);
	}
}
