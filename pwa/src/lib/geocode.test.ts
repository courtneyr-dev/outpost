import { describe, expect, it } from 'vitest';
import { geocode, type GeocodeEnvironment } from './geocode';

describe('geocode', () => {
	it('POSTs q and access_token in the body without putting credentials in the URL', async () => {
		let captured_url = '';
		let captured_init: RequestInit | undefined;
		const env: GeocodeEnvironment = {
			fetch: async (input: RequestInfo | URL, init?: RequestInit) => {
				captured_url = String(input);
				captured_init = init;
				return new Response(
					JSON.stringify({ results: [], cached: false, attribution: 'test' }),
					{
						status: 200,
						headers: { 'Content-Type': 'application/json' },
					},
				);
			},
			location: { origin: 'https://example.test' },
		};

		await geocode({ query: 'Berlin', accessToken: 'token-xyz' }, env);

		const body = new URLSearchParams(String(captured_init?.body ?? ''));
		const headers = (captured_init?.headers as Record<string, string>) ?? {};
		expect(captured_init?.method).toBe('POST');
		expect(captured_url).not.toContain('access_token');
		expect(body.get('q')).toBe('Berlin');
		expect(body.get('access_token')).toBe('token-xyz');
		expect(headers['Authorization']).toBe('Bearer token-xyz');
		expect(headers['Content-Type']).toBe('application/x-www-form-urlencoded');
		expect(captured_init?.credentials).toBe('omit');
	});
});
