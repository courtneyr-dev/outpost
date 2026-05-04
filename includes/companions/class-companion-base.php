<?php
/**
 * Abstract base every companion adapter extends in Phase F.
 *
 * The composer code never imports companion-specific code directly — it asks
 * the adapter what features it surfaces and renders accordingly. This base
 * defines the minimum contract every adapter must satisfy:
 *
 *  - {@see self::file()}            — which OUTPOST_*_PLUGIN_FILE the adapter wraps
 *  - {@see self::label()}           — brand name for admin UI strings
 *  - {@see self::capabilities()}    — composer feature slugs this adapter unlocks
 *  - {@see self::syndicate_chip()}  — outbound Micropub `?q=syndicate-to` chip shape
 *
 * Status helpers are concrete here so adapters all answer "are you active?" the
 * same way, via the central {@see Outpost_Companion_Detector}.
 *
 * Phase F (Companions) lands the actual subclasses: Post Kinds, Post Formats,
 * Link Extension for XFN, Syndication Links, Yoast SEO, ActivityPub.
 *
 * Symmetry note (F1 / F5): {@see self::syndicate_chip()} declares the
 * outbound chip shape — `id`, `label`, `accepts`, `detected`. The inbound
 * `Outpost_Source_Base` planned for F5 mirrors this shape with the
 * extraction direction inverted: same `id` / `label` / `detected` / `accepts`
 * keys, plus an `extractor` key naming the metadata recipe (oembed, og,
 * mf2, rss, api). Outbound and inbound adapters thus share a uniform
 * capability-declaration vocabulary the composer can iterate over without
 * branching on direction.
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
	 * Capability slugs this adapter unlocks when the underlying plugin is active.
	 *
	 * Examples adapters in Phase F may return: 'post-kinds.listen', 'post-kinds.watch',
	 * 'syndication.chips', 'yoast.focus-keyphrase', 'xfn.relationships'. The composer
	 * iterates capabilities() to decide which UI surfaces to render — it does not
	 * branch on adapter class identity.
	 *
	 * @return string[]
	 */
	abstract public function capabilities(): array;

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
	 * Outbound syndication chip this adapter contributes when its underlying
	 * plugin is active. Default returns null — adapters that don't surface a
	 * Micropub `?q=syndicate-to` destination keep the default; adapters that
	 * do (ActivityPub in F1, Bridgy Publish in F14, ManualShare in F9)
	 * override.
	 *
	 * Shape:
	 *
	 *     [
	 *         'id'       => 'activitypub',
	 *         'label'    => __( 'Fediverse (via ActivityPub plugin)', 'outpost' ),
	 *         'accepts'  => [ 'note', 'photo', 'article' ],
	 *         'detected' => true,
	 *     ]
	 *
	 * The merger in {@see Outpost_Micropub_Bridges::register_syndicate_chips}
	 * converts `id` → `uid` and `label` → `name` for the
	 * `micropub_syndicate-to` filter the Micropub plugin (Shanske) consumes.
	 *
	 * Adapters MUST short-circuit to null when `is_active()` is false so the
	 * Companion_Registry's `active()` filter and the merger's redundancy check
	 * both stay honest.
	 *
	 * @return array{id: string, label: string, accepts: string[], detected: bool}|null
	 */
	public function syndicate_chip(): ?array {
		return null;
	}
}
