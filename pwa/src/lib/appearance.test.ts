import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
	AppearanceError,
	CACHE_KEY,
	apply_to_document,
	clear_cache,
	effective_scheme,
	is_start_screen,
	parse_state,
	read_cache,
	reconcile,
	sync_appearance,
	write_cache,
	type AppearanceStorage,
} from './appearance';

function memory_storage(): AppearanceStorage & { data: Map<string, string> } {
	const data = new Map<string, string>();
	return {
		data,
		getItem: (k) => data.get(k) ?? null,
		setItem: (k, v) => void data.set(k, v),
		removeItem: (k) => void data.delete(k),
	};
}

const throwing_storage: AppearanceStorage = {
	getItem: () => {
		throw new DOMException('denied', 'SecurityError');
	},
	setItem: () => {
		throw new DOMException('quota', 'QuotaExceededError');
	},
	removeItem: () => {
		throw new DOMException('denied', 'SecurityError');
	},
};

describe('appearance: device cache', () => {
	it('round-trips a valid state', () => {
		const storage = memory_storage();
		expect(write_cache({ mode: 'night', source: 'site', css: '.x{}', me: 'https://example.test/' }, storage)).toBe(true);
		expect(read_cache(storage)).toEqual({ mode: 'night', source: 'site', css: '.x{}', me: 'https://example.test/' });
	});

	it('never stores a credential-shaped field it was not given', () => {
		const storage = memory_storage();
		write_cache({ mode: 'day', source: 'outpost', css: '' }, storage);
		const raw = storage.data.get(CACHE_KEY) ?? '';
		expect(raw).not.toMatch(/token|bearer|password/i);
	});

	it('rejects unknown modes and sources', () => {
		expect(parse_state({ mode: 'sepia', source: 'site', css: '' })).toBeNull();
		expect(parse_state({ mode: 'day', source: 'forest', css: '' })).toBeNull();
		expect(parse_state('nope')).toBeNull();
	});

	it('keeps only valid pending fields', () => {
		expect(parse_state({ mode: 'day', source: 'outpost', css: '', pending: { mode: 'night', source: 'x' } })?.pending).toEqual({
			mode: 'night',
		});
	});

	it('survives denied or full storage', () => {
		expect(read_cache(throwing_storage)).toBeNull();
		expect(write_cache({ mode: 'day', source: 'outpost', css: '' }, throwing_storage)).toBe(false);
		expect(() => clear_cache(throwing_storage)).not.toThrow();
		expect(read_cache(null)).toBeNull();
	});

	it('clear_cache removes the entry', () => {
		const storage = memory_storage();
		write_cache({ mode: 'day', source: 'outpost', css: '' }, storage);
		clear_cache(storage);
		expect(read_cache(storage)).toBeNull();
	});
});

describe('appearance: effective scheme', () => {
	it('Light and Dark ignore the OS; System follows it', () => {
		expect(effective_scheme('day', true)).toBe('light');
		expect(effective_scheme('night', false)).toBe('dark');
		expect(effective_scheme('system', true)).toBe('dark');
		expect(effective_scheme('system', false)).toBe('light');
	});
});

describe('appearance: apply_to_document', () => {
	beforeEach(() => {
		document.body.className = 'outpost-composer-shell outpost-mode-system outpost-source-outpost';
		document.getElementById('outpost-appearance-tokens')?.remove();
	});

	it('replaces the mode and source classes instead of adding competing ones', () => {
		apply_to_document({ mode: 'night', source: 'site', css: '' }, 'dark');
		const classes = Array.from(document.body.classList);
		expect(classes.filter((c) => c.startsWith('outpost-mode-'))).toEqual(['outpost-mode-night']);
		expect(classes.filter((c) => c.startsWith('outpost-source-'))).toEqual(['outpost-source-site']);
		expect(classes).toContain('outpost-composer-shell');
		expect(document.body.dataset['outpostScheme']).toBe('dark');
	});

	it('writes the scoped CSS once and removes it for Outpost Default', () => {
		apply_to_document({ mode: 'day', source: 'site', css: '.outpost-source-site{--outpost-surface-bg:#fff}' }, 'light');
		apply_to_document({ mode: 'day', source: 'site', css: '.outpost-source-site{--outpost-surface-bg:#fff}' }, 'light');
		expect(document.querySelectorAll('#outpost-appearance-tokens')).toHaveLength(1);
		apply_to_document({ mode: 'day', source: 'outpost', css: '' }, 'light');
		expect(document.getElementById('outpost-appearance-tokens')).toBeNull();
	});
});

