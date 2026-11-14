import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CACHE_KEY, write_cache } from './appearance';
import { DRAFTS_KEY, write_draft } from './drafts';
import { clear_sign_in, finish_sign_out, sign_out } from './sign-out';
import { read_token, write_token, type TokenStoreEnvironment } from './token-store';

afterEach(() => {
	vi.restoreAllMocks();
	localStorage.clear();
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
