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
	'follow-of'?: string;
	'listen-of'?: string;
	'watch-of'?: string;
	'read-of'?: string;
	'play-of'?: string;
	location?: string;
	rsvp?: 'yes' | 'no' | 'maybe' | 'interested';
	/** Photo URL — string for single-photo posts, string[] for galleries. */
	photo?: string | string[];
	/**
	 * Per David Shanske's Micropub plugin convention. Parallel array to `photo`
	 * when `photo` is an array; otherwise a string.
	 */
	'mp-photo-alt'?: string | string[];
	category?: string[];
	'mp-syndicate-to'?: string[];
	/** Optional permalink slug. Native Micropub spec property. */
	'mp-slug'?: string;
	/** Outpost-specific: WordPress Post Format. Bridge maps to wp_set_post_format. */
	'mp-post-format'?: string;
	/** Outpost-specific: Yoast focus keyphrase. Bridge writes _yoast_wpseo_focuskw postmeta. */
	'mp-yoast-focuskw'?: string;
	/** Outpost-specific: XFN relationships for the in-reply-to / target URL. */
	'mp-xfn'?: string[];
	/** Outpost-specific: target URL for the XFN rels. */
	'mp-xfn-target'?: string;
	/** Outpost-specific: WP category names (auto-create new ones via the bridge). */
	'mp-categories'?: string[];
	/** Read kinds (Post Kinds): to-read | reading | finished. */
	'read-status'?: 'to-read' | 'reading' | 'finished';
	/**
	 * Author / artist / director / etc. h-card name. Shipped as a flat string
	 * and Post Kinds normalizes it server-side. For richer authorship (URL,
	 * photo), Post Kinds also reads the target URL's mf2.
	 */
	author?: string;
	/** 1–5 rating for Read / Watch / Listen / Play. Some IndieWeb sites use
	 *  1–10; Outpost defaults to 1–5 because it matches more downstream
	 *  rendering plugins out of the box. */
	rating?: string;
	/** Mood: textual description of the author's mood at posting time. */
	mood?: string;
	/** Weather: textual description of weather conditions. */
	weather?: string;
	/** Exercise: textual description of activity. */
	exercise?: string;
	/** Eat: name/description of food (Post Kinds: `eat-of`). */
	'eat-of'?: string;
	/** Drink: name/description of beverage (Post Kinds: `drink-of`). */
	'drink-of'?: string;
	/** Wishlist target URL (Post Kinds: `wishlist-of`). */
	'wishlist-of'?: string;
	/** Tag target URL (Post Kinds: `tag-of`). */
	'tag-of'?: string;
	/** Issue target URL (Post Kinds: `issue-of`). */
	'issue-of'?: string;
	/** Recipe: list of ingredients (Schema.org / h-recipe `ingredient`). */
	ingredient?: string[];
	/** Recipe: list of instruction steps. */
	instructions?: string[];
	/** Recipe: serving yield (e.g. "4 servings"). */
	yield?: string;
	/** Recipe: total cooking time in ISO 8601 duration (e.g. "PT45M"). */
	duration?: string;
}

export interface SyndicationTarget {
	uid: string;
	name: string;
}

export interface PostHEntryParams {
	properties: HEntryProperties;
	accessToken: string;
	micropubEndpoint: string;
}

export interface PostNoteResult {
	/**
	 * URL of the created post, parsed from the Micropub server's `Location`
	 * response header. Per the Micropub spec the server MUST return Location
	 * on success — but some plugin configurations omit it on edge cases
	 * (multi-photo galleries against David Shanske's WP Micropub plugin
	 * have surfaced this). Treated as a non-fatal omission: the post still
	 * succeeded (we got 201/202), so the UI should announce success and
	 * skip the "view your post" link rather than fail loudly.
	 */
	location?: string;
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
			// Omit cookies. Outpost's API calls are bearer-authenticated; sending
			// the wp-admin cookie alongside trips WordPress's REST nonce check
			// (rest_cookie_check_errors), which silently unsets the bearer-resolved
			// user and cascades into a Micropub 403.
			credentials: 'omit',
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

