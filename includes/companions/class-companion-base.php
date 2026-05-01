<?php
/**
 * Abstract base every companion adapter extends in Phase F.
 *
 * The composer code never imports companion-specific code directly — it asks
 * the adapter what features it surfaces and renders accordingly. This base
 * defines the minimum contract every adapter must satisfy:
 *
 *  - {@see self::file()}         — which OUTPOST_*_PLUGIN_FILE the adapter wraps
 *  - {@see self::label()}        — brand name for admin UI strings
 *  - {@see self::capabilities()} — which composer feature surfaces this adapter unlocks
 *
 * Status helpers are concrete here so adapters all answer "are you active?" the
 * same way, via the central {@see Outpost_Companion_Detector}.
 *
 * Phase F (Companions) lands the actual subclasses: Post Kinds, Post Formats,
 * Link Extension for XFN, Syndication Links, Yoast SEO, ActivityPub.
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
}
