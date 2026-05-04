<?php
/**
 * ActivityPub adapter.
 *
 * Detects whether the Pfefferle/Automattic ActivityPub plugin
 * (`activitypub/activitypub.php`) is active. When it is, surfaces a
 * single `activitypub` syndication chip via Micropub `?q=syndicate-to`
 * so the composer can offer "send this post to the fediverse" as a
 * one-tap toggle. The ActivityPub plugin handles the actual federation
 * on `transition_post_status` — Outpost contributes nothing more than
 * the chip and the user's intent signal.
 *
 * Per F1 (concepts/posse-outbound-may-2026.md §7, §8), this adapter is
 * the highest-leverage outbound shipper because one detection covers
 * Mastodon, Pleroma, Akkoma, Misskey, Friendica, Hubzilla, Pixelfed,
 * plus Bluesky (via Bridgy Fed) and Threads (via the user's
 * Threads → Fediverse opt-in).
 *
 * §5 posture: detection is `is_plugin_active()` only — no calls into
 * the ActivityPub plugin's classes, no embedded credentials, no
 * external API calls.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_ActivityPub_Adapter extends Outpost_Companion_Base {

	/**
	 * Plugin file path the adapter wraps.
	 */
	public function file(): string {
		return OUTPOST_ACTIVITYPUB_PLUGIN_FILE;
	}

	/**
	 * Brand name. Not translated — ActivityPub is the protocol name.
	 */
	public function label(): string {
		return 'ActivityPub';
	}

	/**
	 * Composer capability slugs unlocked when the plugin is active.
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return array( 'activitypub.federate' );
	}

	/**
	 * Outbound syndicate-to chip surfaced when the ActivityPub plugin
	 * is active.
	 *
	 * The chip's user-facing label intentionally credits the ActivityPub
	 * plugin so users understand which sibling plugin owns the
	 * federation — the composer is only signalling intent. The `accepts`
	 * list reflects what ActivityPub federates per its v8.x feature set:
	 * notes, photos (via attachments), and full articles.
	 *
	 * @return array{id: string, label: string, accepts: string[], detected: bool}|null
	 */
	public function syndicate_chip(): ?array {
		if ( ! $this->is_active() ) {
			return null;
		}
		return array(
			'id'       => 'activitypub',
			'label'    => __( 'Fediverse (via ActivityPub plugin)', 'outpost' ),
			'accepts'  => array( 'note', 'photo', 'article' ),
			'detected' => true,
		);
	}
}
