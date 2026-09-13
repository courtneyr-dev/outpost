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
 * needs: the h-entry properties, the access token, and the Micropub
 * endpoint, or the signed-in "me" URL to discover it from when the post was
 * queued on a device that never discovered one. Fields added in 1.0.16 are
 * optional, so entries an older build queued still replay.
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
 * Why per-token storage isn't required: the token in storage is the same
 * one used at enqueue time. If the user signs out, `clear_token()` does
 * not clear the queue — but the next flush will fail with a 401 from the
 * server, marked in `lastError`, and the user can dismiss those entries
 * from the UI.
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
	accessToken: string;
	/** Null when the post was queued before any endpoint was discovered. */
	micropubEndpoint: string | null;
	/** Signed-in "me" URL the replay discovers endpoints from. Absent on 1.0.15 entries. */
	me?: string;
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

export type QueueEnqueueInput = Pick<
	QueueEntry,
	'source' | 'properties' | 'accessToken' | 'micropubEndpoint'
> &
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
		public readonly code: 'open_failed' | 'tx_failed',
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
		accessToken: input.accessToken,
		micropubEndpoint: input.micropubEndpoint,
		...(input.me !== undefined ? { me: input.me } : {}),
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

/** Write an entry back in place (retry state, uploaded photo URLs, lease). */
async function save(entry: QueueEntry, env: OfflineQueueEnvironment): Promise<void> {
	const { id, ...rest } = entry;
	await in_transaction<null>(env, 'readwrite', 'update', null, (store) => {
		store.put(rest, id);
	});
}

/**
 * Take the replay lease on one entry. Resolves null when the entry is gone
 * (another tab already posted it) or another tab holds an unexpired lease.
 * The read and the write share one readwrite transaction, which is what
 * makes the claim exclusive across tabs.
 */
async function claim(
	id: number,
	now: number,
	env: OfflineQueueEnvironment,
): Promise<QueueEntry | null> {
	return in_transaction<QueueEntry | null>(env, 'readwrite', 'claim', null, (store, set) => {
		const request = store.get(id);
		request.onsuccess = (): void => {
			const value = request.result as Omit<QueueEntry, 'id'> | undefined;
			if (!value || (value.claimedUntil ?? 0) > now) return;
			const claimed = { ...value, claimedUntil: now + CLAIM_LEASE_MS };
			store.put(claimed, id);
			set({ id, ...claimed });
		};
	});
}

export interface FlushOptions {
	/** Clock for claim leases. Tests pass a fixed one. */
	now?: () => number;
}

/**
 * Replay every queued entry this tab can claim, removing each on success
 * and recording attempts/lastError on failure. Returns the entries still
 * queued after the pass — empty array means the queue drained. Entries
 * another tab is replaying are skipped and appear in the result until that
 * tab removes them.
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
	for (const listed of await list(env)) {
		const entry = await claim(listed.id, now(), env);
		if (!entry) continue;
		try {
			await replay(entry, micropubEnv, env, now);
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
	return list(env);
}

/**
 * Send one claimed entry: resolve the endpoint, upload any photos still
 * waiting, then post. Mutates `entry` as it goes (endpoint, photo URLs) and
 * saves it after each step, so a failure part-way keeps what succeeded.
 */
async function replay(
	entry: QueueEntry,
	micropubEnv: MicropubEnvironment | undefined,
	env: OfflineQueueEnvironment,
	now: () => number,
): Promise<void> {
	const renew = (): Promise<void> =>
		save({ ...entry, claimedUntil: now() + CLAIM_LEASE_MS }, env);

	let endpoint = entry.micropubEndpoint;
	if (!endpoint) {
		if (!entry.me) {
			throw new MicropubError(
				'queue replay: the entry has neither a Micropub endpoint nor a me URL',
				'no_endpoint',
			);
		}
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
						entry.accessToken,
						micropubEnv,
					);
					if (entry.me) remember_endpoints(entry.me, { media: media_endpoint });
				}
				entry.mediaEndpoint = media_endpoint;
				const upload = await upload_media(
					{
						blob: new Blob([item.bytes], { type: item.type }),
						filename: item.filename,
						accessToken: entry.accessToken,
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
			{ properties, accessToken: entry.accessToken, micropubEndpoint: endpoint },
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
	return err instanceof OfflineQueueError;
}
