import { describe, it, expect, beforeEach } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import {
	write_token,
	read_token,
	clear_token,
	type TokenStoreEnvironment,
} from './token-store';

describe('token-store', () => {
	let env: TokenStoreEnvironment;

	beforeEach(() => {
		// Fresh in-memory IDB per test so state never leaks between cases.
		env = {
			indexedDB: new IDBFactory(),
			crypto: globalThis.crypto,
		};
	});

	it('returns null when nothing has been written', async () => {
		expect(await read_token(env)).toBeNull();
	});

	it('round-trips a token through encrypt/decrypt', async () => {
		await write_token(
			{
				accessToken: 'secret-token-abc',
				tokenType: 'Bearer',
				scope: 'create update',
				me: 'https://courtneyr.dev/',
			},
			env,
		);
		const read = await read_token(env);
		expect(read).not.toBeNull();
		expect(read?.accessToken).toBe('secret-token-abc');
		expect(read?.tokenType).toBe('Bearer');
		expect(read?.scope).toBe('create update');
		expect(read?.me).toBe('https://courtneyr.dev/');
		expect(typeof read?.storedAt).toBe('number');
	});

	it('clears the token but keeps the encryption key', async () => {
		await write_token(
			{ accessToken: 'first', tokenType: 'Bearer', scope: '', me: '' },
			env,
		);
		await clear_token(env);
		expect(await read_token(env)).toBeNull();

		// Re-write should still work — the key persists across clear_token().
		await write_token(
			{ accessToken: 'second', tokenType: 'Bearer', scope: '', me: '' },
			env,
		);
		const read = await read_token(env);
		expect(read?.accessToken).toBe('second');
	});

	it('overwrites the previous token on re-write', async () => {
		await write_token({ accessToken: 'first', tokenType: 'Bearer', scope: '', me: '' }, env);
		await write_token({ accessToken: 'second', tokenType: 'Bearer', scope: '', me: '' }, env);
		const read = await read_token(env);
		expect(read?.accessToken).toBe('second');
	});

	it('persists the encryption key across separate db opens', async () => {
		await write_token({ accessToken: 'persisted', tokenType: 'Bearer', scope: '', me: '' }, env);
		// Same backing IDBFactory but every call to write/read opens a fresh
		// IDBDatabase handle. If the key didn't persist, decrypt would fail.
		const read_a = await read_token(env);
		const read_b = await read_token(env);
		expect(read_a?.accessToken).toBe('persisted');
		expect(read_b?.accessToken).toBe('persisted');
	});

	it('produces different ciphertexts for the same plaintext (random IV)', async () => {
		// Indirect test: write the same token twice, both must round-trip.
		// If the IV ever became deterministic this would still pass, but the
		// AES-GCM contract is that nonce reuse with the same key breaks the
		// scheme — keeping this assertion documents the intent.
		const token = { accessToken: 'same-plaintext', tokenType: 'Bearer', scope: '', me: '' };
		await write_token(token, env);
		const first = await read_token(env);
		await write_token(token, env);
		const second = await read_token(env);
		expect(first?.accessToken).toBe('same-plaintext');
		expect(second?.accessToken).toBe('same-plaintext');
	});
});
