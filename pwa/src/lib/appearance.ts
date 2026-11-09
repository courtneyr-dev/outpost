/**
 * Composer preferences: Light / Dark / System (`mode`), Outpost Default /
 * Use Site Theme (`source`), and the screen a normal launch opens to
 * (`start`). The start screen rides along because it needs the same
 * per-user storage, device copy, and offline reconciliation.
 *
 * The server stores both per user (`/wp-json/outpost/v1/appearance/tokens`).
 * This module keeps a device copy in localStorage so
 * `assets/js/appearance-boot.js` can apply it before first paint, and so a
 * choice made offline stays put. The device copy holds preferences and
 * token CSS only. The sign-in token stays in encrypted IndexedDB and is
 * never written here.
 *
 * Reconciliation: a choice the person makes is applied at once and marked
 * `pending` until the server confirms it. On the next successful contact
 * a pending choice is sent first; otherwise the server's state wins. So a
 * newer choice made on this device is never overwritten by an older server
 * value, and a choice made on another device arrives here on next load.
 *
 * Decision: docs/decisions/session-pwa-ui-appearance.md.
 */

export type AppearanceMode = 'day' | 'night' | 'system';
export type StyleSource = 'outpost' | 'site';
export type EffectiveScheme = 'light' | 'dark';
/** Home, the six composing tabs (Post is `note`, Doing is `listen`), then About. */
export type StartScreen = 'home' | 'note' | 'reply' | 'photo' | 'listen' | 'life' | 'recipe' | 'about';

export const START_SCREENS: readonly StartScreen[] = ['home', 'note', 'reply', 'photo', 'listen', 'life', 'recipe', 'about'];

export const CACHE_KEY = 'outpost.appearance.v1';
const STYLE_ELEMENT_ID = 'outpost-appearance-tokens';
const ENDPOINT_PATH = '/wp-json/outpost/v1/appearance/tokens';

export interface AppearanceState {
	mode: AppearanceMode;
	source: StyleSource;
	/** Scoped token CSS for the source; '' when the token file covers it. */
	css: string;
	/** Screen a normal launch opens to; absent until the site or this device has said. */
	start?: StartScreen;
	/** Choices made on this device the server hasn't confirmed yet. */
	pending?: PreferenceChanges;
	/** The signed-in `me` URL the cache belongs to. */
	me?: string;
}

export interface PreferenceChanges {
	mode?: AppearanceMode;
	source?: StyleSource;
	start?: StartScreen;
}

export const NEW_USER_DEFAULT: AppearanceState = { mode: 'system', source: 'outpost', css: '' };

export function is_mode(value: unknown): value is AppearanceMode {
	return value === 'day' || value === 'night' || value === 'system';
}

export function is_source(value: unknown): value is StyleSource {
	return value === 'outpost' || value === 'site';
}

export function is_start_screen(value: unknown): value is StartScreen {
	return typeof value === 'string' && (START_SCREENS as readonly string[]).includes(value);
}

/* ------------------------------------------------------------------ */
/* Device cache                                                        */
/* ------------------------------------------------------------------ */

export interface AppearanceStorage {
	getItem(key: string): string | null;
	setItem(key: string, value: string): void;
	removeItem(key: string): void;
}

function default_storage(): AppearanceStorage | null {
	try {
		return typeof window !== 'undefined' && window.localStorage ? window.localStorage : null;
	} catch {
		return null;
	}
}

export function read_cache(storage: AppearanceStorage | null = default_storage()): AppearanceState | null {
	if (!storage) return null;
	try {
		const raw: unknown = JSON.parse(storage.getItem(CACHE_KEY) ?? 'null');
		return parse_state(raw);
	} catch {
		return null;
	}
}

/** Returns false when storage is unavailable or full. */
export function write_cache(
	state: AppearanceState,
	storage: AppearanceStorage | null = default_storage(),
): boolean {
	if (!storage) return false;
	try {
		storage.setItem(CACHE_KEY, JSON.stringify(state));
		return true;
	} catch {
		return false;
	}
}

/** Sign-out clears the device copy so the next person starts fresh. */
export function clear_cache(storage: AppearanceStorage | null = default_storage()): void {
	if (!storage) return;
	try {
		storage.removeItem(CACHE_KEY);
	} catch {
		// Storage blocked: nothing to clear.
	}
}

export function parse_state(raw: unknown): AppearanceState | null {
	if (!raw || typeof raw !== 'object') return null;
	const r = raw as Record<string, unknown>;
	if (!is_mode(r.mode) || !is_source(r.source)) return null;
	const state: AppearanceState = {
		mode: r.mode,
		source: r.source,
		css: typeof r.css === 'string' ? r.css : '',
	};
	if (typeof r.me === 'string') state.me = r.me;
	if (is_start_screen(r.start)) state.start = r.start;
	if (r.pending && typeof r.pending === 'object') {
		const p = r.pending as Record<string, unknown>;
		const pending: PreferenceChanges = {};
		if (is_mode(p.mode)) pending.mode = p.mode;
		if (is_source(p.source)) pending.source = p.source;
		if (is_start_screen(p.start)) pending.start = p.start;
		if (pending.mode || pending.source || pending.start) state.pending = pending;
	}
	return state;
}

/* ------------------------------------------------------------------ */
/* Applying to the document                                            */
/* ------------------------------------------------------------------ */

