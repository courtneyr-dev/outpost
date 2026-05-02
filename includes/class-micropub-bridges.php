<?php
/**
 * Micropub bridges — map Outpost's custom Micropub properties to companion
 * plugin storage.
 *
 * Phase C5. The PWA composer's "More" pull-out lets users set fields that
 * are not part of the Micropub spec but matter to specific companion
 * plugins:
 *
 *   - `mp-post-format`        → wp_set_post_format() (Post Formats for Block Themes)
 *   - `mp-yoast-focuskw`      → postmeta `_yoast_wpseo_focuskw` (Yoast SEO)
 *   - `mp-xfn`                → postmeta `_outpost_xfn` (Link Extension for XFN can read this)
 *   - `mp-xfn-target`         → companion key indicating which URL the rels apply to
 *
 * The Micropub plugin (David Shanske) emits the `after_micropub` action
 * after creating the post; that's our hook. We read the original $input
 * properties array, find our `mp-*` keys, and call the right WordPress
 * APIs to persist them.
 *
 * Per A1 #4, full companion adapter classes (extending
 * Outpost_Companion_Base) land in Phase F. This bridge is a tightly-scoped
 * subset that lets C5's More-pull-out actually save data without waiting
 * on the full F refactor.
 *
 * Hardening notes:
 *
 *   - We never trust untrusted property values. Post format gets validated
 *     against `get_post_format_slugs()`; XFN rels validated against the
 *     XFN spec list; focus keyphrase is sanitized as plain text.
 *   - We only act when the relevant companion is active. A `mp-yoast-*`
 *     property arriving without Yoast installed is a no-op, not an error.
 *   - Property keys are namespace-prefixed with `mp-` (Micropub's own
 *     convention for non-property metadata) so they don't conflict with
 *     spec properties.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Micropub_Bridges {

	/** Maximum length of a Yoast focus keyphrase (matches Yoast's own UI cap). */
	private const FOCUSKW_MAX_LENGTH = 191;

	/** Canonical XFN values — defense-in-depth allowlist, mirrors the endpoint. */
	private const XFN_RELS = array(
		'contact',
		'acquaintance',
		'friend',
		'met',
		'co-worker',
		'colleague',
		'co-resident',
		'neighbor',
		'child',
		'parent',
		'sibling',
		'spouse',
		'kin',
		'muse',
		'crush',
		'date',
		'sweetheart',
		'me',
	);

	/**
	 * Hook the bridges into WordPress.
	 *
	 * The Micropub plugin fires `after_micropub` once the post is created,
	 * passing `$input` (the original Micropub request) and `$args` (the
	 * resolved wp_insert_post args including the new post ID). We hook at
	 * priority 20 so any earlier filters in the same action have already
	 * settled the post state.
	 */
	public static function register(): void {
		add_action( 'after_micropub', array( self::class, 'apply_bridges' ), 20, 2 );
	}

	/**
	 * Apply each bridge in turn. Order doesn't matter — they touch
	 * different storage.
	 *
	 * @param array<string, mixed> $input Original Micropub request body (parsed).
	 * @param array<string, mixed> $args  wp_insert_post args, including 'ID' once the post
	 *                                    has been created.
	 */
	public static function apply_bridges( $input, $args ): void {
		if ( ! is_array( $input ) || ! is_array( $args ) || empty( $args['ID'] ) ) {
			return;
		}

		$post_id    = (int) $args['ID'];
		$properties = self::extract_properties( $input );

		self::apply_post_format( $post_id, $properties );
		self::apply_yoast_focuskw( $post_id, $properties );
		self::apply_xfn( $post_id, $properties );
	}

	/**
	 * Extract the properties array from a Micropub input shape.
	 *
	 * Form-encoded Micropub posts arrive as a flat associative array;
	 * JSON Micropub uses a nested `properties` key. We accept either.
	 *
	 * @param array<string, mixed> $input Micropub input shape.
	 * @return array<string, mixed> Flat properties map (string keys → mixed values).
	 */
	private static function extract_properties( array $input ): array {
		if ( isset( $input['properties'] ) && is_array( $input['properties'] ) ) {
			return $input['properties'];
		}
		return $input;
	}

	/**
	 * Bridge: `mp-post-format` → wp_set_post_format(), with auto-inference.
	 *
	 * Two paths:
	 *
	 *   1. Explicit user choice (via the More pull-out's Post Format
	 *      selector) — `mp-post-format` is set; we validate against
	 *      `get_post_format_slugs()` and apply.
	 *   2. Auto-inference from h-entry signals — when the user didn't
	 *      pick a format, we read the Micropub properties to figure out
	 *      what kind of post this is (a like, photo, listen, etc.) and
	 *      map that to the right WordPress Post Format.
	 *
	 * The auto-inference matters for POSSE: when the user posts from
	 * Outpost and the Post Kinds plugin sets the IndieWeb kind taxonomy
	 * from the same h-entry signals, this bridge sets the matching
	 * WordPress Post Format in parallel. Downstream POSSE plugins
	 * (Bridgy, ActivityPub variants, Mastodon Autopost) then have both
	 * IndieWeb kinds and WP formats to render against — each picks
	 * whichever it's wired for.
	 *
	 * Only runs when Post Formats for Block Themes is active.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $properties Flat properties map.
	 */
	private static function apply_post_format( int $post_id, array $properties ): void {
		if ( 'active' !== Outpost_Companion_Detector::is_post_formats_active() ) {
			return;
		}
		$valid = array_keys( get_post_format_slugs() );

		$explicit = self::scalar( $properties, 'mp-post-format' );
		if ( null !== $explicit ) {
			if ( in_array( $explicit, $valid, true ) ) {
				set_post_format( $post_id, 'standard' === $explicit ? '' : $explicit );
			}
			return;
		}

		$inferred = self::infer_post_format( $properties );
		if ( null === $inferred ) {
			return;
		}
		if ( ! in_array( $inferred, $valid, true ) ) {
			return;
		}
		set_post_format( $post_id, 'standard' === $inferred ? '' : $inferred );
	}

	/**
	 * Infer the WordPress Post Format from Micropub h-entry signals.
	 *
	 * Mapping rules (first match wins):
	 *
	 *   like-of / repost-of / bookmark-of  → link
	 *   photo (array, len > 1)             → gallery
	 *   photo (string or array len 1)      → image
	 *   listen-of                          → audio
	 *   watch-of                           → video
	 *   in-reply-to + short content        → status
	 *   in-reply-to (no content)           → status
	 *   name (article)                     → standard
	 *   content only (no name)             → status
	 *
	 * Returns null when nothing maps — caller leaves the format unset.
	 *
	 * The threshold for "short content" (status vs standard) is 280
	 * characters — Twitter/Mastodon's classic post-length convention.
	 *
	 * @param array<string, mixed> $properties Flat properties map.
	 * @return string|null Format slug, or null when unmapped.
	 */
	private static function infer_post_format( array $properties ): ?string {
		if ( self::has_property( $properties, 'like-of' )
			|| self::has_property( $properties, 'repost-of' )
			|| self::has_property( $properties, 'bookmark-of' ) ) {
			return 'link';
		}

		$photo = $properties['photo'] ?? null;
		if ( null !== $photo ) {
			if ( is_array( $photo ) && count( $photo ) > 1 ) {
				return 'gallery';
			}
			return 'image';
		}

		if ( self::has_property( $properties, 'listen-of' ) ) {
			return 'audio';
		}
		if ( self::has_property( $properties, 'watch-of' ) ) {
			return 'video';
		}
		if ( self::has_property( $properties, 'in-reply-to' ) ) {
			return 'status';
		}
		if ( self::has_property( $properties, 'name' ) ) {
			return 'standard';
		}

		$content = self::scalar( $properties, 'content' );
		if ( null !== $content && '' !== trim( $content ) ) {
			return strlen( $content ) <= 280 ? 'status' : 'standard';
		}

		return null;
	}

	/**
	 * Whether a property is present and non-empty.
	 *
	 * Mirrors how the Post Kinds plugin checks h-entry signals — empty
	 * string and empty array both count as absent.
	 *
	 * @param array<string, mixed> $properties Flat properties map.
	 * @param string               $key        Property key.
	 * @return bool
	 */
	private static function has_property( array $properties, string $key ): bool {
		if ( ! array_key_exists( $key, $properties ) ) {
			return false;
		}
		$value = $properties[ $key ];
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		return null !== $value;
	}

	/**
	 * Bridge: `mp-yoast-focuskw` → postmeta `_yoast_wpseo_focuskw`.
	 *
	 * Only runs when Yoast SEO is active. Sanitized as plain text and
	 * length-capped to match Yoast's own UI. Empty string deletes.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $properties Flat properties map.
	 */
	private static function apply_yoast_focuskw( int $post_id, array $properties ): void {
		if ( 'active' !== Outpost_Companion_Detector::is_yoast_active() ) {
			return;
		}
		$value = self::scalar( $properties, 'mp-yoast-focuskw' );
		if ( null === $value ) {
			return;
		}
		$clean = sanitize_text_field( $value );
		if ( '' === $clean ) {
			delete_post_meta( $post_id, '_yoast_wpseo_focuskw' );
			return;
		}
		if ( strlen( $clean ) > self::FOCUSKW_MAX_LENGTH ) {
			$clean = substr( $clean, 0, self::FOCUSKW_MAX_LENGTH );
		}
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', $clean );
	}

	/**
	 * Bridge: `mp-xfn` → postmeta `_outpost_xfn`.
	 *
	 * Stores the user's selected XFN relationships (as a JSON-encoded
	 * structure: `{"target": "<url>", "rels": ["friend", "met"]}`) under
	 * a single postmeta key. The Link Extension for XFN plugin can read
	 * this in a future Phase F adapter to inject `rel` attributes when
	 * the post renders. For now the data is captured so it isn't lost
	 * between Micropub post and eventual rendering integration.
	 *
	 * Only runs when Link Extension for XFN is active. Each rel is
	 * validated against the XFN spec list; any unrecognized value is
	 * silently dropped.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $properties Flat properties map.
	 */
	private static function apply_xfn( int $post_id, array $properties ): void {
		if ( 'active' !== Outpost_Companion_Detector::is_xfn_active() ) {
			return;
		}
		$rels_raw = $properties['mp-xfn'] ?? null;
		if ( null === $rels_raw ) {
			return;
		}
		$rels_array = is_array( $rels_raw ) ? $rels_raw : array( $rels_raw );
		$rels_clean = array_values(
			array_filter(
				array_map( 'strval', $rels_array ),
				static fn( string $rel ): bool => in_array( $rel, self::XFN_RELS, true )
			)
		);
		$target     = self::scalar( $properties, 'mp-xfn-target' );

		if ( empty( $rels_clean ) ) {
			delete_post_meta( $post_id, '_outpost_xfn' );
			return;
		}

		update_post_meta(
			$post_id,
			'_outpost_xfn',
			wp_json_encode(
				array(
					'target' => is_string( $target ) ? esc_url_raw( $target ) : '',
					'rels'   => $rels_clean,
				)
			)
		);
	}

	/**
	 * Read a property as a single string scalar.
	 *
	 * Micropub form-encoded properties arrive as either a string or a
	 * single-element array (per WordPress's $_POST normalization). JSON
	 * Micropub always wraps in an array. Either way, we want the first
	 * non-empty value or null.
	 *
	 * @param array<string, mixed> $properties Flat properties map.
	 * @param string               $key        Property key.
	 * @return string|null
	 */
	private static function scalar( array $properties, string $key ): ?string {
		if ( ! array_key_exists( $key, $properties ) ) {
			return null;
		}
		$value = $properties[ $key ];
		if ( is_array( $value ) ) {
			$value = $value[0] ?? null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		return $value;
	}
}
