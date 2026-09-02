<?php
/**
 * Unit tests for Outpost_Route_Handler.
 *
 * The handler owns five rewrite rules under /post/*: composer, share-target,
 * auth-callback, manifest, and service worker. The shape of the rule table
 * (regex -> query target) and the dispatch behavior are covered here. Whether
 * WordPress actually wires the rewrite rules into the rewrite tree on a real
 * request is verified by the integration suite (gated on wp-env, lands later).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;
use Outpost_Route_Handler;

/**
 * @covers \Outpost_Route_Handler
 */
final class RouteHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/** Model WordPress having matched a rewrite rule for this request. */
	private function set_matched_rule( ?string $rule ): void {
		$wp = new \stdClass();
		if ( null !== $rule ) {
			$wp->matched_rule = $rule;
		}
		$GLOBALS['wp'] = $wp;
	}

	/** @test */
	public function rules_table_contains_all_six_routes_in_upstream_first_order(): void {
		$rules = Outpost_Route_Handler::rules();

		$this->assertSame(
			array(
				'^post/manifest\.json$'        => 'manifest',
				// SW intentionally has no `.js` extension — see Outpost_Route_Handler::rules() comment.
				'^post/sw/?$'                  => 'sw',
				'^post/share-target/?$'        => 'share-target',
				'^post/shortcut/?$'            => 'shortcut',
				'^post/auth/callback/?$'       => 'auth-callback',
				'^post/?$'                     => 'composer',
			),
			$rules,
			'Rule order matters: manifest/sw must register before /post/?$ catch-all so they do not route to the composer.'
		);
	}

	/** @test */
	public function query_var_constant_is_outpost_route(): void {
		$this->assertSame( 'outpost_route', Outpost_Route_Handler::QUERY_VAR );
	}

	/** @test */
	public function register_query_var_appends_outpost_route_to_existing_vars(): void {
		$existing = array( 'p', 'page_id' );

		$result = Outpost_Route_Handler::register_query_var( $existing );

		$this->assertContains( Outpost_Route_Handler::QUERY_VAR, $result );
		$this->assertSame(
			array_merge( $existing, array( 'outpost_route' ) ),
			$result,
			'Query var must be appended, never replace existing vars.'
		);
	}

	/** @test */
	public function register_rewrite_rules_calls_add_rewrite_rule_once_per_table_entry(): void {
		foreach ( Outpost_Route_Handler::rules() as $regex => $target ) {
			WP_Mock::userFunction( 'add_rewrite_rule' )
				->once()
				->with(
					$regex,
					'index.php?' . Outpost_Route_Handler::QUERY_VAR . '=' . $target,
					'top'
				);
		}

		Outpost_Route_Handler::register_rewrite_rules();

		$this->assertTrue( true ); // Mock expectations are the real assertion.
	}

	/** @test */
	public function dispatch_does_nothing_when_query_var_is_empty(): void {
		WP_Mock::userFunction( 'get_query_var' )
			->with( Outpost_Route_Handler::QUERY_VAR )
			->andReturn( '' );

		// No PWA shell render method should be called. We assert by setting up
		// expectations on the side-effects we DO expect (none) and letting
		// strict mode catch unexpected ones.
		Outpost_Route_Handler::dispatch();

		$this->assertTrue( true );
	}

	/** @test */
	public function dispatch_routes_composer_target_to_pwa_shell_render(): void {
		$this->set_matched_rule( '^post/?$' );
		WP_Mock::userFunction( 'get_query_var' )
			->with( Outpost_Route_Handler::QUERY_VAR )
			->andReturn( 'composer' );
		// outpost_is_ready() reads these — stub them so version_compare doesn't
		// warn on null. The values produce a "ready=false, IndieAuth absent"
		// state, which exercises the install-prompt branch via dispatch.
		WP_Mock::userFunction( 'get_bloginfo' )->with( 'version' )->andReturn( '6.9' );
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::userFunction( 'self_admin_url' )
			->andReturnUsing( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( string $url ): string => $url . '&_wpnonce=test' );

		ob_start();
		Outpost_Route_Handler::dispatch();
		$out = ob_get_clean();

		// The composer route is the catch-all; in this stubbed env IndieAuth
		// is absent, so outpost_is_ready() is false and the dispatcher emits
		// the install-prompt page rather than the composer shell.
		$this->assertStringContainsString( 'outpost-install-prompt', $out );
	}

	/** @test */
	public function dispatch_routes_manifest_target_to_manifest_renderer(): void {
		$this->set_matched_rule( '^post/manifest\\.json$' );
		WP_Mock::userFunction( 'get_query_var' )
			->with( Outpost_Route_Handler::QUERY_VAR )
			->andReturn( 'manifest' );

		WP_Mock::userFunction( 'home_url' )
			->andReturnUsing( static fn( string $path = '' ): string => 'https://example.test' . $path );

		ob_start();
		Outpost_Route_Handler::dispatch();
		$out = ob_get_clean();

		$decoded = json_decode( $out, true );
		$this->assertIsArray( $decoded, 'Manifest route must emit JSON.' );
		$this->assertSame( 'Outpost', $decoded['name'] ?? null );
		$this->assertSame( '/post/', $decoded['scope'] ?? null );
	}

	/** @test */
	public function dispatch_routes_sw_target_to_service_worker_renderer(): void {
		$this->set_matched_rule( '^post/sw/?$' );
		WP_Mock::userFunction( 'get_query_var' )
			->with( Outpost_Route_Handler::QUERY_VAR )
			->andReturn( 'sw' );

		ob_start();
		Outpost_Route_Handler::dispatch();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'self.addEventListener', $out, 'Service worker stub must contain at least one event listener registration.' );
	}

	// --- Forged query var without a matched Outpost rewrite rule ----------
	//
	// `outpost_route` is a public query var, so `/?outpost_route=sw` (or any URL
	// with it appended) sets it without WordPress having matched an Outpost
	// rewrite rule. Rendering the shell/manifest/SW from that is the audited
	// R1 finding. Dispatch must key on the matched rule, not the raw query var.

	/** @test */
	public function dispatch_ignores_forged_query_var_when_no_rule_matched(): void {
		$this->set_matched_rule( null ); // WP matched nothing (e.g. front page with ?outpost_route=sw).
		WP_Mock::userFunction( 'get_query_var' )
			->with( Outpost_Route_Handler::QUERY_VAR )
			->andReturn( 'sw' );

		ob_start();
		Outpost_Route_Handler::dispatch();
		$out = ob_get_clean();

		$this->assertSame( '', $out, 'A forged outpost_route with no matched rule must render nothing.' );
	}

	/** @test */
	public function dispatch_ignores_query_var_when_a_foreign_rule_matched(): void {
		// A real page matched some OTHER rewrite rule, with ?outpost_route=sw
		// smuggled onto the query string.
		$this->set_matched_rule( '(.?.+?)/?$' ); // WP's generic page rule, not ours.
		WP_Mock::userFunction( 'get_query_var' )
			->with( Outpost_Route_Handler::QUERY_VAR )
			->andReturn( 'sw' );

		ob_start();
		Outpost_Route_Handler::dispatch();
		$out = ob_get_clean();

		$this->assertSame( '', $out, 'The matched rule is not ours; do not render.' );
	}

	/** @test */
	public function matched_rule_wins_over_the_query_var(): void {
		// The matched rewrite rule is authoritative: if WordPress matched the
		// manifest rule, the manifest renders even when a smuggled
		// ?outpost_route=sw says otherwise. The attacker-controlled query-var
		// value never selects the renderer.
		$this->set_matched_rule( '^post/manifest\\.json$' );
		WP_Mock::userFunction( 'get_query_var' )
			->with( Outpost_Route_Handler::QUERY_VAR )
			->andReturn( 'sw' );
		WP_Mock::userFunction( 'home_url' )
			->andReturnUsing( static fn( $p = '' ) => 'https://example.test' . $p );

		ob_start();
		Outpost_Route_Handler::dispatch();
		$out = ob_get_clean();

		$this->assertStringNotContainsString( 'self.addEventListener', $out, 'The SW must NOT render from a manifest-rule match.' );
		$this->assertStringContainsString( 'standalone', $out, 'The manifest (the matched rule) renders instead.' );
	}
}
