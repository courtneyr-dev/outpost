<?php
/**
 * Outpost_Source_Spotify
 *
 * First concrete inbound-source adapter. Per
 * `concepts/capture-inbound-may-2026.md` §3.1 / §7, Spotify's oEmbed
 * endpoint is the §5-clean extraction path:
 *
 *   - Endpoint: https://open.spotify.com/oembed?url={url}
 *   - Anonymous (no OAuth, no API key, no rate limit advertised)
 *   - Returns JSON with title / thumbnail_url / provider_name /
 *     html / type / version / dimensions
 *   - Accepts every Spotify share-sheet URL form (track, album,
 *     episode, show, playlist, intl-{lang} regional variants,
 *     spotify.link short links — oEmbed follows the redirect)
 *
 * Mode is unambiguous: every Spotify track / album / episode / show /
 * playlist URL is a Listen. Artist URLs (`/artist/{id}`) are NOT
 * matched — `host_patterns` is host-only, but artist pages are
 * conceptually a Bookmark / generic OG case better handled by F16's
 * Source_Unknown. The dispatcher falls through to Source_Unknown for
 * URLs the host_patterns don't claim, but Spotify's host_patterns
 * cover ALL paths under open.spotify.com, including /artist/. The
 * adapter accepts all paths and the user can switch modes from the
 * composer if a /artist/ URL lands here — Listen with empty p-name
 * is still a working composer state.
 *
 * (If subsequent UX research shows /artist/ paths shouldn't auto-
 * route to Listen, F7 follow-up: add a `recipe_for_url` override
 * that returns null for /artist/ paths and a complementary
 * `mode_for_url` override returning a fallback mode. F5's design
 * supports this without further changes — Source_Base provides the
 * template-method seam.)
 *
 * Pure capabilities()-only implementation. Source_Base's defaults
 * handle: matches_url (host_patterns), recipe_for_url (verbatim
 * recipe), map_extracted (mapping with @source_url substitution),
 * mode_for_url (the unambiguous mode). F7 overrides nothing — that
 * was the F5 design intent.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Spotify extends Outpost_Source_Base {

	/** Stable source ID — also the chip id surfaced in the dispatch URL. */
	public const ID = 'spotify';

	/**
	 * Capability shape per the F5 contract. Symmetric to
	 * `Outpost_Companion_ActivityPub::capabilities()` (outbound
	 * sibling); see `Outpost_Source_Base` class docblock for the
	 * Companion-vs-Source key parallels.
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
	public function capabilities(): array {
		$caps = array(
			'id'               => self::ID,
			'label'            => __( 'Spotify', 'outpost-mobile-publishing' ),
			'host_patterns'    => array(
				'open.spotify.com',
				'spotify.link',
			),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'listen',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'oembed',
			'recipe'           => array(
				'endpoint'        => 'https://open.spotify.com/oembed?url={url}',
				'response_format' => 'json',
			),
			'mapping'          => array(
				'title'         => 'p-name',
				'thumbnail_url' => 'u-photo',
				'provider_name' => 'p-publication',
				// `@source_url` becomes u-listen-of via Source_Base's
				// resolve_mapping_value macro. Listed here so the mapping
				// shape is self-documenting; the macro ensures it always
				// reflects the URL the user actually shared.
				'@source_url'   => 'u-listen-of',
			),
			'h_entry_property' => 'u-listen-of',
			'auth_required'    => false,
			'tags_default'     => array( 'listen' ),
			'caveats'          => array(),
		);
		/**
		 * Filter the Spotify source capability shape.
		 *
		 * Site owners can use this to narrow `host_patterns` (e.g. drop
		 * spotify.link short-link support if their users always paste
		 * canonical URLs), restrict `tags_default`, or add caveats per
		 * their setup. The source ID is passed so callers filtering on
		 * multiple sources can dispatch on it.
		 *
		 * @param array  $caps      The capability shape.
		 * @param string $source_id Stable source ID ('spotify').
		 */
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
