import { describe, it, expect } from 'vitest';
import {
	fetch_composer_config,
	pkiw_kind_hint,
	ComposerConfigError,
	type ComposerConfig,
	type ComposerConfigEnvironment,
} from './composer-config';

function mock_env(response: Partial<Response>, body?: unknown): ComposerConfigEnvironment {
	return {
		fetch: (async (): Promise<Response> => {
			return {
				ok: response.ok ?? (response.status ? response.status < 400 : true),
				status: response.status ?? 200,
				json: async () => body,
			} as Response;
		}) as typeof fetch,
	};
}

const valid_config: ComposerConfig = {
	companions: {
		'post-kinds': 'active',
		'post-formats': 'absent',
		xfn: 'inactive',
		'syndication-links': 'active',
		yoast: 'absent',
		activitypub: 'absent',
		'accessibility-checker': 'absent',
		'rss-chat-routing': 'absent',
	},
	postFormats: null,
	xfnRels: ['friend', 'met'],
	existingCategories: [{ slug: 'tech', name: 'Tech' }],
	existingTags: [{ slug: 'indieweb', name: 'IndieWeb' }],
	bridgyHostMap: {
		'twitter.com': { name: 'Twitter (via Bridgy)', uid: 'https://brid.gy/publish/twitter' },
	},
	siteSettings: {
		bridgyAutoSuggest: true,
		defaultPostVariant: 'article',
	},
};

describe('fetch_composer_config', () => {
	it('parses a valid response', async () => {
		const env = mock_env({ ok: true, status: 200 }, valid_config);
		const result = await fetch_composer_config('test-token', env);
		expect(result.companions['post-kinds']).toBe('active');
		expect(result.postFormats).toBeNull();
		expect(result.xfnRels).toContain('friend');
	});

	it('POSTs the token in the body, not the query string, with no cookie', async () => {
		// The token must ride in the JSON body (leak-safe) and the request
		// must send no wp-admin cookie, so a header-stripping host still
		// authenticates by the token and the cookie/nonce CSRF check is never
		// tripped. See the endpoint's Outpost_Bearer_Auth path.
		let seen: { url: string; init: RequestInit } | undefined;
		const env: ComposerConfigEnvironment = {
			fetch: (async (url: string, init: RequestInit): Promise<Response> => {
				seen = { url, init };
				return { ok: true, status: 200, json: async () => valid_config } as Response;
			}) as unknown as typeof fetch,
		};
		await fetch_composer_config('secret-token-abc', env);

		expect(seen?.init.method).toBe('POST');
		expect(seen?.init.credentials).toBe('omit');
		expect(seen?.url).not.toContain('secret-token-abc');
		expect(seen?.url).not.toContain('_o_token');
		expect(String(seen?.init.body)).toContain('secret-token-abc');
		const parsed = JSON.parse(String(seen?.init.body)) as { access_token?: string };
		expect(parsed.access_token).toBe('secret-token-abc');
	});

	it('throws unauthorized on 401', async () => {
		const env = mock_env({ ok: false, status: 401 });
		await expect(fetch_composer_config('test-token', env)).rejects.toMatchObject({
			code: 'unauthorized',
		});
	});

	it('throws unauthorized on 403', async () => {
		const env = mock_env({ ok: false, status: 403 });
		await expect(fetch_composer_config('test-token', env)).rejects.toMatchObject({
			code: 'unauthorized',
		});
	});

	it('throws fetch_failed on 500', async () => {
		const env = mock_env({ ok: false, status: 500 });
		await expect(fetch_composer_config('test-token', env)).rejects.toMatchObject({
			code: 'fetch_failed',
		});
	});

	it('throws fetch_failed when fetch rejects', async () => {
		const env: ComposerConfigEnvironment = {
			fetch: (async (): Promise<Response> => {
				throw new Error('network down');
			}) as typeof fetch,
		};
		await expect(fetch_composer_config('test-token', env)).rejects.toMatchObject({
			code: 'fetch_failed',
		});
	});

	it('throws invalid_response when shape is wrong', async () => {
		const env = mock_env({ ok: true, status: 200 }, { foo: 'bar' });
		await expect(fetch_composer_config('test-token', env)).rejects.toMatchObject({
			code: 'invalid_response',
		});
	});

	it('returns the spec-shaped error class', async () => {
		const env = mock_env({ ok: false, status: 401 });
		try {
			await fetch_composer_config('test-token', env);
			expect.fail('expected throw');
		} catch (err) {
			expect(err).toBeInstanceOf(ComposerConfigError);
		}
	});
});

describe('pkiw_kind_hint', () => {
	it('returns the vendor property when Post Kinds is active', () => {
		expect(pkiw_kind_hint(valid_config, 'jam')).toEqual({ 'pkiw-kind': 'jam' });
	});

	it('returns an empty object when Post Kinds is inactive', () => {
		const inactive: ComposerConfig = {
			...valid_config,
			companions: { ...valid_config.companions, 'post-kinds': 'inactive' },
		};
		expect(pkiw_kind_hint(inactive, 'jam')).toEqual({});
	});

	it('returns an empty object when Post Kinds is absent', () => {
		const absent: ComposerConfig = {
			...valid_config,
			companions: { ...valid_config.companions, 'post-kinds': 'absent' },
		};
		expect(pkiw_kind_hint(absent, 'issue')).toEqual({});
	});

	it('returns an empty object when the config never loaded', () => {
		// Config fetch failure must not block posting — the hint simply
		// drops and the receiving bridge falls back to property inference.
		expect(pkiw_kind_hint(undefined, 'event')).toEqual({});
	});
});
