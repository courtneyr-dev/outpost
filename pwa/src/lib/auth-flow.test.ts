import { describe, it, expect, beforeEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { begin_login, handle_callback, AuthFlowError, type AuthFlowEnvironment } from './auth-flow';
import { read_token } from './token-store';

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
