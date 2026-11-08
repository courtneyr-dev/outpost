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
 * Result cached in a transient keyed on (user_id, mode, source) and
 * validated against the theme identity plus a signature of the theme
 * layer, the user's overrides, and the source. Invalidates on theme
 * switch, Site Editor palette edits, override writes, or a source
 * change; the controller also deletes the transients explicitly.
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

	/**
	 * Outpost Default semantic palette: warm paper, violet ink, orange
	 * actions (light) and a violet night scheme (dark). The component
	 * tokens for this palette live in `styles/outpost-tokens.css`;
	 * {@see component_tokens()} derives the same component set from any
	 * semantic palette (a site theme's, or one with user overrides).
	 * Decision: docs/decisions/session-pwa-ui-appearance.md.
	 */
	public const DEFAULTS = array(
		'day'   => array(
			'colors' => array(
				'bg'             => '#fbfaf5',
				'surface'        => '#ffffff',
				'text'           => '#241c4a',
				'text_secondary' => '#5f5873',
				'accent'         => '#fb8500',
				'accent_2'       => '#5b4a9e',
				'border'         => '#7a70b0',
				'tape'           => 'rgba(251,133,0,0.85)',
				'halftone'       => 'rgba(36,28,74,0.04)',
			),
			'fonts'  => array(
				'body'      => '"IBM Plex Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
				'display'   => '"Newsreader", "Source Serif Pro", Georgia, serif',
				'monospace' => '"IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, monospace',
			),
		),
		'night' => array(
			'colors' => array(
				'bg'             => '#1e1838',
				'surface'        => '#2a2350',
				'text'           => '#f7f4ff',
				'text_secondary' => '#c9c1ef',
				'accent'         => '#ff9a2e',
				'accent_2'       => '#a59bd6',
				'border'         => '#9a90cf',
				'tape'           => 'rgba(255,154,46,0.35)',
				'halftone'       => 'rgba(255,255,255,0.03)',
			),
			'fonts'  => array(
				'body'      => '"IBM Plex Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
				'display'   => '"Newsreader", "Source Serif Pro", Georgia, serif',
				'monospace' => '"IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, monospace',
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
	 * @param string $source  'site' reads the theme layer (the behavior
	 *                        before style sources existed); 'outpost'
	 *                        skips it, so only overrides and Outpost
	 *                        defaults apply.
	 * @return array{mode: string, source: string, colors: array<string,string>, fonts: array<string,string>, sizes: array<string,string>, adjusted: array<string,array{original:string,applied:string,ratio_before:float,ratio_after:float}>, sources: array<string,string>, components: array<string,string>}
	 */
	public static function resolve( int $user_id, string $mode, string $source = 'site' ): array {
		$mode   = self::normalize_mode( $mode );
		$source = 'outpost' === $source ? 'outpost' : 'site';
		if ( 0 === $user_id ) {
			$user_id = (int) get_current_user_id();
		}

		$override    = self::read_override_for_user( $user_id );
		$theme_layer = 'site' === $source
			? self::theme_layer_for_mode( $mode )
			: array(
				'colors' => array(),
				'fonts'  => array(),
			);
		$signature   = md5( serialize( array( $source, $theme_layer, $override, self::CACHE_SCHEMA ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashing only, never unserialized.

		$cached = self::read_cache( $user_id, $mode, $source, $signature );
		if ( null !== $cached ) {
			return $cached;
		}

		$defaults = self::DEFAULTS[ $mode ];

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

		$components = self::component_tokens( $resolved_colors, $resolved_fonts, $adjusted );

		$out = array(
			'mode'       => $mode,
			'source'     => $source,
			'colors'     => $resolved_colors,
			'fonts'      => $resolved_fonts,
			'sizes'      => self::SIZE_DEFAULTS,
			'adjusted'   => $adjusted,
			'sources'    => $sources,
			'components' => $components,
		);

		self::write_cache( $user_id, $mode, $source, $signature, $out );
		return $out;
	}

	/**
	 * The theme layer for one scheme.
	 *
	 * Light reads the theme's main palette and semantic styles. Dark reads
	 * the theme's dark style variation when it has one. Without one, the
	 * light palette is not reused for dark surfaces (light paper behind
	 * "dark mode" text reads as broken): dark keeps Outpost's dark
	 * surfaces and text and carries over only the theme's accent and
	 * fonts. Contrast enforcement then adjusts that accent against the
	 * dark surfaces.
	 *
	 * @return array{colors: array<string,string>, fonts: array<string,string>}
	 */
	private static function theme_layer_for_mode( string $mode ): array {
		$theme = Outpost_Theme_Json_Reader::read();
		$layer = $theme[ $mode ] ?? array(
			'colors' => array(),
			'fonts'  => array(),
		);
		if ( self::MODE_NIGHT === $mode && ! Outpost_Theme_Json_Reader::has_dark_variation() ) {
			$day_accent = $theme['day']['colors']['accent'] ?? '';
			$layer      = array(
				'colors' => '' !== $day_accent ? array( 'accent' => $day_accent ) : array(),
				'fonts'  => $theme['day']['fonts'] ?? array(),
			);
		}
		return $layer;
	}

	/**
	 * Map a resolved semantic palette onto the component tokens
	 * `structure.css` reads, checking every final foreground/background
	 * pair the composer paints (WCAG 2.2 AA):
	 *
	 *   text and muted text on page and field/card surfaces   4.5:1
	 *   primary button label on its fill                       4.5:1
	 *   link text on page and card surfaces                    4.5:1
	 *   field, card, and chip borders on both surfaces         3:1
	 *   focus ring on page and field surfaces                  3:1
	 *   logo outline on the page surface                       3:1
	 *
	 * An adjusted value is recorded as `adjusted['component.<token>']`.
	 *
	 * @param array<string,string> $colors   Resolved semantic colors.
	 * @param array<string,string> $fonts    Resolved semantic fonts.
	 * @param array<string,array{original:string,applied:string,ratio_before:float,ratio_after:float}> $adjusted Adjustment log, appended to.
	 * @return array<string,string> Component token name without the `--outpost-` prefix => value.
	 */
	public static function component_tokens( array $colors, array $fonts, array &$adjusted ): array {
		$page    = self::hex_or( $colors['bg'] ?? '', '#ffffff' );
		$field   = self::hex_or( $colors['surface'] ?? '', $page );
		$text    = self::hex_or( $colors['text'] ?? '', '#000000' );
		$muted   = self::hex_or( $colors['text_secondary'] ?? '', $text );
		$accent  = self::hex_or( $colors['accent'] ?? '', $text );
		$accent2 = self::hex_or( $colors['accent_2'] ?? '', $accent );
		$border  = self::hex_or( $colors['border'] ?? '', $muted );
		$body    = Outpost_Contrast::TARGET_AA_BODY;
		$ui      = Outpost_Contrast::TARGET_AA_LARGE;

		$text   = self::ensure_on_both( 'text', $text, $page, $field, $body, $adjusted );
		$muted  = self::ensure_on_both( 'text-muted', $muted, $page, $field, $body, $adjusted );
		$border = self::ensure_on_both( 'border', $border, $page, $field, $ui, $adjusted );

		// Primary action: accent fill. The label takes whichever of the
		// palette's own ink or paper reads best on it; when none reaches
		// 4.5:1 the fill moves, not the label.
		$label = $text;
		foreach ( array( $page, $field ) as $candidate ) {
			if ( Outpost_Contrast::ratio( $candidate, $accent ) > Outpost_Contrast::ratio( $label, $accent ) ) {
				$label = $candidate;
			}
		}
		$fill = $accent;
		if ( Outpost_Contrast::ratio( $label, $fill ) < $body ) {
			$fill = self::record_adjust( 'button-primary-bg', $fill, Outpost_Contrast::adjust_for_minimum( $fill, $label, $body ), $label, $adjusted );
		}

		$focus = ( Outpost_Contrast::ratio( $accent, $page ) >= $ui && Outpost_Contrast::ratio( $accent, $field ) >= $ui ) ? $accent : $text;
		$link  = $text;
		foreach ( array( $accent2, $accent ) as $candidate ) {
			if ( Outpost_Contrast::ratio( $candidate, $page ) >= $body && Outpost_Contrast::ratio( $candidate, $field ) >= $body ) {
				$link = $candidate;
				break;
			}
		}

		$tokens      = array(
			'surface-bg'              => $page,
			'surface-fg'              => $text,
			'card-bg'                 => $field,
			'card-fg'                 => $text,
			'card-border'             => $border,
			'input-bg'                => $field,
			'input-fg'                => $text,
			'input-border'            => $border,
			'input-border-focus'      => $focus,
			'input-placeholder'       => $muted,
			'button-primary-bg'       => $fill,
			'button-primary-fg'       => $label,
			'button-primary-border'   => $fill,
			'button-secondary-fg'     => $text,
			'button-secondary-border' => $border,
			'button-ghost-fg'         => $text,
			'tab-fg'                  => $muted,
			'tab-fg-active'           => $label,
			'tab-bg-active'           => $fill,
			'tab-indicator'           => $text,
			'chip-fg'                 => $text,
			'chip-border'             => $border,
			'chip-bg-pressed'         => $fill,
			'chip-fg-pressed'         => $label,
			'focus-ring-color'        => $focus,
			'text-muted'              => $muted,
			'border'                  => $border,
			'link'                    => $link,
			'logo'                    => $text,
			'scenery-far'             => $accent2,
			'scenery-near'            => $text,
		);
		$font_tokens = array(
			'body'      => 'font-family-body',
			'display'   => 'font-family-display',
			'monospace' => 'font-family-mono',
		);
		foreach ( $font_tokens as $slug => $token ) {
			if ( isset( $fonts[ $slug ] ) && '' !== $fonts[ $slug ] ) {
				$tokens[ $token ] = $fonts[ $slug ];
			}
		}
		return $tokens;
	}

	/**
	 * Emit component tokens for both schemes, scoped to a style-source
	 * class, in the mode-class structure `outpost-tokens.css` uses: day and
	 * system share the light block; night, and system under a dark OS
	 * preference, share the dark block. The two-class selectors outrank
	 * the one-class defaults in the token files.
	 *
	 * @param array<string,mixed> $day   {@see resolve()} output for day.
	 * @param array<string,mixed> $night {@see resolve()} output for night.
	 */
	public static function to_scoped_css( string $source, array $day, array $night ): string {
		$scope = '.outpost-source-' . ( 'outpost' === $source ? 'outpost' : 'site' );
		$light = self::declarations( is_array( $day['components'] ?? null ) ? $day['components'] : array() );
		$dark  = self::declarations( is_array( $night['components'] ?? null ) ? $night['components'] : array() );
		if ( '' === $light && '' === $dark ) {
			return '';
		}
		return $scope . '.outpost-mode-day, ' . $scope . ".outpost-mode-system {\n\tcolor-scheme: light;\n" . $light . "}\n"
			. $scope . ".outpost-mode-night {\n\tcolor-scheme: dark;\n" . $dark . "}\n"
			. "@media (prefers-color-scheme: dark) {\n" . $scope . ".outpost-mode-system {\n\tcolor-scheme: dark;\n" . $dark . "}\n}\n";
	}

	/**
	 * @param array<string,string> $tokens
	 */
	private static function declarations( array $tokens ): string {
		$out = '';
		foreach ( $tokens as $name => $value ) {
			if ( 1 !== preg_match( '/^[a-z0-9-]+$/', (string) $name ) ) {
				continue;
			}
			$clean = self::safe_css_value( (string) $value );
			if ( '' === $clean ) {
				continue;
			}
			$out .= "\t--outpost-" . $name . ': ' . $clean . ";\n";
		}
		return $out;
	}

	/**
	 * Theme and override values are untrusted. A value that could end the
	 * declaration or the rule, open a comment or tag, or load a resource
	 * is dropped rather than escaped.
	 */
	public static function safe_css_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 256 ) {
			return '';
		}
		if ( 1 === preg_match( '/[\x00-\x1F\x7F;{}<>\\\\]|\/\*|\*\/|url\s*\(|expression\s*\(|@import/i', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Raise $fg to $target against whichever background it reads worse
	 * on, then re-check the other.
	 *
	 * @param array<string,array{original:string,applied:string,ratio_before:float,ratio_after:float}> $adjusted
	 */
	private static function ensure_on_both( string $name, string $fg, string $bg_a, string $bg_b, float $target, array &$adjusted ): string {
		$worst = Outpost_Contrast::ratio( $fg, $bg_a ) <= Outpost_Contrast::ratio( $fg, $bg_b ) ? $bg_a : $bg_b;
		if ( Outpost_Contrast::ratio( $fg, $worst ) >= $target ) {
			return $fg;
		}
		$fixed = Outpost_Contrast::adjust_for_minimum( $fg, $worst, $target );
		$other = $worst === $bg_a ? $bg_b : $bg_a;
		if ( Outpost_Contrast::ratio( $fixed, $other ) < $target ) {
			$fixed = Outpost_Contrast::adjust_for_minimum( $fixed, $other, $target );
		}
		return self::record_adjust( $name, $fg, $fixed, $worst, $adjusted );
	}

	/**
	 * @param array<string,array{original:string,applied:string,ratio_before:float,ratio_after:float}> $adjusted
	 */
	private static function record_adjust( string $name, string $from, string $to, string $against, array &$adjusted ): string {
		if ( $from !== $to ) {
			$adjusted[ 'component.' . $name ] = array(
				'original'     => $from,
				'applied'      => $to,
				'ratio_before' => Outpost_Contrast::ratio( $from, $against ),
				'ratio_after'  => Outpost_Contrast::ratio( $to, $against ),
			);
		}
		return $to;
	}

	private static function hex_or( string $value, string $fallback ): string {
		return self::is_hex_like( $value ) ? trim( $value ) : $fallback;
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
			delete_transient( self::transient_key( $user_id, $mode, 'outpost' ) );
		}
	}

	/** Bumped when the cached payload's shape changes. */
	private const CACHE_SCHEMA = 2;

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
	private static function read_cache( int $user_id, string $mode, string $source, string $signature ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		$key    = self::transient_key( $user_id, $mode, $source );
		$cached = get_transient( $key );
		if ( ! is_array( $cached ) ) {
			return null;
		}
		// A different theme identity, Global Styles content, override set,
		// or style source invalidates the entry. Theme version alone misses
		// a palette edited in the Site Editor; the signature covers it.
		if ( ( $cached['_theme_version'] ?? '' ) !== self::theme_version() || ( $cached['_signature'] ?? '' ) !== $signature ) {
			delete_transient( $key );
			return null;
		}
		unset( $cached['_theme_version'], $cached['_signature'] );
		return $cached;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private static function write_cache( int $user_id, string $mode, string $source, string $signature, array $payload ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$payload['_theme_version'] = self::theme_version();
		$payload['_signature']     = $signature;
		set_transient(
			self::transient_key( $user_id, $mode, $source ),
			$payload,
			defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600
		);
	}

	private static function transient_key( int $user_id, string $mode, string $source = 'site' ): string {
		// The site-source key keeps its pre-amendment name.
		return self::TRANSIENT_PREFIX . $user_id . '_' . $mode . ( 'outpost' === $source ? '_outpost' : '' );
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
