<?php
/**
 * Unit tests for outpost_dependency_presentation().
 *
 * The helper is the single source of label/wp.org-slug pairs for the IndieAuth
 * and Micropub blockers. Both the admin-notice path and the PWA install-prompt
 * page consume it. The filter `outpost_dependency_presentation` lets future
 * sessions and third-party plugins extend or override the map without editing
 * the function.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * @covers ::outpost_dependency_presentation
 */
final class DependencyPresentationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// Workaround for WP_Mock 1.x: Filter::$filtersWithAnyArgs is static and
		// leaks across tests — see the same comment in PWAShellTest::setUp.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/** @test */
	public function returns_indieauth_presentation_for_indieauth_file(): void {
		$this->assertSame(
			array(
				'label' => 'IndieAuth',
				'slug'  => 'indieauth',
			),
			\outpost_dependency_presentation( OUTPOST_INDIEAUTH_PLUGIN_FILE )
		);
	}

	/** @test */
	public function returns_micropub_presentation_for_micropub_file(): void {
		$this->assertSame(
			array(
				'label' => 'Micropub',
				'slug'  => 'micropub',
			),
			\outpost_dependency_presentation( OUTPOST_MICROPUB_PLUGIN_FILE )
		);
	}

	/** @test */
	public function returns_null_for_unknown_plugin_file(): void {
		$this->assertNull(
			\outpost_dependency_presentation( 'some-other-plugin/some-other-plugin.php' )
		);
	}

	/** @test */
	public function filter_can_override_an_existing_entry(): void {
		WP_Mock::onFilter( 'outpost_dependency_presentation' )
			->withAnyArgs()
			->reply(
				array(
					OUTPOST_INDIEAUTH_PLUGIN_FILE => array(
						'label' => 'Custom IndieAuth Label',
						'slug'  => 'custom-indieauth',
					),
				)
			);

		$this->assertSame(
			array(
				'label' => 'Custom IndieAuth Label',
				'slug'  => 'custom-indieauth',
			),
			\outpost_dependency_presentation( OUTPOST_INDIEAUTH_PLUGIN_FILE )
		);
	}

	/** @test */
	public function filter_can_add_new_entries_for_future_chain_extensions(): void {
		// Simulates a future chain extension: a plugin file that isn't in the
		// hard-coded map gets a presentation entry through the filter.
		$future_file = 'future-required/future-required.php';

		WP_Mock::onFilter( 'outpost_dependency_presentation' )
			->withAnyArgs()
			->reply(
				array(
					OUTPOST_INDIEAUTH_PLUGIN_FILE => array(
						'label' => 'IndieAuth',
						'slug'  => 'indieauth',
					),
					OUTPOST_MICROPUB_PLUGIN_FILE  => array(
						'label' => 'Micropub',
						'slug'  => 'micropub',
					),
					$future_file                  => array(
						'label' => 'Future Plugin',
						'slug'  => 'future-required',
					),
				)
			);

		$this->assertSame(
			array(
				'label' => 'Future Plugin',
				'slug'  => 'future-required',
			),
			\outpost_dependency_presentation( $future_file )
		);
	}
}
