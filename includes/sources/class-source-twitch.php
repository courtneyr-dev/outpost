<?php
/**
 * Outpost_Source_Twitch
 *
 * Twitch channel / VOD / clip pages emit OG tags suitable for the
 * composer pre-fill. Per Doc 2 §3.4 — Twitch has a Helix API but
 * it requires app credentials (client_credentials grant) which
 * would be embedded in a free WP.org plugin (§5 risk). F16 sticks
 * to OG-only; BYO Helix credentials remain a future opt-in.
 *
 * URL forms claimed:
 *
 *   - https://www.twitch.tv/{channel}            (live channel)
 *   - https://www.twitch.tv/{channel}/video/{id} (VOD)
 *   - https://www.twitch.tv/videos/{id}          (VOD short form)
 *   - https://clips.twitch.tv/{slug}             (clip)
 *
 * Mode is Watch (videos / live). The `/directory/` discovery URLs
 * fall through to Source_Unknown.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Twitch extends Outpost_Source_Base {

	public const ID = 'twitch';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Twitch', 'outpost-mobile-publishing' ),
			'host_patterns'    => array(
				'twitch.tv',
				'www.twitch.tv',
				'clips.twitch.tv',
			),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'watch',
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
				'@source_url'    => 'u-watch-of',
			),
			'h_entry_property' => 'u-watch-of',
			'auth_required'    => false,
			'tags_default'     => array( 'watch', 'twitch' ),
			'caveats'          => array(
				__( 'OG-only extraction. Game name, viewer count, and other Helix-API fields are not pulled (would require embedded credentials).', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
