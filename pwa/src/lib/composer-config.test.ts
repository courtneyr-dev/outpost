import { describe, it, expect } from 'vitest';
import {
	fetch_composer_config,
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
