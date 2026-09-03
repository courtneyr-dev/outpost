import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { describe, it, expect } from 'vitest';
import {
	consume_shared_photos,
	stash_shared_photos,
	SHARE_INBOX_DB,
	SHARE_INBOX_KEY,
	SHARE_INBOX_STORE,
} from './share-inbox';

const png = (name = 'IMG_0001.png'): File =>
	new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], name, {
		type: 'image/png',
		lastModified: 1_700_000_000_000,
	});

/** Write a record straight into the store, the way the service worker does. */
async function write_raw(factory: IDBFactory, value: unknown): Promise<void> {
	await new Promise<void>((resolve, reject) => {
		const req = factory.open(SHARE_INBOX_DB, 1);
		req.onupgradeneeded = (): void => {
			req.result.createObjectStore(SHARE_INBOX_STORE);
		};
		req.onsuccess = (): void => {
			const db = req.result;
			const tx = db.transaction(SHARE_INBOX_STORE, 'readwrite');
			tx.objectStore(SHARE_INBOX_STORE).put(value, SHARE_INBOX_KEY);
			tx.oncomplete = (): void => {
				db.close();
				resolve();
			};
			tx.onerror = (): void => reject(tx.error);
		};
		req.onerror = (): void => reject(req.error);
	});
}

describe('share inbox', () => {
	it('round-trips parked photos and clears them on read', async () => {
		const env = { indexedDB: new IDBFactory() };
		await stash_shared_photos(
			{
				files: [png(), png('IMG_0002.png')],
				title: 'Ridge',
				text: 'Sunset',
				received: 42,
			},
			env
		);

		const first = await consume_shared_photos(env);
		expect(first).not.toBeNull();
		expect(first?.files.map((f) => f.name)).toEqual([
			'IMG_0001.png',
			'IMG_0002.png',
		]);
		expect(first?.files[0]?.type).toBe('image/png');
		expect(first?.files[0]?.size).toBe(8);
		expect(first?.files[0]?.lastModified).toBe(1_700_000_000_000);
		expect(first?.title).toBe('Ridge');
		expect(first?.text).toBe('Sunset');
		expect(first?.received).toBe(42);

		// One-shot: the second read finds nothing.
		expect(await consume_shared_photos(env)).toBeNull();
	});

	it('resolves null on an empty inbox', async () => {
		expect(
			await consume_shared_photos({ indexedDB: new IDBFactory() })
		).toBeNull();
	});

	it('resolves null without IndexedDB', async () => {
		expect(
			await consume_shared_photos({ indexedDB: undefined })
		).toBeNull();
	});

	it('rebuilds a File from the raw record the service worker writes', async () => {
		const factory = new IDBFactory();
		await write_raw(factory, {
			files: [
				{
					name: 'from-worker.jpg',
					type: 'image/jpeg',
					lastModified: 5,
					buffer: new Uint8Array([1, 2, 3]).buffer,
				},
			],
			title: '',
			text: 'caption',
			received: 7,
		});

		const inbox = await consume_shared_photos({ indexedDB: factory });
		expect(inbox?.files).toHaveLength(1);
		expect(inbox?.files[0]?.name).toBe('from-worker.jpg');
		expect(inbox?.files[0]?.type).toBe('image/jpeg');
		expect(inbox?.files[0]?.size).toBe(3);
		expect(inbox?.text).toBe('caption');
	});

	it('drops records that are not images and clears a record with none', async () => {
		const factory = new IDBFactory();
		await write_raw(factory, {
			files: [
				{
					name: 'notes.txt',
					type: 'text/plain',
					lastModified: 1,
					buffer: new Uint8Array([1]).buffer,
				},
				{
					name: 'empty.png',
					type: 'image/png',
					lastModified: 1,
					buffer: new ArrayBuffer(0),
				},
				{
					name: 'keep.png',
					type: 'image/png',
					lastModified: 1,
					buffer: new Uint8Array([9]).buffer,
				},
			],
			title: '',
			text: '',
			received: 1,
		});
		const inbox = await consume_shared_photos({ indexedDB: factory });
		expect(inbox?.files.map((f) => f.name)).toEqual(['keep.png']);

		await write_raw(factory, { files: 'nope' });
		expect(await consume_shared_photos({ indexedDB: factory })).toBeNull();
		// The bogus record was cleared, not left to come back on the next mount.
		expect(await consume_shared_photos({ indexedDB: factory })).toBeNull();
	});
});
