import { describe, it, expect, beforeEach } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { post_or_queue, type SubmitStage } from './post-or-queue';
import { list, type OfflineQueueEnvironment } from './offline-queue';
import { recall_endpoints, remember_endpoints } from './endpoint-cache';
import type { MicropubEnvironment } from './micropub';

const ME = 'https://example.test/';
const MP = 'https://example.test/wp-json/micropub/1.0/endpoint';
const MEDIA = 'https://example.test/wp-json/micropub/1.0/media';

type Handler = (url: string, init: RequestInit | undefined) => Response | Promise<Response>;

function site(handler: Handler, calls: string[] = []): MicropubEnvironment {
	return {
		fetch: (async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
			const url = String(input);
			calls.push((init?.method ?? 'GET') + ' ' + url);
			return handler(url, init);
		}) as typeof fetch,
	};
}

const offline: Handler = () => {
	throw new TypeError('Failed to fetch');
};

function discovery_page(): Response {
	return new Response(`<html><head><link rel="micropub" href="${MP}"></head></html>`, {
		status: 200,
		headers: { 'Content-Type': 'text/html' },
	});
}

function created(location: string): Response {
	return new Response('', { status: 201, headers: { Location: location } });
}

function jpeg(...bytes: number[]): Blob {
	return new Blob([new Uint8Array(bytes)], { type: 'image/jpeg' });
}

let queueEnv: OfflineQueueEnvironment;

beforeEach(() => {
	queueEnv = { indexedDB: new IDBFactory() };
	localStorage.clear();
});

describe('post_or_queue: online', () => {
	it('discovers the endpoint, posts, and remembers the endpoint for offline submits', async () => {
		const calls: string[] = [];
		const stages: SubmitStage['kind'][] = [];
		const result = await post_or_queue(
			{
				source: 'note',
				me: ME,
				accessToken: 'tk',
				properties: { content: 'hello' },
				onStage: (stage) => stages.push(stage.kind),
			},
			site((_url, init) => (init?.method === 'POST' ? created('https://example.test/a-note/') : discovery_page()), calls),
			queueEnv,
		);
		expect(result).toEqual({
			kind: 'posted',
			location: 'https://example.test/a-note/',
			micropubEndpoint: MP,
			mediaEndpoint: null,
		});
		expect(calls).toEqual(['GET ' + ME, 'POST ' + MP]);
		expect(stages).toEqual(['discovering', 'posting']);
		expect(recall_endpoints(ME).micropub).toBe(MP);
		expect(await list(queueEnv)).toHaveLength(0);
	});

	it('uploads photos and sends their URLs as photo', async () => {
		let upload = 0;
		let body = '';
		const result = await post_or_queue(
			{
				source: 'photo',
				me: ME,
				accessToken: 'tk',
				properties: { 'mp-photo-alt': ['a red door', 'a blue door'] },
				photos: [
					{ blob: jpeg(1), filename: 'photo-1.jpg' },
					{ blob: jpeg(2), filename: 'photo-2.jpg' },
				],
				micropubEndpoint: MP,
				mediaEndpoint: MEDIA,
			},
			site((url, init) => {
				if (url === MEDIA) {
					upload += 1;
					return created('https://example.test/uploads/photo-' + String(upload) + '.jpg');
				}
				body = String(init?.body);
				return created('https://example.test/a-photo/');
			}),
			queueEnv,
		);
		expect(result.kind).toBe('posted');
		const sent = new URLSearchParams(body);
		expect(sent.getAll('photo[]')).toEqual([
			'https://example.test/uploads/photo-1.jpg',
			'https://example.test/uploads/photo-2.jpg',
		]);
		expect(sent.getAll('mp-photo-alt[]')).toEqual(['a red door', 'a blue door']);
	});

	it('throws a server rejection for the mode to show, without queuing it', async () => {
		await expect(
			post_or_queue(
				{ source: 'note', me: ME, accessToken: 'tk', properties: { content: 'x' }, micropubEndpoint: MP },
				site(() => new Response('bad request', { status: 400 })),
				queueEnv,
			),
		).rejects.toMatchObject({ code: 'post_failed', status: 400 });
		expect(await list(queueEnv)).toHaveLength(0);
	});
});

