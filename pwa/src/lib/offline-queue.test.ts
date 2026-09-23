import { describe, it, expect, beforeEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import {
	enqueue,
	list,
	remove,
	flush,
	is_network_error,
	is_retryable_error,
	subscribe_queue_changes,
	CLAIM_LEASE_MS,
	type OfflineQueueEnvironment,
} from './offline-queue';
import { recall_endpoints } from './endpoint-cache';
import { MicropubError, type MicropubEnvironment } from './micropub';
import { write_token, clear_token, type TokenStoreEnvironment } from './token-store';

function fresh_env(): OfflineQueueEnvironment {
	return { indexedDB: new IDBFactory() };
}

function ok_response(headers: Record<string, string> = {}): Response {
	return new Response('', {
		status: 201,
		headers: { Location: 'https://example.test/post/1', ...headers },
	});
}

function rejecting_fetch(message = 'TypeError: Failed to fetch'): MicropubEnvironment {
	return {
		fetch: ((): Promise<Response> => {
			return Promise.reject(new TypeError(message));
		}) as typeof fetch,
	};
}

let env: OfflineQueueEnvironment;
let tokenEnv: TokenStoreEnvironment;

beforeEach(async () => {
	env = fresh_env();
	// replay() now reads the token fresh from token-store.ts (Task H5)
	// instead of a copy on the entry; seed a fresh per-test store so flush()
	// reaches the mock fetch instead of failing every entry with `no_token`.
	tokenEnv = { indexedDB: new IDBFactory(), crypto: globalThis.crypto };
	await write_token({ accessToken: 't', tokenType: 'Bearer', scope: '', me: '' }, tokenEnv);
});

describe('offline-queue: enqueue + list + remove', () => {
	it('enqueues an entry and lists it back', async () => {
		const id = await enqueue(
			{
				source: 'note',
				properties: { content: 'hi' },
				micropubEndpoint: 'https://example.test/wp-json/micropub/1.0/endpoint',
			},
			env,
		);
		expect(id).toBeGreaterThan(0);

		const entries = await list(env);
		expect(entries).toHaveLength(1);
		expect(entries[0]?.source).toBe('note');
		expect(entries[0]?.properties).toEqual({ content: 'hi' });
		expect(entries[0]?.attempts).toBe(0);
		expect(entries[0]?.createdAt).toBeGreaterThan(0);
	});

	it('returns empty list for fresh DB', async () => {
		const entries = await list(env);
		expect(entries).toEqual([]);
	});

	it('preserves insertion order across multiple enqueues', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'first' },
				micropubEndpoint: 'e',
			},
			env,
		);
		await enqueue(
			{
				source: 'reply',
				properties: { content: 'second' },
				micropubEndpoint: 'e',
			},
			env,
		);
		const entries = await list(env);
		expect(entries.map((e) => e.source)).toEqual(['note', 'reply']);
	});

	it('removes an entry by id', async () => {
		const id = await enqueue(
			{
				source: 'note',
				properties: { content: 'hi' },
				micropubEndpoint: 'e',
			},
			env,
		);
		await remove(id, env);
		const entries = await list(env);
		expect(entries).toEqual([]);
	});
});

