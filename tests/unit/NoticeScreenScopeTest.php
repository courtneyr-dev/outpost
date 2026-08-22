<?php
/**
 * Unit tests for outpost_is_notice_screen().
 *
 * Guideline 11 asks that admin notices stay limited in scope. Outpost's
 * dependency and requirements notices used to render on `admin_notices`
 * unconditionally, which put them on every screen in wp-admin. These tests
 * pin where they may and may not appear.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

final class NoticeScreenScopeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Stub get_current_screen() to return a screen with the given id.
	 *
	 * @param string|null $id Screen id, or null for "no screen resolved yet".
	 */
	private function given_screen( ?string $id ): void {
		$screen = null;
		if ( null !== $id ) {
			$screen     = new \WP_Screen();
			$screen->id = $id;
		}
		WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
	}

	/**
	 * @dataProvider provide_screens_that_carry_the_notice
	 */
	public function test_notice_renders_on_actionable_screens( string $screen_id ): void {
		$this->given_screen( $screen_id );
		$this->assertTrue(
			outpost_is_notice_screen(),
			"Setup notices must render on '{$screen_id}' — it is where the reader acts on them."
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_screens_that_carry_the_notice(): array {
		return array(
			'plugins list'          => array( 'plugins' ),
			'plugins list, network' => array( 'plugins-network' ),
			'add plugins'           => array( 'plugin-install' ),
			'add plugins, network'  => array( 'plugin-install-network' ),
			'Outpost main page'     => array( 'toplevel_page_outpost' ),
			'Outpost OAuth page'    => array( 'outpost_page_outpost-oauth' ),
			'Outpost shortcut page' => array( 'settings_page_outpost-ios-shortcut' ),
		);
	}

	/**
	 * @dataProvider provide_screens_that_stay_clean
	 */
	public function test_notice_stays_off_unrelated_screens( string $screen_id ): void {
		$this->given_screen( $screen_id );
		$this->assertFalse(
			outpost_is_notice_screen(),
			"Setup notices must not render on '{$screen_id}' — an unrelated screen."
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_screens_that_stay_clean(): array {
		return array(
			'dashboard'              => array( 'dashboard' ),
			'post editor'            => array( 'post' ),
			'posts list'             => array( 'edit-post' ),
			'media library'          => array( 'upload' ),
			'general settings'       => array( 'options-general' ),
			'themes'                 => array( 'themes' ),
			'users'                  => array( 'users' ),
			'another plugin"s page'  => array( 'settings_page_some-other-plugin' ),
		);
	}

	public function test_no_notice_before_a_screen_resolves(): void {
		// get_current_screen() returns null early in the request, before
		// the screen is set up. Rendering then would be a fatal on ->id.
		$this->given_screen( null );
		$this->assertFalse( outpost_is_notice_screen() );
	}
}
