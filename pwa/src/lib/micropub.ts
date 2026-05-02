/**
 * Micropub client for the Outpost PWA.
 *
 * Implements the browser side of posting via Micropub
 * (https://micropub.spec.indieweb.org/):
 *   1. Discovery — GET the user's "me" URL, find the micropub endpoint via
 *      Link header or HTML <link rel="micropub">. Reuses the parse helpers
 *      from indieauth.ts since the discovery shape is identical.
 *   2. Post — POST form-encoded body to the micropub endpoint with the
 *      bearer token, parse the Location header for the URL of the new post.
 *
 * Form-encoded vs JSON: B1 uses form-encoded (`h=entry&content=...`). It's
 * the simplest valid Micropub request and most servers accept it. JSON
 * Micropub (richer microformat shapes for replies/photos) lands when
 * Phase C's modes need it.
 *
 * MicropubEnvironment follows the B0a-locked injectable env pattern so
 * unit tests stub `fetch` deterministically without touching globals.
 */

import { parse_link_header, parse_html_endpoints } from './indieauth';
import { is_safe_http_url } from './url-validation';

export interface MicropubEnvironment {
	fetch: typeof fetch;
}

const default_env: MicropubEnvironment = {
	fetch: globalThis.fetch.bind(globalThis),
};

export class MicropubError extends Error {
	constructor(
		message: string,
		public readonly code:
			| 'discovery_failed'
			| 'no_endpoint'
			| 'post_failed'
			| 'no_location'
			| 'invalid_location',
	) {
		super(message);
		this.name = 'MicropubError';
	}
}

export interface PostNoteParams {
	content: string;
	accessToken: string;
	micropubEndpoint: string;
}

/**
 * Form-encodable h-entry properties. Add fields here as composer modes
 * need them; everything is optional so each mode populates only what it
 * needs.
 *
 * Per the Micropub spec, array-valued properties (like `category`) get
 * encoded with `[]` suffix in the form body; scalars are plain key=value.
 */
export interface HEntryProperties {
	content?: string;
	name?: string;
	summary?: string;
	'in-reply-to'?: string;
	'like-of'?: string;
	'repost-of'?: string;
	'bookmark-of'?: string;
	category?: string[];
	'mp-syndicate-to'?: string[];
}

export interface PostHEntryParams {
	properties: HEntryProperties;
	accessToken: string;
	micropubEndpoint: string;
}

export interface PostNoteResult {
	location: string;
}

/**
 * Discover the micropub endpoint for a "me" URL.
 *
 * First-wins semantics matching indieauth.ts's discover_endpoints:
 *   - Link: header beats <link rel> beats <a rel>
 *   - Multiple `rel` values within one link element each map to the URL
 */
export async function discover_micropub_endpoint(
	me: string,
	env: MicropubEnvironment = default_env,
): Promise<string> {
	let response: Response;
	try {
		response = await env.fetch(me, {
			method: 'GET',
			redirect: 'follow',
			headers: { Accept: 'text/html' },
		});
	} catch (err) {
		throw new MicropubError(
			'discover_micropub_endpoint: fetch threw — ' +
				(err instanceof Error ? err.message : String(err)),
			'discovery_failed',
		);
	}

	if (!response.ok) {
		throw new MicropubError(
			'discover_micropub_endpoint: fetch failed with status ' + String(response.status),
			'discovery_failed',
		);
	}

	const link_header = response.headers.get('link') ?? response.headers.get('Link');
	const from_header = link_header ? parse_link_header(link_header) : {};

	const html = await response.text();
	const from_html = parse_html_endpoints(html);

	const micropub_endpoint = from_header['micropub'] ?? from_html['micropub'];
	if (!micropub_endpoint) {
		throw new MicropubError(
			'discover_micropub_endpoint: no micropub endpoint found for ' + me,
			'no_endpoint',
		);
	}

	return micropub_endpoint;
}

/**
 * Post an h-entry to the micropub endpoint with arbitrary properties.
 *
 * Body shape: form-encoded. `h=entry` is added automatically. Each property
 * value becomes a key in the form body; arrays get `[]` suffix per the
 * Micropub spec (`category[]=tag1&category[]=tag2`).
 *
 * The server responds with 201 Created or 202 Accepted (both success); both
 * return a Location header pointing at the new post.
 *
 * Used by Note (content only), Reply (content + in-reply-to), and future
 * modes that build richer h-entry shapes (Like, Repost, Bookmark, RSVP,
 * Follow, Article).
 */
export async function post_h_entry(
	params: PostHEntryParams,
	env: MicropubEnvironment = default_env,
): Promise<PostNoteResult> {
	const body = new URLSearchParams();
	body.append('h', 'entry');

	for (const [key, value] of Object.entries(params.properties)) {
		if (value === undefined || value === null) continue;
		if (Array.isArray(value)) {
			for (const item of value) {
				body.append(key + '[]', item);
			}
		} else {
			body.append(key, value);
		}
	}

	let response: Response;
	try {
		response = await env.fetch(params.micropubEndpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				Authorization: 'Bearer ' + params.accessToken,
				Accept: 'application/json',
			},
			body: body.toString(),
		});
	} catch (err) {
		throw new MicropubError(
			'post_h_entry: fetch threw — ' +
				(err instanceof Error ? err.message : String(err)),
			'post_failed',
		);
	}

	if (response.status !== 201 && response.status !== 202) {
		const error_text = await safe_read_text(response);
		throw new MicropubError(
			'post_h_entry: micropub endpoint returned ' +
				String(response.status) +
				(error_text ? ' — ' + error_text : ''),
			'post_failed',
		);
	}

	const location = response.headers.get('location') ?? response.headers.get('Location');
	if (!location) {
		throw new MicropubError(
			'post_h_entry: micropub response is missing the Location header',
			'no_location',
		);
	}

	// Defense in depth: a compromised Micropub endpoint or MitM could return
	// a `javascript:` / `data:` / `file:` Location, which the UI would then
	// render as a clickable <a href>. Validate the scheme before surfacing.
	if (!is_safe_http_url(location)) {
		throw new MicropubError(
			'post_h_entry: micropub returned an unsafe Location URL: ' + location,
			'invalid_location',
		);
	}

	return { location };
}

/**
 * Post a note (h-entry with content only). Thin wrapper around
 * `post_h_entry` for backward compatibility with B1 callers.
 */
export async function post_note(
	params: PostNoteParams,
	env: MicropubEnvironment = default_env,
): Promise<PostNoteResult> {
	return post_h_entry(
		{
			properties: { content: params.content },
			accessToken: params.accessToken,
			micropubEndpoint: params.micropubEndpoint,
		},
		env,
	);
}

async function safe_read_text(response: Response): Promise<string> {
	try {
		return await response.text();
	} catch {
		return '';
	}
}