describe('post_or_queue: offline', () => {
	it('queues a submit from a composer opened offline with the remembered endpoint', async () => {
		remember_endpoints(ME, { micropub: MP });
		const result = await post_or_queue(
			{ source: 'note', me: ME, accessToken: 'tk', properties: { content: 'offline note' } },
			site(offline),
			queueEnv,
		);
		expect(result).toMatchObject({ kind: 'queued', micropubEndpoint: MP });
		const entries = await list(queueEnv);
		expect(entries).toHaveLength(1);
		expect(entries[0]).toMatchObject({
			source: 'note',
			micropubEndpoint: MP,
			me: ME,
			attempts: 0,
			properties: { content: 'offline note' },
		});
	});

	it('queues without an endpoint on a device that never discovered one', async () => {
		const result = await post_or_queue(
			{
				source: 'reply',
				me: ME,
				accessToken: 'tk',
				properties: { 'in-reply-to': 'https://example.com/post', content: 'agreed' },
			},
			site(offline),
			queueEnv,
		);
		expect(result).toMatchObject({ kind: 'queued', micropubEndpoint: null });
		const [entry] = await list(queueEnv);
		expect(entry?.micropubEndpoint).toBeNull();
		expect(entry?.me).toBe(ME);
		expect(entry?.media).toBeUndefined();
	});

	it('queues processed photo bytes when the upload cannot reach the site', async () => {
		remember_endpoints(ME, { micropub: MP, media: MEDIA });
		const result = await post_or_queue(
			{
				source: 'photo',
				me: ME,
				accessToken: 'tk',
				properties: { 'mp-photo-alt': 'a red door' },
				photos: [{ blob: jpeg(255, 216, 255, 224), filename: 'photo-1.jpg' }],
			},
			site(offline),
			queueEnv,
		);
		expect(result.kind).toBe('queued');
		const [entry] = await list(queueEnv);
		expect(entry?.properties).toEqual({ 'mp-photo-alt': 'a red door' });
		expect(entry?.mediaEndpoint).toBe(MEDIA);
		expect(entry?.media).toHaveLength(1);
		expect(entry?.media?.[0]).toMatchObject({ filename: 'photo-1.jpg', type: 'image/jpeg' });
		expect(Array.from(new Uint8Array(entry?.media?.[0]?.bytes ?? new ArrayBuffer(0)))).toEqual([
			255, 216, 255, 224,
		]);
	});

	it('keeps the URL of a photo that uploaded before the connection dropped', async () => {
		let uploads = 0;
		const result = await post_or_queue(
			{
				source: 'photo',
				me: ME,
				accessToken: 'tk',
				properties: {},
				photos: [
					{ blob: jpeg(1), filename: 'photo-1.jpg' },
					{ blob: jpeg(2), filename: 'photo-2.jpg' },
				],
				micropubEndpoint: MP,
				mediaEndpoint: MEDIA,
			},
			site((url) => {
				if (url === MEDIA && uploads === 0) {
					uploads += 1;
					return created('https://example.test/uploads/photo-1.jpg');
				}
				throw new TypeError('Failed to fetch');
			}),
			queueEnv,
		);
		expect(result.kind).toBe('queued');
		const [entry] = await list(queueEnv);
		expect(entry?.media?.[0]).toEqual({
			filename: 'photo-1.jpg',
			type: 'image/jpeg',
			url: 'https://example.test/uploads/photo-1.jpg',
		});
		expect(entry?.media?.[1]?.url).toBeUndefined();
		expect(entry?.media?.[1]?.bytes?.byteLength).toBe(1);
	});

	it('shows the network error when the queue itself cannot be written', async () => {
		const broken: OfflineQueueEnvironment = {
			indexedDB: {
				open: (): never => {
					throw new Error('storage blocked');
				},
			} as unknown as IDBFactory,
		};
		await expect(
			post_or_queue(
				{ source: 'note', me: ME, accessToken: 'tk', properties: { content: 'x' }, micropubEndpoint: MP },
				site(offline),
				broken,
			),
		).rejects.toMatchObject({ code: 'post_failed' });
	});
});
