<?php
/**
 * Outpost_Schema_Helpers — trait shared by JSON-LD schema extractors.
 *
 * Five concrete schema extractors (Recipe / Event / Article / Book /
 * Restaurant) all face the same JSON-LD shape variance:
 *
 *   - "author" can be a string, a Person object, or an array of either.
 *   - "image" can be a string URL or an ImageObject with a `url` property.
 *   - Dates can be ISO 8601 strings or Date strings.
 *   - Durations can be ISO 8601 PT-format strings.
 *   - Many sub-fields can be missing entirely.
 *
 * This trait centralises the normalisation so extractors stay focused
 * on their category-specific field selection.
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Outpost_Schema_Helpers {

	/**
	 * Pull a string from a mixed JSON-LD value. Strings pass through;
	 * arrays return the first string-or-name value; objects with a
	 * `name` key return that. Empty when no string can be derived.
	 *
	 * @param mixed $value Raw JSON-LD value.
	 * @return string
	 */
	protected static function as_string( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['name'] ) && is_string( $value['name'] ) ) {
				return trim( $value['name'] );
			}
			if ( isset( $value[0] ) ) {
				return self::as_string( $value[0] );
			}
		}
		return '';
	}

	/**
	 * Normalise an "author" / "performer" / "organizer" field to a flat
	 * array of name strings.
	 *
	 * @param mixed $value Raw JSON-LD value.
	 * @return string[]
	 */
	protected static function as_name_list( $value ): array {
		if ( '' === self::as_string( $value ) && ! is_array( $value ) ) {
			return array();
		}
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			return '' === $trimmed ? array() : array( $trimmed );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		// Single object {name: 'X'}.
		if ( isset( $value['name'] ) ) {
			$single = self::as_string( $value );
			return '' === $single ? array() : array( $single );
		}
		// Array of objects or strings.
		$out = array();
		foreach ( $value as $entry ) {
			$name = self::as_string( $entry );
			if ( '' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Resolve an image-shaped JSON-LD value to a URL string. Accepts
	 * string URLs, ImageObject `url`/`contentUrl` properties, or arrays
	 * of either (returns the first).
	 *
	 * @param mixed $value Raw JSON-LD value.
	 * @return string
	 */
	protected static function as_image_url( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
				return trim( $value['url'] );
			}
			if ( isset( $value['contentUrl'] ) && is_string( $value['contentUrl'] ) ) {
				return trim( $value['contentUrl'] );
			}
			if ( isset( $value[0] ) ) {
				return self::as_image_url( $value[0] );
			}
		}
		return '';
	}

	/**
	 * Convert an ISO 8601 duration ("PT1H30M", "PT45M", "P1DT2H") to
	 * total minutes. Returns null on malformed input.
	 *
	 * @param mixed $value Raw JSON-LD value.
	 * @return int|null
	 */
	protected static function as_iso_duration_minutes( $value ): ?int {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$pattern = '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i';
		if ( ! preg_match( $pattern, trim( $value ), $matches ) ) {
			return null;
		}
		$days    = (int) ( $matches[1] ?? 0 );
		$hours   = (int) ( $matches[2] ?? 0 );
		$minutes = (int) ( $matches[3] ?? 0 );
		$seconds = (int) ( $matches[4] ?? 0 );
		$total   = ( $days * 1440 ) + ( $hours * 60 ) + $minutes + (int) round( $seconds / 60 );
		// Reject "P" with no time components (regex matches but yields 0).
		if ( 0 === $total && false === stripos( $value, 't' ) && false === stripos( $value, 'd' ) ) {
			return null;
		}
		return $total > 0 ? $total : 0;
	}

	/**
	 * Pull an ISO 8601 date or datetime string, passed through as-is.
	 *
	 * @param mixed $value Raw JSON-LD value.
	 * @return string
	 */
	protected static function as_iso_date( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$trimmed = trim( $value );
		// Reject empty, but tolerate any string that looks date-shaped —
		// we don't reformat, just pass through.
		if ( '' === $trimmed ) {
			return '';
		}
		return $trimmed;
	}

	/**
	 * Format a PostalAddress JSON-LD object as a single-line address
	 * string. Falls back to the raw `addressLocality` or empty string
	 * when the object is missing required parts.
	 *
	 * @param mixed $value Raw JSON-LD value.
	 * @return string
	 */
	protected static function as_postal_address_string( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$parts = array();
		foreach ( array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' ) as $key ) {
			$val = $value[ $key ] ?? '';
			if ( is_array( $val ) && isset( $val['name'] ) && is_string( $val['name'] ) ) {
				$val = $val['name'];
			}
			if ( is_string( $val ) && '' !== trim( $val ) ) {
				$parts[] = trim( $val );
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Flatten an instructions list. Accepts:
	 * - Array of strings.
	 * - Array of HowToStep objects (use the `text` field; recurse for
	 *   HowToSection.itemListElement nesting).
	 * - Single string with newlines.
	 *
	 * @param mixed $value Raw JSON-LD value.
	 * @return string[]
	 */
	protected static function as_instruction_list( $value ): array {
		if ( is_string( $value ) ) {
			$split = preg_split( '/\r\n|\r|\n/', $value );
			$lines = false === $split ? array() : $split;
			$out   = array();
			foreach ( $lines as $line ) {
				$trimmed = trim( $line );
				if ( '' !== $trimmed ) {
					$out[] = $trimmed;
				}
			}
			return $out;
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( is_string( $entry ) ) {
				$trimmed = trim( $entry );
				if ( '' !== $trimmed ) {
					$out[] = $trimmed;
				}
				continue;
			}
			if ( is_array( $entry ) ) {
				$type = isset( $entry['@type'] ) ? (string) $entry['@type'] : '';
				if ( 'HowToSection' === $type && isset( $entry['itemListElement'] ) ) {
					$out = array_merge( $out, self::as_instruction_list( $entry['itemListElement'] ) );
					continue;
				}
				$text = '';
				if ( isset( $entry['text'] ) && is_string( $entry['text'] ) ) {
					$text = trim( $entry['text'] );
				} elseif ( isset( $entry['name'] ) && is_string( $entry['name'] ) ) {
					$text = trim( $entry['name'] );
				}
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}
		return $out;
	}
}
