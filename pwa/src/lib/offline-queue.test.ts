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
import { post_or_queue } from './post-or-queue';

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
	// `me` matches the site every entry in this file that sets `.me` uses,
	// so the cross-account check (fix round 1) doesn't fail them.
	tokenEnv = { indexedDB: new IDBFactory(), crypto: globalThis.crypto };
	await write_token(
		{ accessToken: 't', tokenType: 'Bearer', scope: '', me: 'https://example.test/' },
		tokenEnv,
	);
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
				me: 'https://example.test/',
			},
			env,
		);
		await enqueue(
			{
				source: 'reply',
				properties: { content: 'b' },
				micropubEndpoint: 'https://example.test/m',
				me: 'https://example.test/',
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
				me: 'https://example.test/',
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
		me: 'https://example.test/',
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

/** Open the raw `outpost-queue` IDB and read every row exactly as stored. */
async function raw_queue_rows(queue_env: OfflineQueueEnvironment): Promise<unknown[]> {
	const db = await new Promise<IDBDatabase>((resolve, reject) => {
		const request = queue_env.indexedDB.open('outpost-queue');
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
	return rows;
}

/**
 * Write a raw row straight into the `outpost-queue` IDB, bypassing
 * `enqueue()` — which no longer accepts an `accessToken` field at all.
 * Reproduces exactly what a pre-1.0.22 build's `enqueue()` used to write,
 * for tests that need a legacy plaintext-token row already on disk.
 */
async function seed_legacy_row(
	queue_env: OfflineQueueEnvironment,
	row: Record<string, unknown>,
): Promise<void> {
	const db = await new Promise<IDBDatabase>((resolve, reject) => {
		const request = queue_env.indexedDB.open('outpost-queue', 1);
		request.onupgradeneeded = (): void => {
			if (!request.result.objectStoreNames.contains('queue')) {
				request.result.createObjectStore('queue', { autoIncrement: true });
			}
		};
		request.onsuccess = (): void => resolve(request.result);
		request.onerror = (): void => reject(request.error);
	});
	await new Promise<void>((resolve, reject) => {
		const tx = db.transaction('queue', 'readwrite');
		tx.objectStore('queue').add(row);
		tx.oncomplete = (): void => resolve();
		tx.onerror = (): void => reject(tx.error);
	});
	db.close();
}

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
		const rows = await raw_queue_rows(env);
		expect(rows).toHaveLength(1);
		expect(rows[0]).not.toHaveProperty('accessToken');
		expect(JSON.stringify(rows)).not.toContain('secret-token');
	});

	it('never stores the access token when post_or_queue queues a submit offline', async () => {
		// Drives the actual production call site (post-or-queue.ts) with a
		// real accessToken in the input and a network failure, rather than
		// asserting a string that the test itself never puts in the entry.
		const result = await post_or_queue(
			{
				source: 'note',
				me: 'https://example.test/',
				accessToken: 'secret-token-abc',
				properties: { content: 'queued while offline' },
				micropubEndpoint: 'https://example.test/mp',
			},
			rejecting_fetch(),
			env,
		);
		expect(result.kind).toBe('queued');
		const rows = await raw_queue_rows(env);
		expect(rows).toHaveLength(1);
		expect(rows[0]).not.toHaveProperty('accessToken');
		expect(JSON.stringify(rows)).not.toContain('secret-token-abc');
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

	it('sends the Authorization header for the currently stored token on a matching-site entry', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'hi' },
				micropubEndpoint: 'https://example.test/mp',
				me: 'https://example.test/',
			},
			env,
		);
		let auth_header: string | null = null;
		const site = fetch_env((_url, init) => {
			auth_header = (init?.headers as Record<string, string> | undefined)?.['Authorization'] ?? null;
			return ok_response();
		});
		expect(await flush(site, env, { tokenStore: tokenEnv })).toHaveLength(0);
		expect(auth_header).toBe('Bearer t');
	});

	it('fails an entry non-retryably, without sending anything, when the stored token belongs to a different site', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'hi' },
				micropubEndpoint: 'https://a.test/mp',
				me: 'https://a.test/',
			},
			env,
		);
		const other_site_token_env: TokenStoreEnvironment = {
			indexedDB: new IDBFactory(),
			crypto: globalThis.crypto,
		};
		await write_token(
			{ accessToken: 'b-token', tokenType: 'Bearer', scope: '', me: 'https://b.test/' },
			other_site_token_env,
		);
		let fetch_calls = 0;
		const site = fetch_env(() => {
			fetch_calls += 1;
			return ok_response();
		});
		const [kept] = await flush(site, env, { tokenStore: other_site_token_env });
		expect(fetch_calls).toBe(0);
		expect(kept?.retryable).toBe(false);
		expect(kept?.lastError).toContain('signed in as a different site');
		expect(kept?.lastError).toContain('Retry all now or the next reconnect sends it');
		expect(kept?.lastError).not.toMatch(/sign out/i);
	});

	it('strips a legacy plaintext accessToken field left over from a 1.0.21-or-earlier row', async () => {
		await seed_legacy_row(env, {
			source: 'note',
			properties: { content: 'queued before 1.0.22' },
			accessToken: 'LEGACY-PLAINTEXT',
			micropubEndpoint: 'https://example.test/m',
			me: 'https://example.test/',
			createdAt: Date.now(),
			attempts: 0,
		});

		// Fail the replay so the entry gets written back (via claim() then
		// save()) instead of removed, exercising both of the two places that
		// used to spread a legacy row's fields back unchanged.
		await flush(rejecting_fetch(), env, { tokenStore: tokenEnv });

		const rows = await raw_queue_rows(env);
		expect(rows).toHaveLength(1);
		expect(rows[0]).not.toHaveProperty('accessToken');
		expect(JSON.stringify(rows)).not.toContain('LEGACY-PLAINTEXT');
	});

	it('keeps queued posts through a sign-out and sends them after signing back in to the same site', async () => {
		// One IndexedDB for both stores, as in the browser, so a sign-out
		// that emptied the queue would show up here.
		const shared = new IDBFactory();
		const queue_env: OfflineQueueEnvironment = { indexedDB: shared };
		const token_env: TokenStoreEnvironment = { indexedDB: shared, crypto: globalThis.crypto };
		const token = { accessToken: 't', tokenType: 'Bearer', scope: '', me: 'https://example.test/' };
		await write_token(token, token_env);
		await enqueue(note_input('written before the token expired'), queue_env);

		await clear_token(token_env);
		expect(await list(queue_env)).toHaveLength(1);

		let posts = 0;
		const site = fetch_env(() => {
			posts += 1;
			return ok_response();
		});
		const [waiting] = await flush(site, queue_env, { tokenStore: token_env });
		expect(waiting?.lastError).toContain('sign in again');
		expect(posts).toBe(0);

		await write_token(token, token_env);
		expect(await flush(site, queue_env, { tokenStore: token_env })).toHaveLength(0);
		expect(posts).toBe(1);
	});

	it('never sends an entry queued without a site, and fails it non-retryably', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'queued with no me' },
				micropubEndpoint: 'https://example.test/mp',
			},
			env,
		);
		let fetch_calls = 0;
		const site = fetch_env(() => {
			fetch_calls += 1;
			return ok_response();
		});
		const [kept] = await flush(site, env, { tokenStore: tokenEnv });
		expect(fetch_calls).toBe(0);
		expect(kept?.retryable).toBe(false);
		expect(kept?.lastError).toContain('queued by an older version with no site recorded; re-create this post');
	});
});
