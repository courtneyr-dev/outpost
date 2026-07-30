<?php
/**
 * Outpost_Source_Mastodon
 *
 * Mastodon post URLs follow `{instance}/@{user}/{id}` across thousands
 * of instances. Per-instance host patterns aren't tractable — instead
 * the adapter overrides matches_url with hostname-suffix heuristics
 * that catch the most common fediverse TLD patterns:
 *
 *   - `*.social` (mastodon.social, indieweb.social, hachyderm.io is .io
 *     so excluded by this heuristic — see filter below)
 *   - `*.cloud`
 *   - `*.online`
 *   - `*.network`
 *
 * Plus a filterable allowlist (`outpost_mastodon_allowed_hosts`) for
 * sites whose TLDs don't fit the heuristic (hachyderm.io, mas.to,
 * fosstodon.org, etc.). The Bridgy F14 detector uses the same
 * pattern; this adapter mirrors it for inbound dispatch.
 *
 * Mode is unambiguous Reply — sharing someone else's Mastodon post
 * to your own site is fundamentally a Reply / commentary action.
 *
 * Mastodon post pages emit OG tags:
 *
 *   - og:title (the post text, truncated)
 *   - og:description (sometimes; fall back to og:title)
 *   - og:image (post media or author avatar)
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Mastodon extends Outpost_Source_Base {

	public const ID = 'mastodon';

	private const SUFFIX_HEURISTIC_TLDS = array( '.social', '.cloud', '.online', '.network' );

	/**
	 * Default allowlist of well-known Mastodon instances whose TLDs
	 * don't match the suffix heuristic. Site owners extend via the
	 * `outpost_mastodon_allowed_hosts` filter.
	 *
	 * @return string[]
	 */
	private static function default_allowed_hosts(): array {
		return array(
			'mastodon.social',
			'mas.to',
			'hachyderm.io',
			'fosstodon.org',
			'indieweb.social',
			'tech.lgbt',
			'infosec.exchange',
			'home.social',
			'me.dm',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		// host_patterns lists representative hosts for chip surfacing /
		// debugging; matches_url() is the actual gate. The pattern
		// matcher accepts the suffix-wildcard forms below.
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Mastodon', 'outpost-mobile-publishing' ),
			'host_patterns'    => array(
				'*.social',
				'*.cloud',
				'*.online',
				'*.network',
			),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'reply',
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
				'@source_url'    => 'u-in-reply-to',
			),
			'h_entry_property' => 'u-in-reply-to',
			'auth_required'    => false,
			'tags_default'     => array( 'reply' ),
			'caveats'          => array(
				__( 'Mastodon detection is heuristic; the outpost_mastodon_allowed_hosts filter extends recognition to instances whose TLDs do not match the default suffix patterns.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override matches_url with hostname-suffix heuristics + filterable
	 * allowlist. Path constraint: must include `/@{user}/` segment.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		// Path must contain @user pattern — this is the universal
		// Mastodon post URL shape across instances.
		if ( 1 !== preg_match( '~/@[^/]+/[0-9]+~', $path ) ) {
			return false;
		}

		// Heuristic 1: well-known instance allowlist.
		/**
		 * Filter the allowlist of Mastodon instance hostnames.
		 *
		 * @param string[] $hosts Default allowlist.
		 */
		$allowed = apply_filters( 'outpost_mastodon_allowed_hosts', self::default_allowed_hosts() );
		if ( is_array( $allowed ) && in_array( $host, $allowed, true ) ) {
			return true;
		}

		// Heuristic 2: TLD suffix match.
		foreach ( self::SUFFIX_HEURISTIC_TLDS as $suffix ) {
			if ( strlen( $host ) > strlen( $suffix )
				&& substr( $host, -strlen( $suffix ) ) === $suffix
			) {
				return true;
			}
		}

		return false;
	}
}