describe('offline-queue: flush', () => {
	it('drains all entries on success', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'a' },
				micropubEndpoint: 'https://example.test/m',
			},
			env,
		);
		await enqueue(
			{
				source: 'reply',
				properties: { content: 'b' },
				micropubEndpoint: 'https://example.test/m',
			},
			env,
		);

		const m_env: MicropubEnvironment = {
			fetch: ((): Promise<Response> => Promise.resolve(ok_response())) as typeof fetch,
		};

		const remaining = await flush(m_env, env, { tokenStore: tokenEnv });
		expect(remaining).toEqual([]);
		const entries = await list(env);
		expect(entries).toEqual([]);
	});

	it('keeps entries with incremented attempts on network failure', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'a' },
				micropubEndpoint: 'https://example.test/m',
			},
			env,
		);

		const remaining = await flush(rejecting_fetch(), env, { tokenStore: tokenEnv });
		expect(remaining).toHaveLength(1);
		expect(remaining[0]?.attempts).toBe(1);
		expect(remaining[0]?.lastError).toContain('post_failed');

		// Persisted state matches.
		const persisted = await list(env);
		expect(persisted[0]?.attempts).toBe(1);
		expect(persisted[0]?.lastError).toContain('post_failed');
	});

	it('returns empty array when queue is empty', async () => {
		const m_env: MicropubEnvironment = {
			fetch: ((): Promise<Response> => Promise.resolve(ok_response())) as typeof fetch,
		};
		const remaining = await flush(m_env, env);
		expect(remaining).toEqual([]);
	});
});

describe('offline-queue: is_network_error', () => {
	it('classifies TypeError fetch failures as network errors', () => {
		const err = new TypeError('Failed to fetch');
		expect(is_network_error(err)).toBe(true);
	});

	it('classifies MicropubError post_failed with fetch threw as network error', () => {
		const err = new MicropubError(
			'post_h_entry: fetch threw — TypeError: Failed to fetch',
			'post_failed',
		);
		expect(is_network_error(err)).toBe(true);
	});

	it('classifies MicropubError post_failed with HTTP status as NOT a network error', () => {
		const err = new MicropubError(
			'post_h_entry: micropub endpoint returned 401',
			'post_failed',
		);
		expect(is_network_error(err)).toBe(false);
	});

	it('classifies MicropubError no_endpoint as NOT a network error', () => {
		const err = new MicropubError('no endpoint', 'no_endpoint');
		expect(is_network_error(err)).toBe(false);
	});

	it('classifies plain Error as NOT a network error', () => {
		const err = new Error('something else');
		expect(is_network_error(err)).toBe(false);
	});
});

function note_input(content = 'queued note'): Parameters<typeof enqueue>[0] {
	return {
		source: 'note',
		properties: { content },
		micropubEndpoint: 'https://example.test/wp-json/micropub/1.0/endpoint',
	};
}

function fetch_env(
	handler: (url: string, init: RequestInit | undefined) => Response | Promise<Response>,
): MicropubEnvironment {
	return {
		fetch: (async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> =>
			handler(String(input), init)) as typeof fetch,
	};
}

describe('offline-queue: change notifications', () => {
	it('fires after enqueue, a failed replay, and remove', async () => {
		let events = 0;
		const unsubscribe = subscribe_queue_changes(() => {
			events += 1;
		});
		try {
			const id = await enqueue(note_input(), env);
			expect(events).toBe(1);
			await flush(rejecting_fetch(), env, { tokenStore: tokenEnv });
			expect(events).toBe(2);
			await remove(id, env);
			expect(events).toBe(3);
		} finally {
			unsubscribe();
		}
	});

	it('notifies after the write commits, so a listener reads the new entry', async () => {
		const counts: number[] = [];
		const unsubscribe = subscribe_queue_changes(() => {
			void list(env).then((entries) => counts.push(entries.length));
		});
		try {
			await enqueue(note_input(), env);
			await vi.waitFor(() => expect(counts).toEqual([1]));
		} finally {
			unsubscribe();
		}
	});

	it('posts to the outpost-queue BroadcastChannel so other tabs refresh', async () => {
		const other_tab = new BroadcastChannel('outpost-queue');
		const received = new Promise<unknown>((resolve) => {
			other_tab.onmessage = (event: MessageEvent): void => resolve(event.data);
		});
		try {
			await enqueue(note_input(), env);
			await expect(received).resolves.toBe('changed');
		} finally {
			other_tab.close();
		}
	});
});

