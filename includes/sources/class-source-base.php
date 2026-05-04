<?php
/**
 * Outpost_Source_Base
 *
 * Abstract base every inbound source adapter extends. Source_*
 * adapters declare WHAT to extract from a URL the user shared via
 * the Web Share Target API; they NEVER fetch directly. The preview
 * endpoint owns fetching with SSRF defenses, content-type
 * validation, size capping, and rate limiting; sources tell the
 * endpoint which extractor to use, what recipe to pass, and how to
 * map extracted fields onto h-entry properties.
 *
 * F1–F4 SYMMETRY (load-bearing):
 *
 * This class is the inbound mirror of `Outpost_Companion_Base`
 * (includes/companions/class-companion-base.php). Where
 * Companion_Base declares "I publish to silo X, I accept these
 * post modes, here are my caveats", Source_Base declares "I
 * capture from host X, I produce this composer mode, here are my
 * caveats." The capabilities() shape's keys are deliberately
 * parallel:
 *
 *   shared keys     : id, label, caveats
 *   outbound key    : detected, accepts_modes, accepts_media,
 *                     max_attachments, alt_passthrough,
 *                     char_limit, requires_auth
 *   inbound key     : host_patterns, ambiguity, mode, mode_options,
 *                     mode_default, extractor, recipe, mapping,
 *                     h_entry_property, auth_required, tags_default
 *
 * The composer can iterate either family with a uniform vocabulary
 * — capabilities()['id'] / ['label'] / ['caveats'] are addressable
 * the same way regardless of direction. See
 * concepts/capture-inbound-may-2026.md §10 for the full table and
 * concepts/posse-outbound-may-2026.md §7 for the parallel shipping
 * queue.
 *
 * HOST PATTERN MATCHING:
 *
 * Patterns in capabilities()['host_patterns'] match against the
 * parsed URL host (case-insensitive) and optionally a path prefix
 * (case-sensitive per HTTP semantics). Three accepted forms:
 *
 *   exact          'open.spotify.com'
 *   suffix         '*.example.social'      (any subdomain)
 *   exact + path   'boardgamegeek.com/boardgame/'
 *   match-any      '*'                     (the Source_Unknown fallback)
 *
 * The matcher is bespoke string-comparison code, NOT regex. Regex
 * matching against user-shared URLs is a denial-of-service surface
 * — pathological inputs paired with backtracking patterns can pin
 * a CPU. Suffix + exact + path-prefix covers every Phase F source
 * we plan to ship and avoids the regex DoS class entirely.
 *
 * Patterns are validated at registration via `validate_pattern()`.
 * Malformed patterns raise InvalidArgumentException at registration
 * time, never silently fail to match later.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Outpost_Source_Base {

	/**
	 * Capability shape this source declares. Parallel to
	 * `Outpost_Companion_Base::capabilities()` with the inbound
	 * direction.
	 *
	 * Required keys (see class-level docblock for the full table
	 * and concepts/capture-inbound-may-2026.md §2 for the design
	 * rationale):
	 *
	 *     [
	 *         'id'               => string,
	 *         'label'            => string,        // i18n'd
	 *         'host_patterns'    => string[],      // see class docblock
	 *         'ambiguity'        => 'unambiguous'|'ambiguous',
	 *         'mode'             => string|null,
	 *         'mode_options'     => string[]|null,
	 *         'mode_default'     => string|null,
	 *         'extractor'        => string,        // extractor type ID
	 *         'recipe'           => array,         // extractor-specific
	 *         'mapping'          => array,         // raw-field => h-entry-prop
	 *         'h_entry_property' => string|null,   // verb-of for unambiguous
	 *         'auth_required'    => bool,
	 *         'tags_default'     => string[],
	 *         'caveats'          => string[],      // i18n'd, may be []
	 *     ]
	 *
	 * Concrete subclasses MUST apply the `outpost_source_capabilities`
	 * filter before returning so site owners can override per their
	 * setup, parallel to F2's `outpost_companion_capabilities` filter.
	 *
	 * @return array{
	 *     id: string,
	 *     label: string,
	 *     host_patterns: string[],
	 *     ambiguity: string,
	 *     mode: string|null,
	 *     mode_options: string[]|null,
	 *     mode_default: string|null,
	 *     extractor: string,
	 *     recipe: array<string,mixed>,
	 *     mapping: array<string,string|null>,
	 *     h_entry_property: string|null,
	 *     auth_required: bool,
	 *     tags_default: string[],
	 *     caveats: string[]
	 * }
	 */
	abstract public function capabilities(): array;

	/**
	 * Whether this source claims the given URL based on its
	 * host_patterns. Default implementation walks the patterns
	 * declared in capabilities()['host_patterns'] and returns true
	 * on the first match.
	 *
	 * Override only when the URL alone isn't enough — e.g. the
	 * planned Source_ActivityPub does Accept-header content
	 * negotiation per concepts/capture-inbound-may-2026.md §5
	 * because Mastodon instance hosts vary per user.
	 *
	 * @param string $url URL the user shared.
	 * @return bool
	 */
	public function matches_url( string $url ): bool {
		$caps     = $this->capabilities();
		$patterns = isset( $caps['host_patterns'] ) && is_array( $caps['host_patterns'] )
			? $caps['host_patterns']
			: array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			if ( self::pattern_matches( $pattern, $url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recipe to use for the given URL. Default returns
	 * capabilities()['recipe'] verbatim. Override for adapters that
	 * vary recipe by URL pattern (e.g. a planned Source_GitHub
	 * routes /issues vs /pulls vs repo-root URLs through different
	 * recipe variants — see concepts/capture-inbound-may-2026.md
	 * §3.19).
	 *
	 * @param string $url URL the user shared.
	 * @return array<string,mixed>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Template method; subclasses override and consume $url.
	public function recipe_for_url( string $url ): array {
		$caps   = $this->capabilities();
		$recipe = $caps['recipe'] ?? array();
		return is_array( $recipe ) ? $recipe : array();
	}

	/**
	 * Composer mode this source produces for the given URL. Default
	 * returns capabilities()['mode'] for unambiguous sources, or
	 * capabilities()['mode_default'] for ambiguous sources.
	 *
	 * Override for adapters with per-URL routing.
	 *
	 * @param string $url URL the user shared.
	 * @return string Composer mode slug ('note', 'photo', 'reply', etc.).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Template method; subclasses override and consume $url.
	public function mode_for_url( string $url ): string {
		$caps = $this->capabilities();
		if ( 'unambiguous' === ( $caps['ambiguity'] ?? '' ) && is_string( $caps['mode'] ?? null ) ) {
			return $caps['mode'];
		}
		if ( is_string( $caps['mode_default'] ?? null ) ) {
			return $caps['mode_default'];
		}
		// Defensive fallback: bookmark is the canonical "I don't know
		// what this is" composer mode per the inbound research doc §1.
		return 'bookmark';
	}

	/**
	 * Apply capabilities()['mapping'] to a raw extractor-output
	 * array. Special mapping values:
	 *
	 *   '@source_url' — substitute the URL the user shared
	 *   '@now'        — current ISO 8601 timestamp (gmdate, UTC)
	 *   null          — drop this field (do not write to composer)
	 *
	 * Returns a flat associative array keyed by h-entry property
	 * names (e.g. 'p-name', 'u-photo', 'u-listen-of').
	 *
	 * Override for complex mappings (e.g. assembling a `p-summary`
	 * from multiple raw fields).
	 *
	 * @param array<string,mixed> $raw        Raw extractor output.
	 * @param string              $source_url URL the user shared (for @source_url substitution).
	 * @return array<string,mixed>
	 */
	public function map_extracted( array $raw, string $source_url ): array {
		$caps    = $this->capabilities();
		$mapping = $caps['mapping'] ?? array();
		$out     = array();
		if ( ! is_array( $mapping ) ) {
			return $out;
		}
		foreach ( $mapping as $raw_key => $h_entry_prop ) {
			if ( null === $h_entry_prop ) {
				continue;
			}
			if ( ! is_string( $h_entry_prop ) || '' === $h_entry_prop ) {
				continue;
			}
			if ( ! is_string( $raw_key ) ) {
				continue;
			}
			$value = $this->resolve_mapping_value( $raw_key, $raw, $source_url );
			if ( null !== $value ) {
				$out[ $h_entry_prop ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Resolve a single mapping key against the raw extractor output
	 * and substitution macros. Static so subclasses overriding
	 * map_extracted() can reuse the resolution logic without
	 * re-implementing macros.
	 *
	 * @param string             $raw_key    Raw field name OR `@source_url` / `@now`.
	 * @param array<string,mixed> $raw        Raw extractor output.
	 * @param string             $source_url URL the user shared.
	 * @return mixed|null Resolved value, or null when no value resolves.
	 */
	protected function resolve_mapping_value( string $raw_key, array $raw, string $source_url ) {
		if ( '@source_url' === $raw_key ) {
			return $source_url;
		}
		if ( '@now' === $raw_key ) {
			return gmdate( 'c' );
		}
		if ( array_key_exists( $raw_key, $raw ) ) {
			return $raw[ $raw_key ];
		}
		return null;
	}

	// ---------------------------------------------------------------
	// Pattern matcher (static so the registry can validate at
	// register time without instantiating the source first).
	// ---------------------------------------------------------------

	/**
	 * Validate a host_pattern at registration time. Throws on any
	 * shape the matcher won't accept later, so registry-time errors
	 * never become silent match-time failures.
	 *
	 * Accepted shapes:
	 *
	 *   '*'                            — match-any sentinel (Source_Unknown)
	 *   '*.example.social'             — suffix wildcard (subdomains only)
	 *   'open.spotify.com'             — exact host
	 *   'boardgamegeek.com/boardgame/' — exact host + path prefix
	 *
	 * Rejected shapes (raises \InvalidArgumentException):
	 *
	 *   - empty string
	 *   - regex metacharacters: . [ ] ( ) ? + \ ^ $ | { }
	 *   - middle wildcards: 'foo*.example.com', 'example.*'
	 *   - path patterns without trailing slash
	 *   - schemes ('https://example.com')
	 *   - fragments / query strings ('example.com#anchor', 'example.com?q=1')
	 *
	 * @param string $pattern Pattern to validate.
	 * @throws \InvalidArgumentException When the pattern is malformed.
	 */
	public static function validate_pattern( string $pattern ): void {
		if ( '' === $pattern ) {
			throw new \InvalidArgumentException(
				esc_html( 'Outpost source host pattern cannot be empty.' )
			);
		}
		if ( '*' === $pattern ) {
			return;
		}
		// Rule out scheme prefix.
		if ( false !== strpos( $pattern, '://' ) ) {
			throw new \InvalidArgumentException(
				esc_html( 'Outpost source host pattern must not contain a scheme: ' . $pattern )
			);
		}
		// Rule out fragments and query strings.
		if ( false !== strpos( $pattern, '#' ) || false !== strpos( $pattern, '?' ) ) {
			throw new \InvalidArgumentException(
				esc_html( 'Outpost source host pattern must not contain fragment or query: ' . $pattern )
			);
		}
		// Rule out regex metacharacters that aren't `*` or `/` or `.` or `-`.
		// `*` is allowed only as the very first character.
		// `.` and `-` are valid in hostnames; we don't treat `.` as regex here.
		if ( false !== strpos( $pattern, '*' ) ) {
			if ( '*' !== $pattern[0] || ( 1 < strlen( $pattern ) && '.' !== $pattern[1] ) ) {
				throw new \InvalidArgumentException(
					esc_html( 'Outpost source host pattern wildcard must be `*` alone or appear as the leading `*.` prefix: ' . $pattern )
				);
			}
			if ( substr_count( $pattern, '*' ) > 1 ) {
				throw new \InvalidArgumentException(
					esc_html( 'Outpost source host pattern allows only one wildcard: ' . $pattern )
				);
			}
		}
		$forbidden = array( '[', ']', '(', ')', '?', '+', '\\', '^', '$', '|', '{', '}' );
		foreach ( $forbidden as $char ) {
			if ( false !== strpos( $pattern, $char ) ) {
				throw new \InvalidArgumentException(
					esc_html( 'Outpost source host pattern contains forbidden character "' . $char . '": ' . $pattern )
				);
			}
		}
		// Path component (anything after the first `/`) must end with `/`.
		$slash = strpos( $pattern, '/' );
		if ( false !== $slash ) {
			$path = substr( $pattern, $slash );
			if ( '/' !== substr( $path, -1 ) ) {
				throw new \InvalidArgumentException(
					esc_html( 'Outpost source host pattern with path prefix must end in `/`: ' . $pattern )
				);
			}
			// Host portion is everything before the slash; cannot be empty.
			$host_part = substr( $pattern, 0, $slash );
			if ( '' === $host_part ) {
				throw new \InvalidArgumentException(
					esc_html( 'Outpost source host pattern path needs a host: ' . $pattern )
				);
			}
		}
	}

	/**
	 * Match a pre-validated pattern against a URL. Pattern is
	 * trusted to be well-formed; callers must run
	 * `validate_pattern()` at registration time, not match time.
	 *
	 * Match rules (case-insensitive on host, case-sensitive on path
	 * per HTTP semantics):
	 *
	 *   - '*' matches every URL.
	 *   - '*.example.com' matches any host ending with '.example.com'
	 *     (does NOT match the apex 'example.com' itself).
	 *   - 'example.com' matches host == 'example.com' exactly.
	 *   - 'example.com/path/' matches host == 'example.com' AND
	 *     path starts with '/path/'.
	 *
	 * @param string $pattern Pattern (already validated).
	 * @param string $url     URL the user shared.
	 * @return bool True on match.
	 */
	public static function pattern_matches( string $pattern, string $url ): bool {
		if ( '*' === $pattern ) {
			return true;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		$slash = strpos( $pattern, '/' );
		if ( false !== $slash ) {
			$host_pat = strtolower( substr( $pattern, 0, $slash ) );
			$path_pat = substr( $pattern, $slash );
		} else {
			$host_pat = strtolower( $pattern );
			$path_pat = null;
		}

		if ( '*.' === substr( $host_pat, 0, 2 ) ) {
			$suffix = substr( $host_pat, 1 );
			if ( substr( $host, -strlen( $suffix ) ) !== $suffix ) {
				return false;
			}
			if ( strlen( $host ) === strlen( $suffix ) ) {
				return false;
			}
		} elseif ( $host !== $host_pat ) {
				return false;
		}

		if ( null !== $path_pat ) {
			if ( 0 !== strpos( $path, $path_pat ) ) {
				return false;
			}
		}

		return true;
	}
}
