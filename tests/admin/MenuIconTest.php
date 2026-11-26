<?php
/**
 * Tests for the Outpost administration menu's icon.
 *
 * @package Outpost\Tests\Admin
 */

declare(strict_types=1);

namespace Outpost\Tests\Admin;

use Outpost_Admin_Page;
use WP_Mock;

final class MenuIconTest extends WP_Mock\Tools\TestCase {

	public function test_older_core_uses_the_original_icon(): void {
		WP_Mock::userFunction( 'get_bloginfo' )->with( 'version' )->andReturn( '7.1.5' );
		WP_Mock::userFunction( 'add_menu_page' )->once()->with(
			'Outpost',
			'Outpost',
			'manage_options',
			'outpost',
			array( Outpost_Admin_Page::class, 'render_page' ),
			outpost_menu_icon(),
			76
		)->andReturn( 'toplevel_page_outpost' );

		Outpost_Admin_Page::register_menu();
		$this->assertConditionsMet();
	}

	public function test_core_72_registers_an_admin_only_icon_and_uses_its_name(): void {
		WP_Mock::userFunction( 'get_bloginfo' )->with( 'version' )->andReturn( '7.2-RC3' );
		WP_Mock::userFunction( 'wp_register_icon_collection' )->once()->with(
			'outpost',
			array(
				'label'  => 'Outpost',
				'public' => false,
			)
		)->andReturn( true );
		WP_Mock::userFunction( 'wp_register_icon' )->once()->with(
			'outpost/fingerpost',
			\Mockery::on( static fn ( array $args ): bool => str_contains( $args['content'], 'fill="currentColor"' ) )
		)->andReturn( true );
		WP_Mock::userFunction( 'wp_get_icon' )->with( 'outpost/fingerpost' )->andReturn( '' );
		WP_Mock::userFunction( 'add_menu_page' )->once()->with(
			'Outpost',
			'Outpost',
			'manage_options',
			'outpost',
			array( Outpost_Admin_Page::class, 'render_page' ),
			'outpost/fingerpost',
			76
		)->andReturn( 'toplevel_page_outpost' );

		Outpost_Admin_Page::register_menu();
		$this->assertConditionsMet();
	}
}