describe('appearance: sync_appearance transport', () => {
	let seen: { url: string; init: RequestInit } | null = null;
	const answer = (status: number, body: unknown): typeof fetch =>
		((url: string, init: RequestInit) => {
			seen = { url, init };
			return Promise.resolve(new Response(JSON.stringify(body), { status }));
		}) as unknown as typeof fetch;

	afterEach(() => {
		seen = null;
	});

	it('sends the token in the body and header, never the URL, without cookies', async () => {
		await sync_appearance('secret-token-abc', { source: 'site' }, { fetch: answer(200, { mode_preference: 'day', style_source: 'site', css: '' }) });
		expect(seen?.url).not.toContain('secret-token-abc');
		expect(seen?.init.credentials).toBe('omit');
		expect(seen?.init.method).toBe('POST');
		const body = Object.fromEntries(new URLSearchParams(String(seen?.init.body))) as Record<string, string>;
		expect(body['access_token']).toBe('secret-token-abc');
		expect(body['style_source']).toBe('site');
		expect(body).not.toHaveProperty('mode_preference');
	});

	it('a read sends only the token', async () => {
		await sync_appearance('t', {}, { fetch: answer(200, { mode_preference: 'system', style_source: 'outpost', css: '' }) });
		expect(Object.keys(Object.fromEntries(new URLSearchParams(String(seen?.init.body))) as object)).toEqual(['access_token']);
	});

	it.each([
		[401, 'unauthorized'],
		[403, 'forbidden'],
		[500, 'server'],
	])('maps HTTP %i to %s', async (status, code) => {
		await expect(sync_appearance('t', {}, { fetch: answer(status, {}) })).rejects.toMatchObject({ code });
	});

	it('maps a network failure to offline and an abort to timeout', async () => {
		await expect(sync_appearance('t', {}, { fetch: () => Promise.reject(new TypeError('Failed to fetch')) })).rejects.toMatchObject({
			code: 'offline',
		});
		const abort = new DOMException('aborted', 'AbortError');
		await expect(sync_appearance('t', {}, { fetch: () => Promise.reject(abort) })).rejects.toMatchObject({ code: 'timeout' });
	});

	it('rejects an unexpected response shape', async () => {
		await expect(sync_appearance('t', {}, { fetch: answer(200, { mode_preference: 'sepia' }) })).rejects.toBeInstanceOf(AppearanceError);
	});
});

describe('appearance: reconcile', () => {
	const server = { mode: 'day' as const, source: 'outpost' as const, css: '', start: 'home' as const };

	it('the server wins when nothing is pending', () => {
		expect(reconcile({ mode: 'night', source: 'site', css: 'x', start: 'reply' }, server, false)).toEqual({
			mode: 'day',
			source: 'outpost',
			css: '',
			start: 'home',
		});
	});

	it('a pending start screen survives a read that did not include it', () => {
		const out = reconcile({ mode: 'day', source: 'outpost', css: '', start: 'photo', pending: { start: 'photo' } }, server, false);
		expect(out.start).toBe('photo');
		expect(out.pending).toEqual({ start: 'photo' });
	});

	it('a newer device choice is not overwritten by an older server value', () => {
		const out = reconcile({ mode: 'night', source: 'site', css: 'site-css', pending: { mode: 'night', source: 'site' } }, server, false);
		expect(out.mode).toBe('night');
		expect(out.source).toBe('site');
		expect(out.css).toBe('site-css');
		expect(out.pending).toEqual({ mode: 'night', source: 'site' });
	});

	it('drops pending once it was sent', () => {
		expect(reconcile({ mode: 'night', source: 'site', css: '', pending: { mode: 'night' } }, server, true).pending).toBeUndefined();
	});
});

describe('appearance: start screen', () => {
	const answer = (body: unknown): typeof fetch => () => Promise.resolve(new Response(JSON.stringify(body), { status: 200 }));

	it('accepts all eight destinations and nothing else', () => {
		for (const screen of ['home', 'note', 'reply', 'photo', 'listen', 'life', 'recipe', 'about']) {
			expect(is_start_screen(screen)).toBe(true);
		}
		for (const value of ['settings', 'post', 'doing', '', null, 3]) {
			expect(is_start_screen(value)).toBe(false);
		}
	});

	it('sends start_screen only when it changes', async () => {
		let seen: Record<string, string> = {};
		const fetch = ((_: string, init: RequestInit) => {
			seen = Object.fromEntries(new URLSearchParams(String(init.body))) as Record<string, string>;
			return Promise.resolve(new Response(JSON.stringify({ mode_preference: 'system', style_source: 'outpost', start_screen: 'life' }), { status: 200 }));
		}) as unknown as typeof globalThis.fetch;
		const out = await sync_appearance('t', { start: 'life' }, { fetch });
		expect(seen['start_screen']).toBe('life');
		expect(seen).not.toHaveProperty('mode_preference');
		expect(out.start).toBe('life');
		await sync_appearance('t', {}, { fetch });
		expect(seen).not.toHaveProperty('start_screen');
	});

	it('reads Home when the site does not send a start screen', async () => {
		const out = await sync_appearance('t', {}, { fetch: answer({ mode_preference: 'day', style_source: 'site' }) });
		expect(out.start).toBe('home');
	});

	it('keeps a cached start screen and drops an invalid one', () => {
		expect(parse_state({ mode: 'day', source: 'outpost', css: '', start: 'recipe', pending: { start: 'recipe' } })).toMatchObject({
			start: 'recipe',
			pending: { start: 'recipe' },
		});
		const bad = parse_state({ mode: 'day', source: 'outpost', css: '', start: 'settings', pending: { start: 'nope' } });
		expect(bad?.start).toBeUndefined();
		expect(bad?.pending).toBeUndefined();
	});
});
