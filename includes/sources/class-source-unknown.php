<?php
/**
 * Outpost_Source_Unknown
 *
 * Fallback source registered LAST in the registry. Claims any URL
 * via the `*` host_patterns sentinel; produces an ambiguous-mode
 * shape so the F6 dispatcher renders a mode-picker (Reply / Like /
 * Repost / Bookmark with Bookmark default) when no specific
 * Source_* claims the URL.
 *
 * IMPORTANT — F5 limitation captured in CLAUDE.md F5 #6:
 *
 *   Source_Unknown declares `extractor => 'og_tags'`, but the
 *   og_tags parser is stubbed in F5 (Outpost_Source_Extractor_Og_Tags
 *   throws Outpost_Source_Extractor_Not_Implemented_Exception until
 *   F16 lands the body). End-to-end fallback works only after F16.
 *   F5's preview endpoint integration catches the exception and
 *   surfaces a clean 501 — the F5 Reply-mode regression that
 *   would result is mitigated by the endpoint preserving its
 *   legacy code path when no concrete (non-Unknown) source claims
 *   the URL. F6's dispatcher must tolerate the throw gracefully
 *   too.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Unknown extends Outpost_Source_Base {

	/** Stable source ID. */
	public const ID = 'unknown';

	/**
	 * Capability shape per the F5 spec for the universal fallback.
	 * Values that never change between requests live as constants
	 * inside the method body; the `outpost_source_capabilities`
	 * filter is the override seam for site owners that want to
	 * narrow or extend the shape.
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
			'label'            => __( 'Generic URL', 'outpost' ),
			'host_patterns'    => array( '*' ),
			'ambiguity'        => 'ambiguous',
			'mode'             => null,
			'mode_options'     => array( 'reply', 'like', 'repost', 'bookmark' ),
			'mode_default'     => 'bookmark',
			'extractor'        => 'og_tags',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'og:title'       => 'p-name',
				'og:description' => 'p-summary',
				'og:image'       => 'u-photo',
			),
			'h_entry_property' => null,
			'auth_required'    => false,
			'tags_default'     => array(),
			'caveats'          => array(
				__( 'No specific source adapter detected; best-effort OG extraction only.', 'outpost' ),
			),
		);
		/**
		 * Filter the Source_Unknown capability shape.
		 *
		 * Site owners can use this to narrow the mode_options list,
		 * extend caveats, or replace the mapping with site-specific
		 * fields. Companion ID is passed so callers filtering on
		 * multiple sources can dispatch on it.
		 *
		 * @param array  $caps      The capability shape.
		 * @param string $source_id Stable source ID ('unknown').
		 */
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
