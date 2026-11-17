<?php
/**
 * Outpost_Theme_Json_Reader
 *
 * Reads color palette + font family declarations from the active
 * theme's `theme.json` (or its style variations on schema v3+) and
 * returns them in a normalized shape that {@see Outpost_Token_Resolver}
 * uses as the "theme" priority layer.
 *
 * SCHEMA HANDLING
 *
 * - **v2 (single palette).** `color.palette` is a single array of
 *   `{slug, color, name}` entries. fonts under `typography.fontFamilies`.
 *   Both day and night modes inherit from the same palette; the
 *   resolver applies algorithmic surface/border lightening for night
 *   when only a single palette exists.
 * - **v3+ (style variations).** `styles/variations/*.json` files alongside
 *   `theme.json` define alternate appearances. Outpost reads variations
 *   keyed on common day/night naming patterns (`light`/`dark`,
 *   `day`/`night`, `morning`/`evening`) when present, otherwise falls
 *   back to the main palette for both modes.
 *
 * OUTPUT SHAPE
 *
 *     [
 *         'day' => [
 *             'colors' => [ 'background' => '#fff', 'foreground' => '#000', ... ],
 *             'fonts'  => [ 'body' => 'Inter, ...', 'heading' => '...', 'monospace' => '...' ],
 *         ],
 *         'night' => [ ... same shape ... ],
 *     ]
 *
 * Semantic styles come first: `styles.color.background` (bg),
 * `styles.color.text` (text), the button element's background (accent),
 * and the link element's text color (accent-2). A button or link in the
 * body-text color is skipped. Preset references (`var:preset|color|base`,
 * `var(--wp--preset--color--base)`) resolve through the palette; anything
 * that doesn't resolve to a hex color is ignored.
 *
 * Palette slugs fill the remaining tokens, in priority order:
 *   text:      foreground, contrast, ink, text
 *   bg:        background, base, surface, paper
 *   accent:    accent, accent-1, primary, brand
 *   accent-2:  secondary, accent-2, accent-secondary
 *
 * `primary` is not a text candidate: many themes use it for a brand
 * color that fails as body text.
 *
 * Font slugs:
 *   body:      body, primary, sans
 *   display:   display, heading, headings, secondary, serif
 *   monospace: monospace, mono, code
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Theme_Json_Reader {

	/** @var array<string, string[]> Slug priority lists, color tokens. */
	private const COLOR_SLUG_PRIORITY = array(
		'text'     => array( 'foreground', 'contrast', 'ink', 'text' ),
		'bg'       => array( 'background', 'base', 'surface', 'paper' ),
		'accent'   => array( 'accent', 'accent-1', 'primary', 'brand' ),
		'accent_2' => array( 'secondary', 'accent-2', 'accent-secondary' ),
	);

	/** @var array<string, string[]> Slug priority lists, font tokens. */
	private const FONT_SLUG_PRIORITY = array(
		'body'      => array( 'body', 'primary', 'sans' ),
		'display'   => array( 'display', 'heading', 'headings', 'secondary', 'serif' ),
		'monospace' => array( 'monospace', 'mono', 'code' ),
	);

	/** @var string[] Style-variation filename stems treated as "night" mode. */
	private const NIGHT_VARIATION_SLUGS = array( 'dark', 'night', 'evening', 'midnight' );

	/** @var string[] Style-variation filename stems treated as "day" mode. */
	private const DAY_VARIATION_SLUGS = array( 'light', 'day', 'morning' );

	/**
	 * Test seam: callable that returns the raw `theme.json` array.
	 * Production path uses {@see WP_Theme_JSON_Resolver} via
	 * {@see wp_get_global_settings()}; tests inject fixture data.
	 *
	 * @var callable|null
	 */
	private static $reader_for_tests = null;

	/**
	 * Override the production theme.json reader with a test callable.
	 * Pass null to restore production behavior.
	 *
	 * @param callable|null $reader fn(): array — returns raw theme.json shape.
	 */
	public static function set_reader_for_tests( ?callable $reader ): void {
		self::$reader_for_tests = $reader;
	}

	/**
	 * Read the active theme's color + font declarations, normalized
	 * into the day/night shape Outpost_Token_Resolver expects.
	 *
	 * Returns empty arrays for both modes when the active theme has
	 * no theme.json or the file's shape is malformed — the resolver
	 * handles missing values by falling through to built-in defaults.
	 *
	 * @return array{day: array{colors: array<string,string>, fonts: array<string,string>}, night: array{colors: array<string,string>, fonts: array<string,string>}}
	 */
	public static function read(): array {
		$source = self::load_source();
		if ( null === $source ) {
			return self::empty_shape();
		}

		$day_palette   = array_merge(
			self::resolve_palette( $source, self::DAY_VARIATION_SLUGS ),
			self::semantic_colors( $source, self::palette_by_slug( self::palette_for_variation( $source, self::DAY_VARIATION_SLUGS ) ) )
		);
		$night_palette = self::resolve_palette( $source, self::NIGHT_VARIATION_SLUGS );
		$night_node    = self::find_variation( $source, self::NIGHT_VARIATION_SLUGS );
		if ( null !== $night_node ) {
			$night_palette = array_merge(
				$night_palette,
				self::semantic_colors( $night_node, self::palette_by_slug( self::palette_for_variation( $source, self::NIGHT_VARIATION_SLUGS ) ) )
			);
		}
		$day_fonts   = self::resolve_fonts( $source, self::DAY_VARIATION_SLUGS );
		$night_fonts = self::resolve_fonts( $source, self::NIGHT_VARIATION_SLUGS );

		// When no night variation exists, night inherits the day palette
		// so the resolver can apply algorithmic adjustments downstream.
		if ( empty( $night_palette ) ) {
			$night_palette = $day_palette;
		}
		if ( empty( $night_fonts ) ) {
			$night_fonts = $day_fonts;
		}

		return array(
			'day'   => array(
				'colors' => $day_palette,
				'fonts'  => $day_fonts,
			),
			'night' => array(
				'colors' => $night_palette,
				'fonts'  => $night_fonts,
			),
		);
	}

	/**
	 * Whether the active theme's theme.json declares separate day/night
	 * style variations. Used by the resolver to decide between
	 * "two palette inputs" and "one palette + algorithmic dark surface."
	 */
	public static function has_dark_variation(): bool {
		$source = self::load_source();
		if ( null === $source ) {
			return false;
		}
		$variations = isset( $source['variations'] ) && is_array( $source['variations'] )
			? $source['variations']
			: array();
		foreach ( $variations as $variation ) {
			$slug = isset( $variation['slug'] ) ? strtolower( (string) $variation['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			foreach ( self::NIGHT_VARIATION_SLUGS as $candidate ) {
				if ( $slug === $candidate || str_contains( $slug, $candidate ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Pull the active theme's theme.json shape. Production reads via WP
	 * core's resolver; tests inject fixtures via {@see set_reader_for_tests}.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function load_source(): ?array {
		if ( null !== self::$reader_for_tests ) {
			$result = call_user_func( self::$reader_for_tests );
			return is_array( $result ) ? $result : null;
		}
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return null;
		}
		$resolver = \WP_Theme_JSON_Resolver::get_merged_data();
		if ( ! is_object( $resolver ) || ! method_exists( $resolver, 'get_raw_data' ) ) {
			return null;
		}
		$raw = $resolver->get_raw_data();
		// Style variations appear under a top-level `variations` key in
		// merged data when WP core has loaded them; preserve that shape
		// for has_dark_variation() and resolve_palette() lookup. WP 6.0+
		// always exposes get_style_variations on the resolver class;
		// Outpost's min WP version is 6.5, so the method is always
		// callable when class_exists check above passed.
		$variations        = \WP_Theme_JSON_Resolver::get_style_variations();
		$raw['variations'] = is_array( $variations ) ? $variations : array();
		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * Walk the slug-priority list for each color token and return the
	 * first match found in the palette of the named variation (or main
	 * palette when variation is absent).
	 *
	 * @param array<string,mixed> $source            Raw theme.json shape.
	 * @param string[]            $variation_slug_set Variation slugs to try (e.g., night).
	 * @return array<string,string> Map of token slug → color hex/css.
	 */
	private static function resolve_palette( array $source, array $variation_slug_set ): array {
		$by_slug = self::palette_by_slug( self::palette_for_variation( $source, $variation_slug_set ) );
		if ( empty( $by_slug ) ) {
			return array();
		}
		$out = array();
		foreach ( self::COLOR_SLUG_PRIORITY as $token => $candidates ) {
			foreach ( $candidates as $candidate ) {
				if ( isset( $by_slug[ $candidate ] ) ) {
					$out[ $token ] = $by_slug[ $candidate ];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $palette
	 * @return array<string,string> slug => color
	 */
	private static function palette_by_slug( array $palette ): array {
		$by_slug = array();
		foreach ( $palette as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$slug  = isset( $entry['slug'] ) ? strtolower( (string) $entry['slug'] ) : '';
			$color = isset( $entry['color'] ) ? (string) $entry['color'] : '';
			if ( '' === $slug || '' === $color ) {
				continue;
			}
			$by_slug[ $slug ] = $color;
		}
		return $by_slug;
	}

	/**
	 * Semantic colors from a node's `styles` tree.
	 *
	 * @param array<string,mixed>  $node    theme.json root or a style variation.
	 * @param array<string,string> $by_slug Palette, slug => color.
	 * @return array<string,string>
	 */
	private static function semantic_colors( array $node, array $by_slug ): array {
		$styles   = isset( $node['styles'] ) && is_array( $node['styles'] ) ? $node['styles'] : array();
		$color    = self::path( $styles, array( 'color' ) );
		$button   = self::path( $styles, array( 'elements', 'button', 'color' ) );
		$link     = self::path( $styles, array( 'elements', 'link', 'color' ) );
		$bg       = self::resolve_color_ref( $color['background'] ?? null, $by_slug );
		$text     = self::resolve_color_ref( $color['text'] ?? null, $by_slug );
		$btn_fill = self::resolve_color_ref( $button['background'] ?? null, $by_slug );
		$link_fg  = self::resolve_color_ref( $link['text'] ?? null, $by_slug );

		$out = array();
		if ( '' !== $bg ) {
			$out['bg'] = $bg;
		}
		if ( '' !== $text ) {
			$out['text'] = $text;
		}
		// A button or link styled in the body-text color says nothing
		// about the theme's accent; the palette slugs supply it instead.
		if ( '' !== $btn_fill && $btn_fill !== $text ) {
			$out['accent'] = $btn_fill;
		}
		if ( '' !== $link_fg && $link_fg !== $text ) {
			$out['accent_2'] = $link_fg;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $tree
	 * @param string[]            $keys
	 * @return array<string,mixed>
	 */
	private static function path( array $tree, array $keys ): array {
		foreach ( $keys as $key ) {
			if ( ! isset( $tree[ $key ] ) || ! is_array( $tree[ $key ] ) ) {
				return array();
			}
			$tree = $tree[ $key ];
		}
		return $tree;
	}

	/**
	 * Resolve a theme.json color value to a hex color, or '' when it
	 * isn't one. Accepts a hex value, `var:preset|color|<slug>`, and
	 * `var(--wp--preset--color--<slug>)`.
	 *
	 * @param mixed                $value
	 * @param array<string,string> $by_slug
	 */
	public static function resolve_color_ref( $value, array $by_slug ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		$slug  = '';
		if ( 1 === preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/i', $value, $m ) ) {
			$slug = strtolower( $m[1] );
		} elseif ( 1 === preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/i', $value, $m ) ) {
			$slug = strtolower( $m[1] );
		}
		if ( '' !== $slug ) {
			$value = $by_slug[ $slug ] ?? '';
		}
		return 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ? $value : '';
	}

	/**
	 * Walk the slug-priority list for each font token and return the
	 * first match found in the variation's typography.fontFamilies (or
	 * main fontFamilies when variation is absent).
	 *
	 * @param array<string,mixed> $source             Raw theme.json shape.
	 * @param string[]            $variation_slug_set Variation slugs to try.
	 * @return array<string,string> Map of token slug → font-family CSS string.
	 */
	private static function resolve_fonts( array $source, array $variation_slug_set ): array {
		$fonts = self::font_families_for_variation( $source, $variation_slug_set );
		if ( empty( $fonts ) ) {
			return array();
		}
		$by_slug = array();
		foreach ( $fonts as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$slug   = isset( $entry['slug'] ) ? strtolower( (string) $entry['slug'] ) : '';
			$family = isset( $entry['fontFamily'] ) ? (string) $entry['fontFamily'] : '';
			if ( '' === $slug || '' === $family ) {
				continue;
			}
			$by_slug[ $slug ] = $family;
		}
		$out = array();
		foreach ( self::FONT_SLUG_PRIORITY as $token => $candidates ) {
			foreach ( $candidates as $candidate ) {
				if ( isset( $by_slug[ $candidate ] ) ) {
					$out[ $token ] = $by_slug[ $candidate ];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Return the palette array for the named variation, or the main
	 * palette when no variation matches.
	 *
	 * @param array<string,mixed> $source
	 * @param string[]            $variation_slug_set
	 * @return array<int,array<string,mixed>>
	 */
	private static function palette_for_variation( array $source, array $variation_slug_set ): array {
		$variation = self::find_variation( $source, $variation_slug_set );
		if ( null !== $variation ) {
			$palette = self::extract_palette( $variation );
			if ( ! empty( $palette ) ) {
				return $palette;
			}
		}
		return self::extract_palette( $source );
	}

	/**
	 * @param array<string,mixed> $source
	 * @param string[]            $variation_slug_set
	 * @return array<int,array<string,mixed>>
	 */
	private static function font_families_for_variation( array $source, array $variation_slug_set ): array {
		$variation = self::find_variation( $source, $variation_slug_set );
		if ( null !== $variation ) {
			$fonts = self::extract_font_families( $variation );
			if ( ! empty( $fonts ) ) {
				return $fonts;
			}
		}
		return self::extract_font_families( $source );
	}

	/**
	 * Find the first style variation whose slug matches one of the
	 * candidate set. Variation slugs are matched via substring so
	 * a theme using `dark-pro` still resolves to night.
	 *
	 * @param array<string,mixed> $source
	 * @param string[]            $variation_slug_set
	 * @return array<string,mixed>|null
	 */
	private static function find_variation( array $source, array $variation_slug_set ): ?array {
		$variations = isset( $source['variations'] ) && is_array( $source['variations'] )
			? $source['variations']
			: array();
		foreach ( $variations as $variation ) {
			if ( ! is_array( $variation ) ) {
				continue;
			}
			$slug = isset( $variation['slug'] ) ? strtolower( (string) $variation['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			foreach ( $variation_slug_set as $candidate ) {
				if ( $slug === $candidate || str_contains( $slug, $candidate ) ) {
					return $variation;
				}
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $node
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_palette( array $node ): array {
		// settings.color.palette OR settings.color.palette.theme — both shapes.
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$color    = isset( $settings['color'] ) && is_array( $settings['color'] ) ? $settings['color'] : array();
		$palette  = $color['palette'] ?? array();
		// theme.json schema 3+ keys palette by origin (theme/default/custom).
		if ( is_array( $palette ) && isset( $palette['theme'] ) && is_array( $palette['theme'] ) ) {
			return $palette['theme'];
		}
		return is_array( $palette ) ? $palette : array();
	}

	/**
	 * @param array<string,mixed> $node
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_font_families( array $node ): array {
		$settings   = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$typography = isset( $settings['typography'] ) && is_array( $settings['typography'] ) ? $settings['typography'] : array();
		$families   = $typography['fontFamilies'] ?? array();
		if ( is_array( $families ) && isset( $families['theme'] ) && is_array( $families['theme'] ) ) {
			return $families['theme'];
		}
		return is_array( $families ) ? $families : array();
	}

	/**
	 * @return array{day: array{colors: array<string,string>, fonts: array<string,string>}, night: array{colors: array<string,string>, fonts: array<string,string>}}
	 */
	private static function empty_shape(): array {
		return array(
			'day'   => array(
				'colors' => array(),
				'fonts'  => array(),
			),
			'night' => array(
				'colors' => array(),
				'fonts'  => array(),
			),
		);
	}
}
