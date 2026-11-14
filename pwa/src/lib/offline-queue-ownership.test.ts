/**
 * Queued posts belong to the exact account that queued them (T10-S3-07, F-08).
 *
 * Two people can share one browser on one site: Alice at
 * https://example.test/alice/ and Bob at https://example.test/bob/. Before
 * this fix the queue compared only origins, so Bob's token claimed, retried,
 * and published Alice's queued posts. Now every row records its owner's
 * canonical identity, and replay, listing, dismissal, and removal act only on
 * rows whose owner is exactly the signed-in account. Other rows stay
 * byte-for-byte unchanged: no claim, no attempt, no error, no upload, no
 * request.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { canonical_account } from './account-identity';
import {
	list,
	flush,
	list_for_owner,
	remove_owned_entries,
	dismiss_owned,
	type OfflineQueueEnvironment,
	type QueueEntry,
} from './offline-queue';
import type { MicropubEnvironment } from './micropub';
import { write_token, clear_token, type TokenStoreEnvironment } from './token-store';
import { post_or_queue } from './post-or-queue';

const ALICE = 'https://example.test/alice/';
const BOB = 'https://example.test/bob/';
const ENDPOINT = 'https://example.test/wp-json/micropub/1.0/endpoint';
const MEDIA = 'https://example.test/wp-json/micropub/1.0/media';

let env: OfflineQueueEnvironment;
let tokens: TokenStoreEnvironment;

interface Sent {
	url: string;
	method: string;
	authorization: string | null;
}

/** A Micropub server that records every request and accepts everything. */
function recording(): { env: MicropubEnvironment; sent: Sent[] } {
	const sent: Sent[] = [];
	return {
		sent,
		env: {
			fetch: (async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
				const headers = new Headers(init?.headers);
				sent.push({ url: String(input), method: init?.method ?? 'GET', authorization: headers.get('Authorization') });
				if (String(input).includes('q=config')) {
					return new Response(JSON.stringify({ 'media-endpoint': MEDIA }), {
						status: 200,
						headers: { 'Content-Type': 'application/json' },
					});
				}
				if (String(input) === MEDIA) {
					return new Response('', { status: 201, headers: { Location: 'https://example.test/uploads/1.jpg' } });
				}
				if ((init?.method ?? 'GET') === 'GET') {
					return new Response('<html></html>', { status: 200, headers: { Link: `<${ENDPOINT}>; rel="micropub"` } });
				}
				return new Response('', { status: 201, headers: { Location: 'https://example.test/p/1' } });
			}) as typeof fetch,
		},
	};
}

async function sign_in_as(me: string, token = 'token-for-' + me): Promise<void> {
	await clear_token(tokens);
	await write_token({ accessToken: token, tokenType: 'Bearer', scope: 'create media', me }, tokens);
}

/** A row as stored, with photo bytes as plain arrays so equality is exact. */
async function snapshot(): Promise<unknown[]> {
	return (await list(env)).map((row) => ({
		...row,
		media: row.media?.map((m) => ({ ...m, bytes: m.bytes ? Array.from(new Uint8Array(m.bytes)) : undefined })),
	}));
}

/** Queue one post as `me` while offline, the way the composer does. */
async function queue_as(me: string, content: string, photo = false): Promise<void> {
	const offline: MicropubEnvironment = { fetch: (() => Promise.reject(new TypeError('Failed to fetch'))) as typeof fetch };
	const result = await post_or_queue(
		{
			source: photo ? 'photo' : 'note',
			properties: { content },
			me,
			accessToken: 'token-for-' + me,
			micropubEndpoint: ENDPOINT,
			...(photo ? { photos: [{ blob: new Blob([new Uint8Array([7, 8, 9])], { type: 'image/jpeg' }), filename: 'p.jpg' }] } : {}),
		} as Parameters<typeof post_or_queue>[0],
		offline,
		env,
	);
	expect(result.kind).toBe('queued');
}

/** Alice's text post queued before any endpoint was known. */
async function enqueue_raw_owned_without_endpoint(): Promise<void> {
	await write_raw({
		source: 'note',
		properties: { content: 'Alice post, endpoint unknown' },
		micropubEndpoint: null,
		me: ALICE,
		owner: 'https://example.test/alice',
		createdAt: 1,
		attempts: 0,
	});
}

/** Write a raw row straight into the queue store (legacy or malformed shapes). */
async function write_raw(row: Record<string, unknown>): Promise<void> {
	await new Promise<void>((resolve, reject) => {
		const open = env.indexedDB.open('outpost-queue', 1);
		open.onupgradeneeded = (): void => {
			open.result.createObjectStore('queue', { autoIncrement: true });
		};
		open.onsuccess = (): void => {
			const tx = open.result.transaction('queue', 'readwrite');
			tx.objectStore('queue').add(row);
			tx.oncomplete = (): void => {
				open.result.close();
				resolve();
			};
			tx.onerror = (): void => reject(tx.error);
		};
	});
}

