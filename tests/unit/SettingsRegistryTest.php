<?php
/**
 * Outpost_Settings_Registry unit tests (G3.5d).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Settings_Registry;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SettingsRegistryTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// ---------- Tabs ----------

	public function test_default_tabs_include_api_keys(): void {
		$tabs = Outpost_Settings_Registry::get_tabs();

		$this->assertArrayHasKey( 'api_keys', $tabs );
		$this->assertSame( 'API Keys', $tabs['api_keys']['label'] );
		$this->assertSame( 'manage_options', $tabs['api_keys']['capability'] );
	}

	public function test_filter_can_register_additional_tabs(): void {
		WP_Mock::onFilter( 'outpost_settings_tabs' )
			->withAnyArgs()
			->reply(
				array(
					'api_keys'    => array(
						'label'      => 'API Keys',
						'callback'   => '__return_null',
						'capability' => 'manage_options',
					),
					'instances'   => array(
						'label'      => 'Self-hosted instances',
						'callback'   => '__return_null',
						'capability' => 'manage_options',
					),
				)
			);

		$tabs = Outpost_Settings_Registry::get_tabs();

		$this->assertArrayHasKey( 'instances', $tabs );
	}

	public function test_filter_drops_malformed_entries(): void {
		WP_Mock::onFilter( 'outpost_settings_tabs' )
			->withAnyArgs()
			->reply(
				array(
					'api_keys' => array(
						'label'    => 'API Keys',
						'callback' => '__return_null',
					),
					'broken'   => array( 'callback' => '__return_null' ), // missing label
					42         => array(
						'label'    => 'Numeric key',
						'callback' => '__return_null',
					), // non-string key
				)
			);

		$tabs = Outpost_Settings_Registry::get_tabs();

		$this->assertArrayHasKey( 'api_keys', $tabs );
		$this->assertArrayNotHasKey( 'broken', $tabs );
		$this->assertArrayNotHasKey( 42, $tabs );
	}

	public function test_filter_returning_non_array_falls_back_to_defaults(): void {
		WP_Mock::onFilter( 'outpost_settings_tabs' )
			->withAnyArgs()
			->reply( 'not-an-array' );

		$tabs = Outpost_Settings_Registry::get_tabs();

		$this->assertArrayHasKey( 'api_keys', $tabs );
	}

	public function test_get_tab_returns_null_for_unknown(): void {
		$this->assertNull( Outpost_Settings_Registry::get_tab( 'nonexistent' ) );
	}

	// ---------- Fields ----------

	public function test_field_registration_via_filter(): void {
		WP_Mock::onFilter( 'outpost_settings_fields_api_keys' )
			->withAnyArgs()
			->reply(
				array(
					'api_bible_key' => array(
						'label'     => 'API.Bible key',
						'type'      => 'password',
						'sensitive' => true,
					),
				)
			);

		$fields = Outpost_Settings_Registry::get_fields( 'api_keys' );

		$this->assertArrayHasKey( 'api_bible_key', $fields );
		$this->assertSame( 'password', $fields['api_bible_key']['type'] );
		$this->assertTrue( $fields['api_bible_key']['sensitive'] );
	}

	public function test_get_fields_returns_empty_for_unregistered_tab(): void {
		$this->assertSame( array(), Outpost_Settings_Registry::get_fields( 'no_filters_registered' ) );
	}

	public function test_field_filter_drops_malformed_entries(): void {
		WP_Mock::onFilter( 'outpost_settings_fields_api_keys' )
			->withAnyArgs()
			->reply(
				array(
					'good_field'  => array(
						'label' => 'Good',
						'type'  => 'text',
					),
					'no_label'    => array( 'type' => 'text' ),
					'no_type'     => array( 'label' => 'No type' ),
				)
			);

		$fields = Outpost_Settings_Registry::get_fields( 'api_keys' );

		$this->assertArrayHasKey( 'good_field', $fields );
		$this->assertArrayNotHasKey( 'no_label', $fields );
		$this->assertArrayNotHasKey( 'no_type', $fields );
	}

	public function test_normalize_type_coerces_unknown_to_text(): void {
		$this->assertSame( 'text', Outpost_Settings_Registry::normalize_type( 'totally-bogus' ) );
		$this->assertSame( 'text', Outpost_Settings_Registry::normalize_type( '' ) );
	}

	public function test_normalize_type_passes_through_supported(): void {
		foreach ( array( 'text', 'password', 'url', 'checkbox', 'select' ) as $type ) {
			$this->assertSame( $type, Outpost_Settings_Registry::normalize_type( $type ) );
		}
	}

	public function test_option_name_for_tab_uses_outpost_prefix(): void {
		$this->assertSame( 'outpost_settings_api_keys', Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ) );
	}
}
