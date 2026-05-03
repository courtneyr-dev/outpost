/**
 * Server-side preview client.
 *
 * Calls the Outpost preview endpoint (B2) at `/wp-json/outpost/v1/preview`
 * with the user's IndieAuth bearer token. The endpoint fetches the target
 * URL server-side (defending against SSRF, oversized responses, and
 * non-HTML content types) and returns sanitized HTML.
 *
 * On the client side, we extract the page `<title>` via regex for basic
 * citation context. Richer microformats parsing (h-card author, e-content
 * excerpt) lands in C1b when the reply variants beyond plain-Reply (Like,
 * Repost, Bookmark, RSVP, Follow) need it.
 *
 * Doing this server-side rather than client-side: cross-origin fetch is
 * blocked by CORS for arbitrary URLs. The preview endpoint is the
 * same-origin proxy that lets the PWA enrich citations.
 */

import { is_safe_http_url } from './url-validation';

export interface PreviewEnvironment {
	fetch: typeof fetch;
}

const default_env: PreviewEnvironment = {
	fetch: globalThis.fetch.bind(globalThis),
};

export class PreviewError extends Error {
	constructor(
		message: string,
		public readonly code:
			| 'unauthorized'
			| 'invalid_url'
			| 'fetch_failed'
			| 'unsupported_content_type'
			| 'rate_limited'
			| 'server_error',
	) {
		super(message);
		this.name = 'PreviewError';
	}
}

export interface PreviewResult {
	html: string;
	finalUrl: string;
	contentType: string;
	/** Page title extracted via regex from <title>...</title>; null when not present. */
	title: string | null;
}

export interface FetchPreviewParams {
	url: string;
	accessToken: string;
}

const PREVIEW_ENDPOINT = '/wp-json/outpost/v1/preview';

/**
 * Fetch a preview from the Outpost preview endpoint.
 *
 * Validates the URL client-side (fast feedback before round-trip), POSTs
 * to the endpoint with the bearer token, parses the JSON response, and
 * extracts the page title from the returned HTML.
 */
export async function fetch_preview(
	params: FetchPreviewParams,
	env: PreviewEnvironment = default_env,
): Promise<PreviewResult> {
	if (!is_safe_http_url(params.url)) {
		throw new PreviewError(
			'Target URL must be http:// or https:// (got: ' + params.url + ').',
			'invalid_url',
		);
	}

	let response: Response;
	try {
		// Cache-bust query parameter only — `_t=<timestamp>` defeats Cloudflare's
		// URL-keyed cache (critical for staging mirrors that share Cloudflare with
		// production where the staging edge cache can't be purged independently).
		// The bearer was previously also in the query string for managed-WP hosts
		// that strip the Authorization header (GoDaddy's Apache config dropping
		// HTTP_AUTHORIZATION). That created a token-leak path through web-server
		// access logs, browser history, and Cloudflare cache keys. Token now lives
		// in the body's `access_token` field (Micropub spec compliant) so the same
		// stripping fallback works without leaking through URLs.
		const url_with_cache_bust = PREVIEW_ENDPOINT + '?_t=' + String(Date.now());
		response = await env.fetch(url_with_cache_bust, {
			method: 'POST',
			credentials: 'include',
			headers: {
				Authorization: 'Bearer ' + params.accessToken,
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			body: JSON.stringify({
				url: params.url,
				access_token: params.accessToken,
			}),
		});
	} catch (err) {
		throw new PreviewError(
			'fetch_preview: network error — ' +
				(err instanceof Error ? err.message : String(err)),
			'fetch_failed',
		);
	}

	if (response.status === 401 || response.status === 403) {
		throw new PreviewError(
			'Preview request was rejected. Sign out and back in to refresh your token.',
			'unauthorized',
		);
	}
	if (response.status === 415) {
		throw new PreviewError(
			'The target URL did not return HTML.',
			'unsupported_content_type',
		);
	}
	if (response.status === 429) {
		throw new PreviewError('Too many preview requests. Try again in a minute.', 'rate_limited');
	}
	if (!response.ok) {
		throw new PreviewError(
			'Preview server returned ' + String(response.status) + '.',
			response.status >= 500 ? 'server_error' : 'fetch_failed',
		);
	}

	const json = (await response.json()) as {
		html: string;
		finalUrl: string;
		contentType: string;
	};

	return {
		html: json.html,
		finalUrl: json.finalUrl,
		contentType: json.contentType,
		title: extract_title(json.html),
	};
}

/**
 * Extract the page title from an HTML body.
 *
 * Naive regex-based — doesn't handle pathological cases like <title> inside
 * <script> blocks (which the server already strips). For typical IndieWeb
 * posts and personal sites this is sufficient.
 *
 * Richer microformats parsing (h-entry name, p-summary, h-card) lands when
 * a reply variant needs it. For plain Reply mode, the page title is enough
 * citation context.
 */
export function extract_title(html: string): string | null {
	const match = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
	if (!match || !match[1]) {
		return null;
	}
	const raw = match[1].trim();
	if (!raw) {
		return null;
	}
	return decode_entities(raw);
}

/**
 * Decode the most common HTML entities via pure string replacement.
 *
 * Page titles use a narrow set of entities (`&amp;`, `&quot;`, etc.) and
 * occasional numeric character references. A regex-based decoder covers
 * 99%+ of real-world cases without DOM round-tripping. Doesn't decode
 * obscure named entities (`&hellip;`, `&copy;`, …); those rarely appear
 * in titles and the raw `&hellip;` rendering is acceptable as fallback.
 */
function decode_entities(text: string): string {
	const named: Record<string, string> = {
		'&amp;': '&',
		'&lt;': '<',
		'&gt;': '>',
		'&quot;': '"',
		'&apos;': "'",
		'&nbsp;': ' ',
	};
	let out = text;
	for (const [entity, replacement] of Object.entries(named)) {
		out = out.split(entity).join(replacement);
	}
	out = out.replace(/&#39;/g, "'");
	out = out.replace(/&#(\d+);/g, (_match, dec: string) => String.fromCharCode(parseInt(dec, 10)));
	out = out.replace(/&#x([0-9a-f]+);/gi, (_match, hex: string) =>
		String.fromCharCode(parseInt(hex, 16)),
	);
	return out;
}
