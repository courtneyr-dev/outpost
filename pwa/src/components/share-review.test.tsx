/**
 * Shared content waits for an explicit decision (T10-S3-05 v1.1).
 *
 * A share (system share sheet, bookmarklet, Shortcut, or a link carrying
 * share parameters) can come from any page, so it is untrusted until the
 * person chooses "Use shared content". Before that choice nothing from the
 * share reaches a composer form, a draft, a preview request, Micropub, or the
 * offline queue, and typing in the form doesn't count as accepting it.
 * "Discard shared content" removes it without touching a draft the person
 * already had. Photo shares wait in the share inbox; when a second one
 * arrives the person keeps the earlier one, uses the new one, or discards both.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { render } from 'preact';
import { ComposerTabs } from './composer-tabs';
import type { StoredToken } from '../lib/token-store';
import type { MicropubEnvironment } from '../lib/micropub';
import { write_cache } from '../lib/appearance';
import { stash_share_target, type ShareTargetData } from '../lib/share-target';
import { DRAFT_SAVE_DELAY_MS, read_draft, write_draft, type DraftMode } from '../lib/drafts';
import { stash_shared_photos, read_share_inbox, type ShareInboxEnvironment } from '../lib/share-inbox';
import { list } from '../lib/offline-queue';
import { fetch_preview, fetch_source_preview } from '../lib/preview';

vi.mock('../lib/preview', async (importOriginal) => {
	const mod = await importOriginal<typeof import('../lib/preview')>();
	return {
		...mod,
		fetch_preview: vi.fn(mod.fetch_preview),
		fetch_source_preview: vi.fn(mod.fetch_source_preview),
	};
});

const ME = 'https://example.test/';
const token: StoredToken = { accessToken: 'test-token', tokenType: 'Bearer', scope: 'create', me: ME, storedAt: 0 };
const SHARED_TEXT = 'Shared body text';

let root: HTMLDivElement;
let inbox_env: ShareInboxEnvironment;
let posted: string[];

const micropub: MicropubEnvironment = {
	fetch: async (_input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
		if (init?.method === 'POST') {
			posted.push(String(init.body ?? ''));
			return new Response('', { status: 201, headers: { Location: 'https://example.test/p/1' } });
		}
		return new Response('<html></html>', { status: 200, headers: { Link: '<https://example.test/mp>; rel="micropub"' } });
	},
};

function mount(): void {
	render(
		<ComposerTabs
			token={token}
			tokenStore={{ indexedDB: globalThis.indexedDB, crypto: globalThis.crypto }}
			micropubEnv={micropub}
			composerConfigEnv={{ fetch: ((): Promise<Response> => new Promise(() => {})) as typeof fetch }}
			appearanceEnv={{ fetch: () => Promise.reject(new TypeError('offline')) }}
			queueEnv={{ indexedDB: globalThis.indexedDB }}
			shareInboxEnv={inbox_env}
		/>,
		root,
	);
}

async function wait(ms: number): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, ms));
}

function button(name: string): HTMLButtonElement | null {
	const found = Array.from(root.querySelectorAll('button')).find((b) => (b.textContent ?? '').trim() === name);
	return (found ?? null) as HTMLButtonElement | null;
}

function click(name: string): void {
	const b = button(name);
	expect(b, `button "${name}"`).not.toBeNull();
	b?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
}

function field(id: string): HTMLInputElement | HTMLTextAreaElement {
	return root.querySelector('#' + id) as HTMLInputElement | HTMLTextAreaElement;
}

function type(id: string, value: string): void {
	const el = field(id);
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

function region(): HTMLElement | null {
	return root.querySelector('[data-outpost-share-review]');
}

/** Controls a person can reach: not inside a hidden or inert subtree, and not disabled. */
function reachable(): Element[] {
	return Array.from(root.querySelectorAll('button, input, textarea, select, a[href]')).filter(
		(el) => !el.closest('[hidden], [inert]') && !(el as HTMLButtonElement).disabled,
	);
}

function remount(): void {
	render(null, root);
	mount();
}

function preview_calls(): number {
	return vi.mocked(fetch_preview).mock.calls.length + vi.mocked(fetch_source_preview).mock.calls.length;
}

async function queued_shared(): Promise<number> {
	const entries = await list({ indexedDB: globalThis.indexedDB });
	return entries.filter((e) => JSON.stringify(e.properties).includes('Shared')).length;
}

