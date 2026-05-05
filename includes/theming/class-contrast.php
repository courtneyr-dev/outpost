<?php
/**
 * Outpost_Contrast
 *
 * WCAG 2.1 contrast-ratio computation + adjust-for-minimum helper.
 * Server-side, native PHP, zero dependencies.
 *
 * Used by Outpost_Token_Resolver at resolve time: every (foreground,
 * background) pair Outpost emits to CSS is checked against WCAG AA
 * thresholds. Failing pairs get auto-adjusted by walking the
 * foreground's HSL lightness toward the bg until the ratio passes,
 * and the adjustment is surfaced in the Appearance settings page so
 * the user sees that their theme palette didn't quite meet AA.
 *
 * The algorithm is straight from the WCAG 2.1 spec
 * (https://www.w3.org/WAI/WCAG21/Understanding/contrast-minimum.html):
 *
 *   1. Convert each color to relative luminance (sRGB → linear,
 *      then weighted sum 0.2126R + 0.7152G + 0.0722B)
 *   2. Ratio = (L_lighter + 0.05) / (L_darker + 0.05)
 *
 * Validated against several WCAG-published reference pairs in
 * ContrastTest.php to ±0.01 accuracy.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Contrast {

	/** WCAG AA threshold for normal-size body text. */
	public const TARGET_AA_BODY = 4.5;

	/** WCAG AA threshold for large text (>=18pt or 14pt bold). */
	public const TARGET_AA_LARGE = 3.0;

	/** WCAG AAA threshold for normal-size body text. */
	public const TARGET_AAA_BODY = 7.0;

	/**
	 * Compute the WCAG 2.1 contrast ratio between two colors.
	 *
	 * Inputs accept any of:
	 *   - 7-char hex `#rrggbb`
	 *   - 4-char shorthand `#rgb`
	 *   - 9-char `#rrggbbaa` (alpha ignored — contrast applies to opaque rendering)
	 *
	 * Returns a float in [1.0, 21.0]. Values outside that range
	 * indicate a parse failure; callers should treat such returns
	 * as "unknown — pass through, do not auto-adjust."
	 *
	 * @param string $a Color hex (foreground or background; order-independent).
	 * @param string $b Color hex.
	 * @return float WCAG ratio rounded to 4 decimal places.
	 */
	public static function ratio( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		if ( null === $la || null === $lb ) {
			// Parse failure on one side; signal "unknown" with 0.0.
			// Callers that auto-adjust must handle 0.0 as "skip — we
			// can't reason about this pair." Returning 1.0 would imply
			// "valid pair, equal colors" which is wrong.
			return 0.0;
		}
		$lighter = max( $la, $lb );
		$darker  = min( $la, $lb );
		$ratio   = ( $lighter + 0.05 ) / ( $darker + 0.05 );
		return round( $ratio, 4 );
	}

	/**
	 * Adjust foreground color so it meets a target contrast ratio
	 * against the given background. Walks the foreground's HSL
	 * lightness in 1% steps, biasing toward the direction that's
	 * already further from the background's luminance.
	 *
	 * Returns the adjusted hex. When no adjustment within the
	 * lightness range [0, 100] reaches the target (extremely
	 * close foreground/background or inverted target), returns the
	 * adjusted value at the closest-achieved ratio — never returns
	 * the original failing value, since callers expect "now passes
	 * or as close as possible."
	 *
	 * @param string $fg     Foreground color hex.
	 * @param string $bg     Background color hex.
	 * @param float  $target Target ratio (e.g., 4.5 for AA body).
	 * @return string Adjusted foreground hex.
	 */
	public static function adjust_for_minimum( string $fg, string $bg, float $target ): string {
		$rgb_fg = self::hex_to_rgb( $fg );
		$rgb_bg = self::hex_to_rgb( $bg );
		if ( null === $rgb_fg || null === $rgb_bg ) {
			return $fg;
		}

		$current = self::ratio( $fg, $bg );
		if ( $current >= $target ) {
			return $fg;
		}

		$hsl_fg = self::rgb_to_hsl( $rgb_fg );
		$bg_l   = self::luminance( $bg );
		if ( null === $bg_l ) {
			return $fg;
		}

		// Bias direction by which side is further from the background's
		// luminance. If the bg is light, push the fg darker (decrease
		// lightness). If the bg is dark, push the fg lighter (increase
		// lightness). This preserves the user's design intent —
		// "primary on white" stays dark, just darker.
		$direction = $bg_l > 0.5 ? -1 : 1;

		$best_hex   = $fg;
		$best_ratio = $current;
		$step       = 1.0; // 1% per iteration.

		for ( $i = 1; $i <= 100; $i++ ) {
			$new_l = $hsl_fg['l'] + $direction * $step * $i;
			if ( $new_l < 0.0 || $new_l > 100.0 ) {
				break;
			}
			$candidate_rgb = self::hsl_to_rgb(
				array(
					'h' => $hsl_fg['h'],
					's' => $hsl_fg['s'],
					'l' => $new_l,
				)
			);
			$candidate_hex = self::rgb_to_hex( $candidate_rgb );
			$candidate_r   = self::ratio( $candidate_hex, $bg );
			if ( $candidate_r > $best_ratio ) {
				$best_ratio = $candidate_r;
				$best_hex   = $candidate_hex;
			}
			if ( $candidate_r >= $target ) {
				return $candidate_hex;
			}
		}

		// Couldn't reach target within range; return the closest we got.
		return $best_hex;
	}

	/**
	 * Whether a color pair meets a target ratio.
	 *
	 * @param string $a      One color (foreground or background).
	 * @param string $b      Other color.
	 * @param float  $target Target ratio. Default AA body (4.5).
	 */
	public static function meets( string $a, string $b, float $target = self::TARGET_AA_BODY ): bool {
		return self::ratio( $a, $b ) >= $target;
	}

	/**
	 * Compute relative luminance of a color per WCAG 2.1 spec.
	 * Returns null on parse failure.
	 *
	 * @param string $hex Color hex.
	 * @return float|null
	 */
	private static function luminance( string $hex ): ?float {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return null;
		}
		$linear = static function ( int $c ): float {
			$f = $c / 255.0;
			return $f <= 0.03928
				? $f / 12.92
				: pow( ( $f + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $linear( $rgb['r'] )
			+ 0.7152 * $linear( $rgb['g'] )
			+ 0.0722 * $linear( $rgb['b'] );
	}

	/**
	 * Parse a hex color string to {r, g, b} integer components.
	 * Returns null on malformed input.
	 *
	 * @param string $hex
	 * @return array{r: int, g: int, b: int}|null
	 */
	private static function hex_to_rgb( string $hex ): ?array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		// Tolerate 8-char rgba; ignore alpha for contrast computation.
		if ( 8 === strlen( $hex ) ) {
			$hex = substr( $hex, 0, 6 );
		}
		if ( 6 !== strlen( $hex ) || 1 !== preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return null;
		}
		return array(
			'r' => (int) hexdec( substr( $hex, 0, 2 ) ),
			'g' => (int) hexdec( substr( $hex, 2, 2 ) ),
			'b' => (int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Convert RGB to HSL. h in [0, 360), s/l in [0, 100].
	 *
	 * @param array{r: int, g: int, b: int} $rgb
	 * @return array{h: float, s: float, l: float}
	 */
	private static function rgb_to_hsl( array $rgb ): array {
		$r   = $rgb['r'] / 255.0;
		$g   = $rgb['g'] / 255.0;
		$b   = $rgb['b'] / 255.0;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2.0;
		if ( abs( $max - $min ) < 1e-9 ) {
			return array(
				'h' => 0.0,
				's' => 0.0,
				'l' => $l * 100.0,
			);
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2.0 - $max - $min ) : $d / ( $max + $min );

		$h = 0.0;
		if ( abs( $max - $r ) < 1e-9 ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6.0 : 0.0 );
		} elseif ( abs( $max - $g ) < 1e-9 ) {
			$h = ( $b - $r ) / $d + 2.0;
		} else {
			$h = ( $r - $g ) / $d + 4.0;
		}
		return array(
			'h' => $h * 60.0,
			's' => $s * 100.0,
			'l' => $l * 100.0,
		);
	}

	/**
	 * Convert HSL back to RGB.
	 *
	 * @param array{h: float, s: float, l: float} $hsl
	 * @return array{r: int, g: int, b: int}
	 */
	private static function hsl_to_rgb( array $hsl ): array {
		$h = $hsl['h'] / 360.0;
		$s = $hsl['s'] / 100.0;
		$l = $hsl['l'] / 100.0;

		if ( 0.0 === $s ) {
			$gray = (int) round( $l * 255.0 );
			return array(
				'r' => $gray,
				'g' => $gray,
				'b' => $gray,
			);
		}

		$q = $l < 0.5 ? $l * ( 1.0 + $s ) : $l + $s - $l * $s;
		$p = 2.0 * $l - $q;
		return array(
			'r' => (int) round( self::hue_to_rgb( $p, $q, $h + 1.0 / 3.0 ) * 255.0 ),
			'g' => (int) round( self::hue_to_rgb( $p, $q, $h ) * 255.0 ),
			'b' => (int) round( self::hue_to_rgb( $p, $q, $h - 1.0 / 3.0 ) * 255.0 ),
		);
	}

	private static function hue_to_rgb( float $p, float $q, float $t ): float {
		if ( $t < 0.0 ) {
			$t += 1.0;
		}
		if ( $t > 1.0 ) {
			$t -= 1.0;
		}
		if ( $t < 1.0 / 6.0 ) {
			return $p + ( $q - $p ) * 6.0 * $t;
		}
		if ( $t < 1.0 / 2.0 ) {
			return $q;
		}
		if ( $t < 2.0 / 3.0 ) {
			return $p + ( $q - $p ) * ( 2.0 / 3.0 - $t ) * 6.0;
		}
		return $p;
	}

	/**
	 * Convert RGB integer components back to a `#rrggbb` hex string.
	 *
	 * @param array{r: int, g: int, b: int} $rgb
	 */
	private static function rgb_to_hex( array $rgb ): string {
		return sprintf(
			'#%02x%02x%02x',
			max( 0, min( 255, $rgb['r'] ) ),
			max( 0, min( 255, $rgb['g'] ) ),
			max( 0, min( 255, $rgb['b'] ) )
		);
	}
}
