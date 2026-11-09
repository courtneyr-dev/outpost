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
		$this->assertSame( '#1e1838', $night['colors']['bg'] );
		$this->assertSame( '#241c4a', $day['colors']['text'] );
		$this->assertSame( '#f7f4ff', $night['colors']['text'] );
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

	// --- style source (PWA UI appearance decision) ------------------------

	private function tt5_like_source(): array {
		return array(
			'settings' => array(
				'color' => array(
					'palette' => array(
						'theme' => array(
							array(
								'slug'  => 'base',
								'color' => '#ffffff',
							),
							array(
								'slug'  => 'contrast',
								'color' => '#111111',
							),
							array(
								'slug'  => 'accent-1',
								'color' => '#ffee58',
							),
							array(
								'slug'  => 'accent-3',
								'color' => '#503aa8',
							),
						),
					),
				),
			),
			'styles'   => array(
				'color'    => array(
					'background' => 'var:preset|color|base',
					'text'       => 'var:preset|color|contrast',
				),
				'elements' => array(
					'link' => array( 'color' => array( 'text' => 'var(--wp--preset--color--accent-3)' ) ),
				),
			),
		);
	}

	public function test_outpost_source_ignores_the_theme_palette(): void {
		$this->inject_theme_source( $this->tt5_like_source() );
		$out = Outpost_Token_Resolver::resolve( 42, 'day', 'outpost' );
		$this->assertSame( '#fbfaf5', $out['colors']['bg'] );
		$this->assertSame( '#241c4a', $out['colors']['text'] );
		$this->assertSame( 'default', $out['sources']['colors.bg'] );
		$this->assertSame( 'outpost', $out['source'] );
	}

	public function test_outpost_source_still_applies_user_overrides(): void {
		$this->inject_theme_source( $this->tt5_like_source() );
		$this->user_meta[42][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array( 'colors' => array( 'accent' => '#0055aa' ) ),
		);
		$out = Outpost_Token_Resolver::resolve( 42, 'day', 'outpost' );
		$this->assertSame( '#0055aa', $out['colors']['accent'] );
		$this->assertSame( 'override', $out['sources']['colors.accent'] );
	}

	public function test_site_source_reads_semantic_global_styles(): void {
		$this->inject_theme_source( $this->tt5_like_source() );
		$out = Outpost_Token_Resolver::resolve( 42, 'day', 'site' );
		$this->assertSame( '#ffffff', $out['colors']['bg'] );
		$this->assertSame( '#111111', $out['colors']['text'] );
		$this->assertSame( '#503aa8', $out['components']['link'], 'The theme link color passes 4.5:1 on white and is used.' );
	}

	public function test_site_source_button_label_meets_contrast_on_a_pale_accent(): void {
		// accent-1 #ffee58 (pale yellow) cannot take a white label; the
		// derivation picks the theme's own dark ink instead.
		$this->inject_theme_source( $this->tt5_like_source() );
		$out = Outpost_Token_Resolver::resolve( 42, 'day', 'site' );
		$c   = $out['components'];
		// The FY rule (accent at 3:1 on surface) darkens the yellow first
		// and records it; the button fill is that adjusted accent.
		$this->assertSame( '#ffee58', $out['adjusted']['accent']['original'] );
		$this->assertSame( $out['colors']['accent'], $c['button-primary-bg'] );
		$this->assertGreaterThanOrEqual( 4.5, Outpost_Contrast::ratio( $c['button-primary-fg'], $c['button-primary-bg'] ) );
		$this->assertGreaterThanOrEqual( 3.0, Outpost_Contrast::ratio( $c['focus-ring-color'], $c['surface-bg'] ) );
		$this->assertGreaterThanOrEqual( 3.0, Outpost_Contrast::ratio( $c['focus-ring-color'], $c['input-bg'] ) );
	}

	public function test_dark_without_a_dark_variation_keeps_dark_surfaces(): void {
		$this->inject_theme_source( $this->tt5_like_source() );
		$out = Outpost_Token_Resolver::resolve( 42, 'night', 'site' );
		$this->assertSame( '#1e1838', $out['components']['surface-bg'], 'A light-only theme must not paint dark mode with its light page color.' );
		$this->assertGreaterThanOrEqual( 4.5, Outpost_Contrast::ratio( $out['components']['surface-fg'], $out['components']['surface-bg'] ) );
	}

	public function test_dark_variation_supplies_dark_values(): void {
		$source               = $this->tt5_like_source();
		$source['variations'] = array(
			array(
				'slug'     => 'evening',
				'settings' => array(
					'color' => array(
						'palette' => array(
							'theme' => array(
								array(
									'slug'  => 'base',
									'color' => '#1b1b1b',
								),
								array(
									'slug'  => 'contrast',
									'color' => '#f0f0f0',
								),
							),
						),
					),
				),
			),
		);
		$this->inject_theme_source( $source );
		$out = Outpost_Token_Resolver::resolve( 42, 'night', 'site' );
		$this->assertSame( '#1b1b1b', $out['components']['surface-bg'] );
		$this->assertSame( '#f0f0f0', $out['components']['surface-fg'] );
	}

	public function test_sources_cache_separately(): void {
		$this->inject_theme_source( $this->tt5_like_source() );
		$site    = Outpost_Token_Resolver::resolve( 42, 'day', 'site' );
		$outpost = Outpost_Token_Resolver::resolve( 42, 'day', 'outpost' );
		$this->assertNotSame( $site['colors']['bg'], $outpost['colors']['bg'] );
		$this->assertArrayHasKey( Outpost_Token_Resolver::TRANSIENT_PREFIX . '42_day', $this->transients );
		$this->assertArrayHasKey( Outpost_Token_Resolver::TRANSIENT_PREFIX . '42_day_outpost', $this->transients );
	}

	public function test_site_editor_palette_edit_invalidates_cache_without_theme_version_change(): void {
		$source = $this->tt5_like_source();
		$this->inject_theme_source( $source );
		Outpost_Token_Resolver::resolve( 42, 'day', 'site' );

		$source['settings']['color']['palette']['theme'][0]['color'] = '#fff8e7';
		$this->inject_theme_source( $source );
		$out = Outpost_Token_Resolver::resolve( 42, 'day', 'site' );
		$this->assertSame( '#fff8e7', $out['colors']['bg'], 'A Global Styles edit must reach the composer even when the theme version is unchanged.' );
	}

	public function test_scoped_css_rejects_values_that_could_escape_the_declaration(): void {
		$css = Outpost_Token_Resolver::to_scoped_css(
			'site',
			array(
				'components' => array(
					'font-family-body' => 'x; } body { background: red',
					'surface-bg'       => '#ffffff',
				),
			),
			array(
				'components' => array(
					'font-family-body' => 'a</style><script>',
					'logo'             => 'url(https://example.test/x)',
				),
			)
		);
		$this->assertStringNotContainsString( 'background: red', $css );
		$this->assertStringNotContainsString( '<', $css );
		$this->assertStringNotContainsString( 'url(', $css );
		$this->assertStringContainsString( '--outpost-surface-bg: #ffffff;', $css );
	}

	public function test_scoped_css_follows_mode_class_structure(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );
		$css = Outpost_Token_Resolver::to_scoped_css(
			'site',
			Outpost_Token_Resolver::resolve( 42, 'day', 'site' ),
			Outpost_Token_Resolver::resolve( 42, 'night', 'site' )
		);
		$this->assertStringContainsString( '.outpost-source-site.outpost-mode-day, .outpost-source-site.outpost-mode-system {', $css );
		$this->assertStringContainsString( '.outpost-source-site.outpost-mode-night {', $css );
		$this->assertMatchesRegularExpression( '/@media \(prefers-color-scheme: dark\) \{\s*\.outpost-source-site\.outpost-mode-system \{\s*color-scheme: dark;/', $css );
		$this->assertSame( 0, preg_match( '/#[0-9a-f]{3,8}\s*[^;]*\{/i', $css ), 'Selectors carry no color values.' );
	}

	public function test_site_source_derives_missing_tones_from_the_theme(): void {
		// TT5 names no border or muted-text color. Those tones must come
		// from the theme's own text and page colors, not Outpost's violet.
		$this->inject_theme_source( $this->tt5_like_source() );
		$out = Outpost_Token_Resolver::resolve( 42, 'day', 'site' );
		$c   = $out['components'];
		$this->assertNotSame( Outpost_Token_Resolver::DEFAULTS['day']['colors']['border'], $c['border'] );
		$this->assertNotSame( Outpost_Token_Resolver::DEFAULTS['day']['colors']['text_secondary'], $c['text-muted'] );
		$this->assertSame( Outpost_Token_Resolver::mix_hex( '#ffffff', '#111111', 0.55 ), $c['border'] );
		$this->assertGreaterThanOrEqual( 3.0, Outpost_Contrast::ratio( $c['border'], $c['surface-bg'] ) );
		$this->assertGreaterThanOrEqual( 4.5, Outpost_Contrast::ratio( $c['text-muted'], $c['surface-bg'] ) );
	}

	public function test_mix_hex_endpoints_and_midpoint(): void {
		$this->assertSame( '#ffffff', Outpost_Token_Resolver::mix_hex( '#ffffff', '#000000', 0.0 ) );
		$this->assertSame( '#000000', Outpost_Token_Resolver::mix_hex( '#ffffff', '#000000', 1.0 ) );
		$this->assertSame( '#808080', Outpost_Token_Resolver::mix_hex( '#ffffff', '#000000', 0.5 ) );
	}

	/**
	 * Mockup review P6: the selected Light / Dark / System segment takes a
	 * tint, not the orange action fill, and keeps its label and ring readable.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function segment_palettes(): array {
		return array(
			'Outpost Default light' => array( Outpost_Token_Resolver::DEFAULTS['day']['colors'] ),
			'Outpost Default dark'  => array( Outpost_Token_Resolver::DEFAULTS['night']['colors'] ),
			'pale accent site'      => array(
				array(
					'bg'       => '#ffffff',
					'surface'  => '#ffffff',
					'text'     => '#111111',
					'accent'   => '#ffee58',
					'accent_2' => '#503aa8',
				),
			),
			'low-contrast ink site' => array(
				array(
					'bg'       => '#f4f4f4',
					'surface'  => '#ffffff',
					'text'     => '#767676',
					'accent'   => '#0a7d3b',
					'accent_2' => '#333333',
				),
			),
		);
	}

	/**
	 * @dataProvider segment_palettes
	 * @param array<string,string> $colors
	 */
	public function test_selected_segment_is_a_readable_tint_not_the_action_fill( array $colors ): void {
		$adjusted = array();
		$c        = Outpost_Token_Resolver::component_tokens( $colors, array(), $adjusted );
		$this->assertArrayHasKey( 'segment-bg-selected', $c );
		$this->assertArrayHasKey( 'segment-fg-selected', $c );
		$this->assertNotSame( $c['button-primary-bg'], $c['segment-bg-selected'], 'The selected segment must not reuse the publish fill.' );
		$this->assertNotSame( $c['tab-bg-active'], $c['segment-bg-selected'], 'The selected segment must not reuse the selected-tab fill.' );
		$this->assertGreaterThanOrEqual( 4.5, Outpost_Contrast::ratio( $c['segment-fg-selected'], $c['segment-bg-selected'] ) );
		$this->assertGreaterThanOrEqual( 3.0, Outpost_Contrast::ratio( $c['tab-indicator'], $c['segment-bg-selected'] ), 'The inset ring is the non-color cue and needs 3:1.' );
	}
}
