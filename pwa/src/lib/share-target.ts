/**
 * Share-target intake.
 *
 * Phase E0. The Web Share Target API delivers shared content from
 * other apps (iOS Share sheet, Android Sharesheet) by navigating to
 * /post/share-target?title=&text=&url=. We capture those params,
 * stash them in sessionStorage tagged with which composer tab should
 * consume them, then redirect the user to the regular composer URL
 * (/post/) so the URL bar reads cleanly and the user can refresh
 * without re-triggering the intake.
 *
 * Routing rule per the C5e design defaults:
 *
 *   - text + url   → Reply tab (target = url, content = text)
 *   - url only     → Reply tab (target = url)
 *   - text only    → Post tab, Note variant (content = text)
 *   - title + text → Post tab, Article variant (name = title, content = text)
 *   - nothing      → No-op; just open the composer
 *
 * sessionStorage is the right tier: same-origin, scoped to the tab,
 * cleared when the tab closes. We don't want share data persisting
 * across browser restarts — if the user closes Safari before posting,
 * the next launch should be a fresh composer.
 */

import { keep_shared_photos, type ShareInboxEnvironment } from './share-inbox';

export type ReplyVariant =
	| 'reply'
	| 'like'
	| 'favorite'
	| 'repost'
	| 'bookmark'
	| 'rsvp'
	| 'follow'
	| 'wishlist'
	| 'tag'
	| 'acquisition'
	| 'issue';

/** Doing-tab variants (the "listen" tab id renders ListenMode). */
export type DoingVariant =
	| 'listen'
	| 'watch'
	| 'read'
	| 'play'
	| 'game'
	| 'jam'
	| 'checkin'
	| 'eat'
	| 'drink'
	| 'exercise'
	| 'craft'
	| 'event'
	| 'review'
	| 'video'
	| 'audio';

/** Life-tab variants (content-only kinds rendered by LifeMode). */
export type LifeVariant =
	'mood' | 'weather' | 'sleep' | 'trip' | 'itinerary' | 'question';

export interface ShareTargetData {
	tab: 'note' | 'reply' | 'photo' | 'listen' | 'life' | 'recipe';
	/** Note tab variant — one of the 5 Post-tab variants. */
	variant?: 'note' | 'article' | 'status' | 'aside' | 'quote';
	/** Reply tab variant — one of the 6 Reply-tab variants. Set by E1 bookmarklets. */
	replyVariant?: ReplyVariant;
	/** Doing tab variant — set when the share-target dispatcher routes to Listen/Watch/Read/Play/etc. */
	doingVariant?: DoingVariant;
	/** Life tab variant — set when the dispatcher routes to Mood/Weather/Sleep/Trip/Itinerary/Question. */
	lifeVariant?: LifeVariant;
	title?: string;
	content?: string;
	url?: string;
	/** Source adapter id (e.g. 'spotify', 'youtube') — informational, may drive future routing decisions. */
	sourceId?: string;
	/** Photo tab only — the service worker parked shared image files in the share inbox for PhotoMode to drain. */
	sharedPhotos?: boolean;
	/**
	 * Photo tab only — another photo share arrived while two were already
	 * waiting, so the service worker didn't keep it.
	 */
	shareRefused?: boolean;
}

const REPLY_VARIANT_VALUES: ReplyVariant[] = [
	'reply',
	'like',
	'favorite',
	'repost',
	'bookmark',
	'rsvp',
	'follow',
	'wishlist',
	'tag',
	'acquisition',
	'issue',
];

const DOING_VARIANT_VALUES: DoingVariant[] = [
	'listen',
	'watch',
	'read',
	'play',
	'game',
	'jam',
	'checkin',
	'eat',
	'drink',
	'exercise',
	'craft',
	'event',
	'review',
	'video',
	'audio',
];

const LIFE_VARIANT_VALUES: LifeVariant[] = [
	'mood',
	'weather',
	'sleep',
	'trip',
	'itinerary',
	'question',
];

const KEY = 'outpost.share_target';
/** Where a share waits for the person's decision (see hold_share_for_review). */
const HELD_KEY = 'outpost.share_review';
/** A second share that arrived while the first still waited. */
const CANDIDATE_KEY = 'outpost.share_review_candidate';

