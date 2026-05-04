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
 * F2 evolution: the chip shape grows from F1's
 * `{id, label, accepts, detected}` to the richer F2
 * `{id, label, detected, accepts_modes, accepts_media, max_attachments,
 * alt_passthrough, char_limit, caveats, requires_auth}` shape so the
 * per-mode chip filter and future companions (F9 ManualShare with
 * restricted accepts_modes, F14 BridgyPublish) share a uniform contract.
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
	 * Stable companion ID — also the chip uid surfaced via `?q=syndicate-to`.
	 */
	public const ID = 'activitypub';

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
	 * Composer feature slugs unlocked when the plugin is active.
	 *
	 * @return string[]
	 */
	public function feature_slugs(): array {
		return array( 'activitypub.federate' );
	}

	/**
	 * Syndication-target chip shape when the ActivityPub plugin is active.
	 *
	 * Conservative defaults — Outpost does not probe the user's
	 * configured fediverse instance for live `char_limit` or
	 * `max_attachments`. The ActivityPub plugin federates whatever the
	 * source post contains; instance-side limits apply downstream and
	 * are not Outpost's concern in F2. Per-instance probing is a future
	 * Phase G+ concern (instance API + caching + opt-in).
	 *
	 * `accepts_modes` lists every mode Outpost currently ships because
	 * the AP plugin federates any public post regardless of post-kind.
	 * Future adapters with restricted accepts_modes (F9 ManualShare for
	 * Instagram on photo-only, etc.) use the same key with a smaller
	 * list. The composer filters chips per mode via
	 * {@see Outpost_Companion_Registry::chips_for_mode()}.
	 *
	 * The chip label intentionally credits the ActivityPub plugin so
	 * users understand which sibling plugin owns the federation —
	 * Outpost is only signalling intent.
	 *
	 * The single caveat warns about Bluesky-via-Bridgy-Fed limits
	 * (~10 MB per image, ~1000 px maximum dimension, ~300 grapheme
	 * post truncation). It names the protocol generically — no specific
	 * Bluesky account, no instance handle. Bridgy Fed extends AP reach
	 * to Bluesky for any user; the limits are protocol-level facts, not
	 * user-specific.
	 *
	 * Applies the `outpost_companion_capabilities` filter before
	 * returning so site owners can override per their setup. Filter
	 * signature: `(?array $caps, string $companion_id) => ?array`.
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
		if ( ! $this->is_active() ) {
			return null;
		}
		$caps = array(
			'id'              => self::ID,
			'label'           => __( 'Fediverse (via ActivityPub plugin)', 'outpost' ),
			'detected'        => true,
			'accepts_modes'   => array(
				'note',
				'photo',
				'gallery',
				'article',
				'listen',
				'watch',
				'read',
				'play',
				'checkin',
				'reply',
				'like',
				'repost',
				'bookmark',
			),
			'accepts_media'   => array( 'image', 'video', 'audio' ),
			'max_attachments' => null,
			'alt_passthrough' => true,
			'char_limit'      => null,
			'caveats'         => array(
				__( 'Bluesky reach via Bridgy Fed has stricter limits than other fediverse networks (about 10 MB per image, 1000 pixels maximum dimension, 300 graphemes per post).', 'outpost' ),
				__( 'Photo alt text federates correctly only when Outpost is active alongside the ActivityPub plugin. Outpost\'s Micropub bridge writes alt text to the attachment image-alt meta key; without it, upstream Micropub plugins lose the alt text before federation reaches it.', 'outpost' ),
			),
			'requires_auth'   => false,
		);
		/**
		 * Filter the ActivityPub companion's capability shape.
		 *
		 * Site owners can use this to force-hide the chip (return null),
		 * extend the caveats array, or restrict accepts_modes per their
		 * federation policy. The companion ID is passed so callers
		 * filtering on multiple companions can dispatch on it.
		 *
		 * @param array|null $caps         The capability shape this adapter would return.
		 * @param string     $companion_id Stable companion ID ('activitypub').
		 */
		$filtered = apply_filters( 'outpost_companion_capabilities', $caps, self::ID );
		return is_array( $filtered ) || null === $filtered ? $filtered : $caps;
	}
}
