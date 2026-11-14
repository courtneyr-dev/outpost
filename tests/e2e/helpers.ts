import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
import type { Page } from '@playwright/test';

interface Session {
	token: string;
	me: string;
}

export function session(): Session {
	return JSON.parse(readFileSync(resolve(HERE, '.auth/session.json'), 'utf8')) as Session;
}

/**
 * Sign the composer in by storing the token the way pwa/src/lib/token-store.ts
 * does (AES-GCM with a non-extractable key, both in IndexedDB `outpost`), then
 * wait for the service worker to control /post/.
 */
export async function sign_in(page: Page): Promise<void> {
	const { token, me } = session();
	await page.goto('/post/');
	await page.evaluate(
		async ({ token, me }) => {
			const db = await new Promise<IDBDatabase>((res, rej) => {
				const r = indexedDB.open('outpost', 1);
				r.onupgradeneeded = (): void => {
					if (!r.result.objectStoreNames.contains('crypto-keys')) r.result.createObjectStore('crypto-keys');
					if (!r.result.objectStoreNames.contains('tokens')) r.result.createObjectStore('tokens');
				};
				r.onsuccess = (): void => res(r.result);
				r.onerror = (): void => rej(r.error);
			});
			const put = (store: string, key: string, value: unknown): Promise<void> =>
				new Promise((res, rej) => {
					const tx = db.transaction(store, 'readwrite');
					tx.objectStore(store).put(value, key);
					tx.oncomplete = (): void => res();
					tx.onerror = (): void => rej(tx.error);
				});
			const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
			await put('crypto-keys', 'token-encryption-key', key);
			const iv = crypto.getRandomValues(new Uint8Array(12));
			const ciphertext = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(token)));
			await put('tokens', 'micropub', { iv, ciphertext, meta: { tokenType: 'Bearer', scope: 'create update media', me, storedAt: Date.now() } });
			db.close();
			await navigator.serviceWorker.register('/post/sw', { scope: '/post/' });
			await navigator.serviceWorker.ready;
		},
		{ token, me },
	);
	await page.reload();
	await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
}

/** Submit a multipart share to the share target, as the system share sheet does. */
export async function share_photo(page: Page, name: string, title: string): Promise<void> {
	// The submit navigates (POST, then the worker's 303 to the composer); wait
	// for that navigation, not the URL the page already has.
	const navigated = page.waitForNavigation({ waitUntil: 'load' });
	await page.evaluate(
		({ name, title }) => {
			const form = document.createElement('form');
			form.method = 'POST';
			form.action = '/post/share-target/';
			form.enctype = 'multipart/form-data';
			const t = document.createElement('input');
			t.name = 'title';
			t.value = title;
			const file = document.createElement('input');
			file.type = 'file';
			file.name = 'photos';
			const dt = new DataTransfer();
			// A valid 1x1 PNG.
			const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), (c) => c.charCodeAt(0));
			dt.items.add(new File([png], name, { type: 'image/png' }));
			file.files = dt.files;
			form.append(t, file);
			document.body.append(form);
			form.submit();
		},
		{ name, title },
	);
	await navigated;
}
