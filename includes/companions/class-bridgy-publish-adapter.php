<?php
/**
 * Outpost_Bridgy_Publish_Adapter
 *
 * Umbrella companion (Phase F14). Promotes the existing Bridgy
 * host-pattern logic to a full Companion_Base subclass that surfaces
 * the five May-2026-confirmed Bridgy Publish silos as syndication-
 * target chips: Mastodon, Bluesky, Flickr, GitHub, Reddit.
 *
 * Same architectural pattern as F9's `Outpost_Manual_Share_Adapter`:
 * always-active via `OUTPOST_PLUGIN_BASENAME`; chips come from
 * `platform_chips()` (one per ENABLED silo). Bridgy chip visibility
 * is gated by {@see Outpost_Bridgy_Publish_Settings} — a silo only
 * surfaces a chip when the user has explicitly opted in via the
 * Bridgy settings panel.
 *
 * F14 ships the chip surface + settings + response handling. The
 * webmention SENDER that fires when a post is published is deferred
 * to a future Phase G session — Outpost has no existing webmention
 * sender (per F12 #11). When a sender lands, the wiring is one
 * connector away.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Adapter extends Outpost_Companion_Base {

	public const ID = 'bridgy-publish';

	/**
	 * Always-active. The umbrella has no dedicated plugin file; it
	 * piggybacks on Outpost's own basename so the detector reports
	 * 'active' whenever this code is loaded (same pattern as F9
	 * Manual_Share).
	 */
	public function file(): string {
		return defined( 'OUTPOST_PLUGIN_BASENAME' ) ? (string) OUTPOST_PLUGIN_BASENAME : 'outpost/outpost.php';
	}

	/**
	 * Brand label. Bridgy is the protocol name (Ryan Barrett's
	 * webmention-to-silo proxy).
	 */
	public function label(): string {
		return 'Bridgy Publish';
	}

	/**
	 * Bridgy unlocks no composer feature surfaces; chips come from
	 * `platform_chips()` instead. Same shape as F9 Manual_Share.
	 *
	 * @return string[]
	 */
	public function feature_slugs(): array {
		return array();
	}

	/**
	 * The umbrella itself does not surface a single chip — chips are
	 * per-silo. Returning null prevents
	 * `Outpost_Micropub_Bridges::merge_syndicate_chips` from
	 * generating a meta-chip.
	 */
	public function capabilities(): ?array {
		return null;
	}

	/**
	 * Per-silo chip list. Each enabled silo produces one chip mirroring
	 * the F2 capabilities() shape so {@see Outpost_Companion_Registry::chips_for_mode()}
	 * doesn't need to branch on chip origin.
	 *
	 * Settings drive visibility: silos NOT enabled in
	 * `Outpost_Bridgy_Publish_Settings` are dropped before chip
	 * projection. Default state: all silos disabled (user opts in).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function platform_chips(): array {
		$out = array();
		foreach ( Outpost_Bridgy_Publish_Silo_Registry::all_silos() as $silo ) {
			if ( ! Outpost_Bridgy_Publish_Settings::is_enabled( $silo->id() ) ) {
				continue;
			}
			$out[] = $silo->to_chip();
		}
		return $out;
	}

	/**
	 * Static helper: whether a Bridgy chip with the given silo_id is
	 * currently enabled for the site. Used by F9's
	 * `Outpost_Manual_Share_Platform_Registry` deferral logic to
	 * decide whether reddit-manual / flickr-manual chips should
	 * suppress because the matching Bridgy chip is in play.
	 */
	public static function is_silo_enabled_by_silo_id( string $silo_id ): bool {
		foreach ( Outpost_Bridgy_Publish_Silo_Registry::all_silos() as $silo ) {
			if ( $silo->silo_id() === $silo_id
				&& Outpost_Bridgy_Publish_Settings::is_enabled( $silo->id() ) ) {
				return true;
			}
		}
		return false;
	}
}
