import { describe, it, expect } from 'vitest';
import {
	discover_micropub_endpoint,
	post_note,
	MicropubError,
	type MicropubEnvironment,
} from './micropub';

function make_response(init: {
	status?: number;
	headers?: Record<string, string>;
	body?: string;
}): Response {
	return new Response(init.body ?? '', {
		status: init.status ?? 200,
		headers: init.headers ?? {},
	});
}

describe('discover_micropub_endpoint', () => {
	it('finds endpoint in Link header', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({
					headers: { Link: '<https://example.test/micropub>; rel="micropub"' },
					body: '<html></html>',
				}),
		};
		const endpoint = await discover_micropub_endpoint('https://example.test/', env);
		expect(endpoint).toBe('https://example.test/micropub');
	});

	it('finds endpoint in HTML <link rel>', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({
					body:
						'<html><head><link rel="micropub" href="https://example.test/mp"></head></html>',
				}),
		};
		const endpoint = await discover_micropub_endpoint('https://example.test/', env);
		expect(endpoint).toBe('https://example.test/mp');
	});

	it('prefers Link header over HTML link (first-wins)', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({
					headers: { Link: '<https://example.test/header>; rel="micropub"' },
					body:
						'<html><head><link rel="micropub" href="https://example.test/html"></head></html>',
				}),
		};
		const endpoint = await discover_micropub_endpoint('https://example.test/', env);
		expect(endpoint).toBe('https://example.test/header');
	});

	it('throws no_endpoint when neither header nor HTML has micropub rel', async () => {
		const env: MicropubEnvironment = {
			fetch: async () => make_response({ body: '<html></html>' }),
		};
		await expect(
			discover_micropub_endpoint('https://example.test/', env),
		).rejects.toMatchObject({ code: 'no_endpoint' });
	});

	it('throws discovery_failed on non-2xx response', async () => {
		const env: MicropubEnvironment = {
			fetch: async () => make_response({ status: 500, body: 'oops' }),
		};
		await expect(
			discover_micropub_endpoint('https://example.test/', env),
		).rejects.toMatchObject({ code: 'discovery_failed' });
	});

	it('throws discovery_failed when fetch rejects', async () => {
		const env: MicropubEnvironment = {
			fetch: async () => {
				throw new Error('network down');
			},
		};
		await expect(
			discover_micropub_endpoint('https://example.test/', env),
		).rejects.toMatchObject({ code: 'discovery_failed' });
	});

	it('error is an instance of MicropubError', async () => {
		const env: MicropubEnvironment = {
			fetch: async () => make_response({ body: '<html></html>' }),
		};
		await expect(
			discover_micropub_endpoint('https://example.test/', env),
		).rejects.toBeInstanceOf(MicropubError);
	});
});

describe('post_note', () => {
	it('returns Location URL on 201 Created', async () => {
		const env: MicropubEnvironment = {
			fetch: async (input, init) => {
				const url = typeof input === 'string' ? input : (input as Request).url;
				expect(url).toBe('https://example.test/micropub');
				expect(init?.method).toBe('POST');
				const headers = init?.headers as Record<string, string>;
				expect(headers['Authorization']).toBe('Bearer abc123');
				expect(headers['Content-Type']).toBe('application/x-www-form-urlencoded');
				expect(init?.body).toContain('h=entry');
				expect(init?.body).toContain('content=Hello');
				return make_response({
					status: 201,
					headers: { Location: 'https://example.test/2026/05/01/hello-world' },
				});
			},
		};
		const result = await post_note(
			{
				content: 'Hello world',
				accessToken: 'abc123',
				micropubEndpoint: 'https://example.test/micropub',
			},
			env,
		);
		expect(result.location).toBe('https://example.test/2026/05/01/hello-world');
	});

	it('treats 202 Accepted as success', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({
					status: 202,
					headers: { Location: 'https://example.test/queued' },
				}),
		};
		const result = await post_note(
			{
				content: 'Async post',
				accessToken: 'abc',
				micropubEndpoint: 'https://example.test/mp',
			},
			env,
		);
		expect(result.location).toBe('https://example.test/queued');
	});

	it('throws post_failed on 4xx with body included in message', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({ status: 400, body: '{"error":"invalid_request"}' }),
		};
		try {
			await post_note(
				{
					content: 'bad',
					accessToken: 'abc',
					micropubEndpoint: 'https://example.test/mp',
				},
				env,
			);
			throw new Error('should have thrown MicropubError');
		} catch (err) {
			expect(err).toBeInstanceOf(MicropubError);
			expect((err as MicropubError).code).toBe('post_failed');
			expect((err as MicropubError).message).toContain('400');
			expect((err as MicropubError).message).toContain('invalid_request');
		}
	});

	it('throws no_location when 201 response has no Location header', async () => {
		const env: MicropubEnvironment = {
			fetch: async () => make_response({ status: 201 }),
		};
		await expect(
			post_note(
				{
					content: 'no loc',
					accessToken: 'abc',
					micropubEndpoint: 'https://example.test/mp',
				},
				env,
			),
		).rejects.toMatchObject({ code: 'no_location' });
	});

	it('throws post_failed when fetch rejects', async () => {
		const env: MicropubEnvironment = {
			fetch: async () => {
				throw new Error('network down');
			},
		};
		await expect(
			post_note(
				{
					content: 'noop',
					accessToken: 'abc',
					micropubEndpoint: 'https://example.test/mp',
				},
				env,
			),
		).rejects.toMatchObject({ code: 'post_failed' });
	});

	it('throws invalid_location when Location is a javascript: URL (compromised endpoint defense)', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({
					status: 201,
					headers: { Location: 'javascript:alert(1)' },
				}),
		};
		await expect(
			post_note(
				{
					content: 'attempt',
					accessToken: 'abc',
					micropubEndpoint: 'https://example.test/mp',
				},
				env,
			),
		).rejects.toMatchObject({ code: 'invalid_location' });
	});

	it('throws invalid_location when Location is a data: URL', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({
					status: 201,
					headers: { Location: 'data:text/html,<script>1</script>' },
				}),
		};
		await expect(
			post_note(
				{
					content: 'attempt',
					accessToken: 'abc',
					micropubEndpoint: 'https://example.test/mp',
				},
				env,
			),
		).rejects.toMatchObject({ code: 'invalid_location' });
	});

	it('reads lowercase "location" header (HTTP/2 convention)', async () => {
		const env: MicropubEnvironment = {
			fetch: async () =>
				make_response({
					status: 201,
					headers: { location: 'https://example.test/lc' },
				}),
		};
		const result = await post_note(
			{
				content: 'h2',
				accessToken: 'abc',
				micropubEndpoint: 'https://example.test/mp',
			},
			env,
		);
		expect(result.location).toBe('https://example.test/lc');
	});
});
