/**
 * Offline post queue.
 *
 * Phase D1. When a post can't reach the site because the network is down,
 * the composer keeps it here instead of losing it. Entries live in
 * IndexedDB, the queue badge lists them with retry and dismiss controls,
 * and they replay when the browser comes back online.
 *
 * IDB schema: database `outpost-queue` (version 1), one auto-keyed object
 * store `queue` of `QueueEntry` records. Each entry carries what a replay
 * needs: the h-entry properties and the Micropub endpoint, or the signed-in
 * "me" URL to discover it from when the post was queued on a device that
 * never discovered one. Fields added in 1.0.16 are optional, so entries an
 * older build queued still replay. The access token is never one of those
 * fields — see below.
 *
 * Photos: a post queued before its photos uploaded keeps the processed
 * image bytes in `media`. The replay uploads each one, writes its URL onto
 * the entry straight away (so a later attempt never uploads it twice), and
 * sends the URLs as the `photo` property.
 *
 * Several tabs replay the same queue. A tab claims each entry inside one
 * readwrite transaction before replaying it, and IndexedDB runs readwrite
 * transactions on a store one at a time across every connection to the
 * database, so only one tab holds the claim. The claim is a lease: when a
 * tab closes mid-replay, the entry frees up once the lease runs out.
 *
 * Every write notifies listeners in this tab (a DOM event) and in other
 * tabs (a BroadcastChannel), so the badge updates without polling.
 *
 * No plaintext token in the queue (Task H5): a queued entry used to carry
 * a copy of the access token, so it sat in IndexedDB in plaintext for as
 * long as the entry was queued. `enqueue()` no longer stores one. Replay
 * reads the current token fresh from `token-store.ts` (`read_token()`)
 * each time. When no token is stored — signed out, or the encrypted store
 * was cleared — replay fails the entry with a `no_token`
 * `OfflineQueueError` ("sign in again") instead of sending it with a
 * stale or absent credential; `is_retryable_error()` marks that `false`
 * so the badge doesn't hammer retries a sign-in has to fix. A row queued
 * by 1.0.21 or earlier still carries a plaintext `accessToken` field from
 * before this fix; `claim()` and `save()` both strip it the moment such a
 * row is next read and written back, so it doesn't keep re-persisting.
 *
 * Ownership (T10-S3-07): every entry records `owner`, the canonical
 * identity (account-identity.ts) of the account that queued it. Two paths on
 * one site are two accounts. Replay, listing, dismissal, and removal act only
 * on entries whose owner is exactly the signed-in account: `flush()` reads
 * the token once, skips every other entry before claiming it, and sends with
 * that same token, so another account's entry is never claimed, retried,
 * uploaded, discovered, sent, or changed. An entry with a missing, malformed,
 * or non-canonical owner (every entry queued before this version) is locked:
 * it never replays and stays as it is. `list_for_owner()` reports other
 * entries only as a count.
 *
 * Signing out leaves the queue as it is unless the person chooses to remove
 * their own queued posts (`remove_owned_entries()`). Posts waiting when a
 * token expires send on the first replay after the same account signs in.
 */