beforeEach(() => {
	localStorage.clear();
	env = { indexedDB: new IDBFactory() };
	tokens = { indexedDB: new IDBFactory(), crypto: globalThis.crypto };
});

describe('canonical_account', () => {
	it.each([
		['https://example.test/alice/', 'https://example.test/alice'],
		['https://example.test/alice', 'https://example.test/alice'],
		['HTTPS://Example.TEST/alice/', 'https://example.test/alice'],
		['https://example.test:443/alice/', 'https://example.test/alice'],
		['http://example.test:80/', 'http://example.test/'],
		['https://example.test', 'https://example.test/'],
		['https://example.test/', 'https://example.test/'],
		['https://example.test/alice/#me', 'https://example.test/alice'],
		['https://example.test:8443/alice/', 'https://example.test:8443/alice'],
		['https://example.test/alice/?u=1', 'https://example.test/alice?u=1'],
		['https://example.test/%7ealice/', 'https://example.test/~alice'],
	])('%s → %s', (input, expected) => {
		expect(canonical_account(input)).toBe(expected);
	});

	it.each([
		['relative', '/alice/'],
		['not a URL', 'alice'],
		['empty', ''],
		['ftp', 'ftp://example.test/alice/'],
		['user info', 'https://alice@example.test/'],
		['javascript', 'javascript:alert(1)'],
	])('rejects %s', (_label, input) => {
		expect(canonical_account(input)).toBeNull();
	});

	it('rejects a value that is not a string', () => {
		expect(canonical_account(undefined)).toBeNull();
		expect(canonical_account(42)).toBeNull();
	});

	it.each([
		['scheme', 'http://example.test/alice/'],
		['port', 'https://example.test:8443/alice/'],
		['path', 'https://example.test/alicia/'],
		['query', 'https://example.test/alice/?u=2'],
		['host', 'https://example.org/alice/'],
	])('a different %s is a different account', (_label, other) => {
		expect(canonical_account(other)).not.toBe(canonical_account(ALICE));
	});
});

describe('Alice and Bob on one device', () => {
	it('records the exact owner on every new row', async () => {
		await queue_as(ALICE, 'Alice text post');
		await queue_as(ALICE, 'Alice photo post', true);
		expect((await list(env)).map((row) => row.owner)).toEqual(['https://example.test/alice', 'https://example.test/alice']);
	});

	it('Bob never sees, claims, retries, uploads, or sends Alice’s queued posts', async () => {
		await queue_as(ALICE, 'Alice text post');
		await queue_as(ALICE, 'Alice photo post', true);
		const before = await snapshot();

		await sign_in_as(BOB);
		const server = recording();
		const left = await flush(server.env, env, { tokenStore: tokens });
		await flush(server.env, env, { tokenStore: tokens }); // A manual "Retry all now".

		expect(server.sent).toEqual([]);
		expect(left).toEqual([]);
		expect(await snapshot()).toEqual(before);
		const bob_view = await list_for_owner(canonical_account(BOB), env);
		expect(bob_view.own).toEqual([]);
		expect(bob_view.others).toBe(2);
		expect(JSON.stringify(bob_view)).not.toContain('Alice');
	});

	it('Alice signs back in, sees her unchanged rows, and each sends once with her token', async () => {
		await queue_as(ALICE, 'Alice text post');
		await queue_as(ALICE, 'Alice photo post', true);
		const before = await snapshot();
		await sign_in_as(BOB);
		await flush(recording().env, env, { tokenStore: tokens });

		await sign_in_as(ALICE, 'alice-token');
		const alice_view = await list_for_owner(canonical_account(ALICE), env);
		expect(alice_view.own.map((r) => r.properties.content)).toEqual(['Alice text post', 'Alice photo post']);
		expect(await snapshot()).toEqual(before);

		const server = recording();
		const left = await flush(server.env, env, { tokenStore: tokens });

		expect(left).toEqual([]);
		// Every request, in order, with its exact destination and credential.
		// Each one here acts for Alice, so each carries her token; none of
		// these request classes is meant to be unauthenticated.
		expect(server.sent).toEqual([
			{ url: ENDPOINT, method: 'POST', authorization: 'Bearer alice-token' },
			{ url: ENDPOINT + '?q=config', method: 'GET', authorization: 'Bearer alice-token' },
			{ url: MEDIA, method: 'POST', authorization: 'Bearer alice-token' },
			{ url: ENDPOINT, method: 'POST', authorization: 'Bearer alice-token' },
		]);
		expect(server.sent.filter((s) => s.method === 'POST' && s.url === ENDPOINT)).toHaveLength(2);
		expect(server.sent.filter((s) => s.url === MEDIA)).toHaveLength(1);
	});

	it('discovers the Micropub endpoint without credentials, and sends the post with Alice’s token', async () => {
		await enqueue_raw_owned_without_endpoint();
		await sign_in_as(ALICE, 'alice-token');
		const server = recording();

		expect(await flush(server.env, env, { tokenStore: tokens })).toEqual([]);

		// Discovery fetches Alice's public profile page: intentionally no token.
		expect(server.sent).toEqual([
			{ url: ALICE, method: 'GET', authorization: null },
			{ url: ENDPOINT, method: 'POST', authorization: 'Bearer alice-token' },
		]);
	});

	it('treats a trailing-slash difference as the same account', async () => {
		await queue_as(ALICE, 'Alice text post');
		await sign_in_as('https://example.test/alice');
		const server = recording();

		expect(await flush(server.env, env, { tokenStore: tokens })).toEqual([]);
		expect(server.sent.filter((s) => s.method === 'POST')).toHaveLength(1);
	});

	it.each([
		['scheme', 'http://example.test/alice/'],
		['port', 'https://example.test:8443/alice/'],
		['path', 'https://example.test/alice/blog/'],
		['query', 'https://example.test/alice/?u=1'],
	])('a token whose %s differs sends nothing and changes nothing', async (_label, me) => {
		await queue_as(ALICE, 'Alice text post', true);
		const before = await snapshot();
		await sign_in_as(me);
		const server = recording();

		await flush(server.env, env, { tokenStore: tokens });

		expect(server.sent).toEqual([]);
		expect(await snapshot()).toEqual(before);
	});

	it('sends nothing and changes nothing when no one is signed in', async () => {
		await queue_as(ALICE, 'Alice text post');
		const before = await snapshot();
		await clear_token(tokens);
		const server = recording();

		await flush(server.env, env, { tokenStore: tokens });

		expect(server.sent).toEqual([]);
		expect(await snapshot()).toEqual(before);
	});
});

