/**
 * IndieAuth client for the Outpost PWA.
 *
 * Implements the browser side of the IndieAuth flow (https://indieauth.spec.indieweb.org/):
 *   1. Discovery — GET the user's "me" URL, find authorization_endpoint and
 *      token_endpoint via Link header or HTML <link rel>.
 *   2. PKCE — generate a code_verifier and the SHA-256 code_challenge derived
 *      from it. The verifier stays in sessionStorage; the challenge goes in the
 *      authorization redirect URL.
 *   3. Authorization — build a redirect URL the browser navigates to.
 *   4. Token exchange — POST the returned `code` plus the saved `code_verifier`
 *      to the token_endpoint, receive the bearer token.
 *
 * All side-effecting calls (fetch, crypto, random) flow through an injectable
 * `IndieAuthEnvironment` so unit tests can substitute deterministic stubs.
 */

export interface DiscoveredEndpoints {
	authorizationEndpoint: string;
	tokenEndpoint: string;
	micropubEndpoint?: string;
}

export interface PkcePair {
	codeVerifier: string;
	codeChallenge: string;
}

export interface AuthorizationParams {
	authorizationEndpoint: string;
	clientId: string;
	redirectUri: string;
	me: string;
	scope: string;
	state: string;
	codeChallenge: string;
}

export interface ExchangeParams {
	tokenEndpoint: string;
	code: string;
	clientId: string;
	redirectUri: string;
	codeVerifier: string;
}

export interface TokenResponse {
	accessToken: string;
	tokenType: string;
	scope: string;
	me: string;
}

export interface IndieAuthEnvironment {
	fetch: typeof fetch;
	crypto: Crypto;
	/** Generates 32 random bytes for PKCE verifiers and CSRF state tokens. */
	random: () => Uint8Array;
}

const default_env: IndieAuthEnvironment = {
	fetch: globalThis.fetch.bind(globalThis),
	crypto: globalThis.crypto,
	random: () => globalThis.crypto.getRandomValues(new Uint8Array(32)),
};

const LINK_HEADER_PATTERN = /^<([^>]+)>\s*;\s*rel="?([^";]+)"?/;

/**
 * Discover the IndieAuth endpoints for a "me" URL.
 *
 * Order of preference per the IndieAuth spec:
 *   1. HTTP Link: header
 *   2. HTML <link rel> in the document
 *   3. <a rel> as a final fallback (some sites only put rels in the body)
 */
export async function discover_endpoints(
	me: string,
	env: IndieAuthEnvironment = default_env,
): Promise<DiscoveredEndpoints> {
	const response = await env.fetch(me, {
		method: 'GET',
		redirect: 'follow',
		headers: { Accept: 'text/html' },
		// See micropub.ts: same-origin Outpost fetches must omit cookies so the
		// wp-admin session cookie doesn't trip rest_cookie_check_errors.
		credentials: 'omit',
	});
	if (!response.ok) {
		throw new Error('discover_endpoints: fetch failed with status ' + String(response.status));
	}

	const link_header = response.headers.get('link') ?? response.headers.get('Link');
	const from_header = link_header ? parse_link_header(link_header) : {};

	const html = await response.text();
	const from_html = parse_html_endpoints(html);

	const authorization_endpoint = from_header['authorization_endpoint'] ?? from_html['authorization_endpoint'];
	const token_endpoint = from_header['token_endpoint'] ?? from_html['token_endpoint'];
	const micropub_endpoint = from_header['micropub'] ?? from_html['micropub'];

	if (!authorization_endpoint) {
		throw new Error('discover_endpoints: no authorization_endpoint found for ' + me);
	}
	if (!token_endpoint) {
		throw new Error('discover_endpoints: no token_endpoint found for ' + me);
	}

	const result: DiscoveredEndpoints = {
		authorizationEndpoint: authorization_endpoint,
		tokenEndpoint: token_endpoint,
	};
	if (micropub_endpoint) {
		result.micropubEndpoint = micropub_endpoint;
	}
	return result;
}

/**
 * Parse an HTTP Link header into a rel -> URL map.
 *
 * Format: `<url1>; rel="r1", <url2>; rel="r2 r3"`
 *
 * Multiple rels space-separated within one quoted value get expanded so each
 * rel maps to the same URL. Naive comma-split is fine because IndieAuth Link
 * URLs don't carry commas in practice.
 */
