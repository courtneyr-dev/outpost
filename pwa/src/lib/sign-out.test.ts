import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CACHE_KEY, write_cache } from './appearance';
import { DRAFTS_KEY, write_draft } from './drafts';
import { sign_out } from './sign-out';
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
});
