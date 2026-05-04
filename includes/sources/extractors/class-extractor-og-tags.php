<?php
/**
 * Outpost_Source_Extractor_Og_Tags — concrete (F16).
 *
 * Parses Open Graph protocol meta tags from HTML response bodies.
 * Used by Source_Unknown's universal fallback (any URL with OG tags
 * gets best-effort metadata) and by every concrete source whose
 * `extractor` capability is `og_tags` — F16 ships eight: Snipd,
 * Twitch, Goodreads, LastFm, Readwise, Amazon, Pinterest, TikTok.
 *
 * The parser does NOT call wp_remote_get; the preview endpoint
 * fetches with SSRF defenses, content-type validation, and size
 * cap, then hands the body to parse(). Per F5 #2 the extractor
 * declares parsing semantics, not fetching.
 *
 * EXTRACTION SHAPE:
 *
 * parse() returns a flat associative array keyed by the OG property
 * name (with the `og:` prefix retained):
 *
 *     [
 *         'og:title'       => 'Article Title',
 *         'og:description' => 'Excerpt text...',
 *         'og:image'       => 'https://example.com/cover.jpg',
 *         'og:url'         => 'https://example.com/article/123',
 *         'og:type'        => 'article',
 *         'og:site_name'   => 'Example',
 *     ]
 *
 * Source adapters' `mapping` arrays target these keys — `'og:title'
 * => 'p-name'` projects the extractor field onto the h-entry name
 * property, etc.
 *
 * Only properties with non-empty `content` attribute values are
 * returned. Multiple `og:image` tags on the same page are collapsed
 * to the first (some sites emit multiple images for different
 * crops; first is the canonical primary).
 *
 * ENTITY DECODING — load-bearing contract:
 *
 * HTML attribute values are byte-stream encoded (`&amp;`, `&#39;`,
 * `&quot;`, `&#x2014;`). Decoding at parse time is correct because
 * by the time the value reaches a Source_Base mapping, the byte
 * stream stopped being HTML. Source adapters then pass through
 * decoded text per the F8 #11 transparent-adapter contract — the
 * composer's input-value DOM step + render-time esc_html() handle
 * any further escaping. If we passed the raw byte stream through
 * verbatim, every downstream consumer would have to re-decode and
 * mistakes (double-decoding, missing entities) would propagate.
 *
 * Decoding uses ENT_QUOTES | ENT_HTML5 + UTF-8 so HTML5 named
 * entities (`&hellip;`, `&mdash;`) and numeric character references
 * resolve correctly.
 *
 * REGEX, NOT DOMDocument:
 *
 * Matching `<meta property="og:..." content="...">` is well-suited
 * to regex because <meta> tags are flat (no nesting, no inner text)
 * and the failure modes are constrained: attribute order (`content`
 * before `property`), quote style (single / double / unquoted), and
 * `name=` instead of `property=` (some sites emit this for OG tags
 * even though the spec mandates `property=`). All three are covered.
 *
 * DOMDocument would also work but loads a heavier dependency and
 * raises a forest of warnings on real-world HTML (mismatched tags,
 * non-utf8 bytes, malformed comments). Outpost's existing CSS/HTML
 * surfaces avoid DOMDocument for the same reasons.
 *
 * SIZE CAP:
 *
 * Defense in depth above the preview endpoint's 5 MB cap: the
 * parser refuses bodies larger than 2 MB. Real HTML pages with OG
 * tags are well under this (Twitter media bombs that inflate via
 * embedded base64 thumbnails are the realistic worst case);
 * anything larger likely isn't a useful share target.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Og_Tags extends Outpost_Source_Extractor_Base {

	/** Hard ceiling on the body size the parser will scan. */
	private const MAX_BODY_BYTES = 2_097_152;

	public function id(): string {
		return 'og_tags';
	}

	/**
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array( 'text/html', 'application/xhtml+xml' );
	}

	/**
	 * Extract OG tags from the response body.
	 *
	 * @param string             $body   HTML response body (size-capped upstream).
	 * @param array<string,mixed> $recipe Recipe (unused for og_tags; kept for interface symmetry).
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the body is oversized or no <head> can be located.
	 */
	public function parse( string $body, array $recipe ): array {
		unset( $recipe );

		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			throw new \RuntimeException( 'OG parser refused: body exceeds 2 MB cap.' );
		}

		// Narrow scan to <head> when present (cheaper + avoids body-level meta
		// tags that bots inject post-render). Fall back to the whole document
		// if <head> can't be located or doesn't close — many SPA pages emit
		// a head fragment that overlaps the body.
		$head = $this->extract_head_fragment( $body );

		$properties = $this->extract_og_properties( $head );

		// Return decoded values per the entity-decoding contract above.
		return array_map(
			static fn ( string $value ): string => self::decode_html_entities( $value ),
			$properties
		);
	}

	/**
	 * Return the substring inside <head>...</head>, or the original body
	 * when no head fragment can be located. Case-insensitive.
	 */
	private function extract_head_fragment( string $body ): string {
		$open = stripos( $body, '<head' );
		if ( false === $open ) {
			return $body;
		}
		$open_close = strpos( $body, '>', $open );
		if ( false === $open_close ) {
			return $body;
		}
		$close = stripos( $body, '</head>', $open_close );
		if ( false === $close ) {
			return $body;
		}
		return substr( $body, $open_close + 1, $close - $open_close - 1 );
	}

	/**
	 * Walk the head fragment and collect og:* properties.
	 *
	 * @return array<string,string> Map of `og:<name>` to raw content value.
	 */
	private function extract_og_properties( string $head ): array {
		$out = array();

		// Match <meta ... > tags inside the head. The regex is lenient on
		// whitespace / quoting / attribute order; both <meta property="og:..."
		// content="..." /> and <meta content="..." property="og:..." /> match.
		// The /s modifier lets `.` cross newlines so multi-line tags work.
		if ( ! preg_match_all( '#<meta\b([^>]*)/?>#is', $head, $tags ) ) {
			return $out;
		}

		foreach ( $tags[1] as $attrs ) {
			$property = $this->extract_attr( $attrs, 'property' );
			if ( '' === $property ) {
				// Some sites (incorrectly) use `name=` for OG tags. Tolerate.
				$property = $this->extract_attr( $attrs, 'name' );
			}
			if ( 0 !== strpos( strtolower( $property ), 'og:' ) ) {
				continue;
			}
			$content = $this->extract_attr( $attrs, 'content' );
			if ( '' === $content ) {
				continue;
			}
			$key = strtolower( $property );
			// First occurrence wins (multiple og:image collapsed to first).
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $content;
			}
		}

		return $out;
	}

	/**
	 * Extract a named HTML attribute value from a tag's attribute fragment.
	 * Handles double, single, and unquoted attribute values.
	 *
	 * Matches `name="..."`, `name='...'`, `name=...`. Returns the empty
	 * string when the attribute is absent.
	 */
	private function extract_attr( string $attrs, string $name ): string {
		$pattern = '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i';
		if ( ! preg_match( $pattern, $attrs, $m ) ) {
			return '';
		}
		// preg_match populates unmatched alternatives with empty strings,
		// not unset entries — so `??` doesn't fall through. Walk the
		// three capture groups and return the first non-empty match.
		// (Empty content="" was filtered upstream; here an empty $m[1]
		// just means the double-quoted alternative didn't match.)
		foreach ( array( 1, 2, 3 ) as $idx ) {
			if ( isset( $m[ $idx ] ) && '' !== $m[ $idx ] ) {
				return (string) $m[ $idx ];
			}
		}
		return '';
	}

	/**
	 * Decode HTML entities the way a browser would for an attribute value.
	 * ENT_QUOTES handles &quot; and &apos;; ENT_HTML5 handles &hellip; etc.
	 */
	private static function decode_html_entities( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
