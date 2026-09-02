<?php
/**
 * The settings page must hang under the main Outpost menu, not register a
 * second top-level "Outpost" entry (which put two identical "Outpost" items
 * in the wp-admin sidebar, either side of core's Settings).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Settings_Page;
use WP_Mock;

/**
 * @covers \Outpost_Settings_Page
 */
final class SettingsPageMenuTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_settings_page_is_a_submenu_of_the_main_outpost_menu(): void {
		WP_Mock::userFunction( 'add_submenu_page' )
			->once()
			->with(
				'outpost',
				'Outpost Settings',
				'Settings',
				'manage_options',
				'outpost-settings',
				\Mockery::type( 'array' )
			)
			->andReturn( 'outpost_page_outpost-settings' );
		WP_Mock::userFunction( 'add_menu_page' )->never();

		Outpost_Settings_Page::add_menu_page();

		$this->assertConditionsMet();
	}
}
