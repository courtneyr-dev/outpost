<?php
/**
 * Outpost_Source_YouTube
 *
 * Second concrete inbound-source adapter (F15). Per
 * `concepts/capture-inbound-may-2026.md` §3.3 / §7, YouTube's oEmbed
 * endpoint is the §5-clean extraction path:
 *
 *   - Endpoint: https://www.youtube.com/oembed?url={url}&format=json
 *   - Anonymous (no OAuth, no API key)
 *   - Returns JSON with title, thumbnail_url, author_name (channel),
 *     provider_name, html, type, version, dimensions
 *   - Accepts every YouTube share-sheet URL form: watch (with optional
 *     t= timestamp / list= playlist params), shorts, youtu.be short
 *     links, m.youtube.com mobile, music.youtube.com (YouTube Music)
 *
 * Mode is unambiguous: every YouTube watch / shorts URL is a Watch.
 * YouTube Music URLs route to Watch (not Listen) — Outpost's Listen
 * mode is for audio-only platforms (Spotify, Last.fm, Snipd); Watch
 * is for video, including music videos. Per Doc 2 §3.3.
 *
 * URL claims (matches_url):
 *
 *   - https://www.youtube.com/watch?v={id}              (canonical)
 *   - https://www.youtube.com/watch?v={id}&t={ts}
 *   - https://www.youtube.com/watch?v={id}&list={pid}
 *   - https://www.youtube.com/shorts/{id}               (Shorts)
 *   - https://m.youtube.com/watch?v={id}                (mobile)
 *   - https://music.youtube.com/watch?v={id}            (YT Music)
 *   - https://youtu.be/{id}                             (short link)
 *   - https://youtu.be/{id}?t={ts}
 *
 * Explicitly NOT claimed (route to Source_Unknown):
 *
 *   - https://youtube.com/                              (homepage)
 *   - https://youtube.com/channel/{id}                  (channel)
 *   - https://youtube.com/c/{name}                      (custom URL)
 *   - https://youtube.com/@{handle}                     (handle URL)
 *   - https://youtube.com/playlist?list={id}            (pure playlist)
 *
 * F5 design gap surfaced (CLAUDE.md F15 #1): Source_Base's path-
 * prefix host_patterns require trailing `/` (validate_pattern enforces
 * this). YouTube watch URLs have path `/watch` with no trailing slash,
 * so the host_patterns mechanism alone can't express "match /watch but
 * not /channel/". F15 overrides matches_url to add the path constraint
 * locally; Source_Base extension to allow `youtube.com/watch?` or
 * trailing-slash-optional path prefixes is filed as a follow-up.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_YouTube extends Outpost_Source_Base {

	public const ID = 'youtube';

	/**
	 * Hosts that need path-constraint matching (must be /watch or
	 * /shorts/*). youtu.be is omitted here — it's an all-paths
	 * short-link host handled in {@see self::matches_url()}.
	 */
	private const PATH_CONSTRAINED_HOSTS = array(
		'youtube.com',
		'www.youtube.com',
		'm.youtube.com',
		'music.youtube.com',
	);

	private const SHORT_LINK_HOST = 'youtu.be';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps = array(
			'id'               => self::ID,
			'label'            => __( 'YouTube', 'outpost-mobile-publishing' ),
			// Host_patterns kept host-only because Source_Base's
			// path-prefix syntax requires trailing `/` which doesn't
			// fit YouTube's `/watch` path. matches_url() below adds
			// the path constraint.
			'host_patterns'    => array(
				'youtube.com',
				'www.youtube.com',
				'm.youtube.com',
				'music.youtube.com',
				'youtu.be',
			),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'watch',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'oembed',
			'recipe'           => array(
				'endpoint'        => 'https://www.youtube.com/oembed?url={url}&format=json',
				'response_format' => 'json',
			),
			'mapping'          => array(
				'title'         => 'p-name',
				'thumbnail_url' => 'u-photo',
				'author_name'   => 'p-author',
				'provider_name' => 'p-publication',
				// `@source_url` becomes u-watch-of via Source_Base's
				// resolve_mapping_value macro.
				'@source_url'   => 'u-watch-of',
			),
			'h_entry_property' => 'u-watch-of',
			'auth_required'    => false,
			'tags_default'     => array( 'watch' ),
			'caveats'          => array(),
		);
		/**
		 * Filter the YouTube source capability shape. Same contract as
		 * `outpost_source_capabilities` for other sources.
		 *
		 * @param array  $caps      The capability shape.
		 * @param string $source_id Stable source ID ('youtube').
		 */
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override Source_Base's host-pattern matcher to add a YouTube-
	 * specific path constraint:
	 *
	 *   - youtu.be host: any path matches (short links are all videos)
	 *   - youtube.com / www. / m. / music. hosts: path must be
	 *     `/watch` or start with `/shorts/`
	 *
	 * Channel URLs (`/channel/`, `/c/`, `/@`), playlist URLs
	 * (`/playlist`), feed URLs (`/feed/`), and the homepage (`/`) all
	 * fail this check and fall through to Source_Unknown.
	 *
	 * F5 design-gap follow-up: extending Source_Base's
	 * `validate_pattern` + `pattern_matches` to support
	 * trailing-slash-optional path prefixes (or query-string anchors
	 * like `youtube.com/watch?`) would let YouTube use the standard
	 * host_patterns mechanism without an override. F15 chose the
	 * minimum-viable override; filed as F-later follow-up.
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

		if ( self::SHORT_LINK_HOST === $host ) {
			// youtu.be/{id} — must have a non-empty path beyond `/`.
			return strlen( $path ) > 1;
		}

		if ( ! in_array( $host, self::PATH_CONSTRAINED_HOSTS, true ) ) {
			return false;
		}

		// Path must be exactly /watch (with any query) or start with /shorts/.
		if ( '/watch' === $path ) {
			return true;
		}
		if ( 0 === strpos( $path, '/shorts/' ) && strlen( $path ) > strlen( '/shorts/' ) ) {
			return true;
		}
		return false;
	}
}
