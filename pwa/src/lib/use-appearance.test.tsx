import { render } from 'preact';
import { act } from 'preact/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CACHE_KEY, read_cache, write_cache, type AppearanceStorage } from './appearance';
import { useAppearance, type UseAppearance } from './use-appearance';

function memory_storage(): AppearanceStorage {
	const data = new Map<string, string>();
	return {
		getItem: (k) => data.get(k) ?? null,
		setItem: (k, v) => void data.set(k, v),
		removeItem: (k) => void data.delete(k),
	};
}

type Listener = (e: { matches: boolean }) => void;
let os_dark = false;
let mq_listeners: Listener[] = [];

beforeEach(() => {
	os_dark = false;
	mq_listeners = [];
	vi.stubGlobal('matchMedia', (query: string) => ({
		matches: query.includes('dark') ? os_dark : false,
		media: query,
		addEventListener: (_: string, fn: Listener) => mq_listeners.push(fn),
		removeEventListener: (_: string, fn: Listener) => (mq_listeners = mq_listeners.filter((l) => l !== fn)),
	}));
	document.body.className = 'outpost-composer-shell outpost-mode-system outpost-source-outpost';
});

afterEach(() => {
	vi.unstubAllGlobals();
});

function harness(options: Parameters<typeof useAppearance>[0]): { get: () => UseAppearance; root: HTMLElement } {
	let latest: UseAppearance | null = null;
	function Probe(): null {
		latest = useAppearance(options);
		return null;
	}
	const root = document.createElement('div');
	document.body.appendChild(root);
	act(() => render(<Probe />, root));
	return { get: () => latest as unknown as UseAppearance, root };
}

const server = (mode = 'system', source = 'outpost', css = ''): Response =>
	new Response(JSON.stringify({ mode_preference: mode, style_source: source, css }), { status: 200 });

async function settle(): Promise<void> {
	for (let i = 0; i < 6; i++) {
		await act(async () => {
			await new Promise((r) => setTimeout(r, 0));
		});
	}
}

describe('useAppearance', () => {
	it('a new user starts at Outpost Default + System', async () => {
		const storage = memory_storage();
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage, env: { fetch: () => Promise.resolve(server()) } });
		await settle();
		expect(h.get().state.mode).toBe('system');
		expect(h.get().state.source).toBe('outpost');
		expect(document.body.classList.contains('outpost-source-outpost')).toBe(true);
	});

	it('an offline save applies on this device, says so, and stays pending', async () => {
		const storage = memory_storage();
		const fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage, env: { fetch } });
		await settle();
		act(() => h.get().setSource('site'));
		await settle();
		expect(document.body.classList.contains('outpost-source-site')).toBe(true);
		expect(h.get().status).toMatchObject({ kind: 'device-only', reason: 'offline', stored: true });
		expect(read_cache(storage)?.pending).toEqual({ source: 'site' });
	});

	it('sends a pending choice on the next contact and reports it saved', async () => {
		const storage = memory_storage();
		write_cache({ mode: 'night', source: 'site', css: '', me: 'https://a.test/', pending: { mode: 'night' } }, storage);
		const bodies: Array<Record<string, string>> = [];
		const fetch = vi.fn((_: string, init: RequestInit) => {
			bodies.push(JSON.parse(String(init.body)) as Record<string, string>);
			return Promise.resolve(server('night', 'site', '.outpost-source-site{}'));
		});
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage, env: { fetch: fetch as unknown as typeof globalThis.fetch } });
		await settle();
		expect(bodies[0]?.['mode_preference']).toBe('night');
		expect(h.get().status.kind).toBe('saved');
		expect(read_cache(storage)?.pending).toBeUndefined();
	});

	it('the server value wins over a stale, non-pending device copy', async () => {
		const storage = memory_storage();
		write_cache({ mode: 'night', source: 'outpost', css: '', me: 'https://a.test/' }, storage);
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage, env: { fetch: () => Promise.resolve(server('day', 'site')) } });
		await settle();
		expect(h.get().state.mode).toBe('day');
		expect(h.get().state.source).toBe('site');
	});

	it("ignores another account's device copy", () => {
		const storage = memory_storage();
		write_cache({ mode: 'night', source: 'site', css: '', me: 'https://other.test/' }, storage);
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage, env: { fetch: () => new Promise(() => undefined) } });
		expect(h.get().state.mode).toBe('system');
		expect(h.get().state.source).toBe('outpost');
	});

	it('picks up a change made in another tab', () => {
		const storage = memory_storage();
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage, env: { fetch: () => new Promise(() => undefined) } });
		write_cache({ mode: 'day', source: 'site', css: '', me: 'https://a.test/' }, storage);
		act(() => {
			window.dispatchEvent(new StorageEvent('storage', { key: CACHE_KEY }));
		});
		expect(h.get().state.mode).toBe('day');
		expect(document.body.classList.contains('outpost-mode-day')).toBe(true);
	});

	it('System follows an OS change while open; Light does not', () => {
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage: memory_storage(), env: { fetch: () => new Promise(() => undefined) } });
		expect(h.get().scheme).toBe('light');
		act(() => mq_listeners.forEach((l) => l({ matches: true })));
		expect(h.get().scheme).toBe('dark');
		expect(document.body.dataset['outpostScheme']).toBe('dark');
		act(() => h.get().setMode('day'));
		expect(h.get().scheme).toBe('light');
	});

	it('still works when storage is denied', async () => {
		const denied: AppearanceStorage = {
			getItem: () => {
				throw new DOMException('denied', 'SecurityError');
			},
			setItem: () => {
				throw new DOMException('denied', 'SecurityError');
			},
			removeItem: () => undefined,
		};
		const h = harness({ accessToken: 't', me: 'https://a.test/', storage: denied, env: { fetch: () => Promise.reject(new TypeError('x')) } });
		act(() => h.get().setMode('night'));
		await settle();
		expect(document.body.classList.contains('outpost-mode-night')).toBe(true);
		expect(h.get().status).toMatchObject({ kind: 'device-only', stored: false });
	});
});
