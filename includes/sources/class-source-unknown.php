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
 * F5 LIMITATION LIFTED (F16):
 *
 *   F5 shipped Source_Unknown with extractor='og_tags' against a
 *   stubbed Extractor_Og_Tags. F16 lands the concrete parser, so
 *   Source_Unknown is now end-to-end functional — any URL with
 *   OG tags gets best-effort metadata extraction (p-name from
 *   og:title, p-summary from og:description, u-photo from
 *   og:image). The F5 #6 mitigation (preview endpoint preserving
 *   the legacy code path on Source_Unknown matches) can retire
 *   when callers transition to the structured shape; until then
 *   both paths coexist for backwards compatibility.
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