describe('rows with no exact owner stay locked', () => {
	it.each([
		['a legacy row with no owner, even with a matching me', { me: ALICE }],
		['a legacy row with no owner and no me', {}],
		['an owner that is not a URL', { owner: 'alice', me: ALICE }],
		['an owner that is not in canonical form', { owner: 'HTTPS://EXAMPLE.TEST/alice/', me: ALICE }],
		['an owner that is not a string', { owner: 42, me: ALICE }],
	])('%s', async (_label, fields) => {
		await write_raw({
			source: 'note',
			properties: { content: 'Legacy post' },
			micropubEndpoint: ENDPOINT,
			createdAt: 1,
			attempts: 0,
			...fields,
		});
		const before = await snapshot();
		await sign_in_as(ALICE);
		const server = recording();

		await flush(server.env, env, { tokenStore: tokens });

		expect(server.sent).toEqual([]);
		expect(await snapshot()).toEqual(before);
		const view = await list_for_owner(canonical_account(ALICE), env);
		expect(view.own).toEqual([]);
		expect(view.others).toBe(1);
	});
});

describe('dismissing and removing act only on the signed-in account’s rows', () => {
	it('dismiss refuses another account’s row', async () => {
		await queue_as(ALICE, 'Alice text post');
		const [row] = (await list(env)) as [QueueEntry];

		expect(await dismiss_owned(row.id, canonical_account(BOB), env)).toBe(false);
		expect(await list(env)).toHaveLength(1);
		expect(await dismiss_owned(row.id, canonical_account(ALICE), env)).toBe(true);
		expect(await list(env)).toHaveLength(0);
	});

	it('remove_owned_entries deletes only the current account’s rows', async () => {
		await queue_as(ALICE, 'Alice text post');
		await queue_as(ALICE, 'Alice photo post', true);
		await queue_as(BOB, 'Bob text post');
		await write_raw({ source: 'note', properties: { content: 'Legacy post' }, micropubEndpoint: ENDPOINT, createdAt: 1, attempts: 0 });

		expect(await remove_owned_entries(canonical_account(ALICE), env)).toBe(2);

		const left = (await list(env)).map((r) => r.properties.content);
		expect(left).toEqual(['Bob text post', 'Legacy post']);
	});

	it('remove_owned_entries rejects, and deletes nothing, when storage fails', async () => {
		await queue_as(ALICE, 'Alice text post');
		const broken: OfflineQueueEnvironment = {
			indexedDB: {
				open: (): IDBOpenDBRequest => {
					const req = { error: new DOMException('blocked'), onerror: null as null | (() => void) };
					setTimeout(() => req.onerror?.(), 0);
					return req as unknown as IDBOpenDBRequest;
				},
			} as unknown as IDBFactory,
		};

		await expect(remove_owned_entries(canonical_account(ALICE), broken)).rejects.toBeTruthy();
		expect(await list(env)).toHaveLength(1);
	});

	it('removes nothing for an unknown owner', async () => {
		await queue_as(ALICE, 'Alice text post');
		expect(await remove_owned_entries(null, env)).toBe(0);
		expect(await list(env)).toHaveLength(1);
	});
});

