/**
 * Share inbox — photos handed over by the system share sheet.
 *
 * Web Share Target Level 2 delivers shared image files as a multipart POST
 * to /post/share-target. The service worker (emitted by
 * includes/class-pwa-shell.php) intercepts that POST, parks the files here
 * in IndexedDB, and redirects to /post/?mode=photo&shared=photos. The
 * composer (ComposerTabs) holds that share in ShareReview until the person
 * chooses "Use shared content" or "Discard shared content"; only after Use
 * does PhotoMode mount and drain the `pending` slot.
 *
 * IndexedDB rather than sessionStorage: the service worker has no window
 * (so no sessionStorage to share), and sessionStorage holds strings only.
 * Each photo is parked as a plain record — name, type, bytes — rather than
 * a File, because Blob storage in IndexedDB has a patchy history across
 * engines and bytes rebuild into a File for free. Two fixed slots: the
 * waiting share (`pending`) and at most one newer share (`candidate`) while
 * the person decides between them; the service worker never overwrites
 * either, refuses a third share, and parks only supported raster images
 * within its size and count limits. Nothing here is uploaded until the
 * person chooses Use and then posts.
 *
 * The database, store, and key names must match the service worker.
 */

export const SHARE_INBOX_DB = 'outpost-share-inbox';
export const SHARE_INBOX_STORE = 'inbox';
export const SHARE_INBOX_KEY = 'pending';
/** A second share that arrived while `pending` waited for a decision. */
export const SHARE_INBOX_CANDIDATE_KEY = 'candidate';
export type ShareInboxSlot = typeof SHARE_INBOX_KEY | typeof SHARE_INBOX_CANDIDATE_KEY;

/** One parked photo, as the service worker writes it. */
export interface SharedPhotoRecord {
	name: string;
	type: string;
	lastModified: number;
	buffer: ArrayBuffer;
}

/** The inbox record: the photos plus whatever text rode along with the share. */
export interface SharedPhotosRecord {
	files: SharedPhotoRecord[];
	title: string;
	text: string;
	received: number;
}

/** What the composer gets back: real File objects, ready for the photo list. */
export interface SharedPhotos {
	files: File[];
	title: string;
	text: string;
	received: number;
}

export interface ShareInboxEnvironment {
	indexedDB: IDBFactory | undefined;
}

const default_env = (): ShareInboxEnvironment => ({
	indexedDB: globalThis.indexedDB,
});

function open_inbox(factory: IDBFactory): Promise<IDBDatabase> {
	return new Promise((resolve, reject) => {
		const request = factory.open(SHARE_INBOX_DB, 1);
		request.onupgradeneeded = (): void => {
			const db = request.result;
			if (!db.objectStoreNames.contains(SHARE_INBOX_STORE)) {
				db.createObjectStore(SHARE_INBOX_STORE);
			}
		};
		request.onsuccess = (): void => resolve(request.result);
		request.onerror = (): void =>
			reject(request.error ?? new Error('share inbox: open failed'));
	});
}

function await_request<T>(request: IDBRequest<T>): Promise<T> {
	return new Promise((resolve, reject) => {
		request.onsuccess = (): void => resolve(request.result);
		request.onerror = (): void =>
			reject(request.error ?? new Error('share inbox: request failed'));
	});
}

function await_transaction(tx: IDBTransaction): Promise<void> {
	return new Promise((resolve, reject) => {
		tx.oncomplete = (): void => resolve();
		tx.onerror = (): void =>
			reject(tx.error ?? new Error('share inbox: transaction failed'));
		tx.onabort = (): void =>
			reject(tx.error ?? new Error('share inbox: transaction aborted'));
	});
}

function is_photo_record(value: unknown): value is SharedPhotoRecord {
	if (!value || typeof value !== 'object') return false;
	const v = value as Record<string, unknown>;
	return (
		typeof v.type === 'string' &&
		v.type.startsWith('image/') &&
		v.buffer instanceof ArrayBuffer &&
		v.buffer.byteLength > 0
	);
}

async function to_record(file: File): Promise<SharedPhotoRecord> {
	return {
		name: file.name || 'shared-photo',
		type: file.type,
		lastModified: file.lastModified,
		buffer: await file.arrayBuffer(),
	};
}

function to_file(record: SharedPhotoRecord): File {
	const name =
		typeof record.name === 'string' && record.name
			? record.name
			: 'shared-photo';
	const last_modified =
		typeof record.lastModified === 'number'
			? record.lastModified
			: Date.now();
	return new File([record.buffer], name, {
		type: record.type,
		lastModified: last_modified,
	});
}

/**
 * Park shared photos for the next composer mount. The service worker does
 * this in production; the composer-side copy keeps the record shape in one
 * place and lets the round trip be exercised in tests.
 */
export async function stash_shared_photos(
	payload: SharedPhotos,
	env: ShareInboxEnvironment = default_env(),
	slot: ShareInboxSlot = SHARE_INBOX_KEY
): Promise<void> {
	if (!env.indexedDB) return;
	const record: SharedPhotosRecord = {
		files: await Promise.all(payload.files.map(to_record)),
		title: payload.title,
		text: payload.text,
		received: payload.received,
	};
	const db = await open_inbox(env.indexedDB);
	try {
		const tx = db.transaction(SHARE_INBOX_STORE, 'readwrite');
		tx.objectStore(SHARE_INBOX_STORE).put(record, slot);
		await await_transaction(tx);
	} finally {
		db.close();
	}
}

