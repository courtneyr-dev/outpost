<?php
/**
 * Abstract base every companion adapter extends in Phase F.
 *
 * The composer code never imports companion-specific code directly — it asks
 * the adapter what features it surfaces and renders accordingly. This base
 * defines two complementary contracts:
 *
 *  1. **Feature surfaces** — adapters that wire composer features to a
 *     sibling plugin's storage (Post Kinds, Yoast, XFN, Post Formats,
 *     Syndication Links, Accessibility Checker). They declare which
 *     composer features they unlock via {@see self::feature_slugs()},
 *     a flat list of strings the composer iterates to decide what UI to
 *     render. They do NOT contribute syndicate-to chips.
 *
 *  2. **Syndication targets** — adapters that contribute outbound
 *     destinations the user can toggle per post (ActivityPub from F1,
 *     ManualShare from F9, BridgyPublish from F14). They declare a
 *     richer chip shape via {@see self::capabilities()} that the
 *     `?q=syndicate-to` merger and the per-mode chip filter consume.
 *
 * The two contracts coexist because the same adapter may someday do both
 * (e.g. a future Mastodon adapter that surfaces both a syndicate chip
 * AND composer-level "post URL on Mastodon profile" features). The
 * separation lets each adapter declare only the surface it actually
 * provides.
 *
 * Status helpers (`status()`, `is_active()`) are concrete here so every
 * adapter answers "are you active?" the same way, via the central
 * {@see Outpost_Companion_Detector}.
 *
 * F1 → F2 evolution: F1 introduced `syndicate_chip()` returning
 * `{id, label, accepts, detected}`. F2 promotes that to `capabilities()`
 * with the richer `{id, label, detected, accepts_modes, accepts_media,
 * max_attachments, alt_passthrough, char_limit, caveats, requires_auth}`
 * shape that future adapters (F9 ManualShare, F14 BridgyPublish) need.
 * The F1 method is removed; adapters that surfaced chips via F1 now
 * surface them via F2's `capabilities()`.
 *
 * F2 → F5 symmetry: the `Outpost_Source_Base` planned for F5 (inbound
 * source adapters) mirrors `capabilities()` inverted. Same field names —
 * `id`, `label`, `detected`, `accepts_modes` (which composer modes the
 * source can extract into), `caveats` — plus an `extractor` key naming
 * the metadata recipe (oembed, og, mf2, rss, api) and `host_patterns`
 * for the host-match dispatch. Outbound and inbound adapters share a
 * uniform capability-declaration vocabulary the composer can iterate
 * over without branching on direction.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Outpost_Companion_Base {

	/**
	 * Plugin file path the adapter wraps (one of the OUTPOST_*_PLUGIN_FILE constants).
	 *
	 * @return string
	 */
	abstract public function file(): string;

	/**
	 * Human-readable brand name for admin notices and onboarding copy.
	 *
	 * Not translated — these are plugin brand names (IndieAuth, Yoast SEO, etc).
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Composer feature slugs this adapter unlocks when the underlying
	 * plugin is active.
	 *
	 * Examples Phase F adapters return: 'post-kinds.listen',
	 * 'post-kinds.watch', 'syndication.chips', 'yoast.focus-keyphrase',
	 * 'xfn.relationships', 'activitypub.federate'. The composer iterates
	 * `feature_slugs()` to decide which UI surfaces to render — it does
	 * not branch on adapter class identity.
	 *
	 * Distinct from {@see self::capabilities()}: feature slugs answer
	 * "what composer-level features does this adapter unlock?" while
	 * capabilities() answers "is this adapter a syndicate-to chip, and
	 * if so what does it accept?". An adapter may return slugs without
	 * a chip, or a chip without slugs, or both.
	 *
	 * Renamed from `capabilities()` in F2. The F1 contract used the
	 * `capabilities()` name for slugs; F2 reclaims that name for the
	 * richer chip shape. Existing adapters renamed in lockstep.
	 *
	 * @return string[]
	 */
	abstract public function feature_slugs(): array;

	/**
	 * Three-state status of the underlying plugin: 'active' | 'inactive' | 'absent'.
	 *
	 * @return string
	 */
	final public function status(): string {
		return Outpost_Companion_Detector::status( $this->file() );
	}

	/**
	 * True only when the underlying plugin is active. Adapters that need to surface
	 * "installed but not activated" UI should call {@see self::status()} directly.
	 *
	 * @return bool
	 */
	final public function is_active(): bool {
		return 'active' === $this->status();
	}

	/**
	 * Syndication-target chip shape this adapter contributes when its
	 * underlying plugin is active. Default returns null — feature-surface
	 * adapters keep the default; syndication-target adapters override.
	 *
	 * Shape (all keys required when non-null):
	 *
	 *     [
	 *         'id'              => 'activitypub',                          // stable machine ID
	 *         'label'           => __( 'Fediverse', 'outpost' ),           // i18n display name
	 *         'detected'        => true,                                   // sibling-plugin presence
	 *         'accepts_modes'   => [ 'note', 'photo', ... ],               // composer modes accepted
	 *         'accepts_media'   => [ 'image', 'video', 'audio' ],          // media kinds accepted
	 *         'max_attachments' => null,                                   // null = no companion-side limit
	 *         'alt_passthrough' => true,                                   // image alt text propagates
	 *         'char_limit'      => null,                                   // null = no companion-side limit
	 *         'caveats'         => [ __( 'Bluesky via Bridgy Fed has stricter limits.', 'outpost' ) ],
	 *         'requires_auth'   => false,                                  // user must connect creds first?
	 *     ]
	 *
	 * Adapters MUST short-circuit to null when `is_active()` is false so
	 * the registry's `active()` filter and the merger's redundancy check
	 * both stay honest.
	 *
	 * Adapters SHOULD apply the `outpost_companion_capabilities` filter
	 * before returning so site owners can override per-companion shape
	 * (force-hide a chip, extend caveats, restrict accepts_modes). The
	 * filter signature is `(?array $caps, string $companion_id) => ?array`.
	 *
	 * @return array{
	 *     id: string,
	 *     label: string,
	 *     detected: bool,
	 *     accepts_modes: string[],
	 *     accepts_media: string[],
	 *     max_attachments: int|null,
	 *     alt_passthrough: bool,
	 *     char_limit: int|null,
	 *     caveats: string[],
	 *     requires_auth: bool
	 * }|null
	 */
	public function capabilities(): ?array {
		return null;
	}
}