/** The real IndexedDB write methods, before any spy wraps them. */
const original_put = IDBObjectStore.prototype.put;
const original_update = IDBCursor.prototype.update;
const original_delete = IDBObjectStore.prototype.delete;

describe('the H5 plaintext-token scrub is the only write to a row flush() does not replay (T10-S3-07 v1.1)', () => {
	const LEGACY = {
		source: 'note',
		properties: { content: 'Queued before 1.0.22' },
		micropubEndpoint: ENDPOINT,
		me: ALICE,
		createdAt: 1,
		attempts: 3,
		lastError: 'post_failed: earlier failure',
		retryable: true,
		accessToken: 'LEGACY-PLAINTEXT',
	};

	function without_token(row: Record<string, unknown>): Record<string, unknown> {
		const { accessToken: _drop, ...rest } = row;
		return rest;
	}

	it.each([
		['a locked legacy row (no owner), with Alice signed in', {}, ALICE],
		['another account’s row, with Alice signed in', { owner: 'https://example.test/bob' }, ALICE],
		['a locked legacy row, with no one signed in', {}, null],
	])('%s: removes only the token field; nothing is claimed, retried, discovered, uploaded, sent, or deleted', async (_label, extra, signed_in) => {
		await write_raw({ ...LEGACY, ...extra });
		if (signed_in) await sign_in_as(signed_in);
		const server = recording();

		await flush(server.env, env, { tokenStore: tokens });

		const rows = (await list(env)) as unknown as Array<Record<string, unknown>>;
		expect(rows).toHaveLength(1);
		const { id: _id, ...stored } = rows[0] as Record<string, unknown>;
		expect(stored).toEqual(without_token({ ...LEGACY, ...extra }));
		expect(JSON.stringify(rows)).not.toContain('LEGACY-PLAINTEXT');
		expect(server.sent).toEqual([]);
	});

	it('writes nothing to rows without a token field', async () => {
		await write_raw({ ...without_token(LEGACY) });
		await queue_as(BOB, 'Bob post');
		await sign_in_as(ALICE);
		const writes: string[] = [];
		const put = vi.spyOn(IDBObjectStore.prototype, 'put').mockImplementation(function (this: IDBObjectStore, ...args) {
			writes.push('put');
			return Reflect.apply(original_put, this, args) as IDBRequest<IDBValidKey>;
		});
		const update = vi.spyOn(IDBCursor.prototype, 'update').mockImplementation(function (this: IDBCursor, ...args) {
			writes.push('update');
			return Reflect.apply(original_update, this, args) as IDBRequest<IDBValidKey>;
		});
		const del = vi.spyOn(IDBObjectStore.prototype, 'delete').mockImplementation(function (this: IDBObjectStore, ...args) {
			writes.push('delete');
			return Reflect.apply(original_delete, this, args) as IDBRequest<undefined>;
		});
		try {
			await flush(recording().env, env, { tokenStore: tokens });
		} finally {
			put.mockRestore();
			update.mockRestore();
			del.mockRestore();
		}

		expect(writes).toEqual([]);
	});

	it('a failed scrub transaction stops the flush: no replay, and the row is intact', async () => {
		await queue_as(ALICE, 'Alice text post');
		await write_raw({ ...LEGACY });
		const before = await snapshot();
		await sign_in_as(ALICE);
		const real = env.indexedDB;
		const failing: OfflineQueueEnvironment = {
			indexedDB: {
				open(name: string, version?: number): IDBOpenDBRequest {
					const req = real.open(name, version);
					req.addEventListener('success', () => {
						const db = req.result;
						const transaction = db.transaction.bind(db);
						db.transaction = ((stores: string | string[], mode?: IDBTransactionMode) => {
							if (mode === 'readwrite') throw new DOMException('write blocked', 'UnknownError');
							return transaction(stores, mode);
						}) as IDBDatabase['transaction'];
					});
					return req;
				},
			} as unknown as IDBFactory,
		};
		const server = recording();

		await expect(flush(server.env, failing, { tokenStore: tokens })).rejects.toBeTruthy();

		expect(server.sent).toEqual([]);
		expect(await snapshot()).toEqual(before);
	});
});