describe('offline-queue: one replay per entry across tabs', () => {
	it('publishes a queued entry once when two tabs flush at the same moment', async () => {
		await enqueue(note_input(), env);
		let posts = 0;
		const slow_ok = fetch_env(async () => {
			posts += 1;
			await new Promise((resolve) => setTimeout(resolve, 20));
			return ok_response();
		});
		await Promise.all([
			flush(slow_ok, env, { tokenStore: tokenEnv }),
			flush(slow_ok, env, { tokenStore: tokenEnv }),
		]);
		expect(posts).toBe(1);
		expect(await list(env)).toHaveLength(0);
	});

	it('leaves an entry another tab holds, and replays it once the lease runs out', async () => {
		await enqueue(note_input(), env);
		let posts = 0;
		const t0 = 1_000_000;
		// The first tab claims the entry, then its request never answers.
		void flush(
			fetch_env(() => {
				posts += 1;
				return new Promise<Response>(() => {});
			}),
			env,
			{ now: () => t0, tokenStore: tokenEnv },
		);
		await vi.waitFor(() => expect(posts).toBe(1));

		const ok = fetch_env(() => {
			posts += 1;
			return ok_response();
		});
		expect(
			await flush(ok, env, { now: () => t0 + 1_000, tokenStore: tokenEnv }),
		).toHaveLength(1);
		expect(posts).toBe(1);
		expect(
			await flush(ok, env, { now: () => t0 + CLAIM_LEASE_MS + 1, tokenStore: tokenEnv }),
		).toHaveLength(0);
		expect(posts).toBe(2);
	});
});

describe('offline-queue: entries queued before discovery or upload', () => {
	beforeEach(() => {
		localStorage.clear();
	});

	it('discovers the endpoint from me at replay, remembers it, and posts', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'written offline' },
				micropubEndpoint: null,
				me: 'https://example.test/',
			},
			env,
		);
		const calls: string[] = [];
		const site = fetch_env((url, init) => {
			calls.push((init?.method ?? 'GET') + ' ' + url);
			if (init?.method === 'POST') return ok_response();
			return new Response(
				'<html><head><link rel="micropub" href="https://example.test/mp"></head></html>',
				{ status: 200, headers: { 'Content-Type': 'text/html' } },
			);
		});
		expect(await flush(site, env, { tokenStore: tokenEnv })).toHaveLength(0);
		expect(calls).toEqual(['GET https://example.test/', 'POST https://example.test/mp']);
		expect(recall_endpoints('https://example.test/').micropub).toBe('https://example.test/mp');
	});

	it('uploads queued photo bytes once, even when the post fails after the upload', async () => {
		await enqueue(
			{
				source: 'photo',
				properties: { 'mp-photo-alt': 'a red door' },
				micropubEndpoint: 'https://example.test/mp',
				me: 'https://example.test/',
				media: [
					{ filename: 'photo-1.jpg', type: 'image/jpeg', bytes: new Uint8Array([1, 2, 3]).buffer },
				],
				mediaEndpoint: 'https://example.test/media',
			},
			env,
		);
		let uploads = 0;
		let uploaded_size = -1;
		let post_online = false;
		let post_body = '';
		const site = fetch_env((url, init) => {
			if (url === 'https://example.test/media') {
				uploads += 1;
				const file = (init?.body as FormData).get('file');
				uploaded_size = file instanceof Blob ? file.size : -1;
				return new Response('', {
					status: 201,
					headers: { Location: 'https://example.test/uploads/photo-1.jpg' },
				});
			}
			if (!post_online) throw new TypeError('Failed to fetch');
			post_body = String(init?.body);
			return ok_response();
		});

		const [kept] = await flush(site, env, { tokenStore: tokenEnv });
		expect(kept?.media?.[0]?.url).toBe('https://example.test/uploads/photo-1.jpg');
		expect(kept?.media?.[0]?.bytes).toBeUndefined();
		expect(uploaded_size).toBe(3);

		post_online = true;
		expect(await flush(site, env, { tokenStore: tokenEnv })).toHaveLength(0);
		expect(uploads).toBe(1);
		const sent = new URLSearchParams(post_body);
		expect(sent.get('photo')).toBe('https://example.test/uploads/photo-1.jpg');
		expect(sent.get('mp-photo-alt')).toBe('a red door');
	});
});