	// Belt-and-suspenders: per the Micropub spec the access_token can be sent
	// either in the Authorization header OR in an access_token form parameter.
	// Some hosts (Apache without HTTP_AUTHORIZATION forwarding, certain WAFs)
	// strip the Authorization header before WP sees it; sending both means the
	// body parameter is a fallback if the header is dropped. Servers that
	// receive both prefer the header (per spec) and ignore the body copy.
	body.append('access_token', params.accessToken);

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
			credentials: 'omit',
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
		// Spec violation but the post did succeed (we got 201/202). Soft-fail:
		// caller renders a generic "posted" success without a link. See
		// PostNoteResult docblock for context on which servers omit Location.
		return {};
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

/**
 * Discover the Micropub media endpoint via `?q=config`.
 *
 * Per the Micropub spec, querying the micropub endpoint with `?q=config`
 * returns JSON that includes the media-endpoint URL. The bearer token is
 * required (the response is per-user).
 */
export async function discover_media_endpoint(
	micropub_endpoint: string,
	access_token: string,
	env: MicropubEnvironment = default_env,
): Promise<string> {
	const separator = micropub_endpoint.includes('?') ? '&' : '?';
	// Per the Micropub spec, GET queries can carry the bearer in `access_token`.
	// Header is still preferred (and sent below) but the query param is a
	// fallback for hosts that strip Authorization.
	const url =
		micropub_endpoint +
		separator +
		'q=config&access_token=' +
		encodeURIComponent(access_token);
	let response: Response;
	try {
		response = await env.fetch(url, {
			method: 'GET',
			headers: {
				Authorization: 'Bearer ' + access_token,
				Accept: 'application/json',
			},
			credentials: 'omit',
		});
	} catch (err) {
		throw new MicropubError(
			'discover_media_endpoint: fetch threw — ' +
				(err instanceof Error ? err.message : String(err)),
			'discovery_failed',
		);
	}

	if (!response.ok) {
		throw new MicropubError(
			'discover_media_endpoint: ' + String(response.status) + ' from config query',
			'discovery_failed',
		);
	}

	const json = (await response.json()) as { 'media-endpoint'?: string };
	const media_endpoint = json['media-endpoint'];
	if (!media_endpoint || typeof media_endpoint !== 'string') {
		throw new MicropubError(
			'discover_media_endpoint: config response did not include a media-endpoint.',
			'no_endpoint',
		);
	}
	return media_endpoint;
}

/**
 * Discover the user's configured syndication targets via `?q=syndicate-to`.
 *
 * Per the Micropub spec, querying the micropub endpoint with
 * `?q=syndicate-to` returns JSON of the form:
 *
 *   { "syndicate-to": [ { "uid": "https://...", "name": "Display name" }, ... ] }
 *
 * Returns the empty array when no targets are configured; throws
 * MicropubError when the query itself fails.
 */
export async function discover_syndication_targets(
	micropub_endpoint: string,
	access_token: string,
	env: MicropubEnvironment = default_env,
): Promise<SyndicationTarget[]> {
	const separator = micropub_endpoint.includes('?') ? '&' : '?';
	const url =
		micropub_endpoint +
		separator +
		'q=syndicate-to&access_token=' +
		encodeURIComponent(access_token);
	let response: Response;
	try {
		response = await env.fetch(url, {
			method: 'GET',
			headers: {
				Authorization: 'Bearer ' + access_token,
				Accept: 'application/json',
			},
			credentials: 'omit',
		});
	} catch (err) {
		throw new MicropubError(
			'discover_syndication_targets: fetch threw — ' +
				(err instanceof Error ? err.message : String(err)),
			'discovery_failed',
		);
	}

	if (!response.ok) {
		throw new MicropubError(
			'discover_syndication_targets: ' +
				String(response.status) +
				' from syndicate-to query',
			'discovery_failed',
		);
	}

	const json = (await response.json()) as { 'syndicate-to'?: unknown };
	const raw = json['syndicate-to'];
	if (!Array.isArray(raw)) {
		return [];
	}
	const targets: SyndicationTarget[] = [];
	for (const entry of raw) {
		if (entry && typeof entry === 'object') {
			const candidate = entry as { uid?: unknown; name?: unknown };
			if (typeof candidate.uid === 'string' && typeof candidate.name === 'string') {
				targets.push({ uid: candidate.uid, name: candidate.name });
			}
		}
	}
	return targets;
}

export interface UploadMediaParams {
	blob: Blob;
	filename: string;
	accessToken: string;
	mediaEndpoint: string;
}

export interface UploadMediaResult {
	location: string;
}

/**
 * Upload a media blob (photo) to the Micropub media endpoint.
 *
 * Multipart/form-data with `file` field. The endpoint responds with 201
 * Created and a Location header pointing at the uploaded media URL.
 */
export async function upload_media(
	params: UploadMediaParams,
	env: MicropubEnvironment = default_env,
): Promise<UploadMediaResult> {
	const form = new FormData();
	form.append('file', params.blob, params.filename);
	// Same belt-and-suspenders auth as post_h_entry — body fallback in case
	// the Authorization header is being stripped by the host's web server.
	form.append('access_token', params.accessToken);

	let response: Response;
	try {
		response = await env.fetch(params.mediaEndpoint, {
			method: 'POST',
			headers: {
				Authorization: 'Bearer ' + params.accessToken,
				Accept: 'application/json',
			},
			body: form,
			credentials: 'omit',
		});
	} catch (err) {
		throw new MicropubError(
			'upload_media: fetch threw — ' + (err instanceof Error ? err.message : String(err)),
			'post_failed',
		);
	}

	if (response.status !== 201 && response.status !== 202) {
		const error_text = await safe_read_text(response);
		throw new MicropubError(
			'upload_media: media endpoint returned ' +
				String(response.status) +
				(error_text ? ' — ' + error_text : ''),
			'post_failed',
		);
	}

	const location = response.headers.get('location') ?? response.headers.get('Location');
	if (!location) {
		throw new MicropubError(
			'upload_media: media endpoint response is missing the Location header',
			'no_location',
		);
	}
	if (!is_safe_http_url(location)) {
		throw new MicropubError(
			'upload_media: media endpoint returned an unsafe Location URL: ' + location,
			'invalid_location',
		);
	}

	return { location };
}