beforeEach(() => {
	localStorage.clear();
	sessionStorage.clear();
	globalThis.indexedDB = new IDBFactory();
	inbox_env = { indexedDB: globalThis.indexedDB };
	posted = [];
	vi.mocked(fetch_preview).mockClear();
	vi.mocked(fetch_source_preview).mockClear();
	write_cache({ mode: 'system', source: 'outpost', css: '', start: 'note', me: ME });
	root = document.createElement('div');
	document.body.appendChild(root);
	URL.createObjectURL = vi.fn(() => 'blob:test');
	URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
	render(null, root);
	root.remove();
});

interface ModeCase {
	mode: DraftMode;
	share: ShareTargetData;
	photo?: boolean;
	/** Field that the accepted share fills, and the value it should hold. */
	filled: { id: string; value: string };
	/** A field the person can type into. */
	typed: string;
}

const CASES: ModeCase[] = [
	{
		mode: 'note',
		share: { tab: 'note', title: 'Shared title', content: SHARED_TEXT },
		filled: { id: 'outpost-note-content', value: SHARED_TEXT },
		typed: 'outpost-note-content',
	},
	{
		mode: 'reply',
		share: { tab: 'reply', replyVariant: 'bookmark', url: 'https://example.test/shared-page', content: SHARED_TEXT },
		filled: { id: 'outpost-reply-target', value: 'https://example.test/shared-page' },
		typed: 'outpost-reply-content',
	},
	{
		mode: 'listen',
		share: { tab: 'listen', doingVariant: 'listen', url: 'https://open.spotify.com/track/0000000000000000000000', sourceId: 'spotify' },
		filled: { id: 'outpost-listen-target', value: 'https://open.spotify.com/track/0000000000000000000000' },
		typed: 'outpost-listen-content',
	},
	{
		mode: 'life',
		share: { tab: 'life', lifeVariant: 'mood', content: SHARED_TEXT },
		filled: { id: 'outpost-life-content', value: SHARED_TEXT },
		typed: 'outpost-life-title',
	},
	{
		mode: 'recipe',
		share: { tab: 'recipe', title: 'Shared title', content: SHARED_TEXT, url: 'https://example.test/recipe' },
		filled: { id: 'outpost-recipe-content', value: SHARED_TEXT },
		typed: 'outpost-recipe-name',
	},
	{
		mode: 'photo',
		share: { tab: 'photo', sharedPhotos: true },
		photo: true,
		filled: { id: 'outpost-photo-content', value: SHARED_TEXT },
		typed: 'outpost-photo-name',
	},
];

async function arrive(c: ModeCase): Promise<void> {
	stash_share_target(c.share);
	if (c.photo) {
		await stash_shared_photos(
			{ files: [new File([new Uint8Array([1, 2, 3])], 'shared.jpg', { type: 'image/jpeg' })], title: 'Shared title', text: SHARED_TEXT, received: 1 },
			inbox_env,
		);
	}
	mount();
	await wait(30);
}

describe.each(CASES)('$mode share waits for "Use shared content"', (c) => {
	it('shows the share for review without filling the form, saving, previewing, posting or queueing', async () => {
		await arrive(c);

		expect(region()).not.toBeNull();
		expect(region()?.textContent).toContain(c.share.url ?? SHARED_TEXT);
		expect(button('Use shared content')).not.toBeNull();
		expect(field(c.filled.id).value).not.toBe(c.filled.value);

		root.querySelector<HTMLFormElement>(`#outpost-panel-${c.mode} form`)?.dispatchEvent(
			new Event('submit', { bubbles: true, cancelable: true }),
		);
		await wait(DRAFT_SAVE_DELAY_MS + 100);

		expect(JSON.stringify(read_draft(c.mode, ME) ?? {})).not.toContain('Shared');
		expect(preview_calls()).toBe(0);
		expect(posted.filter((b) => b.includes('Shared')).length).toBe(0);
		expect(await queued_shared()).toBe(0);
	});

	it('does not treat typing in the form as accepting the share', async () => {
		await arrive(c);
		type(c.typed, 'my own words');
		await wait(DRAFT_SAVE_DELAY_MS + 100);

		expect(region()).not.toBeNull();
		expect(field(c.filled.id).value).not.toBe(c.filled.value);
		expect(JSON.stringify(read_draft(c.mode, ME) ?? {})).not.toContain('Shared');
	});

	it('"Use shared content" fills the form and normal drafts resume', async () => {
		await arrive(c);
		click('Use shared content');
		await wait(40);

		expect(region()).toBeNull();
		expect(field(c.filled.id).value).toBe(c.filled.value);

		type(c.typed, 'and my words');
		await wait(DRAFT_SAVE_DELAY_MS + 100);
		expect(read_draft(c.mode, ME)).not.toBeNull();
	});

	it('"Discard shared content" removes the share and keeps the earlier draft as it was', async () => {
		write_draft(c.mode, ME, { content: 'My own draft' }, {}, localStorage);
		const before = JSON.stringify(read_draft(c.mode, ME));
		await arrive(c);
		click('Discard shared content');
		await wait(DRAFT_SAVE_DELAY_MS + 100);

		expect(region()).toBeNull();
		expect(JSON.stringify(read_draft(c.mode, ME))).toBe(before);
		expect(field(c.filled.id).value).not.toBe(c.filled.value);
		if (c.photo) {
			expect((await read_share_inbox(inbox_env)).pending).toBeNull();
		}
	});

	it('shows only the decision while it is open, then returns focus to the tab', async () => {
		await arrive(c);

		const outside = reachable().filter(
			(el) => !el.closest('[data-outpost-share-review]') && (el as HTMLInputElement).name !== 'outpost-header-mode',
		);
		expect(outside.map((el) => el.outerHTML.slice(0, 60))).toEqual([]);

		click('Use shared content');
		await wait(40);
		expect(document.activeElement?.id).toBe(`outpost-tab-${c.mode}`);
		expect(sessionStorage.getItem('outpost.share_target')).toBeNull();
	});
});

