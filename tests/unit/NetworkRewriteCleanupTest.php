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
	 * The deactivation hook calls the cleanup only for a network-wide deactivation on multisite.
	 */
	public function test_deactivation_runs_the_network_cleanup_for_network_wide_only(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/outpost.php' );
		$start  = (int) strpos( $source, 'function outpost_deactivate(' );
		$body   = substr( $source, $start, (int) strpos( $source, "register_deactivation_hook( __FILE__, 'outpost_deactivate' );" ) - $start );

		$this->assertStringNotContainsString( 'unset( $network_wide )', $body );
		$this->assertMatchesRegularExpression( '/if \( \$network_wide && is_multisite\(\) \) \{\s*Outpost_Uninstaller::delete_rewrite_rules_on_other_sites\(\);/', $body );
		$this->assertLessThan(
			(int) strpos( $body, 'delete_rewrite_rules_on_other_sites' ),
			(int) strpos( $body, 'stop_deactivation' ),
			'The cleanup runs only after the Shortcut password cleanup succeeded.'
		);
	}
}
