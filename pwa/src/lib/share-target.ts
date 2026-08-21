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
export type LifeVariant = 'mood' | 'weather' | 'sleep' | 'trip' | 'itinerary' | 'question';

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
	/** Cached preview transient token from F6 dispatcher; lets the mode component fetch parsed metadata via /preview. */
	cachedFor?: string;
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

function safe_session_get(key: string): string | null {
	try {
		return globalThis.sessionStorage?.getItem(key) ?? null;
	} catch (_err) {
		return null;
	}
}

function safe_session_set(key: string, value: string): void {
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
 * (/post/?mode=...&picker=...&default=...&source=...&cached_for=...&url=...).
 *
 * Returns null when no F6-shaped params are present — caller should
 * fall through to the legacy `parse_share_target` for the
 * Web-Share-Target Level 1 GET shape.
 *
 * Mapping:
 *   - `mode=note` (auto)            → tab: note (variant 'note')
 *   - `mode=reply` (auto)           → tab: reply
 *   - `picker=reply` + `default=X`  → tab: reply with X as initial variant
 *
 * The `cached_for` token is preserved in sessionStorage alongside
 * the dispatch data so the composer can read the pre-fill transient
 * once it mounts.
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
	const cached_for = (params.get('cached_for') ?? '').trim();

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
			...(cached_for ? { cachedFor: cached_for } : {}),
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
			...(cached_for ? { cachedFor: cached_for } : {}),
		};
	}

	// Photo tab: photo + gallery (gallery routes through the same component).
	if (mode === 'photo' || mode === 'gallery') {
		return {
			tab: 'photo',
			...(text ? { content: text } : {}),
			...(url ? { url } : {}),
			...(source_id ? { sourceId: source_id } : {}),
			...(cached_for ? { cachedFor: cached_for } : {}),
		};
	}

	// Life tab (rendered by LifeMode): content-only kinds.
	if (LIFE_VARIANT_VALUES.includes(mode as LifeVariant)) {
		return {
			tab: 'life',
			lifeVariant: mode as LifeVariant,
			...(text ? { content: text } : {}),
			...(source_id ? { sourceId: source_id } : {}),
			...(cached_for ? { cachedFor: cached_for } : {}),
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
			...(cached_for ? { cachedFor: cached_for } : {}),
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
			...(cached_for ? { cachedFor: cached_for } : {}),
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
	const raw = safe_session_get(KEY);
	if (!raw) return null;
	try {
		return JSON.parse(raw) as ShareTargetData;
	} catch (_err) {
		return null;
	}
}