/** Every sessionStorage slot a share can wait in: the stash and both review slots. */
const SHARE_SESSION_KEYS = [KEY, HELD_KEY, CANDIDATE_KEY] as const;

/**
 * The share epoch (P4B-07). Shares wait in sessionStorage, which belongs to
 * one tab, while the sign-in is shared by every tab on the origin. Clearing
 * pending shares (sign-out, a new sign-in) writes a new epoch to
 * localStorage; each tab records the epoch its shares arrived under, in its
 * own sessionStorage, and drops them when the two differ. So a share left
 * waiting in another tab never reaches the next account.
 */
const EPOCH_KEY = 'outpost.share_epoch';

function origin_epoch(): string | null {
	try {
		return globalThis.localStorage?.getItem(EPOCH_KEY) ?? null;
	} catch (_err) {
		return null;
	}
}

function drop_stale_shares(): void {
	const current = origin_epoch();
	if (null === current) return;
	try {
		const store = globalThis.sessionStorage;
		if (!store || store.getItem(EPOCH_KEY) === current) return;
		for (const key of SHARE_SESSION_KEYS) store.removeItem(key);
		store.setItem(EPOCH_KEY, current);
	} catch (_err) {
		// sessionStorage is blocked, so no share can be read from it either.
	}
}

function safe_session_get(key: string): string | null {
	drop_stale_shares();
	try {
		return globalThis.sessionStorage?.getItem(key) ?? null;
	} catch (_err) {
		return null;
	}
}

function safe_session_set(key: string, value: string): void {
	drop_stale_shares();
	try {
		globalThis.sessionStorage?.setItem(key, value);
	} catch (_err) {
		// no-op
	}
}

function safe_session_remove(key: string): void {
	try {
		globalThis.sessionStorage?.removeItem(key);
	} catch (_err) {
		// no-op
	}
}

/**
 * Parse F6 dispatcher query params landed on the composer route
 * (/post/?mode=...&picker=...&default=...&source=...&url=...).
 *
 * Returns null when no F6-shaped params are present — caller should
 * fall through to the legacy `parse_share_target` for the
 * Web-Share-Target Level 1 GET shape.
 *
 * Mapping:
 *   - `mode=note` (auto)            → tab: note (variant 'note')
 *   - `mode=reply` (auto)           → tab: reply
 *   - `picker=reply` + `default=X`  → tab: reply with X as initial variant
 *   - `mode=photo&shared=photos`    → tab: photo, with the shared pictures
 *                                     waiting in the share inbox (share-inbox.ts)
 */
