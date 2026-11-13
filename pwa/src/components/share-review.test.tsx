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
