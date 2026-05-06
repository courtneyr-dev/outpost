<?php
/**
 * Outpost_Rss_Inbound (F5 #6).
 *
 * Generic RSS / Atom feed inbound extractor primitive. Higher-level
 * wrapper around WordPress's bundled SimplePie (`fetch_feed()`). Two
 * operating modes:
 *
 *   Mode A — URL → feed → entry. Fetch the URL's HTML, find a feed
 *   `<link rel="alternate">`, fetch the feed, locate the entry whose
 *   permalink matches the original URL.
 *
 *   Mode B — Direct feed URL + entry GUID. Skip discovery; fetch the
 *   feed and look up the entry by GUID.
 *
 * Concrete platform sources (Working Preacher RSS, Universalis RSS,
 * Bear Blog feeds in G6, etc.) call into Mode B once they know the
 * feed URL. Source_Unknown-style "user pasted any URL" flows call
 * Mode A.
 *
 * RESPONSE SHAPE
 *
 * On success returns an associative array:
 *
 *     [
 *         'extracted'  => true,
 *         'title'      => string,
 *         'content'    => string,         // sanitized via wp_kses_post
 *         'summary'    => ?string,
 *         'author'     => ?string,
 *         'author_url' => ?string,
 *         'published'  => ?string,        // ISO 8601 UTC
 *         'updated'    => ?string,        // ISO 8601 UTC
 *         'link'       => string,
 *         'categories' => string[],
 *         'guid'       => string,
 *         'icon_url'   => ?string,        // media:thumbnail
 *         'feed_url'   => string,
 *         'feed_title' => string,
 *     ]
 *
 * On failure returns:
 *
 *     [
 *         'extracted' => false,
 *         'reason'    => 'no_feed_link' | 'no_matching_feed_entry' | 'transport_failed' | 'malformed_feed' | 'entry_not_in_feed',
 *     ]
 *
 * CACHING — uses SimplePie's built-in 12h cache via WP's fetch_feed.
 *
 * NAMESPACES — reads media:thumbnail and dc:creator. Other XML
 * namespaces deferred until a registered platform needs them.
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Rss_Inbound {

	/**
	 * SimplePie XML namespace constants used by entry extraction.
	 */
	private const NS_MEDIA = 'http://search.yahoo.com/mrss/';
	private const NS_DC    = 'http://purl.org/dc/elements/1.1/';

	/**
	 * Test seam: closure that returns the SimplePie feed for a URL.
	 * Production uses fetch_feed; tests inject a fixture loader.
	 *
	 * @var (callable(string):mixed)|null
	 */
	private static $feed_resolver = null;

	/**
	 * Test seam: closure that returns the HTML body for a URL.
	 *
	 * @var (callable(string):?string)|null
	 */
	private static $page_resolver = null;

	/**
	 * Override the feed-resolution function for testing.
	 *
	 * @since 0.1.79
	 *
	 * @param (callable(string):mixed)|null $resolver Returns SimplePie or WP_Error.
	 */
	public static function set_feed_resolver_for_tests( ?callable $resolver ): void {
		self::$feed_resolver = $resolver;
	}

	/**
	 * Override the page-fetch function for testing.
	 *
	 * @since 0.1.79
	 *
	 * @param (callable(string):?string)|null $resolver Returns HTML body or null.
	 */
	public static function set_page_resolver_for_tests( ?callable $resolver ): void {
		self::$page_resolver = $resolver;
	}

	/**
	 * Mode A: extract a feed entry from any URL by discovering the
	 * feed link in the URL's HTML.
	 *
	 * @since 0.1.79
	 *
	 * @param string $url The URL the user shared.
	 * @return array<string,mixed>
	 */
	public static function extract_from_url( string $url ): array {
		$html = self::fetch_page( $url );
		if ( null === $html || '' === $html ) {
			return self::failure( 'transport_failed' );
		}

		$feed_url = self::discover_feed_link( $html );
		if ( null === $feed_url ) {
			return self::failure( 'no_feed_link' );
		}

		$feed = self::fetch_feed_internal( $feed_url );
		if ( ! self::is_valid_feed( $feed ) ) {
			return self::failure( 'malformed_feed' );
		}

		$item = self::find_item_by_link( $feed, $url );
		if ( null === $item ) {
			return self::failure( 'no_matching_feed_entry' );
		}

		return self::extract_item( $item, $feed_url, self::feed_title( $feed ) );
	}

	/**
	 * Mode B: extract an entry from a known feed by entry GUID.
	 *
	 * @since 0.1.79
	 *
	 * @param string $feed_url   Direct feed URL.
	 * @param string $entry_guid Entry id matching SimplePie_Item::get_id.
	 * @return array<string,mixed>
	 */
	public static function extract_from_feed( string $feed_url, string $entry_guid ): array {
		$feed = self::fetch_feed_internal( $feed_url );
		if ( ! self::is_valid_feed( $feed ) ) {
			return self::failure( 'transport_failed' );
		}

		$item = self::find_item_by_guid( $feed, $entry_guid );
		if ( null === $item ) {
			return self::failure( 'entry_not_in_feed' );
		}

		return self::extract_item( $item, $feed_url, self::feed_title( $feed ) );
	}

	// ---------- Item extraction ----------

	/**
	 * Project a SimplePie_Item into the canonical extracted shape.
	 *
	 * @since 0.1.79
	 *
	 * @param object $item       SimplePie_Item or duck-typed test stub.
	 * @param string $feed_url   Source feed URL.
	 * @param string $feed_title Feed-level title.
	 * @return array<string,mixed>
	 */
	private static function extract_item( object $item, string $feed_url, string $feed_title ): array {
		$content    = self::pick_content( $item );
		$summary    = self::pick_summary( $item );
		$author     = self::pick_author( $item );
		$published  = self::pick_published( $item );
		$updated    = self::pick_updated( $item );
		$categories = self::pick_categories( $item );
		$icon_url   = self::pick_icon_url( $item );

		return array(
			'extracted'  => true,
			'title'      => self::scalar_string( self::call_if_exists( $item, 'get_title', '' ) ),
			'content'    => '' === $content ? '' : self::sanitize_content( $content ),
			'summary'    => null === $summary ? null : self::scalar_string( $summary ),
			'author'     => $author['name'],
			'author_url' => $author['url'],
			'published'  => $published,
			'updated'    => $updated,
			'link'       => self::scalar_string( self::call_if_exists( $item, 'get_permalink', '' ) ),
			'categories' => $categories,
			'guid'       => self::scalar_string( self::call_if_exists( $item, 'get_id', '' ) ),
			'icon_url'   => $icon_url,
			'feed_url'   => $feed_url,
			'feed_title' => $feed_title,
		);
	}

	/**
	 * @param object $item SimplePie_Item or test stub.
	 */
	private static function pick_content( object $item ): string {
		// SimplePie's get_content() prefers content:encoded then content then description.
		$content = self::scalar_string( self::call_if_exists( $item, 'get_content', '' ) );
		if ( '' !== $content ) {
			return $content;
		}
		return self::scalar_string( self::call_if_exists( $item, 'get_description', '' ) );
	}

	/**
	 * @param object $item SimplePie_Item or test stub.
	 */
	private static function pick_summary( object $item ): ?string {
		$description = self::scalar_string( self::call_if_exists( $item, 'get_description', '' ) );
		if ( '' === $description ) {
			return null;
		}
		// If get_content also returns the same value, the entry has no
		// distinct summary — surface null rather than duplicating content.
		$content = self::scalar_string( self::call_if_exists( $item, 'get_content', '' ) );
		if ( $description === $content ) {
			return null;
		}
		return $description;
	}

	/**
	 * Author resolution: <author> → <dc:creator> → null.
	 *
	 * @param object $item SimplePie_Item or test stub.
	 * @return array{name: ?string, url: ?string}
	 */
	private static function pick_author( object $item ): array {
		$author_obj = self::call_if_exists( $item, 'get_author', null );
		if ( is_object( $author_obj ) ) {
			$name = self::scalar_string( self::call_if_exists( $author_obj, 'get_name', '' ) );
			$url  = self::scalar_string( self::call_if_exists( $author_obj, 'get_link', '' ) );
			if ( '' !== $name || '' !== $url ) {
				return array(
					'name' => '' !== $name ? $name : null,
					'url'  => '' !== $url ? $url : null,
				);
			}
		}

		// dc:creator fallback.
		$dc = self::namespaced_value( $item, self::NS_DC, 'creator' );
		return array(
			'name' => null === $dc ? null : $dc,
			'url'  => null,
		);
	}

	/**
	 * @param object $item SimplePie_Item or test stub.
	 */
	private static function pick_published( object $item ): ?string {
		$ts = self::call_if_exists( $item, 'get_date', null );
		if ( is_int( $ts ) && $ts > 0 ) {
			return gmdate( 'c', $ts );
		}
		// SimplePie's get_date can return a string when called with a
		// format; the no-arg variant returns int|null. Some test stubs
		// return ISO 8601 directly — pass that through.
		if ( is_string( $ts ) && '' !== $ts ) {
			return $ts;
		}
		return null;
	}

	/**
	 * @param object $item SimplePie_Item or test stub.
	 */
	private static function pick_updated( object $item ): ?string {
		$ts = self::call_if_exists( $item, 'get_updated_date', null );
		if ( is_int( $ts ) && $ts > 0 ) {
			return gmdate( 'c', $ts );
		}
		if ( is_string( $ts ) && '' !== $ts ) {
			return $ts;
		}
		return null;
	}

	/**
	 * @param object $item SimplePie_Item or test stub.
	 * @return string[]
	 */
	private static function pick_categories( object $item ): array {
		$cats = self::call_if_exists( $item, 'get_categories', null );
		if ( ! is_array( $cats ) ) {
			return array();
		}
		$out = array();
		foreach ( $cats as $cat ) {
			if ( is_object( $cat ) ) {
				$label = self::scalar_string( self::call_if_exists( $cat, 'get_label', '' ) );
				if ( '' !== $label ) {
					$out[] = $label;
				}
			} elseif ( is_string( $cat ) && '' !== $cat ) {
				$out[] = $cat;
			}
		}
		return $out;
	}

	/**
	 * @param object $item SimplePie_Item or test stub.
	 */
	private static function pick_icon_url( object $item ): ?string {
		// Prefer media:thumbnail @url attribute.
		$tags = self::call_if_exists( $item, 'get_item_tags', null, array( self::NS_MEDIA, 'thumbnail' ) );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				if ( is_array( $tag ) && isset( $tag['attribs'][''] ['url'] ) ) {
					$url = (string) $tag['attribs'][''] ['url'];
					if ( '' !== $url ) {
						return $url;
					}
				}
			}
		}
		// Fallback: <enclosure> with image-ish type.
		$enclosure = self::call_if_exists( $item, 'get_enclosure', null );
		if ( is_object( $enclosure ) ) {
			$type = self::scalar_string( self::call_if_exists( $enclosure, 'get_type', '' ) );
			if ( str_starts_with( $type, 'image/' ) ) {
				$link = self::scalar_string( self::call_if_exists( $enclosure, 'get_link', '' ) );
				if ( '' !== $link ) {
					return $link;
				}
			}
		}
		return null;
	}

	// ---------- Feed lookup ----------

	/**
	 * @param object $feed SimplePie or test stub.
	 */
	private static function find_item_by_link( object $feed, string $url ): mixed {
		$items = self::call_if_exists( $feed, 'get_items', array() );
		if ( ! is_array( $items ) ) {
			return null;
		}
		$normalized_target = self::normalize_url( $url );
		foreach ( $items as $item ) {
			$item_link = self::normalize_url( self::scalar_string( self::call_if_exists( $item, 'get_permalink', '' ) ) );
			if ( $item_link === $normalized_target ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param object $feed SimplePie or test stub.
	 */
	private static function find_item_by_guid( object $feed, string $guid ): mixed {
		$items = self::call_if_exists( $feed, 'get_items', array() );
		if ( ! is_array( $items ) ) {
			return null;
		}
		foreach ( $items as $item ) {
			if ( self::scalar_string( self::call_if_exists( $item, 'get_id', '' ) ) === $guid ) {
				return $item;
			}
		}
		return null;
	}

	// ---------- HTML feed-link discovery ----------

	/**
	 * Find the first `<link rel="alternate" type="application/{rss,atom}+xml">`
	 * in the supplied HTML. Returns null when none found.
	 *
	 * @since 0.1.79
	 */
	public static function discover_feed_link( string $html ): ?string {
		// Regex-based; light-touch parsing avoids DOMDocument warnings on
		// malformed HTML. Two-pass: capture every <link> tag, then
		// inspect attributes per tag (regardless of attribute order).
		// Order preference: RSS over Atom — purely arbitrary, both work.
		if ( ! preg_match_all( '/<link\b[^>]*>/i', $html, $matches ) ) {
			return null;
		}
		$rss_match  = null;
		$atom_match = null;
		foreach ( $matches[0] as $tag ) {
			if ( ! preg_match( '/\brel\s*=\s*["\']alternate["\']/i', $tag ) ) {
				continue;
			}
			if ( ! preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href_match ) ) {
				continue;
			}
			if ( ! preg_match( '/\btype\s*=\s*["\']([^"\']+)["\']/i', $tag, $type_match ) ) {
				continue;
			}
			$href = html_entity_decode( $href_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$type = strtolower( trim( $type_match[1] ) );
			if ( 'application/rss+xml' === $type && null === $rss_match ) {
				$rss_match = $href;
			} elseif ( 'application/atom+xml' === $type && null === $atom_match ) {
				$atom_match = $href;
			}
		}
		return $rss_match ?? $atom_match;
	}

	// ---------- Helpers ----------

	private static function fetch_page( string $url ): ?string {
		if ( null !== self::$page_resolver ) {
			return ( self::$page_resolver )( $url );
		}
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return is_array( $response ) ? (string) wp_remote_retrieve_body( $response ) : null;
	}

	/**
	 * @return mixed SimplePie object, WP_Error, or null.
	 */
	private static function fetch_feed_internal( string $feed_url ) {
		if ( null !== self::$feed_resolver ) {
			return ( self::$feed_resolver )( $feed_url );
		}
		if ( ! function_exists( 'fetch_feed' ) ) {
			$wpinc = defined( 'WPINC' ) ? constant( 'WPINC' ) : 'wp-includes';
			require_once ABSPATH . $wpinc . '/feed.php';
		}
		return fetch_feed( $feed_url );
	}

	/**
	 * @param mixed $feed SimplePie object, WP_Error, or null.
	 */
	private static function is_valid_feed( $feed ): bool {
		if ( ! is_object( $feed ) ) {
			return false;
		}
		// SimplePie objects expose get_items; WP_Error doesn't.
		return method_exists( $feed, 'get_items' ) || is_callable( array( $feed, 'get_items' ) );
	}

	/**
	 * @param object $feed SimplePie or test stub.
	 */
	private static function feed_title( object $feed ): string {
		return self::scalar_string( self::call_if_exists( $feed, 'get_title', '' ) );
	}

	/**
	 * @param object $item SimplePie_Item or test stub.
	 */
	private static function namespaced_value( object $item, string $xml_ns, string $tag ): ?string {
		$tags = self::call_if_exists( $item, 'get_item_tags', null, array( $xml_ns, $tag ) );
		if ( ! is_array( $tags ) ) {
			return null;
		}
		foreach ( $tags as $entry ) {
			if ( is_array( $entry ) && isset( $entry['data'] ) && is_string( $entry['data'] ) ) {
				$value = trim( $entry['data'] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return null;
	}

	/**
	 * Safely invoke a method on a duck-typed object. Returns the
	 * supplied fallback when the object is null, when the method is
	 * undefined, or when the method throws.
	 *
	 * @param mixed                $obj      Target (may be null/non-object).
	 * @param string               $method   Method name to invoke.
	 * @param mixed                $fallback Value returned on miss.
	 * @param array<int,mixed>     $args     Positional arguments.
	 * @return mixed
	 */
	private static function call_if_exists( $obj, string $method, $fallback, array $args = array() ) {
		if ( ! is_object( $obj ) ) {
			return $fallback;
		}
		if ( ! is_callable( array( $obj, $method ) ) ) {
			return $fallback;
		}
		try {
			$result = $obj->{$method}( ...$args );
		} catch ( \Throwable $e ) {
			unset( $e );
			return $fallback;
		}
		return null === $result ? $fallback : $result;
	}

	/**
	 * @param mixed $value Untyped input.
	 */
	private static function scalar_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function sanitize_content( string $html ): string {
		if ( function_exists( 'wp_kses_post' ) ) {
			return wp_kses_post( $html );
		}
		// Fallback in unit tests where wp_kses_post isn't loaded — strip
		// only the high-risk tags.
		return preg_replace( '#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html ) ?? $html;
	}

	private static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		// Strip a trailing slash + lowercase the scheme + host so
		// permalink-comparison ignores cosmetic variation.
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path   = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		$query  = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
		$port   = isset( $parts['port'] ) ? ':' . (string) $parts['port'] : '';
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * @return array{extracted: bool, reason: string}
	 */
	private static function failure( string $reason ): array {
		return array(
			'extracted' => false,
			'reason'    => $reason,
		);
	}
}
