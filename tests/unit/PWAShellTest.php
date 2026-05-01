<?php
/**
 * Unit tests for Outpost_PWA_Shell.
 *
 * The shell has two main rendering branches and two artefact endpoints:
 *
 *  - render() emits the composer shell HTML when outpost_is_ready() is true.
 *  - render() emits the install-prompt page when first_unsatisfied() returns
 *    a chain entry. The page consumes outpost_dependency_presentation() so
 *    the label/wp.org slug stay in sync with the admin notice path.
 *  - render_manifest() emits a JSON manifest with name + scope + start_url.
 *  - render_service_worker() emits a JS stub with at least one event listener.
 *
 * Body assertions stay light — exact HTML belongs in an integration suite,
 * not here. We assert on the structural hooks the rest of the system reads:
 *  - the install-prompt root class (`outpost-install-prompt`)
 *  - the composer root class (`outpost-composer-shell`)
 *  - manifest scope (`/post/`)
 *  - service worker scope is also `/post/` (Decision #128 in CLAUDE.md)
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;
use Outpost_PWA_Shell;

/**
 * @covers \Outpost_PWA_Shell
 */
final class PWAShellTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// Workaround for WP_Mock 1.x: Filter::$filtersWithAnyArgs is static and
		// leaks across tests. A prior test that called ->withAnyArgs() will
		// poison every subsequent apply_filters() call for that filter name —
		// the static gets read on apply() and the input value is replaced with
		// a random integer, which then fails any downstream array lookup.
		$ref = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Stub WP env so outpost_is_ready() returns true.
	 *
	 * Uses andReturnUsing closures throughout so stubs match every invocation
	 * regardless of how many times the SUT calls them. (The detector tests
	 * established this pattern in A1.)
	 */
	private function stub_ready_environment(): void {
		WP_Mock::userFunction( 'get_bloginfo' )
			->andReturnUsing( static fn(): string => '6.9' );
		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing(
				static function ( $file ): bool {
					return in_array(
						$file,
						array(
							OUTPOST_INDIEAUTH_PLUGIN_FILE,
							OUTPOST_MICROPUB_PLUGIN_FILE,
						),
						true
					);
				}
			);
		WP_Mock::userFunction( 'get_plugins' )
			->andReturnUsing(
				static fn(): array => array(
					OUTPOST_INDIEAUTH_PLUGIN_FILE => array( 'Name' => 'IndieAuth' ),
					OUTPOST_MICROPUB_PLUGIN_FILE  => array( 'Name' => 'Micropub' ),
				)
			);
		WP_Mock::userFunction( 'home_url' )
			->andReturnUsing( static fn( string $path = '' ): string => 'https://example.test' . $path );
	}

	/**
	 * Stub WP env so IndieAuth is missing — first_unsatisfied() returns the IndieAuth file.
	 */
	private function stub_indieauth_missing_environment(): void {
		WP_Mock::userFunction( 'get_bloginfo' )
			->andReturnUsing( static fn(): string => '6.9' );
		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing( static fn(): bool => false );
		WP_Mock::userFunction( 'get_plugins' )
			->andReturnUsing( static fn(): array => array() );
		WP_Mock::userFunction( 'self_admin_url' )
			->andReturnUsing( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturnUsing( static fn( string $url ): string => $url . '&_wpnonce=test' );
	}

	/** @test */
	public function render_emits_composer_shell_when_ready(): void {
		$this->stub_ready_environment();

		ob_start();
		Outpost_PWA_Shell::render();
		$out = ob_get_clean();

		$this->assertStringContainsString(
			'outpost-composer-shell',
			$out,
			'Ready state must render the composer shell root.'
		);
		$this->assertStringNotContainsString(
			'outpost-install-prompt',
			$out,
			'Ready state must NOT render the install-prompt fallback.'
		);
	}

	/** @test */
	public function render_emits_install_prompt_when_indieauth_missing(): void {
		$this->stub_indieauth_missing_environment();

		ob_start();
		Outpost_PWA_Shell::render();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'outpost-install-prompt', $out );
		$this->assertStringContainsString( 'IndieAuth', $out, 'Install prompt must show the IndieAuth label from the presentation map.' );
		$this->assertStringContainsString( 'install-plugin', $out, 'Install prompt for an absent plugin must offer an install link.' );
	}

	/** @test */
	public function render_install_prompt_consumes_filtered_presentation(): void {
		$this->stub_indieauth_missing_environment();

		WP_Mock::onFilter( 'outpost_dependency_presentation' )
			->withAnyArgs()
			->reply(
				array(
					OUTPOST_INDIEAUTH_PLUGIN_FILE => array(
						'label' => 'Custom Auth Plugin',
						'slug'  => 'indieauth',
					),
					OUTPOST_MICROPUB_PLUGIN_FILE  => array(
						'label' => 'Micropub',
						'slug'  => 'micropub',
					),
				)
			);

		ob_start();
		Outpost_PWA_Shell::render();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Custom Auth Plugin', $out );
		$this->assertStringNotContainsString( '>IndieAuth<', $out, 'Filtered label must replace the default.' );
	}

	/** @test */
	public function render_manifest_emits_json_with_post_scope(): void {
		WP_Mock::userFunction( 'home_url' )
			->andReturnUsing( static fn( string $path = '' ): string => 'https://example.test' . $path );

		ob_start();
		Outpost_PWA_Shell::render_manifest();
		$out = ob_get_clean();

		$decoded = json_decode( $out, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'Outpost', $decoded['name'] );
		$this->assertSame( '/post/', $decoded['scope'] );
		$this->assertSame( '/post/', $decoded['start_url'] );
		$this->assertSame( 'standalone', $decoded['display'] );
	}

	/** @test */
	public function render_service_worker_emits_javascript_with_post_scope_only(): void {
		ob_start();
		Outpost_PWA_Shell::render_service_worker();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'self.addEventListener', $out );
		// Service worker scope is /post/ only — never the whole site (CLAUDE.md Standards §128).
		$this->assertStringContainsString( '/post/', $out );
	}
}
