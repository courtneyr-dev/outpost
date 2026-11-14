<?php
/**
 * Outpost_Source_Detector
 *
 * Share-target dispatcher. Turns inbound URLs (from Web Share Target
 * Level 2 POSTs on Android Chrome, GET-method shares, and the iOS
 * Shortcut bridge) into composer route decisions:
 *
 *   - **auto** route — unambiguous source, composer opens directly to
 *     the matched mode with metadata pre-fill enqueued asynchronously
 *   - **picker** route — ambiguous source (or fallback to
 *     Source_Unknown), composer opens with the C1b VARIANTS picker
 *     parameterized for the matching mode group + smart default
 *
 * Dispatch is a pure function of the URL + currently-registered
 * sources. No session storage, no per-request state. The redirect
 * is the entire handoff between server-side dispatch and client-side
 * composer rendering.
 *
 * The $context parameter carries platform / origin telemetry but
 * dispatch logic NEVER branches on it — keeps the behavior
 * deterministic and reproducible regardless of whether the request
 * came from the Android share sheet, the iOS Shortcut bridge, or a
 * desktop bookmarklet.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Detector {

	/** Composer base path — where redirects ultimately land. */
	public const COMPOSER_PATH = '/post/';

	/** Reject candidate URLs longer than this. Matches the bookmarklet/shortcut
	 * contract (CLAUDE.md Security Hot Spots) and the manual-share writeback cap. */
	private const URL_MAX_LENGTH = 2048;

	/**
	 * Per-request memoization of `find_for_url` results, keyed on
	 * `lowercased-host + path`. Sources are filterable per-request
	 * via `outpost_source_capabilities`, so persisting this across
	 * requests would break the filter contract — but within one
	 * request, the result is deterministic and safe to cache.
	 *
	 * @var array<string, ?Outpost_Source_Base>
	 */
	private static array $find_cache = array();

	/**
	 * Reset the per-request memoization. Test seam.
	 */
	public static function reset_cache_for_tests(): void {
		self::$find_cache = array();
	}

	/**
	 * Memoized `Outpost_Source_Registry::find_for_url`. The cache
	 * key strips fragments and query strings since the source
	 * matcher only cares about host + path prefix.
	 *
	 * @param string $url URL to look up.
	 * @return Outpost_Source_Base|null
	 */
	public static function find_source( string $url ): ?Outpost_Source_Base {
		$parts = wp_parse_url( $url );
		$key   = '';
		if ( is_array( $parts ) ) {
			$key  = strtolower( (string) ( $parts['host'] ?? '' ) );
			$key .= (string) ( $parts['path'] ?? '' );
		}
		if ( '' === $key ) {
			return Outpost_Source_Registry::find_for_url( $url );
		}
		if ( array_key_exists( $key, self::$find_cache ) ) {
			return self::$find_cache[ $key ];
		}
		$source                   = Outpost_Source_Registry::find_for_url( $url );
		self::$find_cache[ $key ] = $source;
		return $source;
	}

	/**
	 * Extract the URL from a share-payload using the priority chain:
	 *
	 *   1. `url` field if it's a valid http(s) URL
	 *   2. `text` field starting with http:// or https://
	 *   3. `text` field containing an http(s) URL anywhere
	 *   4. `title` field starting with http:// or https://
	 *
	 * Returns null when no URL is detected — caller renders the
	 * share-text-only flow (compose Note with title/text pre-filled,
	 * no source).
	 *
	 * @param array<string,mixed> $payload Sanitized share-payload fields.
	 * @return string|null
	 */
	public static function extract_url_from_payload( array $payload ): ?string {
		$url   = isset( $payload['url'] ) && is_string( $payload['url'] ) ? trim( $payload['url'] ) : '';
		$text  = isset( $payload['text'] ) && is_string( $payload['text'] ) ? trim( $payload['text'] ) : '';
		$title = isset( $payload['title'] ) && is_string( $payload['title'] ) ? trim( $payload['title'] ) : '';

		// Candidates in priority order; every one, wherever it was found, must
		// pass the URL-only validator before it is returned (T10-S3-13 v1.1).
		// Some iOS apps (Kindle, Books, podcast clients) bundle the
		// shared content as a quote + title + URL all in the share-sheet
		// text payload. iOS Shortcut authors typically wire a single
		// "Shortcut Input" magic variable into one field — usually the
		// `url` field — so the entire text blob lands here. Fall through
		// to URL extraction from the `url` field's content when the
		// field isn't a clean URL on its own.
		$candidates = array();
		if ( '' !== $url ) {
			$candidates[] = $url;
			$candidates[] = self::find_url_in_text( $url );
		}
		if ( '' !== $text ) {
			$candidates[] = $text;
			$candidates[] = self::find_url_in_text( $text );
		}
		if ( '' !== $title ) {
			$candidates[] = $title;
		}
		foreach ( $candidates as $candidate ) {
			$valid = null === $candidate ? '' : self::sanitize_shared_url( $candidate );
			if ( '' !== $valid ) {
				return $valid;
			}
		}
		return null;
	}

	/**
	 * Clean a shared free-text field — share-sheet `text` / `title`, Shortcut
	 * `shared_text` — without rewriting a link inside it. Takes an
	 * already-unslashed value; anything but a string comes back empty.
	 *
	 * sanitize_text_field() deletes every %XX octet, so `/a%20b` reaches
	 * find_url_in_text() as `/ab`. wp_strip_all_tags() keeps the octets but
	 * drops everything after an unclosed `<`: "I <3 this https://…" comes back
	 * as "I". wp_kses() with no allowed tags keeps both, and strips C0 control
	 * characters. It is a fatal TypeError on an array, hence the guard.
	 *
	 * @param mixed $value Unslashed field value.
	 * @return string
	 */
	public static function sanitize_shared_text( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$value = wp_kses( wp_check_invalid_utf8( $value ), array() );
		// wp_kses() entity-encodes a bare `&`, and the quotes after an unclosed
		// `<`. A query string needs them back. None of the three can form a
		// tag; `&lt;` and `&gt;` stay encoded.
		$value = str_replace( array( '&amp;', '&#039;', '&quot;' ), array( '&', "'", '"' ), $value );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		return trim( (string) preg_replace( '/ {2,}/', ' ', $value ) );
	}

	/**
	 * Validate a shared URL (T10-S3-13 v1.1, F-14). URL-only: returns a
	 * validated http(s) URL or ''.
	 *
	 * The value must be one string token within URL_MAX_LENGTH with no
	 * whitespace, control bytes, angle brackets, quotes, backslashes, or HTML
	 * entities, an http(s) scheme, and a host; then it goes through
	 * esc_url_raw() with an http/https allowlist. A value with markup
	 * appended is malformed and is not trimmed into a valid prefix. wp_kses()
	 * never sanitizes a URL. For the share payload's `url` field, which may
	 * hold prose, use sanitize_shared_url_field().
	 *
	 * @param mixed $value Unslashed value.
	 * @return string
	 */
	public static function sanitize_shared_url( mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > self::URL_MAX_LENGTH ) {
			return '';
		}
		$trimmed = trim( $value );
		if ( '' === $trimmed || 1 === preg_match( '/\s/', $trimmed ) ) {
			return '';
		}
		if ( 1 === preg_match( '/[\x00-\x1F\x7F<>"\'`\\\\]/', $trimmed ) ) {
			return '';
		}
		if ( 1 === preg_match( '/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $trimmed ) ) {
			return '';
		}
		if ( ! self::is_http_url( $trimmed ) ) {
			return '';
		}
		$clean = esc_url_raw( $trimmed, array( 'http', 'https' ) );
		return ( '' !== $clean && self::is_http_url( $clean ) ) ? $clean : '';
	}

	/**
	 * Clean the share payload's `url` field, which share sheets and iOS
	 * Shortcuts sometimes fill with prose (a quote plus the link) instead of a
	 * bare URL. Returns the validated URL when the field is one; for prose
	 * (a value with whitespace) the prose cleaned as text, so
	 * extract_url_from_payload() can find the link in it and validate that
	 * candidate with sanitize_shared_url() before any use; otherwise ''.
	 *
	 * @param mixed $value Unslashed field value.
	 * @return string
	 */
	public static function sanitize_shared_url_field( mixed $value ): string {
		$url = self::sanitize_shared_url( $value );
		if ( '' !== $url ) {
			return $url;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\s/', trim( $value ) ) ) {
			return self::sanitize_shared_text( $value );
		}
		return '';
	}

	/**
	 * Whether a string is a syntactically valid http(s) URL with a host.
	 *
	 * @param string $candidate Candidate URL.
	 * @return bool
	 */
	private static function is_http_url( string $candidate ): bool {
		if ( '' === $candidate ) {
			return false;
		}
		// Length-cap the bookmarklet/shortcut URL before parsing, per the
		// Security Hot Spots contract (CLAUDE.md) and the manual-share cap.
		if ( strlen( $candidate ) > self::URL_MAX_LENGTH ) {
			return false;
		}
		$parts = wp_parse_url( $candidate );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		return ! empty( $parts['host'] );
	}

	/**
	 * Find the first http(s) URL embedded in a free-text string.
	 * Used by the URL extraction priority chain when the user shared
	 * text with a URL embedded but didn't populate the `url` field.
	 *
	 * @param string $text Free text.
	 * @return string|null First match, or null if none.
	 */
	private static function find_url_in_text( string $text ): ?string {
		// URL regex — strict enough to reject obvious garbage, loose
		// enough to catch URLs embedded in arbitrary text. We don't
		// need a perfect URL parser here; the candidate gets
		// re-validated via wp_parse_url + is_http_url before use.
		if ( preg_match_all( '~https?://[A-Za-z0-9._/?:%&=+\-\[\]@!,;\']+~i', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				// A link must end at a real boundary: the end of the text,
				// whitespace, or closing quotes or brackets followed by one of
				// those or sentence punctuation. A link that runs on into other
				// characters (`#`, `<`, `">`) is malformed and is skipped, never
				// cut back to a valid-looking prefix (T10-S3-13 v1.1).
				$after = substr( $text, (int) $match[1] + strlen( (string) $match[0] ) );
				if ( '' !== $after && 1 !== preg_match( '/^(?:\s|["\x{201D}\x{2019})\]}]+(?:$|[\s.,;:!?]))/u', $after ) ) {
					continue;
				}
				$candidate = rtrim( (string) $match[0], '.,;:!?)' );
				if ( self::is_http_url( $candidate ) ) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * Decide what composer route a URL maps to. Pure function over
	 * the registered sources.
	 *
	 * Returns a route-decision array:
	 *
	 *     [
	 *         'route_type'    => 'auto' | 'picker',
	 *         'mode'          => string|null,        // present for auto
	 *         'mode_options'  => string[]|null,      // present for picker
	 *         'mode_default'  => string|null,        // present for picker
	 *         'source_id'     => string,
	 *         'redirect_url'  => string,             // /post/?mode=... or /post/?picker=...
	 *     ]
	 *
	 * @param string             $url     The URL to dispatch.
	 * @param array<string,mixed> $context Telemetry only — never branches dispatch.
	 * @return array<string,mixed>
	 */
	public static function dispatch( string $url, array $context = array() ): array {
		unset( $context ); // Reserved for telemetry; never branches dispatch.

		$source = self::find_source( $url );
		if ( null === $source ) {
			// Defensive — F5's registry guarantees Source_Unknown is the
			// trailing fallback, so null means the registry was reset
			// for tests. Dispatch a picker shell anyway.
			return self::picker_decision(
				array(
					'id'           => 'unknown',
					'mode_options' => array( 'reply', 'like', 'repost', 'bookmark' ),
					'mode_default' => 'bookmark',
				),
				$url
			);
		}

		$caps      = $source->capabilities();
		$source_id = isset( $caps['id'] ) && is_string( $caps['id'] ) ? $caps['id'] : 'unknown';
		$ambiguity = isset( $caps['ambiguity'] ) ? $caps['ambiguity'] : 'ambiguous';

		if ( 'unambiguous' === $ambiguity ) {
			return self::auto_decision( $source, $url );
		}
		return self::picker_decision(
			array(
				'id'           => $source_id,
				'mode_options' => isset( $caps['mode_options'] ) && is_array( $caps['mode_options'] )
					? $caps['mode_options']
					: array( 'reply', 'like', 'repost', 'bookmark' ),
				'mode_default' => isset( $caps['mode_default'] ) && is_string( $caps['mode_default'] )
					? $caps['mode_default']
					: 'bookmark',
			),
			$url
		);
	}

	/**
	 * Build the auto-route decision for an unambiguous source. Pure: the
	 * caller only redirects; nothing is stored or fetched on the server.
	 *
	 * @param Outpost_Source_Base $source Matched source.
	 * @param string              $url    Source URL.
	 * @return array<string,mixed>
	 */
	private static function auto_decision( Outpost_Source_Base $source, string $url ): array {
		$caps      = $source->capabilities();
		$mode      = $source->mode_for_url( $url );
		$source_id = isset( $caps['id'] ) && is_string( $caps['id'] ) ? $caps['id'] : 'unknown';

		$query_args = array(
			'mode'   => $mode,
			'source' => $source_id,
			'url'    => $url,
		);
		return array(
			'route_type'   => 'auto',
			'mode'         => $mode,
			'mode_options' => null,
			'mode_default' => null,
			'source_id'    => $source_id,
			'redirect_url' => self::build_composer_url( $query_args ),
		);
	}

	/**
	 * Build the picker-route decision for an ambiguous source.
	 *
	 * @param array{id: string, mode_options: string[], mode_default: string} $info Ambiguity info.
	 * @param string                                                          $url  Source URL.
	 * @return array<string,mixed>
	 */
	private static function picker_decision( array $info, string $url ): array {
		// Mode-group identifier the picker UI consumes. F6 ships only the
		// `reply` group (matching C1b's VARIANTS component); future modes
		// may add more groups.
		$picker_group = self::picker_group_for( $info['mode_options'] );

		$query_args = array(
			'picker'  => $picker_group,
			'default' => $info['mode_default'],
			'source'  => $info['id'],
			'url'     => $url,
		);
		return array(
			'route_type'   => 'picker',
			'mode'         => null,
			'mode_options' => $info['mode_options'],
			'mode_default' => $info['mode_default'],
			'source_id'    => $info['id'],
			'redirect_url' => self::build_composer_url( $query_args ),
		);
	}

	/**
	 * Map an `accepts_modes` list to the picker-group identifier the
	 * PWA composer recognizes. Today only the `reply` group exists
	 * (C1b's Reply / Like / Repost / Bookmark VARIANTS); future
	 * sessions may add more (e.g. a Listen-group picker for sources
	 * that produce multiple listen-kind URLs).
	 *
	 * @param string[] $modes Accepted modes from the source's capabilities().
	 * @return string Picker-group id.
	 */
	private static function picker_group_for( array $modes ): string {
		$reply_group = array( 'reply', 'like', 'repost', 'bookmark' );
		sort( $modes );
		$wanted = $reply_group;
		sort( $wanted );
		if ( $modes === $wanted ) {
			return 'reply';
		}
		// Unknown grouping: surface 'reply' as a sensible default the
		// composer already implements; the picker's mode_options query
		// param will narrow it.
		return 'reply';
	}

	/**
	 * Build a composer URL with the supplied query args appended.
	 *
	 * @param array<string,mixed> $query_args Query args.
	 * @return string
	 */
	private static function build_composer_url( array $query_args ): string {
		// Absolute URL via home_url() so consumers without host context
		// (the iOS Shortcut JSON response, opened by Safari) get a
		// resolvable URL. wp_safe_redirect() in the share-target 303
		// path handles absolute URLs identically to relative ones, so
		// this single source-of-truth is safe for both consumers.
		$base  = home_url( self::COMPOSER_PATH );
		$pairs = array();
		foreach ( $query_args as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		if ( empty( $pairs ) ) {
			return $base;
		}
		return $base . '?' . implode( '&', $pairs );
	}
}
