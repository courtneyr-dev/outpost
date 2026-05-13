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
 *   - `mp-categories[]`       → wp_set_post_categories() with auto-create
 *   - `mp-place-name`         → postmeta `_outpost_place_name` (free-text venue name paired with the standard `location` property)
 *   - photo alt text          → postmeta `_wp_attachment_image_alt` on each
 *                              attached image (F3). Reads the standard
 *                              Micropub structured-photo shape
 *                              `{ value, alt }` AND the parallel
 *                              `mp-photo-alt` array. Fixes an upstream
 *                              Micropub-plugin gap that loses alt text.
 *
 * The standard Micropub `category[]` property gets handled by the Micropub
 * plugin itself (assigns to post_tag taxonomy by default). Outpost adds
 * `mp-categories[]` so users can explicitly assign WordPress categories
 * (different taxonomy) via the More pull-out's autocomplete field.
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
	 * Two pipelines:
	 *
	 * 1. WRITE: `after_micropub` fires once the Micropub plugin has created
	 *    a post. We read the `mp-*` Outpost-specific properties out of the
	 *    request body and persist them through the matching companion-plugin
	 *    APIs. Priority 20 so any earlier filters have settled the post.
	 *
	 * 2. READ: `micropub_syndicate-to` fires whenever a Micropub client
	 *    issues `?q=syndicate-to`. We append a chip per active companion
	 *    that contributes one (ActivityPub in F1, Bridgy Publish in F14,
	 *    ManualShare in F9). Priority 10 so the merged set still feeds any
	 *    later filter callers.
	 */
	public static function register(): void {
		add_action( 'after_micropub', array( self::class, 'apply_bridges' ), 20, 2 );
		self::register_syndicate_chips();
	}

	/**
	 * Hook the syndicate-to chip merger. Public so tests can assert the
	 * registration is idempotent and unit-test the filter callback in
	 * isolation without needing the full `register()` action wiring.
	 *
	 * Filter signature is `(array $targets, ?int $user_id) => array`. Each
	 * `$target` is `[ 'uid' => string, 'name' => string ]` per the
	 * Shanske Micropub plugin's contract.
	 */
	public static function register_syndicate_chips(): void {
		add_filter(
			'micropub_syndicate-to',
			array( self::class, 'merge_syndicate_chips' ),
			10,
			1
		);
	}

	/**
	 * Append a `[ 'uid', 'name' ]` chip per active companion that surfaces
	 * one. De-duplicates by `uid` so the same chip can't accidentally
	 * register twice (e.g. when both `Outpost_Micropub_Bridges::register()`
	 * and a direct `register_syndicate_chips()` test call run).
	 *
	 * Companion adapters declare their chip in the richer
	 * `Outpost_Companion_Base::capabilities()` shape (id / label / detected
	 * / accepts_modes / accepts_media / max_attachments / alt_passthrough
	 * / char_limit / caveats / requires_auth); this merger projects that
	 * down to the `[ uid, name ]` shape the Micropub plugin's filter
	 * consumes.
	 *
	 * No mode filtering happens here — the Shanske `micropub_syndicate-to`
	 * filter contract has no mode parameter, so this merger always
	 * exposes every detected chip. Per-mode filtering for the composer
	 * lives at `Outpost_Companion_Registry::chips_for_mode()` and is
	 * surfaced via the Outpost-owned
	 * `/wp-json/outpost/v1/syndicate-targets` endpoint.
	 *
	 * @param mixed $targets Existing chip list. The Shanske plugin
	 *                       guarantees `array<int, array{uid: string, name:
	 *                       string}>` but defensively we re-array unknown
	 *                       shapes.
	 * @return array<int, array{uid: string, name: string}>
	 */
	public static function merge_syndicate_chips( $targets ): array {
		$normalized = is_array( $targets ) ? array_values( array_filter( $targets, 'is_array' ) ) : array();
		$seen_uids  = array();
		foreach ( $normalized as $existing ) {
			if ( isset( $existing['uid'] ) && is_string( $existing['uid'] ) ) {
				$seen_uids[ $existing['uid'] ] = true;
			}
		}
		foreach ( Outpost_Companion_Registry::chips_for_mode( null ) as $chip ) {
			if ( empty( $chip['id'] ) || empty( $chip['label'] ) ) {
				continue;
			}
			$uid = (string) $chip['id'];
			if ( isset( $seen_uids[ $uid ] ) ) {
				continue;
			}
			$normalized[]      = array(
				'uid'  => $uid,
				'name' => (string) $chip['label'],
			);
			$seen_uids[ $uid ] = true;
		}
		return $normalized;
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
		self::apply_categories( $post_id, $properties );
		self::apply_place_name( $post_id, $properties );
		self::apply_photo_alt_text( $post_id, $properties );
	}

	/**
	 * Bridge: photo alt text → `_wp_attachment_image_alt` on each
	 * attachment post (F3).
	 *
	 * Investigation finding (F3): the upstream Micropub plugin
	 * (indieweb/wordpress-micropub) does NOT write alt text to
	 * `_wp_attachment_image_alt`. Two failure modes:
	 *
	 *   1. The plugin recognizes structured photo entries in the form
	 *      `{ "value": "<url>", "alt": "<alt text>" }` but passes the
	 *      `alt` to `media_sideload_url($url, $post_id, $title)` which
	 *      stores it as the attachment's `post_title`, not as
	 *      `_wp_attachment_image_alt`.
	 *   2. The plugin doesn't recognize the `mp-photo-alt` parallel
	 *      array convention at all. Outpost client requests using that
	 *      shape silently lose alt text.
	 *
	 * Downstream consumers (the ActivityPub plugin's transformer in
	 * particular — verified F3) read `_wp_attachment_image_alt` to
	 * populate the AP `attachment[].name` field. Without the bridge,
	 * every Outpost-originated Photo or Gallery post syndicates to the
	 * fediverse with empty image alt text — accessibility regression
	 * for every user.
	 *
	 * The bridge fixes the chain end-to-end without requiring an
	 * upstream Micropub plugin change. It supports both shapes
	 * Outpost may emit:
	 *
	 *   - Structured: `properties.photo = [ { value: <url>, alt: <alt> } ]`
	 *     (Micropub spec JSON form, supported by `media_sideload_url`'s
	 *     plugin path).
	 *   - Parallel arrays: `properties.photo = [<url1>, <url2>]` plus
	 *     `properties.mp-photo-alt = [<alt1>, <alt2>]` (Outpost client
	 *     legacy + some other Micropub clients).
	 *
	 * For each photo URL with alt text, the bridge resolves the URL
	 * back to its attachment ID via `attachment_url_to_postid()` and
	 * writes the alt text to `_wp_attachment_image_alt`. URLs that
	 * resolve to attachments not parented by `$post_id` are skipped
	 * defensively — the bridge never updates someone else's
	 * attachments.
	 *
	 * Empty alt strings are persisted (not skipped) because the AP
	 * spec is fine with empty `attachment[].name` and an explicit
	 * empty value beats a missing field for downstream consumers.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $properties Flat properties map from `extract_properties()`.
	 */
	private static function apply_photo_alt_text( int $post_id, array $properties ): void {
		$pairs = self::collect_photo_alt_pairs( $properties );
		if ( empty( $pairs ) ) {
			return;
		}
		foreach ( $pairs as $pair ) {
			$url = $pair['url'];
			$alt = $pair['alt'];
			if ( '' === $url ) {
				continue;
			}
			$attachment_id = (int) attachment_url_to_postid( $url );
			if ( $attachment_id <= 0 ) {
				continue;
			}
			$parent_id = (int) wp_get_post_parent_id( $attachment_id );
			if ( 0 !== $parent_id && $parent_id !== $post_id ) {
				continue;
			}
			update_post_meta(
				$attachment_id,
				'_wp_attachment_image_alt',
				sanitize_text_field( $alt )
			);
		}
	}

	/**
	 * Resolve photo + alt-text pairs from either the structured or
	 * parallel-array shape. Returns a list of `{url, alt}` arrays.
	 *
	 * Order matters: structured shape wins per-entry (each photo entry
	 * carries its own alt), and the parallel `mp-photo-alt` array fills
	 * in for plain-string photo entries by index.
	 *
	 * @param array<string, mixed> $properties Flat properties map.
	 * @return array<int, array{url: string, alt: string}>
	 */
	private static function collect_photo_alt_pairs( array $properties ): array {
		$photo = $properties['photo'] ?? null;
		if ( null === $photo ) {
			return array();
		}
		// Normalize to array.
		$entries = is_array( $photo ) ? array_values( $photo ) : array( $photo );

		$alt_array = array();
		$mp_alt    = $properties['mp-photo-alt'] ?? null;
		if ( is_array( $mp_alt ) ) {
			$alt_array = array_values( $mp_alt );
		} elseif ( is_string( $mp_alt ) ) {
			$alt_array = array( $mp_alt );
		}

		$pairs = array();
		foreach ( $entries as $index => $entry ) {
			if ( is_array( $entry ) && isset( $entry['value'] ) ) {
				$url = is_string( $entry['value'] ) ? $entry['value'] : '';
				$alt = isset( $entry['alt'] ) && is_string( $entry['alt'] ) ? $entry['alt'] : '';
			} elseif ( is_string( $entry ) ) {
				$url = $entry;
				$alt = isset( $alt_array[ $index ] ) && is_string( $alt_array[ $index ] )
					? $alt_array[ $index ]
					: '';
			} else {
				continue;
			}
			$pairs[] = array(
				'url' => $url,
				'alt' => $alt,
			);
		}
		return $pairs;
	}

	/**
	 * Bridge: `mp-place-name` → `_outpost_place_name` post meta.
	 *
	 * Outpost-controlled custom property paired with the standard `location`
	 * h-entry property. The PWA composer's GeocodePicker collects two halves
	 * of a location:
	 *
	 *   - `location: geo:lat,lon` — RFC 5870 geo URI from OpenStreetMap.
	 *     Stored by the Micropub plugin in its standard `geo_*` post meta.
	 *   - `mp-place-name` — free-text venue name, optionally auto-filled
	 *     from the OSM `display_name` but always editable. Stored here as
	 *     `_outpost_place_name` so themes can render "📍 at <venue>".
	 *
	 * Runs unconditionally — every Outpost-originated post can carry a
	 * place name regardless of post kind. Deletes the meta key when the
	 * incoming property is empty so a venue tag can be removed by a
	 * subsequent edit.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $properties Flat properties map.
	 */
	private static function apply_place_name( int $post_id, array $properties ): void {
		$raw = self::scalar( $properties, 'mp-place-name' );
		if ( null === $raw ) {
			return;
		}
		$clean = sanitize_text_field( $raw );
		if ( '' === $clean ) {
			delete_post_meta( $post_id, '_outpost_place_name' );
			return;
		}
		update_post_meta( $post_id, '_outpost_place_name', $clean );
	}

	/**
	 * Bridge: `mp-categories[]` → wp_set_post_categories(), with auto-create.
	 *
	 * Each value is looked up by name, then by slug, in the `category`
	 * taxonomy. Existing terms are reused; new terms are created on the
	 * fly via wp_insert_term. Append-mode is used so any categories the
	 * Micropub plugin already assigned (from the `category[]` lookup)
	 * stay in place.
	 *
	 * Runs unconditionally — `category` is a core WordPress taxonomy on
	 * every site, no companion-plugin gating needed.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $properties Flat properties map.
	 */
	private static function apply_categories( int $post_id, array $properties ): void {
		$raw = $properties['mp-categories'] ?? null;
		if ( null === $raw ) {
			return;
		}
		$names = is_array( $raw ) ? $raw : array( $raw );
		$ids   = array();
		foreach ( $names as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}
			$clean = sanitize_text_field( $candidate );
			if ( '' === $clean ) {
				continue;
			}
			$term = get_term_by( 'name', $clean, 'category' );
			if ( ! $term instanceof \WP_Term ) {
				$term = get_term_by( 'slug', sanitize_title( $clean ), 'category' );
			}
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$created = wp_insert_term( $clean, 'category' );
			if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
				$ids[] = (int) $created['term_id'];
			}
		}
		if ( empty( $ids ) ) {
			return;
		}
		wp_set_post_categories( $post_id, $ids, true );
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
	 * After applying the format, mark it manual via the PFBT detector
	 * (coordination contract C1) so PFBT's re-enabled auto-detection on
	 * `save_post` does not override Outpost's choice on future saves of
	 * the same post.
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
				self::mark_format_manual( $post_id );
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
		self::mark_format_manual( $post_id );
	}

	/**
	 * Mark PFBT's manual-format flag so the detector respects Outpost's choice.
	 *
	 * Coordination contract C1 with `PFBT_Format_Detector` (auto-detection
	 * re-enabled in PFBT v2.3.0+). Outpost's `apply_post_format` runs at
	 * `after_micropub` priority 20 — by then the Micropub plugin's
	 * `wp_insert_post` has already fired `save_post`, so PFBT's detector
	 * may have applied a content-derived format on first save. After
	 * Outpost overrides with `mp-post-format` or its own inference, this
	 * call locks in that choice: subsequent saves (user edits, sync
	 * refreshes) see the manual flag and PFBT skips applying its detected
	 * format. The audit meta `_pfbt_format_detected` continues to record
	 * what the content suggests, so divergence is visible without breaking
	 * the user-facing format.
	 *
	 * `class_exists` guard: PFBT may be active under an older version that
	 * lacks the `mark_as_manual` API. Calling without the guard would fail
	 * autoloading on those installs. The companion-detection check at the
	 * top of `apply_post_format` already confirmed the plugin is active;
	 * the class check confirms the API surface.
	 *
	 * Idempotent: marking an already-manual post is a no-op.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function mark_format_manual( int $post_id ): void {
		if ( class_exists( '\PFBT_Format_Detector' ) ) {
			\PFBT_Format_Detector::mark_as_manual( $post_id );
		}
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
			// Dedupe before counting. The upstream Micropub plugin
			// enriches `$input['photo']` post-sideload — Outpost-uploaded
			// photos arrive as `photo[]=url-1&...` but by the time this
			// hook runs the array may contain each URL twice (original +
			// canonical, both resolving to the same local URL). Without
			// dedupe, a single-photo post misclassifies as 'gallery'
			// because the doubled count is 2.
			if ( is_array( $photo ) ) {
				$unique = array_values(
					array_unique(
						array_filter(
							$photo,
							static fn ( $v ): bool => is_string( $v ) && '' !== $v
						)
					)
				);
				if ( count( $unique ) > 1 ) {
					return 'gallery';
				}
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
