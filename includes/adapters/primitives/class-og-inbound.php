<?php
/**
 * Outpost_Og_Inbound (G4).
 *
 * Generic Open Graph + JSON-LD inbound extractor primitive. A higher-
 * level wrapper around Outpost_Source_Extractor_Og_Tags + a JSON-LD
 * schema.org parser, plus category-specific extractors registered via
 * filter. Phase G adapters (G10 scripture cluster, G11 wellness, G12
 * cycling/climbing, G13 conference, G14 maker) consume this primitive
 * for any URL that emits OG tags + optionally JSON-LD.
 *
 * RESPONSE SHAPE
 *
 * fetch() returns a fixed associative array on success:
 *
 *     [
 *         'title'           => string,
 *         'description'     => string,
 *         'image'           => string|null,        // resolved absolute URL
 *         'site_name'       => string|null,
 *         'type'            => string|null,        // og:type
 *         'schema_org_data' => array,              // first matching JSON-LD block
 *         'raw_meta'        => array,              // all og:* + twitter:* keys
 *         'fetched_at'      => string,             // ISO 8601
 *         'source_url'      => string,             // post-redirect resolved URL
 *     ]
 *
 * Failure returns WP_Error with codes outpost_og_invalid_url,
 * outpost_og_fetch_failed, outpost_og_parse_failed.
 *
 * CACHING
 *
 * 1-hour transient keyed `outpost_og_inbound_` + md5(strtolower($url)).
 * Filterable TTL via `outpost_og_inbound_cache_ttl` (returns int seconds).
 * Pass `force_refresh => true` in the args array to bypass cache.
 *
 * USER-AGENT
 *
 * `Outpost/{version} (+https://github.com/courtneyr-dev/outpost)` by default.
 * Filterable via `outpost_og_inbound_user_agent`.
 *
 * NO ROBOTS.TXT CHECK
 *
 * Outpost only fetches URLs the user has explicitly shared with intent
 * to syndicate. Robots.txt is for crawlers; Outpost is acting on the
 * user's behalf. Justified per G4 design decision #5.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Og_Inbound {

	private const CACHE_PREFIX = 'outpost_og_inbound_';

	private const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;

	private const DEFAULT_TIMEOUT = 10;

	private const MAX_REDIRECTS = 5;

	/**
	 * Schema extractors registered via Outpost_Og_Inbound::register_extractor.
	 *
	 * @var array<string,Outpost_Schema_Extractor>
	 */
	private static array $extractors = array();

	/**
	 * Register a schema extractor. Built-in extractors register themselves
	 * on plugins_loaded; third-party adapters can register additional
	 * extractors via the same mechanism or via the
	 * `outpost_og_extractors` filter.
	 *
	 * @since 0.1.69
	 *
	 * @param Outpost_Schema_Extractor $extractor Extractor instance.
	 */
	public static function register_extractor( Outpost_Schema_Extractor $extractor ): void {
		foreach ( $extractor->supported_types() as $type ) {
			self::$extractors[ $type ] = $extractor;
		}
	}

	/**
	 * Reset registered extractors. Test seam.
	 *
	 * @since 0.1.69
	 */
	public static function reset_extractors_for_tests(): void {
		self::$extractors = array();
	}

	/**
	 * Fetch a URL, parse OG + JSON-LD, dispatch to category extractors,
	 * and return the assembled response shape.
	 *
	 * @since 0.1.69
	 *
	 * @param string               $url  URL to fetch.
	 * @param array<string,mixed>  $args Optional. `force_refresh` bypasses cache.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function fetch( string $url, array $args = array() ) {
		$url = trim( $url );
		if ( '' === $url || false === wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'outpost_og_invalid_url',
				__( 'Invalid or unsupported URL.', 'outpost' ),
				array( 'url' => $url )
			);
		}

		$cache_key     = self::CACHE_PREFIX . md5( strtolower( $url ) );
		$force_refresh = ! empty( $args['force_refresh'] );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::filter_timeout(),
				'redirection' => self::MAX_REDIRECTS,
				'user-agent'  => self::filter_user_agent(),
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'outpost_og_fetch_failed',
				/* translators: %s: error message from wp_remote_get */
				sprintf( __( 'OG fetch failed: %s', 'outpost' ), $response->get_error_message() ),
				array( 'url' => $url )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'outpost_og_fetch_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'OG fetch returned HTTP %d.', 'outpost' ), $status ),
				array(
					'url'    => $url,
					'status' => $status,
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error(
				'outpost_og_parse_failed',
				__( 'OG fetch returned empty body.', 'outpost' ),
				array( 'url' => $url )
			);
		}

		// Final URL after redirects, if exposed by the transport.
		$final_url = $url;
		$headers   = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$all = $headers->getAll();
			if ( isset( $all['x-final-url'] ) ) {
				$final_url = (string) $all['x-final-url'];
			}
		}

		$result = self::parse_html_to_response( $body, $url, $final_url );
		set_transient( $cache_key, $result, self::filter_cache_ttl() );

		return $result;
	}

	/**
	 * Parse an HTML body into the response shape. Public for unit-test
	 * access — tests bypass HTTP via this entry point.
	 *
	 * @since 0.1.69
	 *
	 * @param string $body         HTML body.
	 * @param string $original_url Original input URL.
	 * @param string $final_url    Final URL after any redirects.
	 * @return array<string,mixed>
	 */
	public static function parse_html_to_response( string $body, string $original_url, string $final_url ): array {
		$raw_meta = self::parse_meta_tags( $body );
		$jsonld   = self::parse_jsonld_blocks( $body );

		$schema_data = self::dispatch_to_extractors( $jsonld, $final_url );

		$image = self::resolve_image( $raw_meta, $final_url );

		return array(
			'title'           => (string) ( $raw_meta['og:title'] ?? $raw_meta['twitter:title'] ?? '' ),
			'description'     => (string) ( $raw_meta['og:description'] ?? $raw_meta['twitter:description'] ?? '' ),
			'image'           => $image,
			'site_name'       => isset( $raw_meta['og:site_name'] ) ? (string) $raw_meta['og:site_name'] : null,
			'type'            => isset( $raw_meta['og:type'] ) ? (string) $raw_meta['og:type'] : null,
			'schema_org_data' => $schema_data,
			'raw_meta'        => array_merge(
				$raw_meta,
				array( '_original_url' => $original_url )
			),
			'fetched_at'      => gmdate( 'c' ),
			'source_url'      => $final_url,
		);
	}

	/**
	 * Parse og:* and twitter:* meta tags from an HTML body via DOMDocument.
	 * libxml errors suppressed because real-world HTML is rarely strictly
	 * valid; we only need the meta tags.
	 *
	 * @param string $body HTML body.
	 * @return array<string,string>
	 */
	private static function parse_meta_tags( string $body ): array {
		$meta = array();
		if ( '' === trim( $body ) ) {
			return $meta;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		// loadHTML wants ISO-8859-1 by default; prefix with charset hint.
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $body, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$tags = $dom->getElementsByTagName( 'meta' );
		foreach ( $tags as $tag ) {
			if ( ! $tag instanceof \DOMElement ) {
				continue;
			}
			$key = $tag->getAttribute( 'property' );
			if ( '' === $key ) {
				$key = $tag->getAttribute( 'name' );
			}
			if ( '' === $key ) {
				continue;
			}
			if ( 0 !== strpos( $key, 'og:' ) && 0 !== strpos( $key, 'twitter:' ) ) {
				continue;
			}
			if ( isset( $meta[ $key ] ) ) {
				continue;
			}
			$value        = $tag->getAttribute( 'content' );
			$meta[ $key ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $meta;
	}

	/**
	 * Parse application/ld+json blocks from an HTML body.
	 *
	 * @param string $body HTML body.
	 * @return array<int,array<string,mixed>>
	 */
	private static function parse_jsonld_blocks( string $body ): array {
		$blocks = array();
		if ( preg_match_all( '~<script\b[^>]*\btype\s*=\s*"application/ld\+json"[^>]*>(.*?)</script>~is', $body, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$decoded = json_decode( trim( $raw ), true );
				if ( is_array( $decoded ) ) {
					// Some sites wrap in @graph; flatten one level.
					if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
						foreach ( $decoded['@graph'] as $item ) {
							if ( is_array( $item ) ) {
								$blocks[] = $item;
							}
						}
					} else {
						$blocks[] = $decoded;
					}
				}
			}
		}
		return $blocks;
	}

	/**
	 * Dispatch JSON-LD blocks to registered extractors. The first block
	 * whose @type matches a registered extractor wins; if multiple
	 * extractors claim the same type, the higher-priority extractor wins.
	 *
	 * @param array<int,array<string,mixed>> $blocks JSON-LD blocks.
	 * @param string                         $url    Source URL.
	 * @return array<string,mixed>
	 */
	private static function dispatch_to_extractors( array $blocks, string $url ): array {
		// Resolve filter-registered extractors.
		/**
		 * Filter the schema extractor map.
		 *
		 * @param array<string,Outpost_Schema_Extractor> $extractors Map @type => extractor.
		 */
		$filtered   = apply_filters( 'outpost_og_extractors', self::$extractors );
		$extractors = is_array( $filtered ) ? $filtered : self::$extractors;

		foreach ( $blocks as $block ) {
			$type = $block['@type'] ?? null;
			if ( is_array( $type ) ) {
				$type = $type[0] ?? null;
			}
			if ( ! is_string( $type ) ) {
				continue;
			}
			if ( isset( $extractors[ $type ] ) ) {
				$extractor = $extractors[ $type ];
				if ( $extractor instanceof Outpost_Schema_Extractor ) {
					return $extractor->extract( $block, $url );
				}
			}
		}
		return array();
	}

	/**
	 * Resolve the og:image (or twitter:image) value to an absolute URL.
	 *
	 * @param array<string,string> $meta      Raw meta map.
	 * @param string               $final_url Final URL after redirects.
	 * @return string|null
	 */
	private static function resolve_image( array $meta, string $final_url ): ?string {
		$image = $meta['og:image'] ?? $meta['twitter:image'] ?? '';
		$image = trim( (string) $image );
		if ( '' === $image ) {
			return null;
		}
		// Already absolute.
		if ( 0 === strpos( $image, 'http://' ) || 0 === strpos( $image, 'https://' ) ) {
			return $image;
		}
		$parts = wp_parse_url( $final_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = $parts['scheme'] ?? 'https';
		if ( 0 === strpos( $image, '//' ) ) {
			return $scheme . ':' . $image;
		}
		if ( 0 === strpos( $image, '/' ) ) {
			return $scheme . '://' . $parts['host'] . $image;
		}
		return $scheme . '://' . $parts['host'] . '/' . ltrim( $image, '/' );
	}

	/**
	 * Resolved cache TTL via filter.
	 */
	private static function filter_cache_ttl(): int {
		/** @var mixed $ttl */
		$ttl = apply_filters( 'outpost_og_inbound_cache_ttl', self::DEFAULT_CACHE_TTL );
		return max( 60, (int) $ttl );
	}

	/**
	 * Resolved timeout via filter.
	 */
	private static function filter_timeout(): int {
		/** @var mixed $t */
		$t = apply_filters( 'outpost_og_inbound_timeout', self::DEFAULT_TIMEOUT );
		return max( 1, (int) $t );
	}

	/**
	 * Resolved user-agent via filter.
	 */
	private static function filter_user_agent(): string {
		$version = defined( 'OUTPOST_VERSION' ) ? (string) OUTPOST_VERSION : 'dev';
		$default = sprintf(
			'Outpost/%s (+https://github.com/courtneyr-dev/outpost)',
			$version
		);
		/** @var mixed $ua */
		$ua = apply_filters( 'outpost_og_inbound_user_agent', $default );
		return is_string( $ua ) && '' !== $ua ? $ua : $default;
	}
}
