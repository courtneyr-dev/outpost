/**
 * Service-worker share intake (T10-S3-05).
 *
 * Runs the service worker that ships, not a copy: the script is lifted out of
 * Outpost_PWA_Shell::render_service_worker() in includes/class-pwa-shell.php,
 * its three PHP echoes are replaced with test values, and its `fetch` listener
 * is driven with real Request/FormData/File objects against fake IndexedDB.
 *
 * Contract under test (S3-05 v1.1): a share POST to the worker's own
 * /post/share-target parks images only when they are a type the composer can
 * upload and within the per-file, count, and total limits (checked before the
 * bytes are read). The inbox holds the waiting share and at most one newer
 * candidate; neither is overwritten and a third share is refused. The
 * initiating app may be external; anything else passes through untouched.
 */
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { SUPPORTED_IMAGE_TYPES, MAX_PHOTO_BYTES } from './photo';

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
let policy: { types: string[]; perFile: number; files: number; total: number };

function load_worker(factory: IDBFactory | { open: () => unknown } = globalThis.indexedDB): void {
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
	const run = new Function(
		'self',
		'fetch',
		'caches',
		'indexedDB',
		'Response',
		'Request',
		'URL',
		sw_source() +
			'\nreturn { types: SHARE_IMAGE_TYPES, perFile: SHARE_MAX_FILE_BYTES, files: SHARE_MAX_FILES, total: SHARE_MAX_TOTAL_BYTES };',
	);
	// A worker resolves a relative Response.redirect() against its own URL; Node needs it absolute.
	class WorkerResponse extends Response {
		static override redirect(url: string | URL, status?: number): Response {
			return Response.redirect(new URL(String(url), ORIGIN).href, status);
		}
	}
	policy = run(self_stub, fetch_stub, caches_stub, factory, WorkerResponse, Request, URL) as typeof policy;
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

async function inbox(slot = 'pending'): Promise<{ title: string; files: { name: string }[] } | undefined> {
	const db = await new Promise<IDBDatabase>((res, rej) => {
		const r = indexedDB.open('outpost-share-inbox', 1);
		r.onupgradeneeded = (): void => {
			if (!r.result.objectStoreNames.contains('inbox')) r.result.createObjectStore('inbox');
		};
		r.onsuccess = (): void => res(r.result);
		r.onerror = (): void => rej(r.error);
	});
	const value = await new Promise<unknown>((res) => {
		const q = db.transaction('inbox').objectStore('inbox').get(slot);
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

	it('keeps a second share as the candidate and refuses a third', async () => {
		await dispatch(share([image('first.png')], { title: 'first' }));
		const second = await dispatch(share([image('second.png')], { title: 'second' }));
		const third = await dispatch(share([image('third.png')], { title: 'third' }));

		expect(second?.headers.get('Location')).toBe(ORIGIN + '/post/?mode=photo&shared=photos');
		expect(third?.headers.get('Location')).toBe(ORIGIN + '/post/?mode=photo&shared=photos&refused=1');
		expect((await inbox('pending'))?.title).toBe('first');
		expect((await inbox('candidate'))?.title).toBe('second');
	});

	it('parks nothing and promises no photos when IndexedDB fails', async () => {
		load_worker({
			open: () => {
				const req: { onerror?: () => void; error: Error } = { error: new Error('blocked') };
				setTimeout(() => req.onerror?.(), 0);
				return req;
			},
		});
		const response = await dispatch(share([image('a.png')]));

		expect(response?.status).toBe(303);
		expect(response?.headers.get('Location')).toBe(ORIGIN + '/post/?mode=photo');
	});

	it('passes unsupported types through without parking', async () => {
		for (const type of ['text/plain', 'image/svg+xml', 'application/octet-stream', 'image/heic', 'image/heif']) {
			const response = await dispatch(share([image('x', 16, type)]));
			expect(response?.status).toBe(200);
		}
		expect(await inbox()).toBeUndefined();
		expect(passthrough.length).toBe(5);
	});

	it('drops a file over the per-file limit before reading it', async () => {
		const response = await dispatch(share([huge_image('big.png', MAX_PHOTO_BYTES + 1), image('ok.png')]));

		expect(response?.status).toBe(303);
		expect((await inbox())?.files.map((f) => f.name)).toEqual(['ok.png']);
	});

	it('parks nothing when only over-limit files were shared', async () => {
		await dispatch(share([huge_image('big.png', MAX_PHOTO_BYTES + 1)]));

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

	it('accepts a file of exactly the per-file limit and drops one byte over', async () => {
		// The per-file limit is the composer's own cap (50 MB since #322), which
		// equals the total limit, so the two sizes are shared separately.
		await dispatch(share([image('at-limit.png', MAX_PHOTO_BYTES)]));
		expect((await inbox())?.files.map((f) => f.name)).toEqual(['at-limit.png']);

		globalThis.indexedDB = new IDBFactory();
		load_worker();
		passthrough = [];
		await dispatch(share([image('over.png', MAX_PHOTO_BYTES + 1)]));
		expect(await inbox()).toBeUndefined();
	});

	it('accepts exactly the file-count limit', async () => {
		await dispatch(share(Array.from({ length: 10 }, (_, i) => image(`p${String(i)}.png`))));

		expect((await inbox())?.files.length).toBe(10);
	});

	it('accepts exactly the total limit and refuses one byte over', async () => {
		await dispatch(share(Array.from({ length: 5 }, (_, i) => image(`t${String(i)}.png`, 10 * MB))));
		expect((await inbox())?.files.length).toBe(5);

		globalThis.indexedDB = new IDBFactory();
		load_worker();
		passthrough = [];
		await dispatch(share([...Array.from({ length: 5 }, (_, i) => image(`t${String(i)}.png`, 10 * MB)), image('extra.png', 1)]));
		expect(await inbox()).toBeUndefined();
		expect(passthrough.length).toBe(1);
	});
});

describe('share intake bounds before and after parsing (T10-S4-16)', () => {
	beforeEach(() => {
		globalThis.indexedDB = new IDBFactory();
		passthrough = [];
		load_worker();
	});

	it('passes a body that declares more than the intake limit through without parsing it', async () => {
		let parsed = false;
		const declared = String(policy.total + 1024 * 1024 + 1);
		const real = share([image('a.png')]);
		const oversized = {
			method: 'POST',
			url: real.url,
			headers: new Headers({ 'content-length': declared }),
			clone: (): Request => real.clone(),
			formData: async (): Promise<FormData> => {
				parsed = true;
				return real.formData();
			},
		} as unknown as Request;

		await dispatch(oversized);

		expect(parsed).toBe(false);
		expect(passthrough.length).toBe(1);
		expect(await inbox()).toBeUndefined();
	});

	it('stops reading a real multipart body past the intake limit and never parses it (T10-S4-19)', async () => {
		const request = share([huge_image('a.png', 9 * MB), huge_image('b.png', 9 * MB), huge_image('c.png', 9 * MB), huge_image('d.png', 9 * MB), huge_image('e.png', 9 * MB), huge_image('f.png', 9 * MB)]);
		expect(request.headers.get('content-length')).toBeNull();
		const request_parse = vi.spyOn(Request.prototype, 'formData');
		const response_parse = vi.spyOn(Response.prototype, 'formData');
		try {
			await dispatch(request);
			expect(request_parse).not.toHaveBeenCalled();
			expect(response_parse).not.toHaveBeenCalled();
		} finally {
			request_parse.mockRestore();
			response_parse.mockRestore();
		}
		expect(passthrough.length).toBe(1);
		expect(await inbox()).toBeUndefined();
	});

	it('passes an open oversized stream through without waiting on it (T10-S4-23)', async () => {
		const chunk = new Uint8Array(1024 * 1024);
		let sent = 0;
		const body = new ReadableStream<Uint8Array>({
			pull(controller): void {
				// Emits past the limit, then stays open: no close() is ever called.
				if (sent <= policy.total + 2 * MB) {
					sent += chunk.byteLength;
					controller.enqueue(chunk);
				}
			},
		});
		const request = new Request(ORIGIN + '/post/share-target', {
			method: 'POST',
			body,
			headers: { 'content-type': 'multipart/form-data; boundary=x' },
			duplex: 'half',
		} as RequestInit);

		const outcome = await Promise.race([
			dispatch(request).then(() => 'passed through'),
			new Promise<string>((resolve) => setTimeout(() => resolve('stalled'), 3000)),
		]);

		expect(outcome).toBe('passed through');
		expect(passthrough.length).toBe(1);
	});

	it('stores at most 4,000 characters of shared title and text', async () => {
		await dispatch(share([image('a.png')], { title: 'T'.repeat(5000), text: 'x'.repeat(9000) }));

		const parked = (await inbox()) as unknown as { title: string; text: string } | undefined;
		expect(parked?.title.length).toBe(4000);
		expect(parked?.text.length).toBe(4000);
	});
});

describe('share policy parity (T10-S3-05 v1.1)', () => {
	it('the worker accepts exactly the types and per-file size the upload processor accepts', () => {
		load_worker();
		expect([...policy.types].sort()).toEqual([...SUPPORTED_IMAGE_TYPES].sort());
		expect(policy.perFile).toBe(MAX_PHOTO_BYTES);
		expect(policy.files).toBe(10);
		expect(policy.total).toBe(50 * MB);
	});

	it('the Photo file picker offers the same types', () => {
		const tsx = readFileSync(resolve(__dirname, '../components/modes/photo-mode.tsx'), 'utf8');
		const accept = /accept="([^"]+)"/.exec(tsx)?.[1] ?? '';
		expect(accept.split(',').sort()).toEqual([...SUPPORTED_IMAGE_TYPES].sort());
	});
});
