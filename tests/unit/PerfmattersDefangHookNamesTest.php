<?php
/**
 * The Perfmatters defang list only reaches Perfmatters hooks (F-60).
 *
 * On Outpost routes each listed name gets `__return_true`, so a site
 * filter that added `user_has_cap` would grant every capability there.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Perfmatters_Defang;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * @covers \Outpost_Perfmatters_Defang
 */
final class PerfmattersDefangHookNamesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// WP_Mock 1.x leaks Filter::$filtersWithAnyArgs across tests.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		$GLOBALS['wp'] = (object) array( 'matched_rule' => '^post/?$' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		// Clear the any-args reply so it can't reach later test classes.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_site_added_perfmatters_filter_is_kept(): void {
		WP_Mock::onFilter( 'outpost_perfmatters_defang_filters' )->withAnyArgs()->reply(
			array( 'perfmatters_delay_js_disable', 'perfmatters_new_feature_disable' )
		);

		$this->assertSame(
			array( 'perfmatters_delay_js_disable', 'perfmatters_new_feature_disable' ),
			Outpost_Perfmatters_Defang::disable_filters_for_request()
		);
	}

	public function test_non_perfmatters_hooks_are_dropped(): void {
		WP_Mock::onFilter( 'outpost_perfmatters_defang_filters' )->withAnyArgs()->reply(
			array( 'user_has_cap', 'perfmatters_delay_js_disable', 'map_meta_cap', 'show_admin_bar', 'perfmatters', 'perfmatters_x; y', 'Perfmatters_Upper' )
		);

		$this->assertSame(
			array( 'perfmatters_delay_js_disable' ),
			Outpost_Perfmatters_Defang::disable_filters_for_request(),
			'Only perfmatters_* hook names may receive __return_true.'
		);
	}
}
