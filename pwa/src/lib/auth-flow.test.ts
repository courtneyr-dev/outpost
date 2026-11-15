import { describe, it, expect, beforeEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { begin_login, handle_callback, AuthFlowError, type AuthFlowEnvironment } from './auth-flow';
import { read_token, write_token } from './token-store';
import {
	SHARE_INBOX_CANDIDATE_KEY,
	SHARE_INBOX_KEY,
	read_share_inbox,
	stash_shared_photos,
} from './share-inbox';

class MemoryStorage implements Storage {
	private store = new Map<string, string>();
	get length(): number {
		return this.store.size;
	}
	clear(): void {
		this.store.clear();
	}
	getItem(key: string): string | null {
		return this.store.get(key) ?? null;
	}
	key(index: number): string | null {
		return Array.from(this.store.keys())[index] ?? null;
	}
	removeItem(key: string): void {
		this.store.delete(key);
	}
	setItem(key: string, value: string): void {
		this.store.set(key, value);
	}
}

function make_env(fetch_impl: typeof fetch): AuthFlowEnvironment {
	const idb = new IDBFactory();
	return {
		indieauth: {
			fetch: fetch_impl,
			crypto: globalThis.crypto,
			random: () => globalThis.crypto.getRandomValues(new Uint8Array(32)),
		},
		tokenStore: {
			indexedDB: idb,
			crypto: globalThis.crypto,
		},
		sessionStorage: new MemoryStorage(),
	};
}

describe('begin_login', () => {
	it('discovers endpoints, generates PKCE + state, and stashes session state', async () => {
		const fetch_impl = vi.fn(
			async () =>
				new Response(
					'<link rel="authorization_endpoint" href="https://a/auth"><link rel="token_endpoint" href="https://a/token">',
					{ status: 200 },
				),
		);
		const env = make_env(fetch_impl);

		const result = await begin_login(
			'https://courtneyr.dev/',
			'https://courtneyr.dev/post/',
			'https://courtneyr.dev/post/auth/callback',
			'create update',
			env,
		);

		const url = new URL(result.authorizationUrl);
		expect(url.origin + url.pathname).toBe('https://a/auth');
		expect(url.searchParams.get('me')).toBe('https://courtneyr.dev/');
		expect(url.searchParams.get('scope')).toBe('create update');
		expect(url.searchParams.get('code_challenge_method')).toBe('S256');

		// The state and verifier saved to sessionStorage should match what's
		// in the URL (state) / used for the challenge (verifier).
		expect(env.sessionStorage.getItem('outpost.auth.state')).toBe(url.searchParams.get('state'));
		expect(env.sessionStorage.getItem('outpost.auth.verifier')).not.toBeNull();
		expect(env.sessionStorage.getItem('outpost.auth.token_endpoint')).toBe('https://a/token');
		expect(env.sessionStorage.getItem('outpost.auth.me')).toBe('https://courtneyr.dev/');
	});

	it.each([
		'javascript:alert(1)',
		'data:text/html,<script>1</script>',
		'file:///etc/passwd',
		'mailto:foo@example.com',
		'not a url',
		'',
	])('rejects non-http(s) me URL (%s) before any fetch', async (bad_me) => {
		const fetch_impl = vi.fn(async () => new Response('', { status: 200 }));
		const env = make_env(fetch_impl);

		await expect(
			begin_login(bad_me, 'https://courtneyr.dev/post/', 'https://courtneyr.dev/post/auth/callback', undefined, env),
		).rejects.toThrow(/http:\/\/ or https:\/\//);

		// Crucial: the bad URL must never reach fetch.
		expect(fetch_impl).not.toHaveBeenCalled();
	});
});

describe('handle_callback', () => {
	const client_id = 'https://courtneyr.dev/post/';
	const redirect_uri = 'https://courtneyr.dev/post/auth/callback';
	const me = 'https://courtneyr.dev/';

	let env: AuthFlowEnvironment;

	beforeEach(() => {
		env = make_env(async () => new Response('', { status: 200 }));
	});

	it('exchanges code for token and persists when state matches', async () => {
		// Pre-seed sessionStorage as if begin_login had run.
		env.sessionStorage.setItem('outpost.auth.state', 'state-token-abc');
		env.sessionStorage.setItem('outpost.auth.verifier', 'verifier-bytes');
		env.sessionStorage.setItem('outpost.auth.me', me);
		env.sessionStorage.setItem('outpost.auth.token_endpoint', 'https://a/token');

		env.indieauth.fetch = vi.fn(
			async () =>
				new Response(
					JSON.stringify({
						access_token: 'token-xyz',
						token_type: 'Bearer',
						scope: 'create update',
						me,
					}),
					{ status: 200, headers: { 'Content-Type': 'application/json' } },
				),
		);

		const result = await handle_callback(
			{ code: 'auth-code', state: 'state-token-abc' },
			client_id,
			redirect_uri,
			env,
		);

		expect(result.token.accessToken).toBe('token-xyz');
		expect(result.token.me).toBe(me);

		// Token persisted in IDB.
		const stored = await read_token(env.tokenStore);
		expect(stored?.accessToken).toBe('token-xyz');

		// sessionStorage cleared.
		expect(env.sessionStorage.getItem('outpost.auth.state')).toBeNull();
		expect(env.sessionStorage.getItem('outpost.auth.verifier')).toBeNull();
	});

	it('throws state_mismatch when query.state does not match saved state', async () => {
		env.sessionStorage.setItem('outpost.auth.state', 'expected-state');
		env.sessionStorage.setItem('outpost.auth.verifier', 'v');
		env.sessionStorage.setItem('outpost.auth.token_endpoint', 'https://a/token');

		await expect(
			handle_callback(
				{ code: 'c', state: 'wrong-state' },
				client_id,
				redirect_uri,
				env,
			),
		).rejects.toThrow(AuthFlowError);

		// Even a mismatch clears sessionStorage so the next attempt starts fresh.
		expect(env.sessionStorage.getItem('outpost.auth.state')).toBeNull();
	});

	it('throws missing_state when sessionStorage is empty', async () => {
		await expect(
			handle_callback(
				{ code: 'c', state: 's' },
				client_id,
				redirect_uri,
				env,
			),
		).rejects.toMatchObject({ code: 'missing_state' });
	});

	it('throws no_code when query is missing the code param', async () => {
		env.sessionStorage.setItem('outpost.auth.state', 's');
		env.sessionStorage.setItem('outpost.auth.verifier', 'v');
		env.sessionStorage.setItem('outpost.auth.token_endpoint', 'https://a/token');

		await expect(
			handle_callback({ state: 's' }, client_id, redirect_uri, env),
		).rejects.toMatchObject({ code: 'no_code' });
	});

	it('throws exchange_failed when query carries an error param', async () => {
		env.sessionStorage.setItem('outpost.auth.state', 's');
		env.sessionStorage.setItem('outpost.auth.verifier', 'v');
		env.sessionStorage.setItem('outpost.auth.token_endpoint', 'https://a/token');

		await expect(
			handle_callback(
				{ error: 'access_denied', error_description: 'user clicked deny' },
				client_id,
				redirect_uri,
				env,
			),
		).rejects.toMatchObject({ code: 'exchange_failed' });
	});

	it.each([
		['user info', 'https://someone@courtneyr.dev/'],
		['not a URL', 'courtneyr.dev'],
	])('refuses, and stores nothing, when the verified me has no exact account identity (%s) (T10-S3-07)', async (_label, bad_me) => {
		env.sessionStorage.setItem('outpost.auth.state', 's');
		env.sessionStorage.setItem('outpost.auth.verifier', 'v');
		env.sessionStorage.setItem('outpost.auth.token_endpoint', 'https://a/token');
		env.indieauth.fetch = vi.fn(
			async () =>
				new Response(JSON.stringify({ access_token: 'token-xyz', token_type: 'Bearer', scope: 'create', me: bad_me }), {
					status: 200,
					headers: { 'Content-Type': 'application/json' },
				}),
		);

		await expect(handle_callback({ code: 'c', state: 's' }, client_id, redirect_uri, env)).rejects.toMatchObject({
			code: 'me_mismatch',
		});
		expect(await read_token(env.tokenStore)).toBeNull();
	});

	it('throws when token endpoint returns 4xx', async () => {
		env.sessionStorage.setItem('outpost.auth.state', 's');
		env.sessionStorage.setItem('outpost.auth.verifier', 'v');
		env.sessionStorage.setItem('outpost.auth.token_endpoint', 'https://a/token');

		env.indieauth.fetch = vi.fn(async () => new Response('invalid_grant', { status: 400 }));

		await expect(
			handle_callback({ code: 'c', state: 's' }, client_id, redirect_uri, env),
		).rejects.toThrow(/400/);
	});
});

/**
 * F-46 defense in depth: a device that was never signed out (a dead token and
 * "Sign in again", or a cleared site cookie) can still hold the earlier
 * account's pending shares. The callback clears them before it stores the
 * new account's token, and keeps no token when it can't.
 */
describe('handle_callback clears pending shares before storing the new account (F-46)', () => {
	const client_id = 'https://courtneyr.dev/post/';
	const redirect_uri = 'https://courtneyr.dev/post/auth/callback';
	const me = 'https://b.example/';
	const SESSION_KEYS = ['outpost.share_target', 'outpost.share_review', 'outpost.share_review_candidate'];

	function photo(title: string): { files: File[]; title: string; text: string; received: number } {
		return {
			files: [new File([new Uint8Array([1, 2, 3])], title + '.jpg', { type: 'image/jpeg' })],
			title,
			text: '',
			received: 1,
		};
	}

	function ready_env(inbox_idb: IDBFactory | undefined): AuthFlowEnvironment {
		const env = make_env(
			vi.fn(
				async () =>
					new Response(
						JSON.stringify({ access_token: 'token-b', token_type: 'Bearer', scope: 'create', me }),
						{ status: 200, headers: { 'Content-Type': 'application/json' } },
					),
			),
		);
		env.shareInbox = { indexedDB: inbox_idb };
		env.sessionStorage.setItem('outpost.auth.state', 'state-b');
		env.sessionStorage.setItem('outpost.auth.verifier', 'verifier-b');
		env.sessionStorage.setItem('outpost.auth.me', me);
		env.sessionStorage.setItem('outpost.auth.token_endpoint', 'https://b.example/token');
		env.sessionStorage.setItem('outpost.share_target', JSON.stringify({ tab: 'note', content: 'A text' }));
		env.sessionStorage.setItem('outpost.share_review', JSON.stringify({ tab: 'reply', url: 'https://a.example/private' }));
		env.sessionStorage.setItem('outpost.share_review_candidate', JSON.stringify({ tab: 'photo', sharedPhotos: true }));
		return env;
	}

	it('empties the sessionStorage slots and both inbox records, then stores the token', async () => {
		const inbox_idb = new IDBFactory();
		await stash_shared_photos(photo('A-pending'), { indexedDB: inbox_idb }, SHARE_INBOX_KEY);
		await stash_shared_photos(photo('A-held'), { indexedDB: inbox_idb }, SHARE_INBOX_CANDIDATE_KEY);
		const env = ready_env(inbox_idb);

		await handle_callback({ code: 'code-b', state: 'state-b' }, client_id, redirect_uri, env);

		expect((await read_token(env.tokenStore))?.accessToken).toBe('token-b');
		for (const key of SESSION_KEYS) expect(env.sessionStorage.getItem(key), key).toBeNull();
		const state = await read_share_inbox({ indexedDB: inbox_idb });
		expect(state.status).toBe('ok');
		expect(state.pending).toBeNull();
		expect(state.candidate).toBeNull();
	});

	it('doesn’t store the new token, and leaves the earlier sign-in alone, when the inbox can’t be cleared', async () => {
		const broken = {
			open: (): IDBOpenDBRequest => {
				const req = { error: new DOMException('blocked'), onerror: null as null | (() => void) };
				setTimeout(() => req.onerror?.(), 0);
				return req as unknown as IDBOpenDBRequest;
			},
		} as unknown as IDBFactory;
		const env = ready_env(broken);
		await write_token({ accessToken: 'token-a', tokenType: 'Bearer', scope: 'create', me: 'https://a.example/' }, env.tokenStore);

		const error = await handle_callback({ code: 'code-b', state: 'state-b' }, client_id, redirect_uri, env).then(
			() => null,
			(err: unknown) => err,
		);

		expect(error).toBeInstanceOf(AuthFlowError);
		expect((await read_token(env.tokenStore))?.accessToken).toBe('token-a');
	});

	it('doesn’t store the new token when a share slot can’t be removed but stays readable (P4A-01)', async () => {
		const env = ready_env(new IDBFactory());
		const storage = env.sessionStorage;
		env.sessionStorage = {
			getItem: (k: string) => storage.getItem(k),
			setItem: (k: string, v: string) => storage.setItem(k, v),
			removeItem: (k: string) => {
				if (SESSION_KEYS.includes(k)) throw new DOMException('removal refused');
				storage.removeItem(k);
			},
			clear: () => storage.clear(),
			key: (i: number) => storage.key(i),
			get length() {
				return storage.length;
			},
		};

		const error = await handle_callback({ code: 'code-b', state: 'state-b' }, client_id, redirect_uri, env).then(
			() => null,
			(err: unknown) => err,
		);

		expect(error).toBeInstanceOf(AuthFlowError);
		expect((error as AuthFlowError).code).toBe('shares_not_cleared');
		expect(await read_token(env.tokenStore)).toBeNull();
	});
});