import {
	discover_media_endpoint,
	discover_micropub_endpoint,
	post_h_entry,
	upload_media,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from './micropub';
import { recall_endpoints, remember_endpoints } from './endpoint-cache';
import { read_token, type StoredToken, type TokenStoreEnvironment } from './token-store';
import { canonical_account } from './account-identity';

const DB_NAME = 'outpost-queue';
const DB_VERSION = 1;
const STORE_QUEUE = 'queue';

/**
 * How long one tab owns an entry while replaying it. Renewed after every
 * upload, so another tab can only take the entry over from a tab that
 * closed or stalled for this long in a single request.
 */
export const CLAIM_LEASE_MS = 120_000;

/** Window event fired in this tab after every queue write. */
export const QUEUE_CHANGED_EVENT = 'outpost:queue-changed';
const CHANNEL_NAME = 'outpost-queue';

export type QueueSource = 'note' | 'reply' | 'photo' | 'listen' | 'article' | 'life' | 'recipe';

export interface QueuedMedia {
	filename: string;
	/** MIME type the upload blob is rebuilt with. */
	type: string;
	/** Processed image bytes, kept until the upload succeeds. */
	bytes?: ArrayBuffer;
	/** Media endpoint Location, set once the upload succeeds. */
	url?: string;
}

export interface QueueEntry {
	/** Auto-assigned IDB key. */
	id: number;
	/** Where this entry came from — purely for the queue UI's labeling. */
	source: QueueSource;
	/** h-entry properties. Entries with `media` get `photo` added at replay. */
	properties: HEntryProperties;
	/** Null when the post was queued before any endpoint was discovered. */
	micropubEndpoint: string | null;
	/** Signed-in "me" URL the replay discovers endpoints from. Absent on 1.0.15 entries. */
	me?: string;
	/**
	 * Canonical identity of the account that queued this entry
	 * (canonical_account(me) at enqueue). Absent on entries queued before
	 * 1.0.27; those never replay.
	 */
	owner?: string;
	/** Photos for this post, uploaded at replay when `url` is missing. */
	media?: QueuedMedia[];
	mediaEndpoint?: string | null;
	/** Wall-clock ms since epoch at enqueue. Cosmetic. */
	createdAt: number;
	/** Number of replay attempts so far (0 means never tried since enqueue). */
	attempts: number;
	/** Last error code/message from the server, when retries are stalling. */
	lastError?: string;
	/** False when the last failure was a rejection that retrying won't change. */
	retryable?: boolean;
	/** Epoch ms until which one tab owns this entry's replay. */
	claimedUntil?: number;
}

export type QueueEnqueueInput = Pick<QueueEntry, 'source' | 'properties' | 'micropubEndpoint'> &
	Partial<Pick<QueueEntry, 'me' | 'media' | 'mediaEndpoint'>>;

export interface OfflineQueueEnvironment {
	indexedDB: IDBFactory;
}

const default_env: OfflineQueueEnvironment = {
	indexedDB: globalThis.indexedDB,
};

export class OfflineQueueError extends Error {
	constructor(
		message: string,
		public readonly code: 'open_failed' | 'tx_failed' | 'no_token' | 'wrong_account',
	) {
		super(message);
		this.name = 'OfflineQueueError';
	}
}

let channel: BroadcastChannel | null | undefined;

function queue_channel(): BroadcastChannel | null {
	if (channel === undefined) {
		channel = null;
		if (typeof BroadcastChannel === 'function') {
			try {
				channel = new BroadcastChannel(CHANNEL_NAME);
				// Node's implementation holds the process open; browsers have no unref.
				(channel as { unref?: () => void }).unref?.();
			} catch {
				channel = null;
			}
		}
	}
	return channel;
}

/**
 * Tell every open composer that the queue changed. Same-tab listeners get
 * the window event; other tabs get the channel message (a BroadcastChannel
 * never delivers a message to the object that posted it).
 */
export function notify_queue_changed(): void {
	if (typeof window !== 'undefined') {
		window.dispatchEvent(new Event(QUEUE_CHANGED_EVENT));
	}
	try {
		queue_channel()?.postMessage('changed');
	} catch {
		// Channel closed; other tabs catch up on their next read.
	}
}

/** Subscribe to queue changes from this tab and others. Returns the unsubscribe. */
export function subscribe_queue_changes(listener: () => void): () => void {
	const handler = (): void => listener();
	window.addEventListener(QUEUE_CHANGED_EVENT, handler);
	const ch = queue_channel();
	ch?.addEventListener('message', handler);
	return (): void => {
		window.removeEventListener(QUEUE_CHANGED_EVENT, handler);
		ch?.removeEventListener('message', handler);
	};
}

/**
 * Run `body` in one transaction on the queue store and resolve with the
 * value it set once the transaction commits, so a listener that reads the
 * queue after a notification sees the write.
 */
async function in_transaction<T>(
	env: OfflineQueueEnvironment,
	mode: IDBTransactionMode,
	context: string,
	initial: T,
	body: (store: IDBObjectStore, set_result: (value: T) => void) => void,
): Promise<T> {
	const db = await open_db(env);
	try {
		return await new Promise<T>((resolve, reject) => {
			let result = initial;
			const tx = db.transaction(STORE_QUEUE, mode);
			const fail = (): void =>
				reject(
					new OfflineQueueError(
						context + ': tx failed — ' + (tx.error?.message ?? 'unknown'),
						'tx_failed',
					),
				);
			tx.oncomplete = (): void => resolve(result);
			tx.onabort = fail;
			body(tx.objectStore(STORE_QUEUE), (value) => {
				result = value;
			});
		});
	} finally {
		db.close();
	}
}

/**
 * Persist a post for later retry.
 *
 * Returns the assigned id. The caller owns showing UI feedback ("Saved
 * for later"); this function only handles persistence.
 */
export async function enqueue(
	input: QueueEnqueueInput,
	env: OfflineQueueEnvironment = default_env,
): Promise<number> {
	const value: Omit<QueueEntry, 'id'> = {
		source: input.source,
		properties: input.properties,
		micropubEndpoint: input.micropubEndpoint,
		...(input.me !== undefined ? { me: input.me } : {}),
		...(owner_from(input.me) !== null ? { owner: owner_from(input.me) as string } : {}),
		...(input.media !== undefined ? { media: input.media } : {}),
		...(input.mediaEndpoint !== undefined ? { mediaEndpoint: input.mediaEndpoint } : {}),
		createdAt: Date.now(),
		attempts: 0,
	};
	const id = await in_transaction<number>(env, 'readwrite', 'enqueue', 0, (store, set) => {
		const request = store.add(value);
		request.onsuccess = (): void => set(request.result as number);
	});
	notify_queue_changed();
	return id;
}

function owner_from(me: string | undefined): string | null {
	return canonical_account(me);
}

/**
 * The exact owner recorded on an entry, or null when it has none or it isn't
 * a canonical identity. A null owner locks the entry.
 */
export function owner_of(entry: { owner?: unknown }): string | null {
	const owner = entry.owner;
	return typeof owner === 'string' && canonical_account(owner) === owner ? owner : null;
}

/** Whether an entry belongs to exactly this account (never true for a null account). */
function owned_by(entry: { owner?: unknown }, account: string | null): boolean {
	return account !== null && owner_of(entry) === account;
}

/**
 * The signed-in account's entries, oldest first, and a count of everything
 * else on the device (other accounts' entries and locked ones). The count is
 * all the UI may show about entries that aren't the person's own.
 */
export async function list_for_owner(
	account: string | null,
	env: OfflineQueueEnvironment = default_env,
): Promise<{ own: QueueEntry[]; others: number }> {
	const all = await list(env);
	const own = all.filter((entry) => owned_by(entry, account));
	return { own, others: all.length - own.length };
}

/**
 * Dismiss one entry, only when it belongs to `account`. The check and the
 * delete share one transaction. Resolves whether anything was removed.
 */
export async function dismiss_owned(
	id: number,
	account: string | null,
	env: OfflineQueueEnvironment = default_env,
): Promise<boolean> {
	if (account === null) return false;
	const removed = await in_transaction<boolean>(env, 'readwrite', 'dismiss', false, (store, set) => {
		const request = store.get(id);
		request.onsuccess = (): void => {
			const raw = request.result as { owner?: unknown } | undefined;
			if (raw && owned_by(raw, account)) {
				store.delete(id);
				set(true);
			}
		};
	});
	if (removed) notify_queue_changed();
	return removed;
}

/**
 * "Remove my queued posts and sign out": delete every entry owned by
 * `account` in one transaction, leaving other accounts' entries and locked
 * ones. Resolves how many were removed; rejects when storage fails, so the
 * caller doesn't claim a deletion that didn't happen.
 */
export async function remove_owned_entries(
	account: string | null,
	env: OfflineQueueEnvironment = default_env,
): Promise<number> {
	if (account === null) return 0;
	let removed = 0;
	await in_transaction<null>(env, 'readwrite', 'remove_owned', null, (store) => {
		const cursor_request = store.openCursor();
		cursor_request.onsuccess = (): void => {
			const cursor = cursor_request.result;
			if (!cursor) return;
			if (owned_by(cursor.value as { owner?: unknown }, account)) {
				cursor.delete();
				removed += 1;
			}
			cursor.continue();
		};
	});
	if (removed > 0) notify_queue_changed();
	return removed;
}

/**
 * Read all queued entries, oldest-first (insertion order).
 *
 * The UI banner uses this to show count + per-entry retry/dismiss
 * controls. Resilient to a missing DB (returns empty list).
 */
export async function list(
	env: OfflineQueueEnvironment = default_env,
): Promise<QueueEntry[]> {
	const out: QueueEntry[] = [];
	return in_transaction<QueueEntry[]>(env, 'readonly', 'list', out, (store) => {
		const cursor_request = store.openCursor();
		cursor_request.onsuccess = (): void => {
			const cursor = cursor_request.result;
			if (cursor) {
				const value = cursor.value as Omit<QueueEntry, 'id'>;
				out.push({ id: cursor.key as number, ...value });
				cursor.continue();
			}
		};
	});
}

/**
 * Remove a specific entry. Used by the UI's "dismiss" action and by
 * `flush()` after a successful replay.
 */
export async function remove(
	id: number,
	env: OfflineQueueEnvironment = default_env,
): Promise<void> {
	await in_transaction<null>(env, 'readwrite', 'remove', null, (store) => {
		store.delete(id);
	});
	notify_queue_changed();
}

/**
 * Write an entry back in place (retry state, uploaded photo URLs, lease).
 * Strips a legacy `accessToken` field a pre-1.0.22 row may still carry
 * (see the file docblock) so it stops re-persisting the plaintext token
 * on every subsequent write.
 */
async function save(entry: QueueEntry, env: OfflineQueueEnvironment): Promise<void> {
	const { id, accessToken: _drop, ...rest } = entry as QueueEntry & { accessToken?: unknown };
	await in_transaction<null>(env, 'readwrite', 'update', null, (store) => {
		store.put(rest, id);
	});
}

/**
 * Remove the plaintext `accessToken` field a pre-1.0.22 row may still carry
 * (Task H5), whoever owns the row and even when it is locked. This is the
 * only write flush() makes to a row it doesn't replay, and it changes
 * nothing else on the row. Rows without the field aren't written.
 */
async function scrub_legacy_tokens(env: OfflineQueueEnvironment): Promise<void> {
	await in_transaction<null>(env, 'readwrite', 'scrub', null, (store) => {
		const cursor_request = store.openCursor();
		cursor_request.onsuccess = (): void => {
			const cursor = cursor_request.result;
			if (!cursor) return;
			const value = cursor.value as Record<string, unknown>;
			if (Object.prototype.hasOwnProperty.call(value, 'accessToken')) {
				const { accessToken: _drop, ...rest } = value;
				cursor.update(rest);
			}
			cursor.continue();
		};
	});
}

/**
 * Take the replay lease on one entry. Resolves null when the entry is gone
 * (another tab already posted it) or another tab holds an unexpired lease.
 * The read and the write share one readwrite transaction, which is what
 * makes the claim exclusive across tabs.
 *
 * Strips a legacy `accessToken` field a pre-1.0.22 row may still carry
 * (see the file docblock) so the claim write doesn't re-persist it.
 */
async function claim(
	id: number,
	now: number,
	account: string,
	env: OfflineQueueEnvironment,
): Promise<QueueEntry | null> {
	return in_transaction<QueueEntry | null>(env, 'readwrite', 'claim', null, (store, set) => {
		const request = store.get(id);
		request.onsuccess = (): void => {
			const raw = request.result as (Omit<QueueEntry, 'id'> & { accessToken?: unknown }) | undefined;
			// Ownership is checked again inside the claiming transaction, so
			// an entry is never written unless it is this account's.
			if (!raw || !owned_by(raw, account) || (raw.claimedUntil ?? 0) > now) return;
			const { accessToken: _drop, ...value } = raw;
			const claimed = { ...value, claimedUntil: now + CLAIM_LEASE_MS };
			store.put(claimed, id);
			set({ id, ...claimed });
		};
	});
}

export interface FlushOptions {
	/** Clock for claim leases. Tests pass a fixed one. */
	now?: () => number;
	/** Token-store environment for replay's `read_token()` call. Tests pass a fixed one. */
	tokenStore?: TokenStoreEnvironment;
}

/**
 * Replay every queued entry of the signed-in account that this tab can
 * claim, removing each on success and recording attempts/lastError on
 * failure. Returns that account's entries still queued after the pass —
 * empty array means its queue drained. Entries another tab is replaying are
 * skipped and appear in the result until that tab removes them.
 *
 * Reads the token once. With no token, or a token whose `me` isn't a
 * canonical identity, nothing is replayed and nothing is written. Entries
 * owned by anyone else, and locked entries, are never claimed or touched.
 *
 * Replays in oldest-first order so the user's posts land in the order
 * they were attempted.
 */
export async function flush(
	micropubEnv?: MicropubEnvironment,
	env: OfflineQueueEnvironment = default_env,
	options: FlushOptions = {},
): Promise<QueueEntry[]> {
	const now = options.now ?? Date.now;
	await scrub_legacy_tokens(env);
	const token = await read_token(options.tokenStore);
	const account = token ? canonical_account(token.me) : null;
	if (!token || account === null) return [];
	for (const listed of await list(env)) {
		if (!owned_by(listed, account)) continue;
		const entry = await claim(listed.id, now(), account, env);
		if (!entry) continue;
		try {
			await replay(entry, token, micropubEnv, env, now);
		} catch (err) {
			await save(
				{
					...entry,
					attempts: entry.attempts + 1,
					lastError: describe_error(err),
					retryable: is_retryable_error(err),
					claimedUntil: 0,
				},
				env,
			);
			notify_queue_changed();
			continue;
		}
		await remove(entry.id, env);
	}
	return (await list_for_owner(account, env)).own;
}

/**
 * Send one claimed entry: resolve the endpoint, upload any photos still
 * waiting, then post. Mutates `entry` as it goes (endpoint, photo URLs) and
 * saves it after each step, so a failure part-way keeps what succeeded.
 */
async function replay(
	entry: QueueEntry,
	token: StoredToken,
	micropubEnv: MicropubEnvironment | undefined,
	env: OfflineQueueEnvironment,
	now: () => number,
): Promise<void> {
	const renew = (): Promise<void> =>
		save({ ...entry, claimedUntil: now() + CLAIM_LEASE_MS }, env);

	// flush() matched this entry's owner to the token before claiming it.
	if (!entry.me) {
		throw new OfflineQueueError(
			'queue replay: this post has no site recorded to send it to; re-create this post',
			'wrong_account',
		);
	}
	const accessToken = token.accessToken;

	let endpoint = entry.micropubEndpoint;
	if (!endpoint) {
		endpoint =
			recall_endpoints(entry.me).micropub ??
			(await discover_micropub_endpoint(entry.me, micropubEnv));
		remember_endpoints(entry.me, { micropub: endpoint });
		entry.micropubEndpoint = endpoint;
		await renew();
	}

	let properties = entry.properties;
	if (entry.media && entry.media.length > 0) {
		const urls: string[] = [];
		for (const item of entry.media) {
			if (!item.url) {
				if (!item.bytes) {
					throw new MicropubError(
						'queue replay: a queued photo has neither image bytes nor an uploaded URL',
						'post_failed',
					);
				}
				let media_endpoint =
					entry.mediaEndpoint ?? (entry.me ? recall_endpoints(entry.me).media : null);
				if (!media_endpoint) {
					media_endpoint = await discover_media_endpoint(
						endpoint,
						accessToken,
						micropubEnv,
					);
					if (entry.me) remember_endpoints(entry.me, { media: media_endpoint });
				}
				entry.mediaEndpoint = media_endpoint;
				const upload = await upload_media(
					{
						blob: new Blob([item.bytes], { type: item.type }),
						filename: item.filename,
						accessToken,
						mediaEndpoint: media_endpoint,
					},
					micropubEnv,
				);
				item.url = upload.location;
				delete item.bytes;
				await renew();
			}
			urls.push(item.url);
		}
		properties = { ...properties, photo: urls.length === 1 ? urls[0]! : urls };
	}

	try {
		await post_h_entry(
			{ properties, accessToken, micropubEndpoint: endpoint },
			micropubEnv,
		);
	} catch (err) {
		// A 2xx whose Location failed the scheme check still created the post;
		// keeping the entry would publish it a second time on the next flush.
		if (err instanceof MicropubError && err.code === 'invalid_location') return;
		throw err;
	}
}

function describe_error(err: unknown): string {
	return err instanceof MicropubError
		? err.code + ': ' + err.message
		: err instanceof Error
			? err.message
			: 'Unknown error';
}

function open_db(env: OfflineQueueEnvironment): Promise<IDBDatabase> {
	return new Promise((resolve, reject) => {
		const request = env.indexedDB.open(DB_NAME, DB_VERSION);
		request.onupgradeneeded = (): void => {
			const db = request.result;
			if (!db.objectStoreNames.contains(STORE_QUEUE)) {
				db.createObjectStore(STORE_QUEUE, { autoIncrement: true });
			}
		};
		request.onsuccess = (): void => resolve(request.result);
		request.onerror = (): void =>
			reject(
				new OfflineQueueError(
					'open_db: ' + (request.error?.message ?? 'unknown'),
					'open_failed',
				),
			);
	});
}

/**
 * Heuristic: was this error caused by the network being down (and so
 * the post is a candidate for queuing), or by something the server
 * actively rejected (and so retry would just re-fail)?
 *
 * Network-down signals: MicropubError with code `discovery_failed` or
 * `post_failed` AND the message contains "fetch threw" (the lib's
 * canonical signal for fetch() rejecting). Other failures (auth,
 * validation, server errors) we surface to the user as-is — re-trying
 * them later wouldn't help.
 */
export function is_network_error(err: unknown): boolean {
	if (err instanceof MicropubError) {
		if (err.code !== 'post_failed' && err.code !== 'discovery_failed') {
			return false;
		}
		return err.message.includes('fetch threw');
	}
	if (err instanceof TypeError) {
		// Browsers throw TypeError when fetch fails for network reasons.
		return /fetch|network/i.test(err.message);
	}
	return false;
}

/**
 * Should a queued entry that failed this way be retried automatically?
 * Yes for a network failure, a 5xx, 408 and 429, and a local storage
 * error; no for any other answer from the server (a 400 or 401 fails the
 * same way every time).
 */
export function is_retryable_error(err: unknown): boolean {
	if (is_network_error(err)) return true;
	if (err instanceof MicropubError) {
		return (
			err.status !== undefined &&
			(err.status >= 500 || err.status === 408 || err.status === 429)
		);
	}
	if (err instanceof OfflineQueueError) {
		// `no_token` waits for a sign-in, and `wrong_account` for a sign-in
		// to the entry's own site (an entry with no site never sends) —
		// neither is fixed by another automatic attempt.
		return err.code !== 'no_token' && err.code !== 'wrong_account';
	}
	return false;
}
