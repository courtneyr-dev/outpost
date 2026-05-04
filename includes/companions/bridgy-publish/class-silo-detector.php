<?php
/**
 * Outpost_Bridgy_Publish_Silo_Detector
 *
 * Host-pattern utility that maps a URL to one of the five F14
 * Bridgy Publish silo IDs ('mastodon' | 'bluesky' | 'flickr' |
 * 'github' | 'reddit') or null. Used by composer-side flows that
 * detect "the user pasted a URL — which Bridgy silo is this?"
 * to suggest an appropriate Bridgy chip.
 *
 * Mastodon detection is fuzzy by nature — there's no centralised
 * list of Mastodon instances, and any host could host a Mastodon
 * server. F14's detector matches the most common patterns and exposes
 * a filter (`outpost_bridgy_mastodon_hosts`) so site owners can
 * extend with the specific instances they care about.
 *
 * Static-only; no instance state.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Silo_Detector {

	private const BLUESKY_HOSTS        = array( 'bsky.app', 'bsky.social' );
	private const FLICKR_HOST_SUFFIXES = array( 'flickr.com' );
	private const GITHUB_HOST_SUFFIXES = array( 'github.com' );
	private const REDDIT_HOST_SUFFIXES = array( 'reddit.com', 'redd.it' );

	/**
	 * Detect which Bridgy silo a URL belongs to.
	 *
	 * @param string $url Any http(s) URL.
	 * @return string|null One of 'mastodon' / 'bluesky' / 'flickr' /
	 *                     'github' / 'reddit', or null.
	 */
	public static function detect_silo( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );

		if ( in_array( $host, self::BLUESKY_HOSTS, true ) ) {
			return 'bluesky';
		}
		if ( self::host_matches_suffix( $host, self::FLICKR_HOST_SUFFIXES ) ) {
			return 'flickr';
		}
		if ( self::host_matches_suffix( $host, self::GITHUB_HOST_SUFFIXES ) ) {
			return 'github';
		}
		if ( self::host_matches_suffix( $host, self::REDDIT_HOST_SUFFIXES ) ) {
			return 'reddit';
		}
		if ( self::looks_like_mastodon( $host ) ) {
			return 'mastodon';
		}
		return null;
	}

	/**
	 * Whether the host matches a Mastodon instance pattern. Combines:
	 *
	 *   - Common Mastodon TLD suffixes (.social, .online, etc.)
	 *   - Site-owner-extensible allowlist via the
	 *     `outpost_bridgy_mastodon_hosts` filter
	 *
	 * Defensively false-prone (over-matching `.social` is fine since
	 * the user always opts into Bridgy chips explicitly via settings;
	 * the detector is just a hint).
	 */
	public static function looks_like_mastodon( string $host ): bool {
		$host = strtolower( $host );

		// Filter-supplied explicit hosts win first.
		$filter_hosts = apply_filters( 'outpost_bridgy_mastodon_hosts', array() );
		if ( is_array( $filter_hosts ) ) {
			foreach ( $filter_hosts as $candidate ) {
				if ( ! is_string( $candidate ) ) {
					continue;
				}
				if ( strtolower( $candidate ) === $host ) {
					return true;
				}
			}
		}

		// Fuzzy TLD-suffix match for common Mastodon hosting patterns.
		$mastodon_suffixes = array( '.social', '.cloud', '.online', '.network' );
		foreach ( $mastodon_suffixes as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $suffixes
	 */
	private static function host_matches_suffix( string $host, array $suffixes ): bool {
		foreach ( $suffixes as $suffix ) {
			if ( $host === $suffix ) {
				return true;
			}
			$dotted = '.' . $suffix;
			if ( substr( $host, -strlen( $dotted ) ) === $dotted ) {
				return true;
			}
		}
		return false;
	}
}
