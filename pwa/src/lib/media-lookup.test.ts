import { describe, it, expect } from 'vitest';
import { lookup_media, MediaLookupError, type MediaLookupEnvironment } from './media-lookup';

function make_response(init: { status?: number; body?: unknown }): Response {
	const body = typeof init.body === 'string' ? init.body : JSON.stringify(init.body ?? {});
	return new Response(body, {
		status: init.status ?? 200,
		headers: { 'Content-Type': 'application/json' },
	});
}

function ok_body(results: unknown[], notConfigured = false): Response {
	return make_response({ body: { results, kind: 'watch', notConfigured } });
}

describe('lookup_media', () => {
	it('returns normalized results on success', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () =>
				ok_body([
					{
						title: 'Inception',
						cover: 'https://img.example/p.jpg',
						creator: 'Christopher Nolan',
						year: '2010',
						external_id: '27205',
						url: '',
					},
				]),
		};
		const results = await lookup_media(
			{ kind: 'watch', query: 'Inception', accessToken: 'abc' },
			env,
		);
		expect(results).toHaveLength(1);
		expect(results[0]?.title).toBe('Inception');
		expect(results[0]?.cover).toBe('https://img.example/p.jpg');
		expect(results[0]?.year).toBe('2010');
	});

	it('maps external_id to externalId', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => ok_body([{ title: 'Dune', external_id: '/works/OL893415W' }]),
		};
		const results = await lookup_media({ kind: 'read', query: 'Dune', accessToken: 'abc' }, env);
		expect(results[0]?.externalId).toBe('/works/OL893415W');
	});

	it('sends kind + query + access_token in the body', async () => {
		let captured_body: Record<string, unknown> = {};
		const env: MediaLookupEnvironment = {
			fetch: async (_input: RequestInfo | URL, init?: RequestInit) => {
				captured_body = JSON.parse((init?.body as string) ?? '{}');
				return ok_body([]);
			},
		};
		await lookup_media({ kind: 'listen', query: 'Discovery', accessToken: 'tok-1' }, env);
		expect(captured_body['kind']).toBe('listen');
		expect(captured_body['q']).toBe('Discovery');
		expect(captured_body['access_token']).toBe('tok-1');
	});

	it('sends Authorization Bearer header and credentials include', async () => {
		let captured_headers: Record<string, string> = {};
		let captured_credentials: RequestCredentials | undefined;
		const env: MediaLookupEnvironment = {
			fetch: async (_input: RequestInfo | URL, init?: RequestInit) => {
				captured_headers = (init?.headers as Record<string, string>) ?? {};
				captured_credentials = init?.credentials;
				return ok_body([]);
			},
		};
		await lookup_media({ kind: 'watch', query: 'The Bear', accessToken: 'token-xyz' }, env);
		expect(captured_headers['Authorization']).toBe('Bearer token-xyz');
		expect(captured_credentials).toBe('include');
	});

	it('includes type in the body when provided (movie/tv toggle)', async () => {
		let captured_body: Record<string, unknown> = {};
		const env: MediaLookupEnvironment = {
			fetch: async (_input: RequestInfo | URL, init?: RequestInit) => {
				captured_body = JSON.parse((init?.body as string) ?? '{}');
				return ok_body([]);
			},
		};
		await lookup_media(
			{ kind: 'watch', query: 'The Bear', type: 'tv', accessToken: 'abc' },
			env,
		);
		expect(captured_body['type']).toBe('tv');
	});

	it('omits type from the body when not provided', async () => {
		let captured_body: Record<string, unknown> = {};
		const env: MediaLookupEnvironment = {
			fetch: async (_input: RequestInfo | URL, init?: RequestInit) => {
				captured_body = JSON.parse((init?.body as string) ?? '{}');
				return ok_body([]);
			},
		};
		await lookup_media({ kind: 'read', query: 'Dune', accessToken: 'abc' }, env);
		expect('type' in captured_body).toBe(false);
	});

	it('throws unauthorized on 401', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => make_response({ status: 401, body: {} }),
		};
		await expect(
			lookup_media({ kind: 'watch', query: 'x', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'unauthorized' });
	});

	it('throws unauthorized on 403', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => make_response({ status: 403, body: {} }),
		};
		await expect(
			lookup_media({ kind: 'watch', query: 'x', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'unauthorized' });
	});

	it('throws post_kinds_inactive on 501', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => make_response({ status: 501, body: { code: 'post_kinds_inactive' } }),
		};
		await expect(
			lookup_media({ kind: 'watch', query: 'x', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'post_kinds_inactive' });
	});

	it('throws rate_limited on 429', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => make_response({ status: 429, body: {} }),
		};
		await expect(
			lookup_media({ kind: 'watch', query: 'x', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'rate_limited' });
	});

	it('throws server_error on 5xx', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => make_response({ status: 502, body: {} }),
		};
		await expect(
			lookup_media({ kind: 'watch', query: 'x', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'server_error' });
	});

	it('throws not_configured when the server flags the provider unconfigured', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => ok_body([], true),
		};
		await expect(
			lookup_media({ kind: 'game', query: 'Catan', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'not_configured' });
	});

	it('throws fetch_failed when fetch rejects', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => {
				throw new Error('network down');
			},
		};
		await expect(
			lookup_media({ kind: 'watch', query: 'x', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'fetch_failed' });
	});

	it('error is an instance of MediaLookupError', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => make_response({ status: 401, body: {} }),
		};
		await expect(
			lookup_media({ kind: 'watch', query: 'x', accessToken: 'abc' }, env),
		).rejects.toBeInstanceOf(MediaLookupError);
	});

	it('returns an empty array when the server returns no results', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => ok_body([]),
		};
		const results = await lookup_media({ kind: 'watch', query: 'zzz', accessToken: 'abc' }, env);
		expect(results).toEqual([]);
	});
});
