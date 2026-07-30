<?php
/**
 * Outpost_Manual_Share_Syndication_Writeback
 *
 * Validates user-pasted silo URLs and writes them to the
 * `outpost_syndication_links` post-meta. The renderer reads from
 * post-meta to inject `u-syndication` anchors into post output;
 * Bridgy and other webmention consumers parse those anchors to
 * find the silo copy. F12's writeback closes the manual-share
 * two-phase loop (Doc 1 §4.2).
 *
 * Validation posture (CLAUDE.md F12 #4):
 *
 *   - Accept any http/https URL of any domain. Don't restrict to a
 *     pre-approved silo list — users may have edge cases (custom
 *     domains, archived posts, future silos).
 *   - Reject non-http schemes (file://, javascript:, data:) at the
 *     URL-parse layer.
 *   - Use `wp_http_validate_url` for SSRF protection (rejects
 *     localhost, RFC 1918, etc.).
 *   - Length-cap at 2048 chars (browser-typical URL limit).
 *   - Soft-warn on platform mismatch (URL domain doesn't match the
 *     platform_id's expected domain). Caller decides whether to
 *     accept the user's "save anyway" confirmation.
 *
 * Storage shape (`outpost_syndication_links`):
 *
 *     array(
 *         array(
 *             'platform_id' => 'instagram-feed',
 *             'url'         => 'https://www.instagram.com/p/abc123',
 *             'added_at'    => '2026-05-04T18:32:11+00:00',
 *             'source'      => 'manual_share',
 *         ),
 *         ...
 *     )
 *
 * Idempotence: writing a (platform_id, url) pair that already exists
 * updates `added_at` rather than appending a duplicate entry. Each
 * unique (platform_id, url) gets ONE record.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Syndication_Writeback {

	private const META_KEY       = 'outpost_syndication_links';
	private const SOURCE         = 'manual_share';
	private const URL_MAX_LENGTH = 2048;

	/**
	 * Expected domain (substring) per platform, for the soft mismatch
	 * warning. Conservative — only the top-level brand domain is
	 * checked. Platform variants (mobile.twitter.com, m.threads.net)
	 * still match. Unknown platforms (custom site-registered ones)
	 * skip the mismatch check entirely.
	 */
	private const EXPECTED_DOMAINS = array(
		'instagram-feed'    => 'instagram.com',
		'instagram-stories' => 'instagram.com',
		'facebook'          => 'facebook.com',
		'x-twitter'         => 'twitter.com',
		'linkedin'          => 'linkedin.com',
		'threads'           => 'threads.net',
		'tiktok'            => 'tiktok.com',
		'pinterest'         => 'pinterest.com',
		'reddit-manual'     => 'reddit.com',
		'flickr-manual'     => 'flickr.com',
	);

	/**
	 * Validate a user-pasted URL. Returns `true` when acceptable,
	 * `WP_Error` otherwise.
	 *
	 * @param string $url Raw URL string from the prompt.
	 * @return true|WP_Error
	 */
	public static function validate_url( string $url ) {
		$trimmed = trim( $url );
		if ( '' === $trimmed ) {
			return new WP_Error(
				'empty_url',
				__( 'Please paste a URL.', 'outpost-mobile-publishing' )
			);
		}
		if ( strlen( $trimmed ) > self::URL_MAX_LENGTH ) {
			return new WP_Error(
				'url_too_long',
				__( 'URL is too long.', 'outpost-mobile-publishing' )
			);
		}

		$parts = wp_parse_url( $trimmed );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error(
				'malformed_url',
				__( 'That doesn\'t look like a complete URL.', 'outpost-mobile-publishing' )
			);
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new WP_Error(
				'invalid_scheme',
				__( 'Only http and https URLs are accepted.', 'outpost-mobile-publishing' )
			);
		}

		// `wp_http_validate_url` rejects RFC 1918 / loopback / link-local
		// hosts via WordPress's HTTP filter chain. Defense-in-depth — we
		// don't fetch the URL, but the audit log shouldn't carry an
		// internal-network URL the user pasted by accident.
		if ( false === wp_http_validate_url( $trimmed ) ) {
			return new WP_Error(
				'unsafe_url',
				__( 'That URL points at a private or local network address.', 'outpost-mobile-publishing' )
			);
		}

		return true;
	}

	/**
	 * Test whether a URL's host matches the platform's expected domain.
	 * Returns false (no mismatch) for unknown platforms — we don't
	 * have an expected domain to check against, so we don't warn.
	 *
	 * @param string $platform_id Platform id (e.g. 'instagram-feed').
	 * @param string $url         Already-validated URL.
	 */
	public static function is_platform_mismatch( string $platform_id, string $url ): bool {
		$expected = self::EXPECTED_DOMAINS[ $platform_id ] ?? null;
		if ( null === $expected ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		// Match either exact host or subdomain of expected.
		if ( $host === $expected ) {
			return false;
		}
		$suffix = '.' . $expected;
		if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
			return false;
		}
		return true;
	}

	/**
	 * Write or update a syndication link entry. Idempotent: writing the
	 * same (platform_id, url) pair twice updates `added_at` rather than
	 * appending. Returns the full updated meta array.
	 *
	 * @param int    $post_id     Post id.
	 * @param string $platform_id Platform id.
	 * @param string $url         Validated URL.
	 * @return array<int, array<string,mixed>>
	 */
	public static function add_or_update_link(
		int $post_id,
		string $platform_id,
		string $url
	): array {
		$existing = self::get_links( $post_id );
		$now      = gmdate( 'c' );
		$found    = false;
		foreach ( $existing as &$entry ) {
			if ( ( $entry['platform_id'] ?? '' ) === $platform_id
				&& ( $entry['url'] ?? '' ) === $url ) {
				$entry['added_at'] = $now;
				$entry['source']   = self::SOURCE;
				$found             = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			$existing[] = array(
				'platform_id' => $platform_id,
				'url'         => $url,
				'added_at'    => $now,
				'source'      => self::SOURCE,
			);
		}

		update_post_meta( $post_id, self::META_KEY, $existing );
		return $existing;
	}

	/**
	 * Read all syndication links for a post. Returns an empty array
	 * when none. Defensive against malformed meta — drops entries
	 * missing required fields.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_links( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! isset( $entry['platform_id'], $entry['url'] ) ) {
				continue;
			}
			if ( ! is_string( $entry['platform_id'] ) || ! is_string( $entry['url'] ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * The post-meta key. Public so consumers (REST registration, the
	 * renderer) reference one constant.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}
}
