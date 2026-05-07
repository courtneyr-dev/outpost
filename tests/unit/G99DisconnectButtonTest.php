<?php
/**
 * G99 — disconnect handler cap-check regression test.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_OAuth_Settings_Page;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class G99DisconnectButtonTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_disconnect_handler_short_circuits_without_cap(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( false );
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( static function ( $s ) { return $s; } );

		$caught = null;
		try {
			$_POST = array( 'provider' => 'notion' );
			Outpost_OAuth_Settings_Page::handle_disconnect_post();
		} catch ( \Throwable $e ) {
			$caught = $e;
		}
		$this->assertNotNull( $caught, 'Expected wp_die when cap check fails' );
	}
}
