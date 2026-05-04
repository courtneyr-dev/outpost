<?php
/**
 * Outpost_Manual_Share_Adapter
 *
 * Umbrella companion (Phase F9). Registers manual-share targets for the
 * §5-impossible silos: Instagram (feed + Stories), Facebook, X, LinkedIn,
 * Threads, TikTok, Pinterest, Reddit-manual, Flickr-manual. The umbrella
 * is ALWAYS active — manual share works on every Outpost site because
 * the user is their own bridge to those silos. No sibling-plugin
 * detection gates this adapter.
 *
 * F9 ships the declarative layer only:
 *
 *   - {@see Outpost_Manual_Share_Platform_Config} validates each platform's
 *     declarative config (caption_via, ios_strategy, android_pkg, etc).
 *   - {@see Outpost_Manual_Share_Platform_Registry} holds the 10 default
 *     platforms and applies the `outpost_manual_share_platforms` filter
 *     so site owners can append, modify, or remove without forking.
 *   - This adapter exposes `platform_chips()` which the registry's
 *     `chips_for_mode()` enumerates alongside companion `capabilities()`
 *     so manual-share chips appear in the composer's syndication strip
 *     when the mode accepts them.
 *   - F10 lands Android intent firing; F11 lands iOS; F12 lands silo URL
 *     capture; F13 lands audit logging surfacing.
 *
 * Reddit-manual and Flickr-manual platforms declare `prefers_bridgy =>
 * true` so when F14's Companion_BridgyPublish detects Bridgy is
 * configured, those chips drop from the manual list — Bridgy becomes
 * the preferred outbound route. Until F14 lands, the Bridgy-detection
 * helper always reports false and both chips show in the manual list.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Adapter extends Outpost_Companion_Base {

	/** Stable companion ID. */
	public const ID = 'manual-share';

	/**
	 * The umbrella is always active. {@see Outpost_Companion_Base::file()}
	 * is final and routes through {@see Outpost_Companion_Detector::status()}
	 * which calls is_plugin_active() — so we return Outpost's own basename,
	 * which is necessarily active when this code runs.
	 */
	public function file(): string {
		return defined( 'OUTPOST_PLUGIN_BASENAME' ) ? (string) OUTPOST_PLUGIN_BASENAME : 'outpost/outpost.php';
	}

	/**
	 * Brand label. Not translated — manual share is the protocol/pattern name.
	 */
	public function label(): string {
		return 'Manual Share';
	}

	/**
	 * Manual share unlocks no composer feature surfaces; chips come from
	 * `platform_chips()` instead. Empty array preserves the
	 * feature-surface-vs-syndication-target distinction documented on
	 * Companion_Base.
	 *
	 * @return string[]
	 */
	public function feature_slugs(): array {
		return array();
	}

	/**
	 * The umbrella itself does not contribute a single syndicate-to chip
	 * — each platform_chips() entry is its own chip. Returning null keeps
	 * `Outpost_Micropub_Bridges::merge_syndicate_chips()` (which projects
	 * `capabilities()` to the [uid, name] shape) from generating a
	 * meta-chip for the umbrella.
	 */
	public function capabilities(): ?array {
		return null;
	}

	/**
	 * Per-platform chip list. Each entry mirrors the
	 * {@see Outpost_Companion_Base::capabilities()} shape so the
	 * registry's `chips_for_mode()` doesn't need to branch on chip
	 * origin (umbrella-platform vs. single-companion). Manual-share-
	 * specific extension data lives under the chip's `manual_share`
	 * key (icon, caption_via, ios/android/web routing, after_share).
	 *
	 * Bridgy-defer logic: any platform with `prefers_bridgy => true`
	 * drops out when {@see self::should_defer_to_bridgy()} reports
	 * Bridgy is configured. Until F14 lands, the detection helper
	 * returns false and Reddit-manual + Flickr-manual remain visible.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function platform_chips(): array {
		$defer_to_bridgy = self::should_defer_to_bridgy();
		$out             = array();
		foreach ( Outpost_Manual_Share_Platform_Registry::all_platforms() as $platform ) {
			if ( $defer_to_bridgy && $platform->prefers_bridgy() ) {
				continue;
			}
			$out[] = $platform->to_chip();
		}
		return $out;
	}

	/**
	 * Whether F14's Companion_BridgyPublish reports Bridgy is configured.
	 * Static so tests can override via the `outpost_manual_share_defer_to_bridgy`
	 * filter; F14 replaces this with real detection.
	 *
	 * Defaults to false — until F14 lands, no Bridgy detection exists,
	 * so manual chips for Reddit and Flickr remain visible. Once F14
	 * ships, the helper consults Companion_BridgyPublish::is_configured()
	 * and the prefers_bridgy chips drop automatically.
	 */
	public static function should_defer_to_bridgy(): bool {
		/**
		 * Filter whether manual-share chips for `prefers_bridgy` platforms
		 * should hide because Bridgy Publish is configured.
		 *
		 * F9 default: false (Bridgy detection lands in F14). Site owners
		 * who want to force-hide manual Reddit/Flickr chips ahead of
		 * F14 can return true via this filter.
		 *
		 * @param bool $defer Default false.
		 */
		return (bool) apply_filters( 'outpost_manual_share_defer_to_bridgy', false );
	}
}
