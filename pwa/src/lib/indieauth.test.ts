import { describe, it, expect, vi } from 'vitest';
import {
	parse_link_header,
	parse_html_endpoints,
	generate_pkce,
	generate_state,
	build_authorization_url,
	discover_endpoints,
	exchange_code_for_token,
	type IndieAuthEnvironment,
} from './indieauth';

function make_env(): IndieAuthEnvironment {
	return {
		fetch: globalThis.fetch.bind(globalThis),
		crypto: globalThis.crypto,
		random: () => globalThis.crypto.getRandomValues(new Uint8Array(32)),
	};
}

describe('parse_link_header', () => {
	it('parses a single rel', () => {
		const result = parse_link_header('<https://example.com/auth>; rel="authorization_endpoint"');
		expect(result).toEqual({ authorization_endpoint: 'https://example.com/auth' });
	});

	it('parses multiple links comma-separated', () => {
		const header =
			'<https://a/auth>; rel="authorization_endpoint", <https://a/token>; rel="token_endpoint"';
		expect(parse_link_header(header)).toEqual({
			authorization_endpoint: 'https://a/auth',
			token_endpoint: 'https://a/token',
		});
	});

	it('expands space-separated rels in one quoted value', () => {
		const header = '<https://a/auth>; rel="authorization_endpoint indieauth-server"';
		expect(parse_link_header(header)).toEqual({
			authorization_endpoint: 'https://a/auth',
			'indieauth-server': 'https://a/auth',
		});
	});

	it('first occurrence wins on duplicate rels', () => {
		const header =
			'<https://first/auth>; rel="authorization_endpoint", <https://second/auth>; rel="authorization_endpoint"';
		expect(parse_link_header(header)).toEqual({
			authorization_endpoint: 'https://first/auth',
		});
	});
});

describe('parse_html_endpoints', () => {
	it('parses <link rel> in the document', () => {
		const html =
			'<!doctype html><html><head><link rel="authorization_endpoint" href="https://a/auth"><link rel="token_endpoint" href="https://a/token"></head><body></body></html>';
		const result = parse_html_endpoints(html);
		expect(result['authorization_endpoint']).toBe('https://a/auth');
		expect(result['token_endpoint']).toBe('https://a/token');
	});

	it('falls back to <a rel> when no <link rel> present', () => {
		const html =
			'<html><body><a rel="authorization_endpoint" href="https://a/auth">x</a></body></html>';
		expect(parse_html_endpoints(html)['authorization_endpoint']).toBe('https://a/auth');
	});

	it('<link rel> takes precedence over <a rel>', () => {
		const html =
			'<html><head><link rel="authorization_endpoint" href="https://link/auth"></head><body><a rel="authorization_endpoint" href="https://anchor/auth">x</a></body></html>';
		expect(parse_html_endpoints(html)['authorization_endpoint']).toBe('https://link/auth');
	});
});

describe('generate_pkce', () => {
	it('returns a verifier and a SHA-256 challenge', async () => {
		const env = make_env();
		const result = await generate_pkce(env);
		expect(result.codeVerifier).toMatch(/^[A-Za-z0-9_-]+$/);
		expect(result.codeChallenge).toMatch(/^[A-Za-z0-9_-]+$/);
		expect(result.codeVerifier).not.toBe(result.codeChallenge);
	});

	it('produces different verifiers across calls', async () => {
		const env = make_env();
		const a = await generate_pkce(env);
		const b = await generate_pkce(env);
		expect(a.codeVerifier).not.toBe(b.codeVerifier);
	});

	it('challenge is deterministic for a given verifier', async () => {
		// Stub random with fixed bytes so the verifier is identical across calls.
		const fixed = new Uint8Array(32).fill(7);
		const env: IndieAuthEnvironment = {
			fetch: globalThis.fetch.bind(globalThis),
			crypto: globalThis.crypto,
			random: () => fixed.slice(),
		};
		const a = await generate_pkce(env);
		const b = await generate_pkce(env);
		expect(a.codeVerifier).toBe(b.codeVerifier);
		expect(a.codeChallenge).toBe(b.codeChallenge);
	});
});

describe('generate_state', () => {
	it('returns a base64url string', () => {
		expect(generate_state(make_env())).toMatch(/^[A-Za-z0-9_-]+$/);
	});
});

describe('build_authorization_url', () => {
	it('includes every required IndieAuth + PKCE param', () => {
		const url = build_authorization_url({
			authorizationEndpoint: 'https://a/auth',
			clientId: 'https://courtneyr.dev/post/',
			redirectUri: 'https://courtneyr.dev/post/auth/callback',
			me: 'https://courtneyr.dev/',
			scope: 'create update',
			state: 'state-token',
			codeChallenge: 'challenge-bytes',
		});
		const parsed = new URL(url);
		expect(parsed.origin + parsed.pathname).toBe('https://a/auth');
		expect(parsed.searchParams.get('response_type')).toBe('code');
		expect(parsed.searchParams.get('client_id')).toBe('https://courtneyr.dev/post/');
		expect(parsed.searchParams.get('redirect_uri')).toBe('https://courtneyr.dev/post/auth/callback');
		expect(parsed.searchParams.get('me')).toBe('https://courtneyr.dev/');
		expect(parsed.searchParams.get('scope')).toBe('create update');
		expect(parsed.searchParams.get('state')).toBe('state-token');
		expect(parsed.searchParams.get('code_challenge')).toBe('challenge-bytes');
		expect(parsed.searchParams.get('code_challenge_method')).toBe('S256');
	});
});

