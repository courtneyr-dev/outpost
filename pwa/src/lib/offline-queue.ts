/**
 * Offline post queue.
 *
 * Phase D1. When a post submission fails because the network is down (or
 * the Micropub endpoint is unreachable), we don't want to lose the user's
 * content. The queue captures the in-flight post arguments to IndexedDB,
 * surfaces them in the UI with retry/dismiss controls, and auto-replays
 * when the browser reports it's back online.
 *
 * IDB schema: a single auto-keyed object store `outpost_queue` of
 * `QueueEntry` records. Each entry carries everything `post_h_entry`
 * needs to retry: the resolved Micropub endpoint URL, the access token,
 * and the h-entry properties. On retry success, the entry is removed.
 * On failure, `attempts` increments and `lastError` updates so the UI
 * can show why retries are stalling.
 *
 * Why per-token storage isn't required: the token in storage is the same
 * one used at enqueue time. If the user signs out, `clear_token()` does
 * not clear the queue — but the next flush will fail with a 401 from the
 * server, marked in `lastError`, and the user can dismiss those entries
 * from the UI.
 *
 * Photo posts: by the time a photo enqueues, the media has already been
 * uploaded successfully (the media endpoint POST happens before the
 * post_h_entry POST). The queue stores only the h-entry call with the
 * media URL embedded as the `photo` property — replays do not re-upload.
 */

import { post_h_entry, MicropubError, type HEntryProperties, type MicropubEnvironment } from './micropub';

const DB_NAME = 'outpost-queue';
const DB_VERSION = 1;
const STORE_QUEUE = 'queue';

export interface QueueEntry {
	/** Auto-assigned IDB key. */
	id: number;
	/** Where this entry came from — purely for the queue UI's labeling. */
	source: 'note' | 'reply' | 'photo' | 'listen' | 'article';
	properties: HEntryProperties;
	accessToken: string;
	micropubEndpoint: string;
	/** Wall-clock ms since epoch at enqueue. Cosmetic. */
	createdAt: number;
	/** Number of replay attempts so far (0 means never tried since enqueue). */
	attempts: number;
	/** Last error code/message from the server, when retries are stalling. */
	lastError?: string;
}

export type QueueEnqueueInput = Omit<QueueEntry, 'id' | 'createdAt' | 'attempts'>;

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
	const db = await open_db(env);
	try {
		return await new Promise<number>((resolve, reject) => {
			const tx = db.transaction(STORE_QUEUE, 'readwrite');
			const store = tx.objectStore(STORE_QUEUE);
			const value: Omit<QueueEntry, 'id'> = {
				source: input.source,
				properties: input.properties,
				accessToken: input.accessToken,
				micropubEndpoint: input.micropubEndpoint,
				createdAt: Date.now(),
				attempts: 0,
			};
			const request = store.add(value);
			request.onsuccess = (): void => resolve(request.result as number);
			request.onerror = (): void =>
				reject(
					new OfflineQueueError(
						'enqueue: tx failed — ' +
							(request.error?.message ?? 'unknown'),
						'tx_failed',
					),
				);
		});
	} finally {
		db.close();
	}
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
	const db = await open_db(env);
	try {
		return await new Promise<QueueEntry[]>((resolve, reject) => {
			const tx = db.transaction(STORE_QUEUE, 'readonly');
			const store = tx.objectStore(STORE_QUEUE);
			const out: QueueEntry[] = [];
			const cursor_request = store.openCursor();
			cursor_request.onsuccess = (): void => {
				const cursor = cursor_request.result;
				if (cursor) {
					const value = cursor.value as Omit<QueueEntry, 'id'>;
					out.push({ id: cursor.key as number, ...value });
					cursor.continue();
				} else {
					resolve(out);
				}
			};
			cursor_request.onerror = (): void =>
				reject(
					new OfflineQueueError(
						'list: cursor failed — ' +
							(cursor_request.error?.message ?? 'unknown'),
						'tx_failed',
					),
				);
		});
	} finally {
		db.close();
	}
}

/**
 * Remove a specific entry. Used by the UI's "dismiss" action and by
 * `flush()` after a successful replay.
 */
export async function remove(
	id: number,
	env: OfflineQueueEnvironment = default_env,
): Promise<void> {
	const db = await open_db(env);
	try {
		await new Promise<void>((resolve, reject) => {
			const tx = db.transaction(STORE_QUEUE, 'readwrite');
			const store = tx.objectStore(STORE_QUEUE);
			const request = store.delete(id);
			request.onsuccess = (): void => resolve();
			request.onerror = (): void =>
				reject(
					new OfflineQueueError(
						'remove: tx failed — ' +
							(request.error?.message ?? 'unknown'),
						'tx_failed',
					),
				);
		});
	} finally {
		db.close();
	}
}

/**
 * Replay every queued entry, removing each on success and updating
 * attempts/lastError on failure. Returns the entries that remained
 * after the pass — empty array means the queue drained cleanly.
 *
 * Replays in oldest-first order so the user's posts land in the order
 * they were attempted.
 */
export async function flush(
	micropubEnv?: MicropubEnvironment,
	env: OfflineQueueEnvironment = default_env,
): Promise<QueueEntry[]> {
	const entries = await list(env);
	const remaining: QueueEntry[] = [];
	for (const entry of entries) {
		try {
			await post_h_entry(
				{
					properties: entry.properties,
					accessToken: entry.accessToken,
					micropubEndpoint: entry.micropubEndpoint,
				},
				micropubEnv,
			);
			await remove(entry.id, env);
		} catch (err) {
			const message =
				err instanceof MicropubError
					? err.code + ': ' + err.message
					: err instanceof Error
						? err.message
						: 'Unknown error';
			const next: QueueEntry = {
				...entry,
				attempts: entry.attempts + 1,
				lastError: message,
			};
			await update(next, env);
			remaining.push(next);
		}
	}
	return remaining;
}

/**
 * Update an existing entry in place — used by `flush()` to record retry
 * state. Not exported as a public mutation; internal to the flush loop.
 */
async function update(
	entry: QueueEntry,
	env: OfflineQueueEnvironment,
): Promise<void> {
	const db = await open_db(env);
	try {
		await new Promise<void>((resolve, reject) => {
			const tx = db.transaction(STORE_QUEUE, 'readwrite');
			const store = tx.objectStore(STORE_QUEUE);
			const { id, ...rest } = entry;
			const request = store.put(rest, id);
			request.onsuccess = (): void => resolve();
			request.onerror = (): void =>
				reject(
					new OfflineQueueError(
						'update: tx failed — ' +
							(request.error?.message ?? 'unknown'),
						'tx_failed',
					),
				);
		});
	} finally {
		db.close();
	}
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
