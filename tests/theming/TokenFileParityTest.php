<?php
/**
 * Pins the shipped Outpost Default token file to the resolver's
 * component derivation, and measures the file's final pairs against
 * WCAG 2.2 AA thresholds.
 *
 * If this fails after a palette edit, either the file or
 * Outpost_Token_Resolver::DEFAULTS drifted: Outpost Default with user
 * overrides would then paint differently from Outpost Default without.
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Contrast;
use Outpost_Token_Resolver;

final class TokenFileParityTest extends \WP_Mock\Tools\TestCase {

	/**
	 * @return array<string,string> token name (no prefix) => value
	 */
	private function file_block( string $selector_start ): array {
		$css   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/styles/outpost-tokens.css' );
		$start = strpos( $css, $selector_start );
		$this->assertNotFalse( $start, "Block $selector_start missing from outpost-tokens.css" );
		$open  = strpos( $css, '{', (int) $start );
		$close = strpos( $css, '}', (int) $open );
		$body  = substr( $css, (int) $open + 1, (int) $close - (int) $open - 1 );
		preg_match_all( '/--outpost-([a-z0-9-]+):\s*([^;]+);/', $body, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $match ) {
			$out[ $match[1] ] = trim( $match[2] );
		}
		return $out;
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function schemes(): array {
		return array(
			'light' => array( 'day', ".outpost-mode-day,\n.outpost-mode-system {" ),
			'dark'  => array( 'night', '.outpost-mode-night {' ),
		);
	}

	/**
	 * @dataProvider schemes
	 */
	public function test_file_matches_derivation_from_defaults( string $mode, string $selector ): void {
		$adjusted = array();
		$derived  = Outpost_Token_Resolver::component_tokens( Outpost_Token_Resolver::DEFAULTS[ $mode ]['colors'], array(), $adjusted );
		$file     = $this->file_block( $selector );
		$this->assertSame( array(), $adjusted, 'The Outpost Default palette must pass without adjustment.' );
		foreach ( $derived as $name => $value ) {
			$this->assertArrayHasKey( $name, $file, "--outpost-$name missing from the $mode block" );
			$this->assertSame( $value, $file[ $name ], "--outpost-$name differs from the derivation in the $mode block" );
		}
	}

	/**
	 * @dataProvider schemes
	 */
	public function test_file_pairs_meet_wcag_aa( string $mode, string $selector ): void {
		$t     = $this->file_block( $selector );
		$pairs = array(
			array( 'surface-fg', 'surface-bg', 4.5 ),
			array( 'card-fg', 'card-bg', 4.5 ),
			array( 'input-fg', 'input-bg', 4.5 ),
			array( 'input-placeholder', 'input-bg', 4.5 ),
			array( 'text-muted', 'surface-bg', 4.5 ),
			array( 'text-muted', 'card-bg', 4.5 ),
			array( 'button-primary-fg', 'button-primary-bg', 4.5 ),
			array( 'chip-fg-pressed', 'chip-bg-pressed', 4.5 ),
			array( 'tab-fg-active', 'tab-bg-active', 4.5 ),
			array( 'tab-fg', 'surface-bg', 4.5 ),
			array( 'link', 'surface-bg', 4.5 ),
			array( 'link', 'card-bg', 4.5 ),
			array( 'button-primary-fg-disabled', 'button-primary-bg-disabled', 4.5 ),
			array( 'error-fg', 'error-bg', 4.5 ),
			array( 'success-fg', 'success-bg', 4.5 ),
			array( 'warning-fg', 'warning-bg', 4.5 ),
			array( 'info-fg', 'info-bg', 4.5 ),
			array( 'input-border', 'input-bg', 3.0 ),
			array( 'input-border', 'surface-bg', 3.0 ),
			array( 'card-border', 'surface-bg', 3.0 ),
			array( 'focus-ring-color', 'surface-bg', 3.0 ),
			array( 'focus-ring-color', 'input-bg', 3.0 ),
			array( 'input-border-focus', 'input-bg', 3.0 ),
			array( 'tab-indicator', 'surface-bg', 3.0 ),
			array( 'logo', 'surface-bg', 3.0 ),
			array( 'error-border', 'surface-bg', 3.0 ),
		);
		foreach ( $pairs as list( $fg, $bg, $target ) ) {
			$this->assertArrayHasKey( $fg, $t, "$mode: --outpost-$fg missing" );
			$this->assertArrayHasKey( $bg, $t, "$mode: --outpost-$bg missing" );
			$ratio = Outpost_Contrast::ratio( $t[ $fg ], $t[ $bg ] );
			$this->assertGreaterThanOrEqual( $target, $ratio, sprintf( '%s: %s (%s) on %s (%s) is %.2f:1, needs %.1f:1', $mode, $fg, $t[ $fg ], $bg, $t[ $bg ], $ratio, $target ) );
		}
	}

	public function test_bundled_and_server_files_declare_the_same_tokens(): void {
		$root    = dirname( __DIR__, 2 );
		$strip   = static fn ( string $css ): string => (string) preg_replace( '/\/\*.*?\*\//s', '', $css );
		$server  = $strip( (string) file_get_contents( $root . '/styles/outpost-tokens.css' ) );
		$bundled = $strip( (string) file_get_contents( $root . '/pwa/src/styles/tokens.css' ) );
		$this->assertSame( trim( $server ), trim( $bundled ) );
	}
}