describe('discover_endpoints', () => {
	it('prefers Link header over HTML <link rel>', async () => {
		const env = make_env();
		env.fetch = vi.fn(
			async () =>
				new Response('<link rel="authorization_endpoint" href="https://html/auth">', {
					status: 200,
					headers: {
						Link: '<https://header/auth>; rel="authorization_endpoint", <https://header/token>; rel="token_endpoint"',
					},
				}),
		);
		const endpoints = await discover_endpoints('https://courtneyr.dev/', env);
		expect(endpoints.authorizationEndpoint).toBe('https://header/auth');
		expect(endpoints.tokenEndpoint).toBe('https://header/token');
	});

	it('falls back to HTML when Link header is absent', async () => {
		const env = make_env();
		env.fetch = vi.fn(
			async () =>
				new Response(
					'<link rel="authorization_endpoint" href="https://html/auth"><link rel="token_endpoint" href="https://html/token">',
					{ status: 200 },
				),
		);
		const endpoints = await discover_endpoints('https://courtneyr.dev/', env);
		expect(endpoints.authorizationEndpoint).toBe('https://html/auth');
		expect(endpoints.tokenEndpoint).toBe('https://html/token');
	});

	it('throws when authorization_endpoint cannot be found', async () => {
		const env = make_env();
		env.fetch = vi.fn(async () => new Response('<html></html>', { status: 200 }));
		await expect(discover_endpoints('https://courtneyr.dev/', env)).rejects.toThrow(
			/no authorization_endpoint/,
		);
	});

	it('throws when fetch returns non-2xx', async () => {
		const env = make_env();
		env.fetch = vi.fn(async () => new Response('Not Found', { status: 404 }));
		await expect(discover_endpoints('https://courtneyr.dev/', env)).rejects.toThrow(/status 404/);
	});

	it('captures micropub endpoint when advertised', async () => {
		const env = make_env();
		env.fetch = vi.fn(
			async () =>
				new Response(
					'<link rel="authorization_endpoint" href="https://a/auth"><link rel="token_endpoint" href="https://a/token"><link rel="micropub" href="https://a/micropub">',
					{ status: 200 },
				),
		);
		const endpoints = await discover_endpoints('https://courtneyr.dev/', env);
		expect(endpoints.micropubEndpoint).toBe('https://a/micropub');
	});
});

describe('exchange_code_for_token', () => {
	it('POSTs form-encoded body and returns the token', async () => {
		const env = make_env();
		let captured_url: string | null = null;
		let captured_body: string | null = null;
		let captured_method: string | null = null;
		env.fetch = vi.fn(async (input, init) => {
			captured_url = typeof input === 'string' ? input : (input as URL).toString();
			captured_body = String(init?.body ?? '');
			captured_method = init?.method ?? 'GET';
			return new Response(
				JSON.stringify({
					access_token: 'token-abc',
					token_type: 'Bearer',
					scope: 'create update',
					me: 'https://courtneyr.dev/',
				}),
				{ status: 200, headers: { 'Content-Type': 'application/json' } },
			);
		});

		const result = await exchange_code_for_token(
			{
				tokenEndpoint: 'https://t/token',
				code: 'auth-code',
				clientId: 'https://c/post/',
				redirectUri: 'https://c/post/auth/callback',
				codeVerifier: 'verifier',
			},
			env,
		);

		expect(captured_url).toBe('https://t/token');
		expect(captured_method).toBe('POST');
		expect(captured_body).toContain('grant_type=authorization_code');
		expect(captured_body).toContain('code=auth-code');
		expect(captured_body).toContain('code_verifier=verifier');
		expect(result.accessToken).toBe('token-abc');
		expect(result.scope).toBe('create update');
		expect(result.me).toBe('https://courtneyr.dev/');
	});

	it('throws on non-2xx response with the body included in the message', async () => {
		const env = make_env();
		env.fetch = vi.fn(async () => new Response('invalid_grant', { status: 400 }));
		await expect(
			exchange_code_for_token(
				{
					tokenEndpoint: 'https://t/token',
					code: 'bad',
					clientId: 'c',
					redirectUri: 'r',
					codeVerifier: 'v',
				},
				env,
			),
		).rejects.toThrow(/400.*invalid_grant/);
	});

	it('throws when access_token is missing from a 200 response', async () => {
		const env = make_env();
		env.fetch = vi.fn(
			async () =>
				new Response(JSON.stringify({ scope: 'create' }), {
					status: 200,
					headers: { 'Content-Type': 'application/json' },
				}),
		);
		await expect(
			exchange_code_for_token(
				{
					tokenEndpoint: 'https://t/token',
					code: 'code',
					clientId: 'c',
					redirectUri: 'r',
					codeVerifier: 'v',
				},
				env,
			),
		).rejects.toThrow(/missing access_token/);
	});

	it('defaults token_type to Bearer when omitted', async () => {
		const env = make_env();
		env.fetch = vi.fn(
			async () =>
				new Response(JSON.stringify({ access_token: 'abc' }), {
					status: 200,
					headers: { 'Content-Type': 'application/json' },
				}),
		);
		const result = await exchange_code_for_token(
			{
				tokenEndpoint: 'https://t/token',
				code: 'code',
				clientId: 'c',
				redirectUri: 'r',
				codeVerifier: 'v',
			},
			env,
		);
		expect(result.tokenType).toBe('Bearer');
	});
});
