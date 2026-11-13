/**
 * Service-worker share intake (T10-S3-05).
 *
 * Runs the service worker that ships, not a copy: the script is lifted out of
 * Outpost_PWA_Shell::render_service_worker() in includes/class-pwa-shell.php,
 * its three PHP echoes are replaced with test values, and its `fetch` listener
 * is driven with real Request/FormData/File objects against fake IndexedDB.
 *
 * Contract under test: a share POST to /post/share-target parks images only
 * when they are a supported raster type and within the per-file, count, and
 * total limits; it never replaces a share that is still waiting; anything
 * else passes through to the server untouched.
 */
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect, beforeEach } from 'vitest';

const ORIGIN = 'http://localhost';
const MB = 1024 * 1024;

type FetchListener = (event: {
	request: Request;
	respondWith: (r: Promise<Response> | Response) => void;
}) => void;

function sw_source(): string {
	const php = readFileSync(resolve(__dirname, '../../../includes/class-pwa-shell.php'), 'utf8');
	const start = php.indexOf('public static function render_service_worker(): void {');
	const open = php.indexOf('?>', start) + 2;
	// The template ends at the first `<?php` that opens a line; the inline
	// `<?php echo … ?>` values inside it are replaced below.
	const close = php.indexOf('\n\t\t<?php', open);
	return php
		.slice(open, close)
		.replace(/<\?php echo esc_js\( \$version \); \?>/g, 'test')
		.replace(/<\?php echo esc_js\( \$plugin_url \); \?>/g, ORIGIN + '/wp-content/plugins/outpost/');
}

let passthrough: Request[];
let fetch_listener: FetchListener;

function load_worker(): void {
	const listeners: Record<string, unknown> = {};
	const self_stub = {
		location: new URL(ORIGIN + '/post/sw'),
		addEventListener: (type: string, fn: unknown): void => {
			listeners[type] = fn;
		},
		skipWaiting: (): Promise<void> => Promise.resolve(),
		clients: { claim: (): Promise<void> => Promise.resolve() },
	};
	const fetch_stub = async (input: Request): Promise<Response> => {
		passthrough.push(input);
		return new Response('server', { status: 200 });
	};
	const caches_stub = {
		open: async (): Promise<unknown> => ({ addAll: async (): Promise<void> => undefined, put: async (): Promise<void> => undefined, match: async (): Promise<undefined> => undefined }),
		keys: async (): Promise<string[]> => [],
		delete: async (): Promise<boolean> => true,
		match: async (): Promise<undefined> => undefined,
	};
	const run = new Function('self', 'fetch', 'caches', 'indexedDB', 'Response', 'Request', 'URL', sw_source());
	// A worker resolves a relative Response.redirect() against its own URL; Node needs it absolute.
	class WorkerResponse extends Response {
		static override redirect(url: string | URL, status?: number): Response {
			return Response.redirect(new URL(String(url), ORIGIN).href, status);
		}
	}
	run(self_stub, fetch_stub, caches_stub, globalThis.indexedDB, WorkerResponse, Request, URL);
	fetch_listener = listeners.fetch as FetchListener;
}

async function dispatch(request: Request): Promise<Response | null> {
	let responded: Promise<Response> | Response | null = null;
	fetch_listener({ request, respondWith: (r) => (responded = r) });
	return responded === null ? null : await responded;
}

function share(files: File[], fields: Record<string, string> = {}, path = '/post/share-target'): Request {
	const form = new FormData();
	for (const [k, v] of Object.entries(fields)) form.append(k, v);
	for (const f of files) form.append('photos', f);
	return new Request(ORIGIN + path, { method: 'POST', body: form });
}

function image(name: string, bytes = 16, type = 'image/png'): File {
	return new File([new Uint8Array(bytes)], name, { type });
}

/** A File of exactly `size` bytes (the multipart body carries real bytes). */
function huge_image(name: string, size: number): File {
	return image(name, size);
}

