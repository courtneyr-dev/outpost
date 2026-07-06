/**
 * Media metadata lookup client.
 *
 * Calls the Outpost media-lookup proxy at `/wp-json/outpost/v1/lookup`, which
 * dispatches server-side to Post Kinds for IndieWeb's lookup APIs (TMDB,
 * OpenLibrary, MusicBrainz, BoardGameGeek/RAWG, Foursquare/Nominatim) and
 * normalizes the rows to a stable shape.
 *
 * Why a proxy instead of calling Post Kinds directly: managed-WP hosts
 * (GoDaddy's Apache) strip the `Authorization` header, so a bearer-in-header
 * request from the PWA authenticates as anonymous and Post Kinds' edit_posts
 * gate 403s. This mirrors `micropub.ts`: the token rides in a form-encoded
 * `access_token` field, which populates PHP's `$_POST` — the only place
 * IndieAuth's `get_token_from_request()` looks. A JSON body never reaches
 * `$_POST`, so on GoDaddy it stays anonymous and 403s. `credentials: 'omit'`
 * avoids the cookie/nonce collision (a logged-in wp-admin session would
 * otherwise attach `wordpress_logged_in_*` and demand a nonce bearer auth
 * doesn't carry). See CLAUDE.md B1 #8 + the cookie/bearer collision note.
 */

export interface MediaLookupEnvironment {
	fetch: typeof fetch;
}

const default_env: MediaLookupEnvironment = {
	fetch: globalThis.fetch.bind(globalThis),
};

export class MediaLookupError extends Error {
	constructor(
		message: string,
		public readonly code:
			| 'unauthorized'
			| 'post_kinds_inactive'
			| 'not_configured'
			| 'rate_limited'
			| 'server_error'
			| 'fetch_failed',
	) {
		super(message);
		this.name = 'MediaLookupError';
	}
}

/** One normalized lookup result row. */
export interface MediaLookupResult {
	title: string;
	cover: string;
	creator: string;
	year: string;
	externalId: string;
	url: string;
}

export interface LookupMediaParams {
	/** Composer variant or Post Kinds category: watch/read/listen/jam/play/game/checkin (or video/book/music/venue). */
	kind: string;
	query: string;
	/** Movie/TV toggle — only meaningful for the video (Watch) kind. */
	type?: 'movie' | 'tv';
	accessToken: string;
}

const LOOKUP_ENDPOINT = '/wp-json/outpost/v1/lookup';

/**
 * Look up media metadata by title. Returns a (possibly empty) list of
 * normalized results. Throws MediaLookupError on failure — callers surface
 * `post_kinds_inactive` / `not_configured` as friendly hints rather than hard
 * errors.
 */
export async function lookup_media(
	params: LookupMediaParams,
	env: MediaLookupEnvironment = default_env,
): Promise<MediaLookupResult[]> {
	let response: Response;
	try {
		// Cache-bust so managed-WP edge caches (GoDaddy promotes some POST-ish
		// responses) don't serve a stale result for a repeated query.
		const url_with_cache_bust = LOOKUP_ENDPOINT + '?_t=' + String(Date.now());
		// Form-encode the body — NOT JSON — for the same reason micropub.ts does:
		// GoDaddy's Apache strips the Authorization header, so the IndieAuth token
		// must ride as an `access_token` form field. That populates PHP's $_POST,
		// which is what IndieAuth's get_token_from_request() reads to authenticate
		// the WordPress user. The lookup proxy then dispatches internally to Post
		// Kinds (which requires an authenticated user), so a JSON body — which
		// never reaches $_POST — leaves the request unauthenticated on GoDaddy.
		// credentials:'omit' avoids the cookie/nonce collision (see micropub.ts).
		const body = new URLSearchParams();
		body.append('kind', params.kind);
		body.append('q', params.query);
		body.append('access_token', params.accessToken);
		if (params.type) {
			body.append('type', params.type);
		}
		response = await env.fetch(url_with_cache_bust, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				Authorization: 'Bearer ' + params.accessToken,
				'Content-Type': 'application/x-www-form-urlencoded',
				Accept: 'application/json',
			},
			body: body.toString(),
		});
	} catch (err) {
		throw new MediaLookupError(
			'lookup_media: network error — ' + (err instanceof Error ? err.message : String(err)),
			'fetch_failed',
		);
	}

	if (response.status === 401 || response.status === 403) {
		throw new MediaLookupError(
			'The lookup was rejected. Sign out and back in to refresh your token.',
			'unauthorized',
		);
	}
	if (response.status === 501) {
		throw new MediaLookupError(
			'Post Kinds for IndieWeb must be active to look up media.',
			'post_kinds_inactive',
		);
	}
	if (response.status === 429) {
		throw new MediaLookupError('Too many lookups. Try again in a minute.', 'rate_limited');
	}
	if (!response.ok) {
		throw new MediaLookupError(
			'The lookup service returned ' + String(response.status) + '.',
			'server_error',
		);
	}

	const json = (await response.json()) as {
		results?: unknown;
		notConfigured?: boolean;
	};

	if (json.notConfigured === true) {
		throw new MediaLookupError(
			'This provider is not configured. Add its API key in Post Kinds → API Connections.',
			'not_configured',
		);
	}

	const rows = Array.isArray(json.results) ? json.results : [];
	return rows.map(to_result);
}

/** Coerce one server row into the client result shape (external_id → externalId). */
function to_result(row: unknown): MediaLookupResult {
	const r = (row ?? {}) as Record<string, unknown>;
	const str = (value: unknown): string => (typeof value === 'string' ? value : '');
	return {
		title: str(r['title']),
		cover: str(r['cover']),
		creator: str(r['creator']),
		year: str(r['year']),
		externalId: str(r['external_id']),
		url: str(r['url']),
	};
}
