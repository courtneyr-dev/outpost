import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CACHE_KEY, write_cache } from './appearance';
import { DRAFTS_KEY, write_draft } from './drafts';
import { clear_sign_in, finish_sign_out, sign_out } from './sign-out';
import { read_token, write_token, type TokenStoreEnvironment } from './token-store';
import {
	discard_held_share,
	hold_share_for_review,
	release_held_share,
	stash_share_target,
	type ShareTargetData,
} from './share-target';
import {
	SHARE_INBOX_CANDIDATE_KEY,
	SHARE_INBOX_KEY,
	keep_shared_photos,
	read_share_inbox,
	stash_shared_photos,
	type ShareInboxEnvironment,
} from './share-inbox';

afterEach(() => {
	vi.restoreAllMocks();
	localStorage.clear();
	sessionStorage.clear();
});

describe('sign_out', () => {
	it('forgets the token, the drafts, and the preferences copy, then reloads', async () => {
		const env: TokenStoreEnvironment = { indexedDB: new IDBFactory(), crypto: globalThis.crypto };
		await write_token({ accessToken: 'abc', tokenType: 'Bearer', me: 'https://a.test/', scope: 'create' }, env);
		write_draft('note', 'https://a.test/', { content: 'private words' });
		write_cache({ mode: 'night', source: 'site', css: '', start: 'reply', me: 'https://a.test/' });
		const reload = vi.fn();
		vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, reload } as Location);

		await sign_out(env);

		expect(await read_token(env)).toBeNull();
		expect(localStorage.getItem(DRAFTS_KEY)).toBeNull();
		expect(localStorage.getItem(CACHE_KEY)).toBeNull();
		expect(reload).toHaveBeenCalledTimes(1);
	});

	describe('in two steps the sign-out control can order (T10-S3-07 v1.1)', () => {
		it('clear_sign_in removes only the token: drafts and the preferences copy stay, and nothing reloads', async () => {
			const env: TokenStoreEnvironment = { indexedDB: new IDBFactory(), crypto: globalThis.crypto };
			await write_token({ accessToken: 'abc', tokenType: 'Bearer', me: 'https://a.test/', scope: 'create' }, env);
			// Written directly: draft storage stays locked once a sign-out in this
			// file has cleared it (by design: the page reloads after sign-out).
			localStorage.setItem(DRAFTS_KEY, JSON.stringify({ v: 1, me: 'https://a.test/', modes: { note: { fields: { content: 'private words' } } } }));
			write_cache({ mode: 'night', source: 'site', css: '', start: 'reply', me: 'https://a.test/' });
			const reload = vi.fn();
			vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, reload } as Location);

			await clear_sign_in(env);

			expect(await read_token(env)).toBeNull();
			expect(localStorage.getItem(DRAFTS_KEY)).not.toBeNull();
			expect(localStorage.getItem(CACHE_KEY)).not.toBeNull();
			expect(reload).not.toHaveBeenCalled();
		});

		it('clear_sign_in rejects when the token store fails, so the caller can say the person is still signed in', async () => {
			const broken: TokenStoreEnvironment = {
				indexedDB: {
					open: (): IDBOpenDBRequest => {
						const req = { error: new DOMException('blocked'), onerror: null as null | (() => void) };
						setTimeout(() => req.onerror?.(), 0);
						return req as unknown as IDBOpenDBRequest;
					},
				} as unknown as IDBFactory,
				crypto: globalThis.crypto,
			};
			await expect(clear_sign_in(broken)).rejects.toBeTruthy();
		});

		it('finish_sign_out forgets the drafts and the preferences copy, then reloads', () => {
			// Written directly: draft storage stays locked once a sign-out in this
			// file has cleared it (by design: the page reloads after sign-out).
			localStorage.setItem(DRAFTS_KEY, JSON.stringify({ v: 1, me: 'https://a.test/', modes: { note: { fields: { content: 'private words' } } } }));
			write_cache({ mode: 'night', source: 'site', css: '', start: 'reply', me: 'https://a.test/' });
			const reload = vi.fn();
			vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, reload } as Location);

			finish_sign_out();

			expect(localStorage.getItem(DRAFTS_KEY)).toBeNull();
			expect(localStorage.getItem(CACHE_KEY)).toBeNull();
			expect(reload).toHaveBeenCalledTimes(1);
		});
	});
});

/**
 * F-46: pending shares belong to whoever was signed in when they arrived, so
 * signing out clears them before the sign-in itself goes. A share that comes
 * in after the next sign-in, and an explicit Discard, keep working.
 */
