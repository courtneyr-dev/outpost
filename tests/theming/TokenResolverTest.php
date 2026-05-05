<?php
/**
 * Unit tests for Outpost_Token_Resolver (FY-Theming).
 *
 * Covers the priority chain (override > theme > default), per-mode
 * resolution, contrast enforcement with auto-adjust, the
 * bypass_contrast escape hatch, transient cache + theme-version
 * invalidation, and CSS emission shape (must pass B6 lint).
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Contrast;
use Outpost_Theme_Json_Reader;
use Outpost_Token_Resolver;
use WP_Mock;

final class TokenResolverTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	private string $theme_version = 'twentytwentyfive:1.0';

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta     = array();
		$this->transients    = array();
		$this->theme_version = 'twentytwentyfive:1.0';

		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, bool $single ) {
				$value = $this->user_meta[ $user_id ][ $key ] ?? '';
				return $single ? $value : array( $value );
			}
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_transient' )->andReturnUsing(
			fn ( string $key ) => $this->transients[ $key ] ?? false
		);
		WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function ( string $key, $value, int $expiration ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_transient' )->andReturnUsing(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);

		Outpost_Token_Resolver::set_theme_version_for_tests(
			fn (): string => $this->theme_version
		);
	}

	public function tearDown(): void {
		Outpost_Token_Resolver::set_theme_version_for_tests( null );
		Outpost_Theme_Json_Reader::set_reader_for_tests( null );
		WP_Mock::tearDown();
	}

	private function inject_theme_source( array $source ): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests(
			static fn () => $source
		);
	}

	// --- priority: override > theme > default ----------------------------

	public function test_resolves_to_default_when_no_theme_or_override(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( '#fbfaf5', $out['colors']['bg'] );
		$this->assertSame( '#241c4a', $out['colors']['text'] );
		$this->assertSame( 'default', $out['sources']['colors.bg'] );
		$this->assertSame( 'default', $out['sources']['colors.text'] );
	}

	public function test_theme_palette_takes_priority_over_default(): void {
		$this->inject_theme_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'background',
								'color' => '#abcdef',
							),
							array(
								'slug'  => 'foreground',
								'color' => '#001122',
							),
						),
					),
				),
			)
		);

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( '#abcdef', $out['colors']['bg'] );
		$this->assertSame( '#001122', $out['colors']['text'] );
		$this->assertSame( 'theme', $out['sources']['colors.bg'] );
	}

	public function test_user_override_beats_theme_and_default(): void {
		$this->inject_theme_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'background',
								'color' => '#abcdef',
							),
						),
					),
				),
			)
		);
		$this->user_meta[42][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'colors' => array(
					'bg' => '#123456',
				),
			),
		);

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( '#123456', $out['colors']['bg'] );
		$this->assertSame( 'override', $out['sources']['colors.bg'] );
	}

	public function test_per_token_override_does_not_affect_other_tokens(): void {
		$this->inject_theme_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'background',
								'color' => '#abcdef',
							),
							array(
								'slug'  => 'foreground',
								'color' => '#001122',
							),
						),
					),
				),
			)
		);
		$this->user_meta[42][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'colors' => array(
					'bg' => '#000000',
				),
			),
		);

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( '#000000', $out['colors']['bg'] );
		$this->assertSame( '#001122', $out['colors']['text'] );
		$this->assertSame( 'override', $out['sources']['colors.bg'] );
		$this->assertSame( 'theme', $out['sources']['colors.text'] );
	}

	public function test_font_override_path_works_same_as_colors(): void {
		$this->user_meta[42][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'fonts' => array(
					'body' => "'Custom Body Font', sans-serif",
				),
			),
		);

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( "'Custom Body Font', sans-serif", $out['fonts']['body'] );
		$this->assertSame( 'override', $out['sources']['fonts.body'] );
	}

	// --- per-mode resolution ---------------------------------------------

	public function test_day_and_night_resolve_to_different_defaults(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		$day   = Outpost_Token_Resolver::resolve( 42, 'day' );
		$night = Outpost_Token_Resolver::resolve( 42, 'night' );

		$this->assertSame( '#fbfaf5', $day['colors']['bg'] );
		$this->assertSame( '#251f1c', $night['colors']['bg'] );
		$this->assertSame( '#241c4a', $day['colors']['text'] );
		$this->assertSame( '#fbfaf5', $night['colors']['text'] );
	}

	public function test_invalid_mode_falls_back_to_day(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );
		$out = Outpost_Token_Resolver::resolve( 42, 'sepia' );
		$this->assertSame( 'day', $out['mode'] );
	}

	// --- contrast enforcement -------------------------------------------

	public function test_resolves_compliant_pair_unchanged(): void {
		// Russian violet on warm paper passes AA easily — no adjustment.
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( '#241c4a', $out['colors']['text'] );
		$this->assertArrayNotHasKey( 'text', $out['adjusted'] );
	}

	public function test_failing_pair_gets_auto_adjusted(): void {
		// Light gray on white fails body contrast; resolver adjusts.
		$this->user_meta[42][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'colors' => array(
					'text'    => '#cccccc',
					'surface' => '#ffffff',
				),
			),
		);

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertNotSame( '#cccccc', $out['colors']['text'] );
		$this->assertArrayHasKey( 'text', $out['adjusted'] );
		$this->assertSame( '#cccccc', $out['adjusted']['text']['original'] );
		$this->assertGreaterThanOrEqual(
			Outpost_Contrast::TARGET_AA_BODY,
			$out['adjusted']['text']['ratio_after']
		);
	}

	public function test_bypass_contrast_keeps_failing_pair_intact(): void {
		// User explicitly toggled "Override anyway" for the text token.
		$this->user_meta[42][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day'             => array(
				'colors' => array(
					'text'    => '#cccccc',
					'surface' => '#ffffff',
				),
			),
			'bypass_contrast' => array( 'text' ),
		);

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( '#cccccc', $out['colors']['text'] );
		$this->assertArrayNotHasKey( 'text', $out['adjusted'] );
	}

	public function test_non_hex_tokens_not_subjected_to_contrast(): void {
		// tape + halftone are rgba(); resolver doesn't try to compute
		// contrast on them and doesn't crash.
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertStringContainsString( 'rgba', $out['colors']['tape'] );
		$this->assertStringContainsString( 'rgba', $out['colors']['halftone'] );
	}

	// --- cache + invalidation -------------------------------------------

	public function test_resolved_tokens_cached_in_transient(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertArrayHasKey(
			Outpost_Token_Resolver::TRANSIENT_PREFIX . '42_day',
			$this->transients
		);
	}

	public function test_cache_invalidation_via_explicit_call(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		Outpost_Token_Resolver::resolve( 42, 'day' );
		Outpost_Token_Resolver::resolve( 42, 'night' );

		Outpost_Token_Resolver::invalidate_cache_for_user( 42 );

		$this->assertArrayNotHasKey(
			Outpost_Token_Resolver::TRANSIENT_PREFIX . '42_day',
			$this->transients
		);
		$this->assertArrayNotHasKey(
			Outpost_Token_Resolver::TRANSIENT_PREFIX . '42_night',
			$this->transients
		);
	}

	public function test_theme_version_change_invalidates_cache_implicitly(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		// First resolve — caches under v1.
		Outpost_Token_Resolver::resolve( 42, 'day' );

		// Theme switches.
		$this->theme_version = 'mynewtheme:2.0';

		// Override in user-meta to make the resolved value visibly different.
		$this->user_meta[42][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'colors' => array(
					'bg' => '#abcdef',
				),
			),
		);

		// Resolve again — cache miss because theme_version moved.
		$out = Outpost_Token_Resolver::resolve( 42, 'day' );
		$this->assertSame( '#abcdef', $out['colors']['bg'] );
	}

	// --- CSS emission ---------------------------------------------------

	public function test_to_css_produces_token_only_block(): void {
		$resolved = array(
			'mode'   => 'day',
			'colors' => array(
				'bg'   => '#fbfaf5',
				'text' => '#241c4a',
			),
			'fonts'  => array(
				'body' => "'Inter', sans-serif",
			),
			'sizes'  => array(
				'body' => '1rem',
			),
		);

		$css = Outpost_Token_Resolver::to_css( $resolved );
		$this->assertStringStartsWith( '.outpost-mode-day {', $css );
		$this->assertStringContainsString( '--outpost-bg: #fbfaf5;', $css );
		$this->assertStringContainsString( '--outpost-text: #241c4a;', $css );
		$this->assertStringContainsString( "--outpost-font-body: 'Inter', sans-serif;", $css );
		$this->assertStringContainsString( '--outpost-size-body: 1rem;', $css );
		$this->assertStringEndsWith( '}', trim( $css ) );
	}

	public function test_to_css_contains_only_custom_property_declarations(): void {
		// Property of the B6 lint: every line inside the block must
		// either be the open/close brace or `--outpost-*: value;`.
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );
		$resolved = Outpost_Token_Resolver::resolve( 42, 'day' );

		$css   = Outpost_Token_Resolver::to_css( $resolved );
		$lines = array_map( 'trim', explode( "\n", $css ) );

		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue;
			}
			if ( str_ends_with( $line, '{' ) || '}' === $line ) {
				continue;
			}
			$this->assertMatchesRegularExpression(
				'/^--outpost-[a-z0-9-]+:\s.+;$/',
				$line,
				"Token-only block expected; got: $line"
			);
		}
	}

	public function test_to_css_uses_normalized_token_names(): void {
		// `text_secondary` slug → `--outpost-text-secondary`.
		// `accent_2` slug → `--outpost-accent-2`.
		$resolved = array(
			'mode'   => 'day',
			'colors' => array(
				'text_secondary' => '#888888',
				'accent_2'       => '#444444',
			),
			'fonts'  => array(),
			'sizes'  => array(),
		);

		$css = Outpost_Token_Resolver::to_css( $resolved );
		$this->assertStringContainsString( '--outpost-text-secondary: #888888;', $css );
		$this->assertStringContainsString( '--outpost-accent-2: #444444;', $css );
	}
}
