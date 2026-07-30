<?php
/**
 * Outpost_Source_Ifixit (G14a).
 *
 * iFixit (https://www.ifixit.com/) — repair guides under CC BY-NC-SA.
 * The og_tags-only adapter ships now; full integration with iFixit's
 * public REST API at /api/2.0/guides/{guideid} (which exposes tools,
 * parts, time-required, difficulty as structured fields) arrives once
 * the F-phase api_json extractor (F5 #6 stub) lands.
 *
 * URL forms claimed:
 *
 *   - https://www.ifixit.com/Guide/{slug}/{guideid}
 *   - https://ifixit.com/Guide/{slug}/{guideid}
 *
 * Wiki URLs (`/Wiki/{slug}`) are NOT claimed in v1 — Wiki pages have
 * a different API endpoint shape and need their own adapter pattern.
 * They fall through to Source_Unknown.
 *
 * License attribution
 *
 * iFixit guides are CC BY-NC-SA by default. Adapter caveats include
 * the attribution note; the docs page covers the rendering recommendation.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Ifixit extends Outpost_Source_Base {

	public const ID = 'ifixit';

	private const CLAIMED_HOSTS = array( 'ifixit.com', 'www.ifixit.com' );

	private const GUIDE_PATH_PREFIX = '/Guide/';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'iFixit', 'outpost-mobile-publishing' ),
			'host_patterns'    => self::CLAIMED_HOSTS,
			'ambiguity'        => 'unambiguous',
			'mode'             => 'bookmark',
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
				'@source_url'    => 'u-bookmark-of',
			),
			'h_entry_property' => 'u-bookmark-of',
			'auth_required'    => false,
			'tags_default'     => array( 'bookmark', 'repair' ),
			'caveats'          => array(
				__( 'iFixit guides are CC BY-NC-SA. Render attribution like "Source: iFixit (CC BY-NC-SA)" alongside the captured content. Filterable via outpost_ifixit_attribution_html for users with custom rendering needs; removing attribution may violate the license.', 'outpost-mobile-publishing' ),
				__( 'Full API integration (tools, parts, time-required, difficulty as structured fields) arrives with G14b once the F-phase api_json extractor lands. v1 ships og_tags only.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Constrain the host_patterns to /Guide/{slug}/{guideid} paths.
	 * Wiki pages and other iFixit URLs fall through to Source_Unknown.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! in_array( $host, self::CLAIMED_HOSTS, true ) ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		return 0 === strpos( $path, self::GUIDE_PATH_PREFIX )
			&& strlen( $path ) > strlen( self::GUIDE_PATH_PREFIX );
	}

	/**
	 * Default attribution HTML rendered alongside iFixit captures.
	 * Filterable via `outpost_ifixit_attribution_html`. Returning an
	 * empty string from the filter triggers a debug-level log warning;
	 * removal does not block.
	 *
	 * @since 0.1.69
	 *
	 * @param string $source_url The iFixit guide URL.
	 * @return string Attribution HTML.
	 */
	public static function attribution_html( string $source_url ): string {
		$default = sprintf(
			/* translators: %s: iFixit guide URL */
			'<p class="outpost-ifixit-attribution">%s</p>',
			sprintf(
				/* translators: %s: iFixit guide URL */
				esc_html__( 'Source: %s (CC BY-NC-SA)', 'outpost-mobile-publishing' ),
				'<a href="' . esc_url( $source_url ) . '">iFixit</a>'
			)
		);
		/**
		 * Filter the iFixit attribution HTML.
		 *
		 * Returning an empty string suppresses attribution. Note that
		 * iFixit guides are CC BY-NC-SA; removing attribution may
		 * violate the license.
		 *
		 * @param string $html       Default attribution markup.
		 * @param string $source_url The iFixit guide URL.
		 */
		$filtered = apply_filters( 'outpost_ifixit_attribution_html', $default, $source_url );
		if ( ! is_string( $filtered ) ) {
			return $default;
		}
		if ( '' === trim( $filtered ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
			error_log( 'Outpost: outpost_ifixit_attribution_html returned empty; iFixit content is CC BY-NC-SA and attribution removal may violate the license.' );
		}
		return $filtered;
	}
}