describe('text and link share conflicts (T10-S3-05 v1.2)', () => {
	it('holds a second share as the new one instead of replacing the first', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		mount();
		await wait(30);
		stash_share_target({ tab: 'reply', replyVariant: 'bookmark', url: 'https://example.test/second' });
		remount();
		await wait(30);

		const r = region();
		expect(r?.querySelector('h2')?.textContent).toBe('Two shares are waiting');
		expect(r?.textContent).toContain('First shared words');
		expect(r?.textContent).toContain('https://example.test/second');
	});

	it('keeps both waiting shares across a reload until the person decides', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		mount();
		await wait(30);
		stash_share_target({ tab: 'note', content: 'Second shared words' });
		remount();
		await wait(30);
		remount();
		await wait(30);

		expect(region()?.textContent).toContain('First shared words');
		expect(region()?.textContent).toContain('Second shared words');
	});

	it('refuses a third share without replacing either and says so', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		mount();
		await wait(30);
		stash_share_target({ tab: 'note', content: 'Second shared words' });
		remount();
		await wait(30);
		stash_share_target({ tab: 'note', content: 'Third shared words' });
		remount();
		await wait(30);

		expect(region()?.textContent).not.toContain('Third shared words');
		expect(region()?.querySelector('[role="status"]')?.textContent).toMatch(/wasn.t kept/i);
	});

	it('Keep earlier share, then Use shared content, fills the first share', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		mount();
		await wait(30);
		stash_share_target({ tab: 'note', content: 'Second shared words' });
		remount();
		await wait(30);
		click('Keep earlier share');
		await wait(30);
		click('Use shared content');
		await wait(40);

		expect(field('outpost-note-content').value).toBe('First shared words');
	});

	it('Use new share, then Use shared content, fills the second share', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		mount();
		await wait(30);
		stash_share_target({ tab: 'note', content: 'Second shared words' });
		remount();
		await wait(30);
		click('Use new share');
		await wait(30);
		click('Use shared content');
		await wait(40);

		expect(field('outpost-note-content').value).toBe('Second shared words');
	});

	it('Discard both clears both waiting shares', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		mount();
		await wait(30);
		stash_share_target({ tab: 'note', content: 'Second shared words' });
		remount();
		await wait(30);
		click('Discard both');
		await wait(30);
		remount();
		await wait(30);

		expect(region()).toBeNull();
	});

	it('shows one decision when a photo share arrives after a text share', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		mount();
		await wait(30);
		await stash_shared_photos({ files: [new File([new Uint8Array([1])], 'later.jpg', { type: 'image/jpeg' })], title: 'Later', text: '', received: 2 }, inbox_env, 'pending');
		stash_share_target({ tab: 'photo', sharedPhotos: true });
		remount();
		await wait(30);

		expect(root.querySelectorAll('[data-outpost-share-review]').length).toBe(1);
		expect(region()?.textContent).toContain('First shared words');
		expect(region()?.textContent).toContain('later.jpg');

		click('Use new share');
		await wait(30);
		expect(region()?.textContent).toContain('later.jpg');
		expect(region()?.textContent).not.toContain('First shared words');
		expect((await read_share_inbox(inbox_env)).pending?.title).toBe('Later');
	});
});