describe('offline-queue: retry classification', () => {
	it('marks a 5xx failure retryable and a 4xx failure not', async () => {
		await enqueue(note_input(), env);
		const answer = (status: number): MicropubEnvironment =>
			fetch_env(() => new Response('nope', { status }));

		const [after_500] = await flush(answer(500), env, { tokenStore: tokenEnv });
		expect(after_500?.retryable).toBe(true);
		expect(after_500?.lastError).toContain('500');
		expect(after_500?.claimedUntil).toBe(0);

		const [after_400] = await flush(answer(400), env, { tokenStore: tokenEnv });
		expect(after_400?.retryable).toBe(false);
		expect(after_400?.attempts).toBe(2);
	});

	it('classifies errors for automatic retry', () => {
		expect(is_retryable_error(new TypeError('Failed to fetch'))).toBe(true);
		expect(is_retryable_error(new MicropubError('x', 'post_failed', 503))).toBe(true);
		expect(is_retryable_error(new MicropubError('x', 'post_failed', 429))).toBe(true);
		expect(is_retryable_error(new MicropubError('x', 'post_failed', 401))).toBe(false);
		expect(is_retryable_error(new MicropubError('x', 'no_endpoint'))).toBe(false);
	});

	it('removes an entry whose post succeeded with an unsafe Location instead of posting it again', async () => {
		await enqueue(note_input(), env);
		let posts = 0;
		const site = fetch_env(() => {
			posts += 1;
			return new Response('', { status: 201, headers: { Location: 'javascript:void(0)' } });
		});
		expect(await flush(site, env, { tokenStore: tokenEnv })).toHaveLength(0);
		expect(await flush(site, env, { tokenStore: tokenEnv })).toHaveLength(0);
		expect(posts).toBe(1);
	});
});

describe('offline-queue: no plaintext token in storage (Task H5)', () => {
	it('never stores the access token in the entry', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'hi' },
				micropubEndpoint: 'https://example.test/wp-json/micropub/1.0/endpoint',
			},
			env,
		);
		const db = await new Promise<IDBDatabase>((resolve, reject) => {
			const request = env.indexedDB.open('outpost-queue');
			request.onsuccess = (): void => resolve(request.result);
			request.onerror = (): void => reject(request.error);
		});
		const rows = await new Promise<unknown[]>((resolve, reject) => {
			const tx = db.transaction('queue');
			const request = tx.objectStore('queue').getAll();
			request.onsuccess = (): void => resolve(request.result);
			request.onerror = (): void => reject(request.error);
		});
		db.close();
		expect(rows).toHaveLength(1);
		expect(rows[0]).not.toHaveProperty('accessToken');
		expect(JSON.stringify(rows)).not.toContain('secret-token');
	});

	it('fails the entry with a sign-in-again status when no token is stored', async () => {
		await enqueue(note_input(), env);
		// An empty token store (fresh IDBFactory, nothing ever written).
		const empty_token_env: TokenStoreEnvironment = {
			indexedDB: new IDBFactory(),
			crypto: globalThis.crypto,
		};
		const [kept] = await flush(rejecting_fetch(), env, { tokenStore: empty_token_env });
		expect(kept?.lastError).toContain('sign in again');
		expect(kept?.retryable).toBe(false);
	});

	it('empties the queue when the session token is cleared', async () => {
		// Default env (no `env` argument) — the window event clear_token()
		// fires is handled by a listener that clears offline-queue.ts's own
		// default env, not whatever custom env a test constructed.
		await enqueue(note_input('first'));
		await enqueue(note_input('second'));
		expect(await list()).toHaveLength(2);
		await clear_token();
		await vi.waitFor(async () => expect(await list()).toHaveLength(0));
	});
});
