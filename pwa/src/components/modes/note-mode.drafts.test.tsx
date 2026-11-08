import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { NoteMode } from './note-mode';
import type { StoredToken, TokenStoreEnvironment } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';
import { DRAFTS_KEY, DRAFT_SAVE_DELAY_MS } from '../../lib/drafts';

const ME = 'https://example.test/';

function token_for(me: string): StoredToken {
	return {
		accessToken: 'secret-bearer-value',
		tokenType: 'Bearer',
		scope: 'create',
		me,
		storedAt: 0,
	};
}

const token_store: TokenStoreEnvironment = {
	indexedDB: globalThis.indexedDB,
	crypto: globalThis.crypto,
} as TokenStoreEnvironment;

let root: HTMLDivElement;
let posts: URLSearchParams[];
let fetch_calls: number;

function online_micropub(): MicropubEnvironment {
	return {
		fetch: async (_input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
			fetch_calls += 1;
			if (init?.method === 'POST') {
				posts.push(new URLSearchParams(String(init.body ?? '')));
				return new Response('', {
					status: 201,
					headers: { Location: 'https://example.test/2026/11/08/note' },
				});
			}
			return new Response('<html></html>', {
				status: 200,
				headers: { Link: '<https://example.test/mp>; rel="micropub"' },
			});
		},
	};
}

beforeEach(() => {
	localStorage.clear();
	sessionStorage.clear();
	posts = [];
	fetch_calls = 0;
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
	vi.useRealTimers();
	vi.restoreAllMocks();
});

function mount(me = ME): void {
	render(<NoteMode token={token_for(me)} tokenStore={token_store} micropubEnv={online_micropub()} />, root);
}

/** Unmount and mount again: what a page reload does to component state. */
function reload(me = ME): void {
	render(null, root);
	mount(me);
}

async function flush(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
}

function field(id: string): HTMLInputElement | HTMLTextAreaElement {
	return root.querySelector('#' + id) as HTMLInputElement | HTMLTextAreaElement;
}

function type(id: string, value: string): void {
	const el = field(id);
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

function draft_status(): string {
	return root.querySelector('.outpost-draft-status')?.textContent ?? '';
}

async function wait_for_save(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, DRAFT_SAVE_DELAY_MS + 50));
}

describe('NoteMode drafts', () => {
	it('restores title and body after a reload for the same account', async () => {
		mount();
		type('outpost-note-title', 'Draft title');
		type('outpost-note-content', 'Half a thought');
		await wait_for_save();
		reload();
		await flush();
		expect(field('outpost-note-title').value).toBe('Draft title');
		expect(field('outpost-note-content').value).toBe('Half a thought');
	});

	it('restores the selected style', async () => {
		mount();
		(root.querySelector('input[name="outpost-note-variant"][value="quote"]') as HTMLInputElement).click();
		type('outpost-note-content', 'Quoted words');
		await wait_for_save();
		reload();
		await flush();
		expect(
			(root.querySelector('input[name="outpost-note-variant"][value="quote"]') as HTMLInputElement).checked,
		).toBe(true);
	});

	it('never publishes a restored draft on its own', async () => {
		mount();
		type('outpost-note-content', 'Not ready yet');
		await wait_for_save();
		fetch_calls = 0;
		reload();
		await flush();
		await wait_for_save();
		expect(field('outpost-note-content').value).toBe('Not ready yet');
		expect(posts).toHaveLength(0);
		expect(fetch_calls).toBe(0);
	});

	it('says "Draft saved" only after the write succeeds, and not before', async () => {
		mount();
		type('outpost-note-content', 'Typing');
		expect(draft_status()).not.toContain('Draft saved');
		await wait_for_save();
		expect(draft_status()).toContain('Draft saved');
		expect(localStorage.getItem(DRAFTS_KEY)).toContain('Typing');
	});

	it('never writes the bearer token to device storage', async () => {
		mount();
		type('outpost-note-content', 'Some words');
		await wait_for_save();
		expect(localStorage.getItem(DRAFTS_KEY)).not.toContain('secret-bearer-value');
	});

	it("ignores another account's draft", async () => {
		mount('https://other.test/');
		type('outpost-note-content', 'Their words');
		await wait_for_save();
		reload(ME);
		await flush();
		expect(field('outpost-note-content').value).toBe('');
	});

	it('clears the draft after a successful publish', async () => {
		mount();
		type('outpost-note-title', 'Ready');
		type('outpost-note-content', 'Publish me');
		await wait_for_save();
		(root.querySelector('form') as HTMLFormElement).dispatchEvent(
			new Event('submit', { bubbles: true, cancelable: true }),
		);
		await vi.waitFor(() => expect(posts).toHaveLength(1));
		await flush();
		expect(localStorage.getItem(DRAFTS_KEY)).toBeNull();
		await wait_for_save();
		expect(localStorage.getItem(DRAFTS_KEY)).toBeNull();
		reload();
		await flush();
		expect(field('outpost-note-content').value).toBe('');
	});

	it('clears drafts on sign-out', async () => {
		const reload_spy = vi.fn();
		vi.spyOn(window, 'location', 'get').mockReturnValue({
			...window.location,
			reload: reload_spy,
		} as Location);
		mount();
		type('outpost-note-content', 'Private words');
		await wait_for_save();
		expect(localStorage.getItem(DRAFTS_KEY)).not.toBeNull();
		const sign_out = Array.from(root.querySelectorAll('button')).find(
			(b) => b.textContent === 'Sign out',
		) as HTMLButtonElement;
		sign_out.click();
		await vi.waitFor(() => expect(reload_spy).toHaveBeenCalled());
		expect(localStorage.getItem(DRAFTS_KEY)).toBeNull();
	});

	it('keeps working without "Draft saved" when storage refuses writes', async () => {
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
			throw new DOMException('denied', 'SecurityError');
		});
		mount();
		type('outpost-note-title', 'Offline-proof');
		type('outpost-note-content', 'Still postable');
		await wait_for_save();
		expect(draft_status()).not.toContain('Draft saved');
		expect(draft_status()).toContain("can't keep drafts");
		(root.querySelector('form') as HTMLFormElement).dispatchEvent(
			new Event('submit', { bubbles: true, cancelable: true }),
		);
		await vi.waitFor(() => expect(posts).toHaveLength(1));
		expect(posts[0]?.get('content')).toBe('Still postable');
	});

	it('mounts with an empty form when storage reads throw', async () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
			throw new DOMException('denied', 'SecurityError');
		});
		mount();
		await flush();
		expect(field('outpost-note-content').value).toBe('');
	});
});
