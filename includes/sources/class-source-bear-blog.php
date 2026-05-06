<?php
/**
 * Outpost_Source_Bear_Blog (G6).
 *
 * Bear Blog is a minimalist blogging platform; user blogs run at
 * `{slug}.bearblog.dev`. The platform supports custom domains too —
 * the `outpost_bear_blog_domain_patterns` filter lets site owners
 * extend recognition to their own Bear-hosted custom domains.
 *
 * Inbound capture mode: read (URL → u-read-of). Bear posts are
 * essays / reading material; bookmark / quote variants live one tab
 * over for the user to switch to in the composer if desired.
 *
 * Note on RSS: G6 prompt specifies RSS-as-primary with OG fallback,
 * but the F-phase RSS extractor stub (F5 #6) is not yet implemented.
 * Phase G G6a ships og_tags-only (matching F17 Substack pattern);
 * RSS-as-primary lands alongside the RSS extractor implementation.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Bear_Blog extends Outpost_Source_Base {

	public const ID = 'bear-blog';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Bear Blog', 'outpost' ),
			'host_patterns'    => array( '*.bearblog.dev' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'read',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'og_tags',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'og:title'       => 'p-name',
				'og:description' => 'p-summary',
				'og:image'       => 'u-photo',
				'@source_url'    => 'u-read-of',
			),
			'h_entry_property' => 'u-read-of',
			'auth_required'    => false,
			'tags_default'     => array( 'read' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to support self-hosted Bear instances on custom domains.
	 * Site owners declare additional patterns via the
	 * `outpost_bear_blog_domain_patterns` filter — same shape as
	 * Source_Base host_patterns (exact host or `*.suffix`).
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		if ( parent::matches_url( $url ) ) {
			return true;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		/**
		 * Filter the additional host patterns Bear Blog claims.
		 *
		 * Site owners running a self-hosted Bear instance at a custom
		 * domain extend this list to surface inbound captures from
		 * their Bear posts.
		 *
		 * @param string[] $patterns Default empty; users append.
		 */
		$patterns = apply_filters( 'outpost_bear_blog_domain_patterns', array() );
		if ( ! is_array( $patterns ) ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( $host === $pattern ) {
				return true;
			}
			if ( 0 === strpos( $pattern, '*.' ) ) {
				$suffix = substr( $pattern, 1 );
				if ( strlen( $host ) > strlen( $suffix )
					&& substr( $host, -strlen( $suffix ) ) === $suffix
				) {
					return true;
				}
			}
		}
		return false;
	}
}