describe('a decision made before the share inbox is read (T10-S3-05 v1.2)', () => {
	/** Fake-indexeddb behind an open that answers only after `ms`. */
	function slow_env(ms: number): ShareInboxEnvironment {
		const real = globalThis.indexedDB;
		return {
			indexedDB: {
				open(name: string, version?: number): IDBOpenDBRequest {
					const proxy = { result: null as unknown, error: null, onsuccess: null as null | (() => void), onerror: null, onupgradeneeded: null as null | (() => void) };
					setTimeout(() => {
						const req = real.open(name, version);
						req.onupgradeneeded = (): void => {
							proxy.result = req.result;
							proxy.onupgradeneeded?.();
						};
						req.onsuccess = (): void => {
							proxy.result = req.result;
							proxy.onsuccess?.();
						};
					}, ms);
					return proxy as unknown as IDBOpenDBRequest;
				},
			} as unknown as IDBFactory,
		};
	}

	it('stays made: the first inbox read does not bring a discarded share back', async () => {
		stash_share_target({ tab: 'note', content: 'First shared words' });
		inbox_env = slow_env(60);
		mount();
		await wait(5);
		click('Discard shared content');
		await wait(150);

		expect(region()).toBeNull();
		expect(sessionStorage.getItem('outpost.share_review')).toBeNull();
	});
});

describe('share inbox storage failures (T10-S3-05 v1.2)', () => {
	/** Wrap fake-indexeddb so read-write work fails the given way. */
	function failing_env(mode: 'open' | 'write' | 'abort'): ShareInboxEnvironment {
		const real = globalThis.indexedDB;
		return {
			indexedDB: {
				open(name: string, version?: number): IDBOpenDBRequest {
					if (mode === 'open') {
						const req = { error: new DOMException('blocked'), onerror: null as null | (() => void) } as unknown as IDBOpenDBRequest;
						setTimeout(() => (req.onerror as unknown as (() => void) | null)?.(), 0);
						return req;
					}
					const req = real.open(name, version);
					const original = req.addEventListener.bind(req);
					void original;
					const wrap = (): void => {
						const db = req.result;
						const tx = db.transaction.bind(db);
						db.transaction = ((stores: string | string[], m?: IDBTransactionMode) => {
							if (m === 'readwrite' && mode === 'write') throw new DOMException('write failed', 'UnknownError');
							const t = tx(stores, m);
							if (m === 'readwrite' && mode === 'abort') {
								// Abort while the transaction is still active, after work is queued on it.
								const store_of = t.objectStore.bind(t);
								t.objectStore = ((store_name: string) => {
									const store = store_of(store_name);
									queueMicrotask(() => t.abort());
									return store;
								}) as IDBTransaction['objectStore'];
							}
							return t;
						}) as IDBDatabase['transaction'];
					};
					const handler = { set: undefined as undefined | ((e: Event) => void) };
					Object.defineProperty(req, 'onsuccess', {
						set(fn: (e: Event) => void) {
							handler.set = fn;
							req.addEventListener('success', (e) => {
								wrap();
								handler.set?.(e);
							});
						},
						get() {
							return handler.set;
						},
					});
					return req;
				},
			} as unknown as IDBFactory,
		};
	}

	async function photo_share_waiting(): Promise<void> {
		await stash_shared_photos({ files: [new File([new Uint8Array([1])], 'a.jpg', { type: 'image/jpeg' })], title: 'A', text: '', received: 1 }, inbox_env, 'pending');
		stash_share_target({ tab: 'photo', sharedPhotos: true });
	}

	it.each(['write', 'abort'] as const)('a %s failure on Discard keeps the review and says the choice wasn\'t applied', async (mode) => {
		await photo_share_waiting();
		inbox_env = failing_env(mode);
		mount();
		await wait(30);
		click('Discard shared content');
		await wait(40);

		expect(region()).not.toBeNull();
		expect(region()?.querySelector('[role="status"]')?.textContent).toMatch(/wasn.t applied|try again/i);
		expect(region()?.textContent).not.toMatch(/discarded/i);
		expect(sessionStorage.getItem('outpost.share_review')).not.toBeNull();
		expect((await read_share_inbox({ indexedDB: globalThis.indexedDB })).pending?.title).toBe('A');
	});

	it('an inbox that cannot be opened is reported, not treated as empty', async () => {
		await photo_share_waiting();
		inbox_env = failing_env('open');
		mount();
		await wait(30);

		expect(region()).not.toBeNull();
		expect(region()?.querySelector('[role="status"]')?.textContent).toMatch(/couldn.t read/i);
		expect(button('Use shared content')).not.toBeNull();
	});

	it('read_share_inbox reports failure instead of an empty inbox', async () => {
		const state = await read_share_inbox(failing_env('open'));
		expect(state.status).toBe('unavailable');
		expect((await read_share_inbox(inbox_env)).status).toBe('ok');
	});
});