export function parse_dispatch_params(search: string): ShareTargetData | null {
	const params = new URLSearchParams(search);
	const mode = (params.get('mode') ?? '').trim();
	const picker = (params.get('picker') ?? '').trim();
	const default_variant = (params.get('default') ?? '').trim();
	const url = (params.get('url') ?? '').trim();
	const text = (params.get('text') ?? '').trim();
	const title = (params.get('title') ?? '').trim();
	const source_id = (params.get('source') ?? '').trim();
	const shared = (params.get('shared') ?? '').trim();
	const refused = (params.get('refused') ?? '').trim() === '1';

	if (!mode && !picker) {
		return null;
	}

	// Reply tab: explicit reply mode + sub-modes (like, repost, bookmark, rsvp, follow).
	if (
		picker === 'reply' ||
		mode === 'reply' ||
		(REPLY_VARIANT_VALUES as string[]).includes(mode)
	) {
		const reply_variant: ReplyVariant | undefined =
			REPLY_VARIANT_VALUES.includes(default_variant as ReplyVariant)
				? (default_variant as ReplyVariant)
				: REPLY_VARIANT_VALUES.includes(mode as ReplyVariant)
					? (mode as ReplyVariant)
					: undefined;
		return {
			tab: 'reply',
			...(reply_variant ? { replyVariant: reply_variant } : {}),
			...(text ? { content: text } : {}),
			...(url ? { url } : {}),
			...(source_id ? { sourceId: source_id } : {}),
		};
	}

	// Note tab: note + article variants.
	if (mode === 'note' || mode === 'article') {
		return {
			tab: 'note',
			variant: mode === 'article' || (title && text) ? 'article' : 'note',
			...(title ? { title } : {}),
			...(text ? { content: text } : {}),
			...(url ? { url } : {}),
			...(source_id ? { sourceId: source_id } : {}),
		};
	}

	// Photo tab: photo + gallery (gallery routes through the same component).
	// `shared=photos` is the service worker saying it parked the shared
	// files in the inbox; ComposerTabs holds them for review, and PhotoMode
	// drains them only after the person chooses Use.
	if (mode === 'photo' || mode === 'gallery') {
		return {
			tab: 'photo',
			...(shared === 'photos' ? { sharedPhotos: true } : {}),
			...(shared === 'photos' && refused ? { shareRefused: true } : {}),
			...(text ? { content: text } : {}),
			...(url ? { url } : {}),
			...(source_id ? { sourceId: source_id } : {}),
		};
	}

	// Life tab (rendered by LifeMode): content-only kinds.
	if (LIFE_VARIANT_VALUES.includes(mode as LifeVariant)) {
		return {
			tab: 'life',
			lifeVariant: mode as LifeVariant,
			...(text ? { content: text } : {}),
			...(source_id ? { sourceId: source_id } : {}),
		};
	}

	// Recipe tab.
	if (mode === 'recipe') {
		return {
			tab: 'recipe',
			...(title ? { title } : {}),
			...(text ? { content: text } : {}),
			...(url ? { url } : {}),
			...(source_id ? { sourceId: source_id } : {}),
		};
	}

	// Doing tab (rendered by ListenMode). F7 (Spotify) emits mode=listen,
	// F15 (YouTube) emits mode=watch, F16 (Goodreads / Readwise) emits
	// mode=read, etc.
	if (DOING_VARIANT_VALUES.includes(mode as DoingVariant)) {
		return {
			tab: 'listen',
			doingVariant: mode as DoingVariant,
			...(text ? { content: text } : {}),
			...(url ? { url } : {}),
			...(source_id ? { sourceId: source_id } : {}),
		};
	}

	// Unknown mode — fall through to legacy parser. Better to land in
	// Reply tab with URL pre-filled than drop it entirely.
	if (mode || picker) {
		const fallback = parse_share_target(search);
		if (fallback) {
			return fallback;
		}
	}

	return null;
}

/**
 * Parse query params from a share-target URL into a tagged
 * ShareTargetData blob.
 *
 * Returns null when no shareable content was provided — the share
 * route has nothing to do with empty params, so we treat that as
 * "user navigated here directly" and just forward to the composer.
 */
export function parse_share_target(search: string): ShareTargetData | null {
	const params = new URLSearchParams(search);
	const title = (params.get('title') ?? '').trim();
	const text = (params.get('text') ?? '').trim();
	const url = (params.get('url') ?? '').trim();
	const variant_raw = (params.get('variant') ?? '').trim();
	const reply_variant: ReplyVariant | undefined =
		REPLY_VARIANT_VALUES.includes(variant_raw as ReplyVariant)
			? (variant_raw as ReplyVariant)
			: undefined;

	if (!title && !text && !url) {
		return null;
	}

	if (url) {
		return {
			tab: 'reply',
			...(reply_variant ? { replyVariant: reply_variant } : {}),
			...(text ? { content: text } : {}),
			url,
		};
	}

	if (title && text) {
		return {
			tab: 'note',
			variant: 'article',
			title,
			content: text,
		};
	}

	return {
		tab: 'note',
		variant: 'note',
		...(title ? { title } : {}),
		...(text ? { content: text } : {}),
	};
}

/**
 * Stash a share-target intake into sessionStorage so the composer
 * can consume it on its next mount.
 */
export function stash_share_target(data: ShareTargetData): void {
	safe_session_set(KEY, JSON.stringify(data));
}

