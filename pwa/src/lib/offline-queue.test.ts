import { describe, it, expect, beforeEach } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import {
	enqueue,
	list,
	remove,
	flush,
	is_network_error,
	type OfflineQueueEnvironment,
} from './offline-queue';
import { MicropubError, type MicropubEnvironment } from './micropub';

function fresh_env(): OfflineQueueEnvironment {
	return { indexedDB: new IDBFactory() };
}

function ok_response(headers: Record<string, string> = {}): Response {
	return new Response('', {
		status: 201,
		headers: { Location: 'https://example.test/post/1', ...headers },
	});
}

function rejecting_fetch(message = 'TypeError: Failed to fetch'): MicropubEnvironment {
	return {
		fetch: ((): Promise<Response> => {
			return Promise.reject(new TypeError(message));
		}) as typeof fetch,
	};
}

let env: OfflineQueueEnvironment;

beforeEach(() => {
	env = fresh_env();
});

describe('offline-queue: enqueue + list + remove', () => {
	it('enqueues an entry and lists it back', async () => {
		const id = await enqueue(
			{
				source: 'note',
				properties: { content: 'hi' },
				accessToken: 'tk',
				micropubEndpoint: 'https://example.test/wp-json/micropub/1.0/endpoint',
			},
			env,
		);
		expect(id).toBeGreaterThan(0);

		const entries = await list(env);
		expect(entries).toHaveLength(1);
		expect(entries[0]?.source).toBe('note');
		expect(entries[0]?.properties).toEqual({ content: 'hi' });
		expect(entries[0]?.attempts).toBe(0);
		expect(entries[0]?.createdAt).toBeGreaterThan(0);
	});

	it('returns empty list for fresh DB', async () => {
		const entries = await list(env);
		expect(entries).toEqual([]);
	});

	it('preserves insertion order across multiple enqueues', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'first' },
				accessToken: 't',
				micropubEndpoint: 'e',
			},
			env,
		);
		await enqueue(
			{
				source: 'reply',
				properties: { content: 'second' },
				accessToken: 't',
				micropubEndpoint: 'e',
			},
			env,
		);
		const entries = await list(env);
		expect(entries.map((e) => e.source)).toEqual(['note', 'reply']);
	});

	it('removes an entry by id', async () => {
		const id = await enqueue(
			{
				source: 'note',
				properties: { content: 'hi' },
				accessToken: 't',
				micropubEndpoint: 'e',
			},
			env,
		);
		await remove(id, env);
		const entries = await list(env);
		expect(entries).toEqual([]);
	});
});

describe('offline-queue: flush', () => {
	it('drains all entries on success', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'a' },
				accessToken: 't',
				micropubEndpoint: 'https://example.test/m',
			},
			env,
		);
		await enqueue(
			{
				source: 'reply',
				properties: { content: 'b' },
				accessToken: 't',
				micropubEndpoint: 'https://example.test/m',
			},
			env,
		);

		const m_env: MicropubEnvironment = {
			fetch: ((): Promise<Response> => Promise.resolve(ok_response())) as typeof fetch,
		};

		const remaining = await flush(m_env, env);
		expect(remaining).toEqual([]);
		const entries = await list(env);
		expect(entries).toEqual([]);
	});

	it('keeps entries with incremented attempts on network failure', async () => {
		await enqueue(
			{
				source: 'note',
				properties: { content: 'a' },
				accessToken: 't',
				micropubEndpoint: 'https://example.test/m',
			},
			env,
		);

		const remaining = await flush(rejecting_fetch(), env);
		expect(remaining).toHaveLength(1);
		expect(remaining[0]?.attempts).toBe(1);
		expect(remaining[0]?.lastError).toContain('post_failed');

		// Persisted state matches.
		const persisted = await list(env);
		expect(persisted[0]?.attempts).toBe(1);
		expect(persisted[0]?.lastError).toContain('post_failed');
	});

	it('returns empty array when queue is empty', async () => {
		const m_env: MicropubEnvironment = {
			fetch: ((): Promise<Response> => Promise.resolve(ok_response())) as typeof fetch,
		};
		const remaining = await flush(m_env, env);
		expect(remaining).toEqual([]);
	});
});

describe('offline-queue: is_network_error', () => {
	it('classifies TypeError fetch failures as network errors', () => {
		const err = new TypeError('Failed to fetch');
		expect(is_network_error(err)).toBe(true);
	});

	it('classifies MicropubError post_failed with fetch threw as network error', () => {
		const err = new MicropubError(
			'post_h_entry: fetch threw — TypeError: Failed to fetch',
			'post_failed',
		);
		expect(is_network_error(err)).toBe(true);
	});

	it('classifies MicropubError post_failed with HTTP status as NOT a network error', () => {
		const err = new MicropubError(
			'post_h_entry: micropub endpoint returned 401',
			'post_failed',
		);
		expect(is_network_error(err)).toBe(false);
	});

	it('classifies MicropubError no_endpoint as NOT a network error', () => {
		const err = new MicropubError('no endpoint', 'no_endpoint');
		expect(is_network_error(err)).toBe(false);
	});

	it('classifies plain Error as NOT a network error', () => {
		const err = new Error('something else');
		expect(is_network_error(err)).toBe(false);
	});
});
