<?php
/**
 * Network deactivation clears Outpost's rewrite rules on every site
 * (Phase 5b review finding F5-02).
 *
 * The deactivation hook runs once, on the current site. Each other site
 * keeps its own stored rewrite table, which still holds Outpost's rules
 * until that table is rebuilt. Deleting each site's `rewrite_rules`
 * option makes WordPress rebuild it on that site's next request, without
 * Outpost. wp-env is single-site, so this runs with WP_Mock.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Uninstaller;
use WP_Mock;

final class NetworkRewriteCleanupTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/includes/class-outpost-uninstaller.php';
	}

	/**
	 * Every other site's stored table is deleted, and the current site is left to the caller.
	 */
	public function test_deletes_the_stored_rules_on_every_other_site(): void {
		$switched = array();
		$deleted  = array();
		$current  = 1;
		WP_Mock::userFunction( 'get_current_blog_id' )->andReturnUsing( static function () use ( &$current ) {
			return $current;
		} );
		WP_Mock::userFunction( 'get_sites' )->once()->andReturn( array( 1, 2, 3 ) );
		WP_Mock::userFunction( 'switch_to_blog' )->andReturnUsing(
			static function ( int $id ) use ( &$switched, &$current ): bool {
				$switched[] = $id;
				$current    = $id;
				return true;
			}
		);
		WP_Mock::userFunction( 'restore_current_blog' )->andReturnUsing(
			static function () use ( &$current ): bool {
				$current = 1;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			static function ( string $name ) use ( &$deleted, &$current ): bool {
				$deleted[] = $current . ':' . $name;
				return true;
			}
		);

		Outpost_Uninstaller::delete_rewrite_rules_on_other_sites();

		$this->assertSame( array( 2, 3 ), $switched );
		$this->assertSame( array( '2:rewrite_rules', '3:rewrite_rules' ), $deleted );
		$this->assertSame( 1, $current, 'The original site is restored.' );
	}

	/**
	 * Mock what outpost_deactivate() reaches. The unit suite's in-memory
	 * WP_Application_Passwords stands in for core; user 5 holds a Shortcut
	 * password, which the cleanup deletes unless `$fail` makes deletes fail.
	 *
	 * @param bool  $multisite Whether the site is a network.
	 * @param array $deleted   Collects "site:option" for each deletion.
	 * @param bool  $fail      Whether the Shortcut password deletion fails.
	 */
	private function mock_deactivation( bool $multisite, array &$deleted, bool $fail = false ): void {
		\WP_Application_Passwords::reset();
		\WP_Application_Passwords::create_new_application_password( 5, array( 'name' => 'Outpost iOS Shortcut', 'app_id' => 'eb290b80-79b0-4104-b470-4d6188280ccc' ) );
		\WP_Application_Passwords::$fail_deletes = $fail;
		WP_Mock::userFunction( 'get_users' )->andReturn( array( 5 ) );
		$current = 1;
		if ( ! $fail ) {
			WP_Mock::userFunction( 'flush_rewrite_rules' )->once();
		}
		WP_Mock::userFunction( 'is_multisite' )->andReturn( $multisite );
		WP_Mock::userFunction( 'get_current_blog_id' )->andReturnUsing( static function () use ( &$current ) {
			return $current;
		} );
		WP_Mock::userFunction( 'get_sites' )->andReturn( array( 1, 2 ) );
		WP_Mock::userFunction( 'switch_to_blog' )->andReturnUsing( static function ( int $id ) use ( &$current ): bool {
			$current = $id;
			return true;
		} );
		WP_Mock::userFunction( 'restore_current_blog' )->andReturnUsing( static function () use ( &$current ): bool {
			$current = 1;
			return true;
		} );
		WP_Mock::userFunction( 'delete_option' )->andReturnUsing( static function ( string $name ) use ( &$deleted, &$current ): bool {
			$deleted[] = $current . ':' . $name;
			return true;
		} );
	}

	/**
	 * A network-wide deactivation on multisite clears the other sites' tables.
	 */
	public function test_network_deactivation_clears_the_other_sites(): void {
		$deleted = array();
		$this->mock_deactivation( true, $deleted );

		outpost_deactivate( true );

		$this->assertSame( array( '2:rewrite_rules' ), $deleted );
	}

	/**
	 * A single-site deactivation, or a per-site one on multisite, touches no other site.
	 *
	 * @dataProvider local_deactivations
	 */
	public function test_other_deactivations_touch_no_other_site( bool $multisite, bool $network_wide ): void {
		$deleted = array();
		$this->mock_deactivation( $multisite, $deleted );

		outpost_deactivate( $network_wide );

		$this->assertSame( array(), $deleted );
	}

	/**
	 * A network deactivation stopped by a surviving Shortcut password touches no site.
	 */
	public function test_a_stopped_network_deactivation_touches_no_site(): void {
		$deleted = array();
		$this->mock_deactivation( true, $deleted, true );
		WP_Mock::userFunction( 'flush_rewrite_rules' )->never();
		WP_Mock::userFunction( 'wp_die' )->once()->andReturnUsing(
			static function (): void {
				throw new \OverflowException( 'wp_die' );
			}
		);

		try {
			outpost_deactivate( true );
			$this->fail( 'Deactivation must stop while a Shortcut password survives.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertSame( 'wp_die', $stopped->getMessage() );
		}
		$this->assertSame( array(), $deleted );
	}

	public function tearDown(): void {
		\WP_Application_Passwords::reset();
		parent::tearDown();
	}

	/** @return array<string, array{bool, bool}> */
	public function local_deactivations(): array {
		return array(
			'single site'              => array( false, false ),
			'single site, flag set'    => array( false, true ),
			'multisite, one site only' => array( true, false ),
		);
	}
}