async function inbox(): Promise<{ title: string; files: { name: string }[] } | undefined> {
	const db = await new Promise<IDBDatabase>((res, rej) => {
		const r = indexedDB.open('outpost-share-inbox', 1);
		r.onupgradeneeded = (): void => {
			if (!r.result.objectStoreNames.contains('inbox')) r.result.createObjectStore('inbox');
		};
		r.onsuccess = (): void => res(r.result);
		r.onerror = (): void => rej(r.error);
	});
	const value = await new Promise<unknown>((res) => {
		const q = db.transaction('inbox').objectStore('inbox').get('pending');
		q.onsuccess = (): void => res(q.result);
	});
	db.close();
	return value as { title: string; files: { name: string }[] } | undefined;
}

describe('service worker share intake (T10-S3-05)', () => {
	beforeEach(() => {
		globalThis.indexedDB = new IDBFactory();
		passthrough = [];
		load_worker();
	});

	it('parks a supported image share in an empty inbox and opens Photo', async () => {
		const response = await dispatch(share([image('a.png')], { title: 'T', text: 'X' }));

		expect(response?.status).toBe(303);
		expect(response?.headers.get('Location')).toContain('/post/?mode=photo&shared=photos');
		expect((await inbox())?.files.map((f) => f.name)).toEqual(['a.png']);
		expect(passthrough.length).toBe(0);
	});

	it('never replaces a share that is still waiting', async () => {
		await dispatch(share([image('first.png')], { title: 'first' }));
		await dispatch(share([image('second.png')], { title: 'second' }));

		const kept = await inbox();
		expect(kept?.title).toBe('first');
		expect(kept?.files.map((f) => f.name)).toEqual(['first.png']);
	});

	it('passes unsupported types through without parking', async () => {
		for (const type of ['text/plain', 'image/svg+xml', 'application/octet-stream']) {
			const response = await dispatch(share([image('x', 16, type)]));
			expect(response?.status).toBe(200);
		}
		expect(await inbox()).toBeUndefined();
		expect(passthrough.length).toBe(3);
	});

	it('drops a file over the per-file limit before reading it', async () => {
		const response = await dispatch(share([huge_image('big.png', 11 * MB), image('ok.png')]));

		expect(response?.status).toBe(303);
		expect((await inbox())?.files.map((f) => f.name)).toEqual(['ok.png']);
	});

	it('parks nothing when only over-limit files were shared', async () => {
		await dispatch(share([huge_image('big.png', 11 * MB)]));

		expect(await inbox()).toBeUndefined();
		expect(passthrough.length).toBe(1);
	});

	it('parks nothing when the share is over the total size limit', async () => {
		const files = Array.from({ length: 6 }, (_, i) => huge_image(`p${i}.png`, 9 * MB));
		await dispatch(share(files));

		expect(await inbox()).toBeUndefined();
		expect(passthrough.length).toBe(1);
	});

	it('parks nothing when the share has too many files', async () => {
		const files = Array.from({ length: 11 }, (_, i) => image(`p${i}.png`));
		await dispatch(share(files));

		expect(await inbox()).toBeUndefined();
		expect(passthrough.length).toBe(1);
	});

	it('passes a malformed body through', async () => {
		const response = await dispatch(
			new Request(ORIGIN + '/post/share-target', {
				method: 'POST',
				body: 'not multipart',
				headers: { 'Content-Type': 'multipart/form-data; boundary=x' },
			}),
		);

		expect(response?.status).toBe(200);
		expect(await inbox()).toBeUndefined();
	});

	it('ignores share POSTs to any other path or origin', async () => {
		expect(await dispatch(share([image('a.png')], {}, '/post/share-targetx'))).toBeNull();
		expect(
			await dispatch(new Request('http://elsewhere.test/post/share-target', { method: 'POST', body: new FormData() })),
		).toBeNull();
		expect(await inbox()).toBeUndefined();
	});
});