/**
 * Put the state on the document: one `outpost-mode-*` and one
 * `outpost-source-*` class on <body> (replacing, never adding a second),
 * the scoped CSS in a single <style>, and `data-outpost-scheme` with the
 * effective scheme for anything that needs it in JS.
 */
export function apply_to_document(
	state: AppearanceState,
	scheme: EffectiveScheme,
	doc: Document = document,
): void {
	const body = doc.body;
	if (!body) return;
	const keep = Array.from(body.classList).filter(
		(c) => !c.startsWith('outpost-mode-') && !c.startsWith('outpost-source-'),
	);
	body.className = [...keep, `outpost-mode-${state.mode}`, `outpost-source-${state.source}`].join(' ');
	body.dataset['outpostScheme'] = scheme;

	let style = doc.getElementById(STYLE_ELEMENT_ID);
	if (state.css === '') {
		style?.remove();
	} else {
		if (!style) {
			style = doc.createElement('style');
			style.id = STYLE_ELEMENT_ID;
			doc.head.appendChild(style);
		}
		if (style.textContent !== state.css) style.textContent = state.css;
	}
}

/** Light always renders light, Dark always dark, System follows the OS. */
export function effective_scheme(mode: AppearanceMode, prefers_dark: boolean): EffectiveScheme {
	if (mode === 'day') return 'light';
	if (mode === 'night') return 'dark';
	return prefers_dark ? 'dark' : 'light';
}

/* ------------------------------------------------------------------ */
/* Server                                                              */
/* ------------------------------------------------------------------ */

export type AppearanceErrorCode = 'offline' | 'timeout' | 'unauthorized' | 'forbidden' | 'server' | 'invalid_response';

export class AppearanceError extends Error {
	constructor(
		message: string,
		public readonly code: AppearanceErrorCode,
	) {
		super(message);
		this.name = 'AppearanceError';
	}
}

export interface AppearanceEnvironment {
	fetch: typeof fetch;
	timeoutMs?: number;
}

const default_env = (): AppearanceEnvironment => ({ fetch: globalThis.fetch.bind(globalThis) });

export interface ServerAppearance {
	mode: AppearanceMode;
	source: StyleSource;
	css: string;
	start: StartScreen;
}

/**
 * Read (no `changes`) or save the state. Always a POST so the token can
 * ride in the body on hosts that strip the Authorization header; a POST
 * without changes writes nothing on the server. `credentials: 'omit'`
 * keeps a wp-admin cookie from turning this into a nonce-less cookie
 * request (CLAUDE.md, cookie + bearer collision).
 */
export async function sync_appearance(
	access_token: string,
	changes: PreferenceChanges = {},
	env: AppearanceEnvironment = default_env(),
): Promise<ServerAppearance> {
	const body: Record<string, string> = { access_token };
	if (changes.mode) body['mode_preference'] = changes.mode;
	if (changes.source) body['style_source'] = changes.source;
	if (changes.start) body['start_screen'] = changes.start;

	const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
	const timer = controller ? setTimeout(() => controller.abort(), env.timeoutMs ?? 10_000) : null;
	let response: Response;
	try {
		response = await env.fetch(ENDPOINT_PATH + '?_t=' + String(Date.now()), {
			method: 'POST',
			credentials: 'omit',
			cache: 'no-store',
			headers: {
				Authorization: 'Bearer ' + access_token,
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			body: JSON.stringify(body),
			...(controller ? { signal: controller.signal } : {}),
		});
	} catch (err) {
		// A fetch that never got a response: the site is unreachable from
		// here (offline, DNS, or connection reset), or the timer fired.
		const aborted = err instanceof Error && err.name === 'AbortError';
		throw new AppearanceError('Appearance request failed', aborted ? 'timeout' : 'offline');
	} finally {
		if (timer) clearTimeout(timer);
	}

	if (response.status === 401) throw new AppearanceError('Sign-in expired', 'unauthorized');
	if (response.status === 403) throw new AppearanceError('Not allowed', 'forbidden');
	if (!response.ok) throw new AppearanceError('Server error ' + String(response.status), 'server');

	let data: unknown;
	try {
		data = await response.json();
	} catch {
		throw new AppearanceError('Invalid JSON', 'invalid_response');
	}
	const d = (data ?? {}) as Record<string, unknown>;
	if (!is_mode(d['mode_preference']) || !is_source(d['style_source'])) {
		throw new AppearanceError('Unexpected response shape', 'invalid_response');
	}
	return {
		mode: d['mode_preference'],
		source: d['style_source'],
		css: typeof d['css'] === 'string' ? d['css'] : '',
		// A site still on 1.0.24 doesn't send it; Home is the default there too.
		start: is_start_screen(d['start_screen']) ? d['start_screen'] : 'home',
	};
}

/**
 * Merge the server's answer with the device copy. A pending local choice
 * survives a read that didn't include it (the caller sends it next).
 */
export function reconcile(local: AppearanceState | null, server: ServerAppearance, sent_pending: boolean): AppearanceState {
	const out: AppearanceState = { mode: server.mode, source: server.source, css: server.css, start: server.start };
	if (local?.me) out.me = local.me;
	if (local?.pending && !sent_pending) {
		if (local.pending.mode) out.mode = local.pending.mode;
		if (local.pending.start) out.start = local.pending.start;
		if (local.pending.source) {
			out.source = local.pending.source;
			// The server's CSS is for its source; drop it until resent.
			if (local.pending.source !== server.source) out.css = local.css;
		}
		out.pending = { ...local.pending };
	}
	return out;
}
