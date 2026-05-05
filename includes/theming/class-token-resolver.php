<?php
/**
 * Outpost_Token_Resolver
 *
 * Walks the source layer (user override > theme.json > built-in
 * default) and produces a single normalized map of `--outpost-*`
 * CSS custom properties for the requested mode.
 *
 * THREE LAYERS, ONE OUTPUT
 *
 *   user override   — per-user-meta value, settings UI writes here
 *                     (highest priority)
 *   theme.json      — read by {@see Outpost_Theme_Json_Reader}
 *   built-in default — warm-paper neutrals chosen for the Field
 *                     Notes aesthetic; CC0 values that aren't
 *                     attributable to any specific user's site
 *                     (lowest priority)
 *
 * Components in `pwa/src/styles/structure.css` reference the
 * resolved `--outpost-*` properties only — they never read the
 * source layers directly. The Hard Contract holds: paint inherits,
 * structure does not.
 *
 * CONTRAST ENFORCEMENT (load-bearing)
 *
 * Resolved (text, surface) pairs run through
 * {@see Outpost_Contrast::adjust_for_minimum} at WCAG AA threshold
 * (4.5 for body, 3.0 for large display text). Failing pairs get
 * auto-adjusted; the adjustment is recorded in the returned map's
 * `adjusted` array so the settings page can surface "Your theme's
 * primary text on surface fails contrast; we adjusted to {hex}."
 *
 * No global disable for contrast enforcement. Per-token
 * `override anyway` exists as the only escape hatch (handled in the
 * settings layer when written into the override-meta entry's
 * `bypass_contrast` flag — this resolver respects the flag).
 *
 * CACHE
 *
 * Result cached in transient keyed on (user_id, mode, theme_version).
 * Invalidates on theme switch (theme_version moves) or settings
 * write (controller deletes the transient explicitly).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Token_Resolver {

	public const OVERRIDE_META_KEY = 'outpost_appearance_overrides';

	public const TRANSIENT_PREFIX = 'outpost_resolved_tokens_';

	/** Built-in defaults: warm-paper neutrals for the Field Notes aesthetic. */
	public const DEFAULTS = array(
		'day'   => array(
			'colors' => array(
				'bg'             => '#fbfaf5',
				'surface'        => '#ffffff',
				'text'           => '#241c4a',
				'text_secondary' => '#6b6358',
				'accent'         => '#fb8500',
				'accent_2'       => '#241c4a',
				'border'         => '#2a2622',
				'tape'           => 'rgba(251,133,0,0.85)',
				'halftone'       => 'rgba(36,28,74,0.04)',
			),
			'fonts'  => array(
				'body'      => "'Inter', system-ui, sans-serif",
				'display'   => "'Newsreader', 'Source Serif Pro', Georgia, serif",
				'monospace' => "'JetBrains Mono', ui-monospace, monospace",
			),
		),
		'night' => array(
			'colors' => array(
				'bg'             => '#251f1c',
				'surface'        => '#1a1614',
				'text'           => '#fbfaf5',
				'text_secondary' => '#a9a097',
				'accent'         => '#fb8500',
				'accent_2'       => '#8ecae6',
				'border'         => '#3a322c',
				'tape'           => 'rgba(251,133,0,0.75)',
				'halftone'       => 'rgba(255,255,255,0.03)',
			),
			'fonts'  => array(
				'body'      => "'Inter', system-ui, sans-serif",
				'display'   => "'Newsreader', 'Source Serif Pro', Georgia, serif",
				'monospace' => "'JetBrains Mono', ui-monospace, monospace",
			),
		),
	);

	/** Type sizes — fixed, not theme-inheritable. */
	public const SIZE_DEFAULTS = array(
		'display' => '1.75rem',
		'h1'      => '1.375rem',
		'body'    => '1rem',
		'small'   => '0.875rem',
		'tiny'    => '0.75rem',
	);

	/**
	 * Test seam for theme-version readouts.
	 *
	 * @var callable|null
	 */
	private static $theme_version_for_tests = null;

	public static function set_theme_version_for_tests( ?callable $resolver ): void {
		self::$theme_version_for_tests = $resolver;
	}

	/**
	 * Resolve the full token set for a user + mode.
	 *
	 * Returns a structured array with:
	 *   - `mode`      — the mode requested ('day' | 'night')
	 *   - `colors`    — slug → CSS color
	 *   - `fonts`     — slug → CSS font-family
	 *   - `sizes`     — slug → CSS size
	 *   - `adjusted`  — array<slug, {original, applied, ratio_before, ratio_after}>
	 *                   Records each token Contrast adjusted; settings
	 *                   page renders these as warnings.
	 *   - `sources`   — slug → 'override' | 'theme' | 'default'
	 *                   So the settings UI can show the badge "from
	 *                   theme" or "default" next to each field.
	 *
	 * @param int    $user_id User; 0 = current.
	 * @param string $mode    'day' or 'night'. 'system' should be resolved
	 *                        client-side to one of these before calling.
	 * @return array{mode: string, colors: array<string,string>, fonts: array<string,string>, sizes: array<string,string>, adjusted: array<string,array{original:string,applied:string,ratio_before:float,ratio_after:float}>, sources: array<string,string>}
	 */
	public static function resolve( int $user_id, string $mode ): array {
		$mode = self::normalize_mode( $mode );
		if ( 0 === $user_id ) {
			$user_id = (int) get_current_user_id();
		}

		$cached = self::read_cache( $user_id, $mode );
		if ( null !== $cached ) {
			return $cached;
		}

		$theme    = Outpost_Theme_Json_Reader::read();
		$override = self::read_override_for_user( $user_id );

		$defaults    = self::DEFAULTS[ $mode ];
		$theme_layer = $theme[ $mode ] ?? array(
			'colors' => array(),
			'fonts'  => array(),
		);

		$resolved_colors = array();
		$sources         = array();
		foreach ( array_keys( $defaults['colors'] ) as $slug ) {
			$value                        = self::pick_value( $slug, 'colors', $mode, $override, $theme_layer, $defaults );
			$resolved_colors[ $slug ]     = $value['value'];
			$sources[ 'colors.' . $slug ] = $value['source'];
		}

		$resolved_fonts = array();
		foreach ( array_keys( $defaults['fonts'] ) as $slug ) {
			$value                       = self::pick_value( $slug, 'fonts', $mode, $override, $theme_layer, $defaults );
			$resolved_fonts[ $slug ]     = $value['value'];
			$sources[ 'fonts.' . $slug ] = $value['source'];
		}

		$adjusted = array();
		self::enforce_contrast( $resolved_colors, $override, $adjusted );

		$out = array(
			'mode'     => $mode,
			'colors'   => $resolved_colors,
			'fonts'    => $resolved_fonts,
			'sizes'    => self::SIZE_DEFAULTS,
			'adjusted' => $adjusted,
			'sources'  => $sources,
		);

		self::write_cache( $user_id, $mode, $out );
		return $out;
	}

	/**
	 * Render the resolved tokens as a CSS string suitable for inline
	 * `<style>` injection. The output contains a single declaration
	 * block scoped to `.outpost-mode-{mode}` whose only declarations
	 * are `--outpost-*: value`. By design — passes the F4-extended
	 * §5 audit lint's B6 rule.
	 *
	 * @param array<string,mixed> $resolved Output of {@see resolve()}.
	 */
	public static function to_css( array $resolved ): string {
		$mode    = isset( $resolved['mode'] ) ? (string) $resolved['mode'] : self::normalize_mode( '' );
		$lines   = array();
		$lines[] = '.outpost-mode-' . $mode . ' {';
		foreach ( ( $resolved['colors'] ?? array() ) as $slug => $value ) {
			$lines[] = "\t" . self::token_name( 'colors', $slug ) . ': ' . self::esc_css_value( $value ) . ';';
		}
		foreach ( ( $resolved['fonts'] ?? array() ) as $slug => $value ) {
			$lines[] = "\t" . self::token_name( 'fonts', $slug ) . ': ' . self::esc_css_value( $value ) . ';';
		}
		foreach ( ( $resolved['sizes'] ?? array() ) as $slug => $value ) {
			$lines[] = "\t" . self::token_name( 'sizes', $slug ) . ': ' . self::esc_css_value( $value ) . ';';
		}
		$lines[] = '}';
		return implode( "\n", $lines );
	}

	/**
	 * Invalidate the cached resolution for a user. Called when the
	 * appearance settings are saved.
	 */
	public static function invalidate_cache_for_user( int $user_id ): void {
		foreach ( array( self::MODE_DAY, self::MODE_NIGHT ) as $mode ) {
			delete_transient( self::transient_key( $user_id, $mode ) );
		}
	}

	private const MODE_DAY   = 'day';
	private const MODE_NIGHT = 'night';

	/**
	 * Walk the priority chain for a single token and return the chosen value.
	 *
	 * @param array<string,mixed> $override
	 * @param array{colors: array<string,string>, fonts: array<string,string>} $theme_layer
	 * @param array{colors: array<string,string>, fonts: array<string,string>} $defaults
	 * @return array{value: string, source: string}
	 */
	private static function pick_value(
		string $slug,
		string $kind,
		string $mode,
		array $override,
		array $theme_layer,
		array $defaults
	): array {
		$override_value = self::override_value( $override, $kind, $slug, $mode );
		if ( '' !== $override_value ) {
			return array(
				'value'  => $override_value,
				'source' => 'override',
			);
		}
		$theme_value = $theme_layer[ $kind ][ $slug ] ?? '';
		if ( '' !== $theme_value ) {
			return array(
				'value'  => $theme_value,
				'source' => 'theme',
			);
		}
		return array(
			'value'  => $defaults[ $kind ][ $slug ],
			'source' => 'default',
		);
	}

	/**
	 * @param array<string,mixed> $override
	 */
	private static function override_value( array $override, string $kind, string $slug, string $mode ): string {
		$mode_overrides = $override[ $mode ] ?? array();
		if ( ! is_array( $mode_overrides ) ) {
			return '';
		}
		$kind_overrides = $mode_overrides[ $kind ] ?? array();
		if ( ! is_array( $kind_overrides ) ) {
			return '';
		}
		$value = $kind_overrides[ $slug ] ?? '';
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * @param array<string,string> $colors
	 * @param array<string,mixed>  $override
	 * @param array<string,array{original:string,applied:string,ratio_before:float,ratio_after:float}> $adjusted
	 */
	private static function enforce_contrast( array &$colors, array $override, array &$adjusted ): void {
		$pairs  = array(
			array(
				'fg'     => 'text',
				'bg'     => 'surface',
				'target' => Outpost_Contrast::TARGET_AA_BODY,
			),
			array(
				'fg'     => 'text_secondary',
				'bg'     => 'surface',
				'target' => Outpost_Contrast::TARGET_AA_BODY,
			),
			array(
				'fg'     => 'accent',
				'bg'     => 'surface',
				'target' => Outpost_Contrast::TARGET_AA_LARGE,
			),
		);
		$bypass = self::bypass_set( $override );

		foreach ( $pairs as $pair ) {
			$fg_slug = $pair['fg'];
			$bg_slug = $pair['bg'];
			$target  = $pair['target'];
			if ( in_array( $fg_slug, $bypass, true ) ) {
				continue;
			}
			$fg = $colors[ $fg_slug ] ?? '';
			$bg = $colors[ $bg_slug ] ?? '';
			if ( '' === $fg || '' === $bg ) {
				continue;
			}
			// Skip non-hex tokens (e.g. rgba tape/halftone) — Contrast
			// math operates on opaque hex inputs.
			if ( ! self::is_hex_like( $fg ) || ! self::is_hex_like( $bg ) ) {
				continue;
			}
			$ratio_before = Outpost_Contrast::ratio( $fg, $bg );
			if ( $ratio_before >= $target || 0.0 === $ratio_before ) {
				continue;
			}
			$adjusted_fg = Outpost_Contrast::adjust_for_minimum( $fg, $bg, $target );
			if ( $adjusted_fg === $fg ) {
				continue;
			}
			$ratio_after          = Outpost_Contrast::ratio( $adjusted_fg, $bg );
			$colors[ $fg_slug ]   = $adjusted_fg;
			$adjusted[ $fg_slug ] = array(
				'original'     => $fg,
				'applied'      => $adjusted_fg,
				'ratio_before' => $ratio_before,
				'ratio_after'  => $ratio_after,
			);
		}
	}

	/**
	 * @param array<string,mixed> $override
	 * @return string[]
	 */
	private static function bypass_set( array $override ): array {
		$bypass = $override['bypass_contrast'] ?? array();
		return is_array( $bypass ) ? array_values( array_filter( $bypass, 'is_string' ) ) : array();
	}

	private static function is_hex_like( string $value ): bool {
		return 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/', trim( $value ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function read_override_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$value = get_user_meta( $user_id, self::OVERRIDE_META_KEY, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function read_cache( int $user_id, string $mode ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		$cached = get_transient( self::transient_key( $user_id, $mode ) );
		if ( ! is_array( $cached ) ) {
			return null;
		}
		// Theme version mismatch invalidates the cache entry.
		$expected = self::theme_version();
		if ( ( $cached['_theme_version'] ?? '' ) !== $expected ) {
			delete_transient( self::transient_key( $user_id, $mode ) );
			return null;
		}
		unset( $cached['_theme_version'] );
		return $cached;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private static function write_cache( int $user_id, string $mode, array $payload ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$payload['_theme_version'] = self::theme_version();
		set_transient(
			self::transient_key( $user_id, $mode ),
			$payload,
			defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600
		);
	}

	private static function transient_key( int $user_id, string $mode ): string {
		return self::TRANSIENT_PREFIX . $user_id . '_' . $mode;
	}

	private static function theme_version(): string {
		if ( null !== self::$theme_version_for_tests ) {
			return (string) call_user_func( self::$theme_version_for_tests );
		}
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( is_object( $theme ) && method_exists( $theme, 'get' ) ) {
				$version    = $theme->get( 'Version' );
				$stylesheet = method_exists( $theme, 'get_stylesheet' ) ? (string) $theme->get_stylesheet() : '';
				return $stylesheet . ':' . (string) $version;
			}
		}
		return 'unknown';
	}

	private static function normalize_mode( string $mode ): string {
		$mode = strtolower( trim( $mode ) );
		if ( self::MODE_NIGHT === $mode ) {
			return self::MODE_NIGHT;
		}
		return self::MODE_DAY;
	}

	private static function token_name( string $kind, string $slug ): string {
		// Map internal slugs to the documented `--outpost-*` names.
		// `text_secondary` → `--outpost-text-secondary`; `accent_2` → `--outpost-accent-2`.
		$normalized = str_replace( '_', '-', $slug );
		switch ( $kind ) {
			case 'fonts':
				return '--outpost-font-' . $normalized;
			case 'sizes':
				return '--outpost-size-' . $normalized;
			case 'colors':
			default:
				return '--outpost-' . $normalized;
		}
	}

	private static function esc_css_value( string $value ): string {
		// Strip newlines + control chars; everything else passes
		// through. Caller is responsible for shape validation.
		return preg_replace( '/[\r\n\x00-\x1F\x7F]/', '', $value ) ?? '';
	}
}