/**
 * Read the stashed intake and clear it (one-shot consumption).
 *
 * Returns null when nothing's stashed or the value can't be parsed.
 * The caller is responsible for using the return value before the
 * next render — sessionStorage is cleared atomically here so a
 * subsequent peek() returns null.
 */
export function consume_share_target(): ShareTargetData | null {
	const raw = safe_session_get(KEY);
	if (!raw) return null;
	safe_session_remove(KEY);
	try {
		return JSON.parse(raw) as ShareTargetData;
	} catch (_err) {
		return null;
	}
}

/**
 * Read the stashed intake without clearing it.
 *
 * Used by ComposerTabs to decide which tab to focus when share data
 * is present, before each mode consumes its piece. Modes call
 * consume_share_target() to drain.
 */
export function peek_share_target(): ShareTargetData | null {
	return parse_share(safe_session_get(KEY));
}

/**
 * The composer tabs a share can open. Keyed by the `tab` union so a new
 * tab that isn't listed here fails to compile.
 */
const SHARE_TABS: Record<ShareTargetData['tab'], true> = {
	note: true,
	reply: true,
	photo: true,
	listen: true,
	life: true,
	recipe: true,
};

/**
 * Parse a stored share, or null when it isn't one the composer can open.
 * sessionStorage can hold an old value or a hand-edited one; a `tab` that
 * isn't a composer mode would open with no mode current (#323).
 */
function parse_share(raw: string | null): ShareTargetData | null {
	if (!raw) return null;
	try {
		const data: unknown = JSON.parse(raw);
		if (typeof data !== 'object' || data === null) return null;
		const tab: unknown = (data as { tab?: unknown }).tab;
		return typeof tab === 'string' && Object.hasOwn(SHARE_TABS, tab) ? (data as ShareTargetData) : null;
	} catch (_err) {
		return null;
	}
}

/** Shares waiting for a decision, earliest first (at most two). */
export interface HeldShares {
	shares: ShareTargetData[];
	/** A share arrived while two were already waiting and wasn't kept. */
	refused: boolean;
	/**
	 * The share epoch these shares were read under (P4B-07). Writes of a
	 * cached copy pass it back, so a review that outlived an account change
	 * in another tab can't save its shares under the new epoch.
	 */
	epoch: string | null;
}

/** Whether shares read under `epoch` still belong to the current sign-in (P4F). */
export function shares_still_current(epoch: string | null): boolean {
	return origin_epoch() === epoch;
}


/**
 * Write the waiting shares back to their two session slots, earliest first,
 * when `epoch` (from hold_share_for_review) is still current. Otherwise the
 * slots are dropped and nothing is written, and this returns false (P4F).
 */
export function set_held_shares(shares: ShareTargetData[], epoch: string | null): boolean {
	if (!shares_still_current(epoch)) {
		drop_stale_shares();
		return false;
	}
	write_held_shares(shares);
	return true;
}

/**
 * Anything past two is dropped; callers refuse a third share before this.
 */
function write_held_shares(shares: ShareTargetData[]): void {
	const [earlier, candidate] = shares;
	if (earlier) safe_session_set(HELD_KEY, JSON.stringify(earlier));
	else safe_session_remove(HELD_KEY);
	if (candidate) safe_session_set(CANDIDATE_KEY, JSON.stringify(candidate));
	else safe_session_remove(CANDIDATE_KEY);
}

/**
 * Take the stashed intake out of the modes' reach until the person decides.
 *
 * A share can come from any page, so the composer holds it for review
 * ("Use shared content" / "Discard shared content") instead of letting each
 * mode consume it on mount. Called once, before the modes render: it moves
 * the stash into a session slot, so a mode mounting now finds nothing.
 *
 * Two slots: a share that arrives while another waits becomes the new
 * share, and the person picks one. A third share replaces neither; it is
 * refused and `refused` says so. Both slots survive a reload until the
 * person decides. A photo share the service worker already refused (both
 * photo slots full) is refused here too, since its bytes were never kept.
 */