export function parse_link_header(header: string): Record<string, string> {
	const result: Record<string, string> = {};
	for (const segment of header.split(',')) {
		const trimmed = segment.trim();
		const match = trimmed.match(LINK_HEADER_PATTERN);
		if (!match) continue;
		const url = match[1];
		const rel_value = match[2];
		if (!url || !rel_value) continue;
		for (const r of rel_value.split(/\s+/)) {
			if (!(r in result)) {
				result[r] = url;
			}
		}
	}
	return result;
}

/**
 * Parse <link rel> and <a rel> elements out of an HTML document.
 *
 * Same first-wins semantics as parse_link_header, and the same multi-rel
 * expansion (`rel="me indieauth-me"` populates both keys).
 */
export function parse_html_endpoints(html: string): Record<string, string> {
	const result: Record<string, string> = {};
	const parser = new DOMParser();
	const doc = parser.parseFromString(html, 'text/html');
	const collect = (selector: string): void => {
		const nodes = doc.querySelectorAll(selector);
		for (const node of Array.from(nodes)) {
			const rel = node.getAttribute('rel');
			const href = node.getAttribute('href');
			if (!rel || !href) continue;
			for (const r of rel.split(/\s+/)) {
				if (!(r in result)) {
					result[r] = href;
				}
			}
		}
	};
	collect('link[rel][href]');
	collect('a[rel][href]');
	return result;
}

/**
 * Generate a PKCE code_verifier + code_challenge pair.
 *
 * The verifier is 43-character base64url (256 bits of entropy via 32 random
 * bytes); the challenge is the SHA-256 of the verifier, also base64url. Both
 * are spec-mandated formats — reject any change here without re-reading the
 * IndieAuth + PKCE specs.
 */
export async function generate_pkce(env: IndieAuthEnvironment = default_env): Promise<PkcePair> {
	const verifier_bytes = env.random();
	const code_verifier = base64url_encode(verifier_bytes);
	const challenge_bytes = await env.crypto.subtle.digest(
		'SHA-256',
		new TextEncoder().encode(code_verifier),
	);
	const code_challenge = base64url_encode(new Uint8Array(challenge_bytes));
	return { codeVerifier: code_verifier, codeChallenge: code_challenge };
}

/**
 * Generate a random `state` token for CSRF protection on the auth redirect.
 */
export function generate_state(env: IndieAuthEnvironment = default_env): string {
	return base64url_encode(env.random());
}

/**
 * Build the authorization URL the browser navigates to.
 *
 * Caller is responsible for persisting `state` and `codeVerifier` somewhere
 * the callback handler can retrieve them (sessionStorage is the natural
 * choice — same-tab, cleared on close).
 */
export function build_authorization_url(params: AuthorizationParams): string {
	const u = new URL(params.authorizationEndpoint);
	u.searchParams.set('response_type', 'code');
	u.searchParams.set('client_id', params.clientId);
	u.searchParams.set('redirect_uri', params.redirectUri);
	u.searchParams.set('me', params.me);
	u.searchParams.set('scope', params.scope);
	u.searchParams.set('state', params.state);
	u.searchParams.set('code_challenge', params.codeChallenge);
	u.searchParams.set('code_challenge_method', 'S256');
	return u.toString();
}

/**
 * Exchange an authorization code for a bearer token.
 */
export async function exchange_code_for_token(
	params: ExchangeParams,
	env: IndieAuthEnvironment = default_env,
): Promise<TokenResponse> {
	const body = new URLSearchParams({
		grant_type: 'authorization_code',
		code: params.code,
		client_id: params.clientId,
		redirect_uri: params.redirectUri,
		code_verifier: params.codeVerifier,
	});

	const response = await env.fetch(params.tokenEndpoint, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
			Accept: 'application/json',
		},
		body: body.toString(),
		credentials: 'omit',
	});

	if (!response.ok) {
		const error_text = await response.text();
		throw new Error('exchange_code_for_token: ' + String(response.status) + ' ' + error_text);
	}

	const json = (await response.json()) as {
		access_token?: string;
		token_type?: string;
		scope?: string;
		me?: string;
	};
	if (!json.access_token) {
		throw new Error('exchange_code_for_token: response missing access_token');
	}
	return {
		accessToken: json.access_token,
		tokenType: json.token_type ?? 'Bearer',
		scope: json.scope ?? '',
		me: json.me ?? '',
	};
}

function base64url_encode(bytes: Uint8Array): string {
	let s = '';
	for (const byte of bytes) {
		s += String.fromCharCode(byte);
	}
	return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
