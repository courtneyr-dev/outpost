<?php
/**
 * Outpost_Settings unit tests — the default categories / tags fields.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Abilities;
use Outpost_Settings;
use Outpost_Uninstaller;
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

	public function test_registers_two_disabled_boolean_ability_options_and_fields(): void {
		$settings = array();
		$sections = array();
		$fields   = array();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( string $text ): string => $text );
		WP_Mock::userFunction( 'register_setting' )
			->times( 3 )
			->andReturnUsing(
				static function ( string $group, string $option, array $args ) use ( &$settings ): void {
					$settings[ $option ] = array( $group, $args );
				}
			);
		WP_Mock::userFunction( 'add_settings_section' )
			->times( 2 )
			->andReturnUsing(
				static function ( string $id, string $title ) use ( &$sections ): void {
					$sections[ $id ] = $title;
				}
			);
		WP_Mock::userFunction( 'add_settings_field' )
			->times( 8 )
			->andReturnUsing(
				static function ( string $id, string $title, $callback, string $page, string $section ) use ( &$fields ): void {
					$fields[ $id ] = array( $title, $callback, $page, $section );
				}
			);

		Outpost_Settings::register_settings();

		foreach ( array( 'outpost_ability_manual_share', 'outpost_ability_capture_syndication' ) as $option ) {
			$this->assertSame( 'outpost_settings_group', $settings[ $option ][0] );
			$this->assertSame( 'boolean', $settings[ $option ][1]['type'] );
			$this->assertSame( 'rest_sanitize_boolean', $settings[ $option ][1]['sanitize_callback'] );
			$this->assertFalse( $settings[ $option ][1]['show_in_rest'] );
			$this->assertFalse( $settings[ $option ][1]['default'] );
			$this->assertSame( 'outpost_settings_agent_abilities', $fields[ $option ][3] );
		}
		$this->assertSame( 'Agent abilities', $sections['outpost_settings_agent_abilities'] );
	}

	/**
	 * @dataProvider ability_option_value_provider
	 *
	 * @param mixed $stored Stored option value.
	 */
	public function test_ability_switch_reads_only_wordpress_boolean_values( $stored, bool $enabled ): void {
		WP_Mock::userFunction( 'get_option' )->once()->andReturn( $stored );

		$this->assertSame( $enabled, Outpost_Abilities::ability_is_enabled( Outpost_Abilities::OPTION_MANUAL_SHARE ) );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function ability_option_value_provider(): array {
		return array(
			'string false' => array( 'false', false ),
			'no'           => array( 'no', false ),
			'off'          => array( 'off', false ),
			'string zero'  => array( '0', false ),
			'empty string' => array( '', false ),
			'integer zero' => array( 0, false ),
			'boolean false' => array( false, false ),
			'boolean true' => array( true, true ),
			'integer one'  => array( 1, true ),
			'string one'   => array( '1', true ),
		);
	}

	public function test_renders_the_exact_ability_checkbox_labels_and_checks_a_stored_string_one(): void {
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( string $text ): string => $text );
		WP_Mock::userFunction( 'get_option' )->andReturn( '1' );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( static fn ( string $text ): string => $text );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static fn ( string $text ): string => $text );
		WP_Mock::userFunction( 'checked' )->with( true, true, false )->andReturn( ' checked="checked"' );

		ob_start();
		Outpost_Settings::render_manual_share_ability_field();
		Outpost_Settings::render_capture_syndication_ability_field();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="outpost_ability_manual_share"', $output );
		$this->assertStringContainsString( 'Let agents prepare a manual share for a post and record it in the post\'s share log.', $output );
		$this->assertStringContainsString( 'name="outpost_ability_capture_syndication"', $output );
		$this->assertStringContainsString( 'Let agents record a silo URL as a post\'s syndication link.', $output );
		$this->assertSame( 2, substr_count( $output, 'checked="checked"' ) );
	}

	public function test_uninstaller_owns_both_ability_options(): void {
		$reflection = new \ReflectionClass( Outpost_Uninstaller::class );
		$options    = $reflection->getConstant( 'OPTIONS' );

		$this->assertContains( 'outpost_ability_manual_share', $options );
		$this->assertContains( 'outpost_ability_capture_syndication', $options );
	}
}