export function hold_share_for_review(): HeldShares {
	const fresh_raw = safe_session_get(KEY);
	safe_session_remove(KEY);
	const shares = [parse_share(safe_session_get(HELD_KEY)), parse_share(safe_session_get(CANDIDATE_KEY))].filter(
		(s): s is ShareTargetData => s !== null,
	);
	let refused = false;
	const fresh = parse_share(fresh_raw);
	if (fresh) {
		if (fresh.shareRefused || shares.length >= 2) {
			refused = true;
		} else {
			shares.push(fresh);
		}
	}
	write_held_shares(shares);
	return { shares, refused, epoch: origin_epoch() };
}

/**
 * "Use shared content": hand the earliest held share to its mode's normal
 * intake and clear both slots. Only called with a single share waiting.
 */
export function release_held_share(): ShareTargetData | null {
	const data = parse_share(safe_session_get(HELD_KEY));
	write_held_shares([]);
	if (!data) return null;
	safe_session_set(KEY, JSON.stringify(data));
	return data;
}

/** "Discard shared content" / "Discard both": drop every held share. */
export function discard_held_share(): void {
	write_held_shares([]);
}

/** Clearing pending shares failed; some may still be readable on this device. */
export class PendingSharesError extends Error {
	constructor(message: string) {
		super(message);
		this.name = 'PendingSharesError';
	}
}

export interface PendingSharesEnvironment {
	shareInbox: ShareInboxEnvironment;
	sessionStorage: Storage | undefined;
}

const default_pending_shares_env = (): PendingSharesEnvironment => ({
	shareInbox: { indexedDB: globalThis.indexedDB },
	sessionStorage: globalThis.sessionStorage,
});

/**
 * Forget every share waiting on this device (F-46): the photo inbox's
 * `pending` and `candidate` records, then the three sessionStorage slots.
 *
 * Shares carry no owner, so they belong to whoever is signed in when they
 * arrive. Sign-out and a new sign-in call this so the next account never
 * sees the last one's text, URLs or photos.
 *
 * The inbox goes first and its failure stops everything: the call rejects
 * with PendingSharesError and the sessionStorage slots stay as they were, so
 * the caller can keep the person signed in. A missing IndexedDB is a failure,
 * not an empty inbox.
 *
 * Each sessionStorage slot is read back after removal: a value still readable
 * rejects with PendingSharesError (P4A-01). A slot whose read is blocked
 * counts as cleared, since nobody can read a share from it. Shares waiting in
 * other tabs are dropped there through the share epoch (P4B-07).
 */
function session_storage_usable(store: Storage | undefined): boolean {
	if (!store) return false;
	try {
		store.getItem(EPOCH_KEY);
		return true;
	} catch (_err) {
		return false;
	}
}

export async function clear_pending_shares(
	env: PendingSharesEnvironment = default_pending_shares_env(),
): Promise<void> {
	// A new epoch first, so a share waiting in any other tab is dropped
	// there on its next read (P4B-07). It is read back: when other tabs can't
	// be told and this browser can hold shares in sessionStorage, the call
	// rejects before anything changes (P4F).
	const epoch = Date.now().toString(36) + Math.random().toString(36).slice(2);
	let told = false;
	try {
		globalThis.localStorage?.setItem(EPOCH_KEY, epoch);
		told = globalThis.localStorage?.getItem(EPOCH_KEY) === epoch;
	} catch (_err) {
		told = false;
	}
	if (!told && session_storage_usable(env.sessionStorage)) {
		throw new PendingSharesError('Other open tabs could not be told to drop shares waiting in them.');
	}
	try {
		await keep_shared_photos(null, env.shareInbox);
	} catch (err) {
		throw new PendingSharesError(
			'Shared photos waiting on this device could not be removed: ' +
				(err instanceof Error ? err.message : String(err)),
		);
	}
	for (const key of SHARE_SESSION_KEYS) {
		try {
			env.sessionStorage?.removeItem(key);
		} catch (_err) {
			// Checked by the read-back below.
		}
		let remaining: string | null;
		try {
			remaining = env.sessionStorage?.getItem(key) ?? null;
		} catch (_err) {
			remaining = null;
		}
		if (null !== remaining) {
			throw new PendingSharesError('A share waiting on this device could not be removed.');
		}
	}
}