describe('photo share conflicts', () => {
	async function two_waiting(): Promise<void> {
		const photo = (name: string): File => new File([new Uint8Array([1])], name, { type: 'image/jpeg' });
		await stash_shared_photos({ files: [photo('earlier.jpg')], title: 'Earlier', text: '', received: 1 }, inbox_env, 'pending');
		await stash_shared_photos({ files: [photo('newer.jpg')], title: 'Newer', text: '', received: 2 }, inbox_env, 'candidate');
		stash_share_target({ tab: 'photo', sharedPhotos: true });
		mount();
		await wait(30);
	}

	it('names both shares and offers the three choices', async () => {
		await two_waiting();

		expect(region()?.textContent).toContain('earlier.jpg');
		expect(region()?.textContent).toContain('newer.jpg');
		expect(button('Keep earlier share')).not.toBeNull();
		expect(button('Use new share')).not.toBeNull();
		expect(button('Discard both')).not.toBeNull();
		expect(button('Use shared content')).toBeNull();
	});

	it('"Keep earlier share" drops the new one and returns to the review', async () => {
		await two_waiting();
		click('Keep earlier share');
		await wait(30);

		const inbox = await read_share_inbox(inbox_env);
		expect(inbox.pending?.title).toBe('Earlier');
		expect(inbox.candidate).toBeNull();
		expect(button('Use shared content')).not.toBeNull();
	});

	it('"Use new share" replaces the earlier one with the new one', async () => {
		await two_waiting();
		click('Use new share');
		await wait(30);

		const inbox = await read_share_inbox(inbox_env);
		expect(inbox.pending?.title).toBe('Newer');
		expect(inbox.candidate).toBeNull();
	});

	it('"Discard both" empties the inbox', async () => {
		await two_waiting();
		click('Discard both');
		await wait(30);

		const inbox = await read_share_inbox(inbox_env);
		expect(inbox.pending).toBeNull();
		expect(inbox.candidate).toBeNull();
		expect(region()).toBeNull();
	});

	it('says so when a share arrived while two were already waiting', async () => {
		stash_share_target({ tab: 'photo', sharedPhotos: true, shareRefused: true });
		await stash_shared_photos({ files: [new File([new Uint8Array([1])], 'a.jpg', { type: 'image/jpeg' })], title: 'A', text: '', received: 1 }, inbox_env, 'pending');
		mount();
		await wait(30);

		expect(root.querySelector('[data-outpost-share-review] [role="status"]')?.textContent).toMatch(/wasn.t kept/i);
	});

	it('keeps a waiting photo share when Photo opens without a decision', async () => {
		await stash_shared_photos({ files: [new File([new Uint8Array([1])], 'a.jpg', { type: 'image/jpeg' })], title: 'A', text: '', received: 1 }, inbox_env, 'pending');
		mount();
		await wait(30);
		click('Photo');
		await wait(30);

		expect((await read_share_inbox(inbox_env)).pending?.title).toBe('A');
		expect(region()).not.toBeNull();
	});
});

describe('accessibility of the decision region', () => {
	it('has a heading, a persistent status region, and native buttons with one primary action', async () => {
		stash_share_target({ tab: 'note', content: SHARED_TEXT });
		mount();
		await wait(30);

		const r = region();
		const heading = r?.querySelector('h2');
		expect(heading?.textContent).toBe('Shared content');
		expect(r?.getAttribute('aria-labelledby')).toBe(heading?.id);
		expect(r?.querySelector('[role="status"][aria-live="polite"]')).not.toBeNull();
		const buttons = Array.from(r?.querySelectorAll('button') ?? []);
		expect(buttons.every((b) => b.type === 'button')).toBe(true);
		expect(buttons.filter((b) => !b.classList.contains('outpost-button--secondary')).map((b) => b.textContent)).toEqual([
			'Use shared content',
		]);
	});
});
