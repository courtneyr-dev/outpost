<?php
/**
 * Issue #320: the composer shell opts out of page optimizers on its own
 * routes, and only there.
 *
 * Perfmatters' Remove Unused CSS rewrites stylesheets to media="print" with
 * an inline onload swap, and its Delay JavaScript retypes scripts to
 * `pmdelayedscript` behind an inline loader. The shell's CSP allows no
 * inline script, so both leave the composer unstyled or unbooted. The stub
 * buffer below does what Perfmatters 2.6.9 does (inc/classes/Buffer.php,
 * CSS.php, JS.php), including asking `perfmatters_allow_buffer` first.
 *
 * Each test runs in its own process: the opt-out defines constants, and a
 * constant can't be undefined for the next test.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_PWA_Shell;
use Outpost_Route_Handler;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ShellOptimizerOptOutTest extends TestCase {

	/**
	 * Constants page optimizers and caches read when their buffer flushes
	 * (WP Rocket 3.24, LiteSpeed Cache 7.9.3) or before they start one
	 * (Autoptimize 3.1.17 reads DONOTMINIFY).
	 */
	private const CONSTANTS = array( 'DONOTCACHEPAGE', 'DONOTROCKETOPTIMIZE', 'DONOTMINIFY', 'LITESPEED_NO_OPTM', 'LITESPEED_NO_PAGEOPTM' );

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_PWA_Shell' ) || ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}
	}

	/**
	 * Start a capture buffer, then inside it a buffer that rewrites the page
	 * the way Perfmatters does. finish_stub_optimizer() flushes the inner
	 * buffer through its handler and returns what a browser would get.
	 */
	private static function start_stub_optimizer(): void {
		ob_start();
		ob_start(
			static function ( string $html ): string {
				if ( ! apply_filters( 'perfmatters_allow_buffer', true ) ) {
					return $html;
				}
				if ( apply_filters( 'perfmatters_remove_unused_css', true ) ) {
					$html = (string) preg_replace(
						'~<link rel=([\'"])stylesheet\1~',
						'<link rel=$1stylesheet$1 media="print" onload="this.media=\'all\';this.onload=null;"',
						$html
					);
				}
				if ( apply_filters( 'perfmatters_delay_js', true ) ) {
					$html = (string) preg_replace( '~<script type="module"~', '<script type="pmdelayedscript" data-perfmatters-type="module"', $html );
					$html = str_replace( '</body>', '<script id="perfmatters-delayed-scripts-js">(function(){})();</script></body>', $html );
				}
				return $html;
			}
		);
	}

	private static function finish_stub_optimizer(): string {
		ob_end_flush();
		return (string) ob_get_clean();
	}

	/**
	 * @test
	 */
	public function shell_reaches_the_browser_without_optimizer_rewrites(): void {
		self::start_stub_optimizer();
		Outpost_PWA_Shell::render_shell();
		$html = self::finish_stub_optimizer();

		$this->assertStringContainsString( '<html', $html, 'The shell printed a document.' );
		$this->assertMatchesRegularExpression( '~<link rel=[\'"]stylesheet~', $html, 'The shell links its stylesheets.' );
		$this->assertStringNotContainsString( 'onload=', $html, 'No inline onload swap reaches a page whose CSP blocks it.' );
		$this->assertStringNotContainsString( 'pmdelayedscript', $html, 'No script is retyped behind a loader.' );
		$this->assertStringNotContainsString( 'perfmatters-delayed-scripts-js', $html, 'No inline loader is added.' );
		$this->assertStringContainsString( '<!-- noptimize-page -->', $html, 'Autoptimize skips a buffer it opened early.' );
	}

	/**
	 * @test
	 */
	public function shell_render_sets_each_known_opt_out(): void {
		ob_start();
		Outpost_PWA_Shell::render_shell();
		ob_end_clean();

		foreach ( self::CONSTANTS as $constant ) {
			$this->assertTrue( defined( $constant ) && true === constant( $constant ), "{$constant} is true on the shell." );
		}
		$this->assertFalse( apply_filters( 'perfmatters_allow_buffer', true ), 'Perfmatters buffer is off.' );
		$this->assertFalse( apply_filters( 'perfmatters_remove_unused_css', true ), 'Perfmatters unused-CSS removal is off.' );
		$this->assertFalse( apply_filters( 'perfmatters_delay_js', true ), 'Perfmatters delay JS is off.' );
		$this->assertTrue( apply_filters( 'autoptimize_filter_noptimize', false ), 'Autoptimize skips the page.' );
		$this->assertSame( 1, did_action( 'litespeed_control_set_nocache' ), 'LiteSpeed marks the shell non-cacheable.' );
	}

	/**
	 * A request that matched no Outpost rule goes through dispatch()
	 * untouched, so the site's optimizers keep working everywhere else.
	 *
	 * @test
	 */
	public function other_pages_keep_their_optimizers(): void {
		global $wp;
		$wp->matched_rule = '([^/]+)/?$';
		set_query_var( Outpost_Route_Handler::QUERY_VAR, '' );

		self::start_stub_optimizer();
		Outpost_Route_Handler::dispatch();
		echo "<!doctype html><html><head><link rel='stylesheet' href='/a.css' /></head><body><script type=\"module\" src=\"/a.js\"></script></body></html>";
		$html = self::finish_stub_optimizer();

		foreach ( self::CONSTANTS as $constant ) {
			$this->assertFalse( defined( $constant ), "{$constant} stays undefined off the shell." );
		}
		$this->assertTrue( apply_filters( 'perfmatters_allow_buffer', true ) );
		$this->assertTrue( apply_filters( 'perfmatters_remove_unused_css', true ) );
		$this->assertTrue( apply_filters( 'perfmatters_delay_js', true ) );
		$this->assertFalse( apply_filters( 'autoptimize_filter_noptimize', false ) );
		$this->assertSame( 0, did_action( 'litespeed_control_set_nocache' ) );
		$this->assertStringContainsString( 'onload=', $html, 'The stub still rewrites other pages.' );
		$this->assertStringContainsString( 'pmdelayedscript', $html );
		$this->assertStringNotContainsString( 'noptimize-page', $html );
	}
}
