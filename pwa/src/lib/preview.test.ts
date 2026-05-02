import { describe, it, expect } from 'vitest';
import { fetch_preview, extract_title, PreviewError, type PreviewEnvironment } from './preview';

function make_response(init: { status?: number; body?: unknown }): Response {
	const body = typeof init.body === 'string' ? init.body : JSON.stringify(init.body ?? {});
	return new Response(body, {
		status: init.status ?? 200,
		headers: { 'Content-Type': 'application/json' },
	});
}

describe('extract_title', () => {
	it('returns the title text from a normal HTML body', () => {
		expect(extract_title('<html><head><title>Hello</title></head><body>x</body></html>')).toBe(
			'Hello',
		);
	});

	it('trims surrounding whitespace', () => {
		expect(extract_title('<title>   spaced out   </title>')).toBe('spaced out');
	});

	it('decodes common HTML entities', () => {
		expect(extract_title('<title>Tom &amp; Jerry</title>')).toBe('Tom & Jerry');
	});

	it('handles attributes on the title tag', () => {
		expect(extract_title('<title lang="en">Chapter 1</title>')).toBe('Chapter 1');
	});

	it('returns null when no title tag present', () => {
		expect(extract_title('<html><body>no title</body></html>')).toBeNull();
	});

	it('returns null when title is empty', () => {
		expect(extract_title('<title></title>')).toBeNull();
	});

	it('returns null when title is only whitespace', () => {
		expect(extract_title('<title>   </title>')).toBeNull();
	});

	it('matches case-insensitively', () => {
		expect(extract_title('<TITLE>UPPER</TITLE>')).toBe('UPPER');
	});
});

describe('fetch_preview', () => {
	it('rejects non-http(s) URL before any fetch', async () => {
		const env: PreviewEnvironment = {
			fetch: async () => {
				throw new Error('fetch should not be called');
			},
		};
		await expect(
			fetch_preview({ url: 'javascript:alert(1)', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'invalid_url' });
	});

	it('returns parsed JSON + extracted title on success', async () => {
		const env: PreviewEnvironment = {
			fetch: async () =>
				make_response({
					body: {
						html: '<html><head><title>A post</title></head><body>x</body></html>',
						finalUrl: 'https://example.test/post',
						contentType: 'text/html; charset=utf-8',
					},
				}),
		};
		const result = await fetch_preview(
			{ url: 'https://example.test/post', accessToken: 'abc' },
			env,
		);
		expect(result.title).toBe('A post');
		expect(result.finalUrl).toBe('https://example.test/post');
	});

	it('sends Authorization: Bearer header', async () => {
		let captured_headers: Record<string, string> = {};
		const env: PreviewEnvironment = {
			fetch: async (_input: RequestInfo | URL, init?: RequestInit) => {
				captured_headers = (init?.headers as Record<string, string>) ?? {};
				return make_response({ body: { html: '<title>x</title>', finalUrl: 'u', contentType: 'text/html' } });
			},
		};
		await fetch_preview({ url: 'https://example.test/', accessToken: 'token-xyz' }, env);
		expect(captured_headers['Authorization']).toBe('Bearer token-xyz');
	});

	it('throws unauthorized on 401', async () => {
		const env: PreviewEnvironment = {
			fetch: async () => make_response({ status: 401, body: { error: 'forbidden' } }),
		};
		await expect(
			fetch_preview({ url: 'https://example.test/', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'unauthorized' });
	});

	it('throws unsupported_content_type on 415', async () => {
		const env: PreviewEnvironment = {
			fetch: async () => make_response({ status: 415, body: {} }),
		};
		await expect(
			fetch_preview({ url: 'https://example.test/', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'unsupported_content_type' });
	});

	it('throws rate_limited on 429', async () => {
		const env: PreviewEnvironment = {
			fetch: async () => make_response({ status: 429, body: {} }),
		};
		await expect(
			fetch_preview({ url: 'https://example.test/', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'rate_limited' });
	});

	it('throws server_error on 5xx', async () => {
		const env: PreviewEnvironment = {
			fetch: async () => make_response({ status: 502, body: {} }),
		};
		await expect(
			fetch_preview({ url: 'https://example.test/', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'server_error' });
	});

	it('throws fetch_failed when fetch rejects', async () => {
		const env: PreviewEnvironment = {
			fetch: async () => {
				throw new Error('network down');
			},
		};
		await expect(
			fetch_preview({ url: 'https://example.test/', accessToken: 'abc' }, env),
		).rejects.toMatchObject({ code: 'fetch_failed' });
	});

	it('error is an instance of PreviewError', async () => {
		const env: PreviewEnvironment = {
			fetch: async () => make_response({ status: 401, body: {} }),
		};
		await expect(
			fetch_preview({ url: 'https://example.test/', accessToken: 'abc' }, env),
		).rejects.toBeInstanceOf(PreviewError);
	});
});
