<?php
/**
 * Outpost_Settings unit tests — the default categories / tags fields.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_Term;

final class SettingsTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function trim_sanitizer(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $value ) => trim( strip_tags( (string) $value ) )
		);
	}

	public function test_defaults_carry_no_default_terms(): void {
		$defaults = Outpost_Settings::defaults();

		$this->assertSame( array(), $defaults['default_categories'] );
		$this->assertSame( array(), $defaults['default_tags'] );
	}

	public function test_sanitize_keeps_positive_unique_category_ids(): void {
		$this->trim_sanitizer();

		$out = Outpost_Settings::sanitize(
			array(
				'default_post_variant' => 'note',
				'default_categories'   => array( '1105', 1105, '0', '-3', 'abc', array( 9 ), '7' ),
			)
		);

		$this->assertSame( array( 1105, 7 ), $out['default_categories'] );
	}

	public function test_sanitize_splits_trims_and_dedupes_tags(): void {
		$this->trim_sanitizer();

		$out = Outpost_Settings::sanitize(
			array(
				'default_post_variant' => 'note',
				'default_tags'         => ' WordPress, IndieWeb ,, wordpress, <b>photos</b> ',
			)
		);

		$this->assertSame( array( 'WordPress', 'IndieWeb', 'photos' ), $out['default_tags'] );
	}

	public function test_sanitize_accepts_an_array_of_tags_and_caps_the_list(): void {
		$this->trim_sanitizer();
		$many = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$many[] = 'tag' . $i;
		}

		$out = Outpost_Settings::sanitize(
			array(
				'default_post_variant' => 'note',
				'default_tags'         => $many,
			)
		);

		$this->assertCount( 25, $out['default_tags'] );
		$this->assertSame( 'tag1', $out['default_tags'][0] );
		$this->assertSame( 'tag25', $out['default_tags'][24] );
	}

	public function test_sanitize_without_the_fields_stores_empty_lists(): void {
		$this->trim_sanitizer();

		$out = Outpost_Settings::sanitize( array( 'default_post_variant' => 'article' ) );

		$this->assertSame( array(), $out['default_categories'] );
		$this->assertSame( array(), $out['default_tags'] );
		$this->assertSame( 'article', $out['default_post_variant'] );
	}

	public function test_default_category_ids_drop_terms_that_no_longer_exist(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array( 'default_categories' => array( 1105, 42 ) ) );
		WP_Mock::userFunction( 'term_exists' )->andReturnUsing(
			static fn( $id ) => 1105 === $id ? 1105 : 0
		);

		$this->assertSame( array( 1105 ), Outpost_Settings::default_category_ids() );
	}

	public function test_default_category_names_resolve_in_stored_order(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array( 'default_categories' => array( 1105, 7 ) ) );
		WP_Mock::userFunction( 'term_exists' )->andReturn( 1 );
		WP_Mock::userFunction( 'get_term' )->andReturnUsing(
			static function ( $id ) {
				return 1105 === $id
					? new WP_Term( array( 'term_id' => 1105, 'name' => 'Activity' ) )
					: new WP_Term( array( 'term_id' => 7, 'name' => 'WordPress' ) );
			}
		);

		$this->assertSame( array( 'Activity', 'WordPress' ), Outpost_Settings::default_category_names() );
	}

	public function test_default_category_names_skip_a_term_that_fails_to_load(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array( 'default_categories' => array( 1105, 7 ) ) );
		WP_Mock::userFunction( 'term_exists' )->andReturn( 1 );
		WP_Mock::userFunction( 'get_term' )->andReturnUsing(
			static fn( $id ) => 1105 === $id ? new WP_Term( array( 'term_id' => 1105, 'name' => 'Activity' ) ) : null
		);

		$this->assertSame( array( 'Activity' ), Outpost_Settings::default_category_names() );
	}

	public function test_default_tag_names_come_back_as_non_empty_strings(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array( 'default_tags' => array( 'indieweb', '', 42 ) ) );

		$this->assertSame( array( 'indieweb', '42' ), Outpost_Settings::default_tag_names() );
	}

	public function test_get_merges_defaults_for_a_site_that_saved_before_the_fields_existed(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array( 'default_post_variant' => 'note' ) );

		$settings = Outpost_Settings::get();

		$this->assertSame( 'note', $settings['default_post_variant'] );
		$this->assertSame( array(), $settings['default_categories'] );
		$this->assertSame( array(), $settings['default_tags'] );
	}
}
