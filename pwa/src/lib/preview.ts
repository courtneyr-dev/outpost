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

/**
 * Source-aware preview result (F5+). Returned when the request includes
 * `source_id` and a registered source claims the URL.
 *
 * Shape mirrors the server response from `Outpost_Preview_Endpoint`:
 *
 *   { source_url, source_id, extracted: {...h-entry props...},
 *     raw: <recipe-specific>, warnings: [...] }
 *
 * The `extracted` object is mapped per source: Spotify → p-name (track
 * title), u-photo (album art), p-publication, u-listen-of. YouTube → adds
 * p-author (channel name). See `Outpost_Source_*` adapters for per-source
 * mapping.
 */
export interface SourcePreviewResult {
	sourceUrl: string;
	sourceId: string;
	extracted: Record<string, string>;
	raw: unknown;
	warnings: string[];
}

export interface FetchPreviewParams {
	url: string;
	accessToken: string;
	/** Optional: source adapter id (e.g. 'spotify'). Triggers source-aware extraction. */
	sourceId?: string;
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
				...(params.sourceId ? { source_id: params.sourceId } : {}),
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
 * Source-aware variant of fetch_preview. POSTs `{url, source_id,
 * access_token}` to /preview; the endpoint dispatches the URL to the
 * named source adapter, runs its extractor recipe (oEmbed / og_tags /
 * mf2 / rss / api_json / api_xml / composite), and returns the mapped
 * h-entry properties under `extracted`.
 *
 * Used by ListenMode + future PhotoMode share-target consumption to
 * pre-fill metadata (track title, artist, cover) when an FX iOS
 * Shortcut or F6 Web Share Target dispatches with `source_id=spotify`
 * (or youtube/twitch/etc.).
 *
 * Throws the same PreviewError codes as fetch_preview. Callers
 * typically swallow errors silently — pre-fill is best-effort and the
 * user can manually fill fields.
 */
export async function fetch_source_preview(
	params: FetchPreviewParams,
	env: PreviewEnvironment = default_env,
): Promise<SourcePreviewResult> {
	if (!params.sourceId) {
		throw new PreviewError(
			'fetch_source_preview requires a sourceId',
			'invalid_url',
		);
	}

	if (!is_safe_http_url(params.url)) {
		throw new PreviewError(
			'Target URL must be http:// or https:// (got: ' + params.url + ').',
			'invalid_url',
		);
	}

	let response: Response;
	try {
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
				source_id: params.sourceId,
				access_token: params.accessToken,
			}),
		});
	} catch (err) {
		throw new PreviewError(
			'fetch_source_preview: network error — ' +
				(err instanceof Error ? err.message : String(err)),
			'fetch_failed',
		);
	}

	if (response.status === 401 || response.status === 403) {
		throw new PreviewError(
			'Source preview request was rejected. Sign out and back in to refresh your token.',
			'unauthorized',
		);
	}
	if (response.status === 415) {
		throw new PreviewError('Source URL returned an unsupported content type.', 'unsupported_content_type');
	}
	if (response.status === 429) {
		throw new PreviewError('Too many preview requests. Try again in a minute.', 'rate_limited');
	}
	if (!response.ok) {
		throw new PreviewError(
			'Source preview fetch failed with HTTP ' + String(response.status) + '.',
			'server_error',
		);
	}

	const json = (await response.json()) as {
		source_url?: string;
		source_id?: string;
		extracted?: Record<string, string>;
		raw?: unknown;
		warnings?: string[];
		// Endpoint may also return the legacy {html, finalUrl, contentType} shape
		// when source_id matches Source_Unknown — caller should handle that as
		// "no extraction available".
		html?: string;
	};

	if (json.html !== undefined && json.extracted === undefined) {
		throw new PreviewError(
			'Source returned legacy preview shape (no extracted h-entry).',
			'server_error',
		);
	}

	return {
		sourceUrl: json.source_url ?? params.url,
		sourceId: json.source_id ?? params.sourceId,
		extracted: json.extracted ?? {},
		raw: json.raw,
		warnings: json.warnings ?? [],
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