describe('pending shares across sign-out (F-46)', () => {
	const SESSION_KEYS = ['outpost.share_target', 'outpost.share_review', 'outpost.share_review_candidate'];

	function token_env(): TokenStoreEnvironment {
		return { indexedDB: new IDBFactory(), crypto: globalThis.crypto };
	}

	async function signed_in(env: TokenStoreEnvironment): Promise<void> {
		await write_token({ accessToken: 'abc', tokenType: 'Bearer', me: 'https://a.test/', scope: 'create' }, env);
	}

	function photos(title: string): { files: File[]; title: string; text: string; received: number } {
		return {
			files: [new File([new Uint8Array([1, 2, 3])], title + '.jpg', { type: 'image/jpeg' })],
			title,
			text: title + ' text',
			received: 1,
		};
	}

	/** An IndexedDB whose open() always fails, as a blocked or broken store does. */
	function broken_idb(): IDBFactory {
		return {
			open: (): IDBOpenDBRequest => {
				const req = { error: new DOMException('blocked'), onerror: null as null | (() => void) };
				setTimeout(() => req.onerror?.(), 0);
				return req as unknown as IDBOpenDBRequest;
			},
		} as unknown as IDBFactory;
	}

	const A_TEXT: ShareTargetData = { tab: 'note', content: 'Account A private text' };
	const A_URL: ShareTargetData = { tab: 'reply', url: 'https://private.example/a' };

	it('text and URL path: every sessionStorage share slot is empty after sign-out', async () => {
		const env = token_env();
		await signed_in(env);
		stash_share_target(A_TEXT);
		hold_share_for_review();
		stash_share_target(A_URL);
		hold_share_for_review();
		stash_share_target({ tab: 'note', content: 'not yet held' });
		for (const key of SESSION_KEYS) expect(sessionStorage.getItem(key), key).not.toBeNull();

		await clear_sign_in(env, { shareInbox: { indexedDB: new IDBFactory() }, sessionStorage });

		expect(await read_token(env)).toBeNull();
		for (const key of SESSION_KEYS) expect(sessionStorage.getItem(key), key).toBeNull();
	});

	it('photo path: the pending and held inbox records are gone after sign-out', async () => {
		const env = token_env();
		await signed_in(env);
		const inbox: ShareInboxEnvironment = { indexedDB: new IDBFactory() };
		await stash_shared_photos(photos('A-pending'), inbox, SHARE_INBOX_KEY);
		await stash_shared_photos(photos('A-held'), inbox, SHARE_INBOX_CANDIDATE_KEY);

		await clear_sign_in(env, { shareInbox: inbox, sessionStorage });

		expect(await read_token(env)).toBeNull();
		const state = await read_share_inbox(inbox);
		expect(state.status).toBe('ok');
		expect(state.pending).toBeNull();
		expect(state.candidate).toBeNull();
	});

	it('when the inbox can’t be cleared, sign-out rejects and the person stays signed in', async () => {
		const env = token_env();
		await signed_in(env);
		stash_share_target(A_TEXT);
		hold_share_for_review();

		await expect(
			clear_sign_in(env, { shareInbox: { indexedDB: broken_idb() }, sessionStorage }),
		).rejects.toBeTruthy();

		// Still signed in, so no next person can sign in over a readable share.
		expect((await read_token(env))?.accessToken).toBe('abc');
	});

	it('when IndexedDB is missing, sign-out rejects rather than assume the inbox is empty', async () => {
		const env = token_env();
		await signed_in(env);

		await expect(
			clear_sign_in(env, { shareInbox: { indexedDB: undefined }, sessionStorage }),
		).rejects.toBeTruthy();
		expect((await read_token(env))?.accessToken).toBe('abc');
	});

	// P4A-01: deletion that fails while the value stays readable must not pass.
	function retaining(storage: Storage): Storage {
		return {
			getItem: (k: string) => storage.getItem(k),
			setItem: (k: string, v: string) => storage.setItem(k, v),
			removeItem: () => {
				throw new DOMException('removal refused');
			},
			clear: () => storage.clear(),
			key: (i: number) => storage.key(i),
			get length() {
				return storage.length;
			},
		};
	}

	it('when a share slot can’t be removed but stays readable, sign-out rejects and the person stays signed in', async () => {
		const env = token_env();
		await signed_in(env);
		stash_share_target(A_TEXT);
		hold_share_for_review();

		await expect(
			clear_sign_in(env, { shareInbox: { indexedDB: new IDBFactory() }, sessionStorage: retaining(sessionStorage) }),
		).rejects.toBeTruthy();

		expect((await read_token(env))?.accessToken).toBe('abc');
	});

	it('a sessionStorage that refuses every access holds nothing anyone can read, so sign-out goes ahead', async () => {
		const env = token_env();
		await signed_in(env);
		const blocked = {
			getItem: () => {
				throw new DOMException('blocked');
			},
			removeItem: () => {
				throw new DOMException('blocked');
			},
		} as unknown as Storage;

		await clear_sign_in(env, { shareInbox: { indexedDB: new IDBFactory() }, sessionStorage: blocked });

		expect(await read_token(env)).toBeNull();
	});

	// P4B-07: sessionStorage belongs to one tab, the token to the origin.
	function memory_storage(): Storage {
		const m = new Map<string, string>();
		return {
			getItem: (k: string) => m.get(k) ?? null,
			setItem: (k: string, v: string) => void m.set(k, String(v)),
			removeItem: (k: string) => void m.delete(k),
			clear: () => m.clear(),
			key: (i: number) => [...m.keys()][i] ?? null,
			get length() {
				return m.size;
			},
		};
	}

	it('a share waiting in another tab is gone once this tab signs out (P4B-07)', async () => {
		const env = token_env();
		await signed_in(env);
		// This test's own sessionStorage plays the second tab.
		stash_share_target(A_TEXT);
		hold_share_for_review();
		stash_share_target(A_URL);

		await clear_sign_in(env, { shareInbox: { indexedDB: new IDBFactory() }, sessionStorage: memory_storage() });

		expect(hold_share_for_review().shares).toEqual([]);
		expect(release_held_share()).toBeNull();
	});

	it('a share that arrives in the other tab after the sign-out still reaches review', async () => {
		const env = token_env();
		await signed_in(env);
		stash_share_target(A_TEXT);
		hold_share_for_review();
		await clear_sign_in(env, { shareInbox: { indexedDB: new IDBFactory() }, sessionStorage: memory_storage() });

		await signed_in(env);
		stash_share_target({ tab: 'note', content: 'B shares in the second tab' });

		expect(hold_share_for_review().shares).toEqual([{ tab: 'note', content: 'B shares in the second tab' }]);
	});

	it('when other tabs can’t be told (localStorage write fails), sign-out rejects and the person stays signed in (P4F)', async () => {
		const env = token_env();
		await signed_in(env);
		const blocked = memory_storage();
		blocked.setItem = (): void => {
			throw new DOMException('blocked');
		};
		vi.stubGlobal('localStorage', blocked);
		try {
			await expect(
				clear_sign_in(env, { shareInbox: { indexedDB: new IDBFactory() }, sessionStorage: memory_storage() }),
			).rejects.toBeTruthy();
		} finally {
			vi.unstubAllGlobals();
		}

		expect((await read_token(env))?.accessToken).toBe('abc');
	});

	it('a share received after the next sign-in still reaches review, text and photos', async () => {
		const env = token_env();
		await signed_in(env);
		const inbox: ShareInboxEnvironment = { indexedDB: new IDBFactory() };
		stash_share_target(A_TEXT);
		hold_share_for_review();
		await stash_shared_photos(photos('A'), inbox);
		await clear_sign_in(env, { shareInbox: inbox, sessionStorage });

		await signed_in(env);
		stash_share_target({ tab: 'note', content: 'B shares after signing in' });
		const held = hold_share_for_review();
		await stash_shared_photos(photos('B'), inbox);

		expect(held.shares).toEqual([{ tab: 'note', content: 'B shares after signing in' }]);
		const state = await read_share_inbox(inbox);
		expect(state.pending?.title).toBe('B');
	});

	it('explicit Discard still clears the held text share and the parked photos', async () => {
		const inbox: ShareInboxEnvironment = { indexedDB: new IDBFactory() };
		stash_share_target(A_TEXT);
		hold_share_for_review();
		stash_share_target(A_URL);
		hold_share_for_review();
		await stash_shared_photos(photos('A'), inbox, SHARE_INBOX_KEY);
		await stash_shared_photos(photos('A2'), inbox, SHARE_INBOX_CANDIDATE_KEY);

		discard_held_share();
		await keep_shared_photos(null, inbox);

		for (const key of SESSION_KEYS) expect(sessionStorage.getItem(key), key).toBeNull();
		const state = await read_share_inbox(inbox);
		expect(state.pending).toBeNull();
		expect(state.candidate).toBeNull();
	});
});