/**
 * Read the parked photos and clear them (one-shot). Resolves null when the
 * inbox is empty, IndexedDB is unavailable, or nothing in the record is a
 * usable image — every one of those means "nothing to attach". A record
 * that fails the shape check is still cleared, so a stale or foreign write
 * can't keep coming back.
 */
export async function consume_shared_photos(
	env: ShareInboxEnvironment = default_env()
): Promise<SharedPhotos | null> {
	if (!env.indexedDB) return null;
	let db: IDBDatabase;
	try {
		db = await open_inbox(env.indexedDB);
	} catch (_err) {
		return null;
	}
	try {
		const tx = db.transaction(SHARE_INBOX_STORE, 'readwrite');
		const store = tx.objectStore(SHARE_INBOX_STORE);
		const record: unknown = await await_request(store.get(SHARE_INBOX_KEY));
		store.delete(SHARE_INBOX_KEY);
		await await_transaction(tx);
		if (!record || typeof record !== 'object') return null;
		const v = record as Record<string, unknown>;
		const photos = Array.isArray(v.files)
			? v.files.filter(is_photo_record)
			: [];
		if (photos.length === 0) return null;
		return {
			files: photos.map(to_file),
			title: typeof v.title === 'string' ? v.title : '',
			text: typeof v.text === 'string' ? v.text : '',
			received: typeof v.received === 'number' ? v.received : 0,
		};
	} catch (_err) {
		return null;
	} finally {
		db.close();
	}
}

/** Rebuild a stored record into composer-ready photos, or null when unusable. */
function from_record(record: unknown): SharedPhotos | null {
	if (!record || typeof record !== 'object') return null;
	const v = record as Record<string, unknown>;
	const photos = Array.isArray(v.files) ? v.files.filter(is_photo_record) : [];
	if (photos.length === 0) return null;
	return {
		files: photos.map(to_file),
		title: typeof v.title === 'string' ? v.title : '',
		text: typeof v.text === 'string' ? v.text : '',
		received: typeof v.received === 'number' ? v.received : 0,
	};
}

/**
 * What is waiting in the share inbox. Reads only; nothing is removed.
 *
 * `status` separates an empty inbox from one that couldn't be read: a
 * blocked or failing IndexedDB is `unavailable`, never "nothing waiting",
 * so the composer doesn't drop a share it simply couldn't see.
 */
export interface ShareInboxState {
	status: 'ok' | 'unavailable';
	pending: SharedPhotos | null;
	candidate: SharedPhotos | null;
}

/**
 * Read both inbox slots without consuming them. A share waits here until
 * the person chooses: opening Photo never removes it.
 */
export async function read_share_inbox(
	env: ShareInboxEnvironment = default_env()
): Promise<ShareInboxState> {
	const unavailable: ShareInboxState = { status: 'unavailable', pending: null, candidate: null };
	if (!env.indexedDB) return unavailable;
	let db: IDBDatabase;
	try {
		db = await open_inbox(env.indexedDB);
	} catch (_err) {
		return unavailable;
	}
	try {
		const store = db.transaction(SHARE_INBOX_STORE, 'readonly').objectStore(SHARE_INBOX_STORE);
		const pending: unknown = await await_request(store.get(SHARE_INBOX_KEY));
		const candidate: unknown = await await_request(store.get(SHARE_INBOX_CANDIDATE_KEY));
		return { status: 'ok', pending: from_record(pending), candidate: from_record(candidate) };
	} catch (_err) {
		return unavailable;
	} finally {
		db.close();
	}
}

/**
 * Apply the person's decision about waiting photo shares, in one
 * transaction.
 *
 * `keep` names the slot whose photos survive (they end up in `pending`,
 * where Photo mode drains them); null keeps none. Every other record is
 * removed. Resolves only after the transaction commits and rejects when
 * there is no IndexedDB, or it can't open, errors, or aborts, so the caller
 * can keep the review open and say the choice wasn't applied. A missing
 * backend is a failure, not a no-op: records it can't see may still exist.
 */
export async function keep_shared_photos(
	keep: ShareInboxSlot | null,
	env: ShareInboxEnvironment = default_env()
): Promise<void> {
	if (!env.indexedDB) throw new Error('share inbox: IndexedDB is unavailable');
	const db = await open_inbox(env.indexedDB);
	try {
		const tx = db.transaction(SHARE_INBOX_STORE, 'readwrite');
		const done = await_transaction(tx);
		// Observed below; this keeps an early abort from going unhandled.
		done.catch(() => undefined);
		const store = tx.objectStore(SHARE_INBOX_STORE);
		const kept: unknown = keep ? await await_request(store.get(keep)) : undefined;
		if (kept !== undefined) {
			store.put(kept, SHARE_INBOX_KEY);
		} else {
			store.delete(SHARE_INBOX_KEY);
		}
		store.delete(SHARE_INBOX_CANDIDATE_KEY);
		await done;
	} finally {
		db.close();
	}
}
