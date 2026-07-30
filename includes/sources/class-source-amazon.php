<?php
/**
 * Outpost_Source_Amazon
 *
 * Per Doc 2 §3.9 — Amazon aggressively blocks automated fetches;
 * OG tags exist on product pages but are best-effort. The Product
 * Advertising API requires Associates approval + signed requests
 * which isn't viable for a free WP.org plugin. Auto-route to
 * Bookmark mode with p-name (product title) + u-photo (cover) +
 * u-bookmark-of (cleaned URL).
 *
 * URL forms claimed (path-constrained, multi-region):
 *
 *   - amazon.com / amazon.co.uk / amazon.de / amazon.ca / amazon.com.au
 *   - /dp/{ASIN}
 *   - /gp/product/{ASIN}
 *
 * Wishlist URLs (`/hz/wishlist/`), search URLs, and category URLs
 * are NOT claimed — they aren't single-product events.
 *
 * AFFILIATE-TAG STRIPPING:
 *
 * Amazon share-sheet URLs frequently carry `?tag=xxx-20` affiliate
 * parameters that route earnings to the original sharer. Recording
 * these on a personal blog leaks the third-party's commission
 * relationship + tracks the user's referral source. The adapter
 * strips known affiliate / tracking parameters at recipe_for_url
 * time so the URL written to u-bookmark-of is the clean canonical
 * form. Site owners who want their OWN affiliate tag can override
 * via the `outpost_amazon_affiliate_tag` filter.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Amazon extends Outpost_Source_Base {

	public const ID = 'amazon';

	private const HOST_SUFFIXES = array(
		'amazon.com',
		'amazon.co.uk',
		'amazon.de',
		'amazon.ca',
		'amazon.com.au',
		'amazon.fr',
		'amazon.it',
		'amazon.es',
		'amazon.co.jp',
	);

	private const CLAIMED_PATH_PREFIXES = array( '/dp/', '/gp/product/' );

	/** Query parameters known to be affiliate / tracking values. */
	private const STRIP_QUERY_KEYS = array(
		'tag',
		'linkCode',
		'linkId',
		'ref',
		'ref_',
		'ascsubtag',
		'creativeASIN',
		'th',
		'psc',
		'qid',
		'sr',
		'pd_rd_w',
		'pd_rd_r',
		'pd_rd_wg',
		'pf_rd_p',
		'pf_rd_r',
		'_encoding',
		'smid',
	);

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Amazon', 'outpost-mobile-publishing' ),
			// Stored host_patterns shape is "exact host" per Source_Base
			// validate_pattern; we match each region via matches_url
			// since we want suffix matching across region TLDs.
			'host_patterns'    => array_map(
				static fn ( string $suffix ): string => 'www.' . $suffix,
				self::HOST_SUFFIXES
			),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'bookmark',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'og_tags',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'og:title'    => 'p-name',
				'og:image'    => 'u-photo',
				'@source_url' => 'u-bookmark-of',
			),
			'h_entry_property' => 'u-bookmark-of',
			'auth_required'    => false,
			'tags_default'     => array( 'bookmark', 'product' ),
			'caveats'          => array(
				__( 'Best-effort OG extraction. Amazon blocks some automated fetches; some product pages may yield empty results.', 'outpost-mobile-publishing' ),
				__( 'Affiliate / tracking query parameters are stripped from the recorded URL.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to support host-suffix matching (every region TLD plus
	 * the bare apex) AND to constrain to single-product paths.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! $this->host_is_amazon( $host ) ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		foreach ( self::CLAIMED_PATH_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) && strlen( $path ) > strlen( $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Override recipe_for_url to inject the cleaned URL — strips
	 * affiliate / tracking parameters before B2 fetches and before
	 * the source URL gets recorded as u-bookmark-of.
	 *
	 * @param string $url URL the user shared.
	 * @return array<string,mixed>
	 */
	public function recipe_for_url( string $url ): array {
		$caps   = $this->capabilities();
		$recipe = is_array( $caps['recipe'] ?? null ) ? $caps['recipe'] : array();
		// The fetch_url field is set by the preview endpoint via
		// compute_fetch_url(); recording a clean URL is the recipe's
		// concern — stash the cleaned form so callers can read it.
		$recipe['canonical_url'] = self::strip_affiliate_params( $url );
		return $recipe;
	}

	/**
	 * Strip known affiliate / tracking parameters from an Amazon URL.
	 * Public so tests can assert the cleaning logic in isolation.
	 *
	 * @param string $url URL to clean.
	 */
	public static function strip_affiliate_params( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		if ( empty( $parts['query'] ) ) {
			return $url;
		}
		parse_str( (string) $parts['query'], $query );
		// parse_str always populates $query as array; PHPStan narrows
		// the type, no defensive guard needed.
		foreach ( self::STRIP_QUERY_KEYS as $key ) {
			unset( $query[ $key ] );
		}
		$rebuilt_query = http_build_query( $query );
		$scheme        = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$user          = isset( $parts['user'] ) ? $parts['user'] : '';
		$pass          = isset( $parts['pass'] ) ? ':' . $parts['pass'] : '';
		$user_pass     = '' !== $user ? $user . $pass . '@' : '';
		$host          = (string) $parts['host'];
		$port          = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path          = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query_part    = '' !== $rebuilt_query ? '?' . $rebuilt_query : '';
		$fragment      = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		return $scheme . $user_pass . $host . $port . $path . $query_part . $fragment;
	}

	private function host_is_amazon( string $host ): bool {
		foreach ( self::HOST_SUFFIXES as $suffix ) {
			if ( $suffix === $host || 'www.' . $suffix === $host || 'smile.' . $suffix === $host ) {
				return true;
			}
		}
		return false;
	}
}
