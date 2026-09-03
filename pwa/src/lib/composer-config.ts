/**
 * Composer-config client.
 *
 * Phase C5. Calls /wp-json/outpost/v1/composer-config to discover which
 * companion plugins are active and what optional fields the More pull-out
 * should render. Cached in App component state for the session — the
 * answer doesn't change between the user logging in and them posting,
 * and refetching on every mode switch would be wasteful.
 *
 * Mirrors the shape returned by Outpost_Composer_Config_Endpoint.
 */

export type CompanionStatus = 'active' | 'inactive' | 'absent';

export type CompanionId =
	| 'post-kinds'
	| 'post-formats'
	| 'xfn'
	| 'syndication-links'
	| 'yoast'
	| 'activitypub'
	| 'accessibility-checker'
	| 'rss-chat-routing';

export interface TermSuggestion {
	slug: string;
	name: string;
}

export interface BridgyTarget {
	name: string;
	uid: string;
}

export interface SiteSettings {
	bridgyAutoSuggest: boolean;
	defaultPostVariant: 'note' | 'status' | 'aside' | 'article' | 'quote';
	/** Category names pre-selected in More options (Outpost > Settings > Composer defaults). Absent on older servers. */
	defaultCategories?: string[];
	/** Tag names pre-selected in More options. Absent on older servers. */
	defaultTags?: string[];
}

export interface ComposerConfig {
	companions: Record<CompanionId, CompanionStatus>;
	postFormats: string[] | null;
	xfnRels: string[];
	existingCategories: TermSuggestion[];
	existingTags: TermSuggestion[];
	bridgyHostMap: Record<string, BridgyTarget>;
	siteSettings: SiteSettings;
}

export interface ComposerConfigEnvironment {
	fetch: typeof fetch;
}

const default_env: ComposerConfigEnvironment = {
	fetch: globalThis.fetch.bind(globalThis),
};

export class ComposerConfigError extends Error {
	constructor(
		message: string,
		public readonly code: 'unauthorized' | 'fetch_failed' | 'invalid_response',
	) {
		super(message);
		this.name = 'ComposerConfigError';
	}
}

const ENDPOINT_PATH = '/wp-json/outpost/v1/composer-config';

/**
 * Fetch the composer-config from the same-origin endpoint.
 *
 * Uses bearer auth (the IndieAuth plugin's REST middleware translates
 * the Authorization header into a current_user, so the WP cap check on
 * the endpoint side accepts it).
 */
export async function fetch_composer_config(
	access_token: string,
	env: ComposerConfigEnvironment = default_env,
): Promise<ComposerConfig> {
	// The bearer travels in the Authorization header AND the request body:
	//   - The header works on hosts that pass it through to PHP.
	//   - Managed-WP hosts (GoDaddy) strip the Authorization header, so the
	//     token also rides in the JSON body's `access_token` field
	//     (Micropub-spec), which the endpoint reads when the header is gone.
	//     This is a POST for that reason — a GET can't carry a body reliably —
	//     and the token stays out of the query string, so it never lands in
	//     access logs, referrers, or CDN cache keys.
	//   - `credentials: 'omit'` sends no wp-admin cookie: this request is
	//     authenticated purely by the token, so it never trips WordPress's
	//     cookie/nonce CSRF check.
	//   - `_t=<timestamp>` keeps every request URL unique so an edge cache
	//     can't serve a stale response (e.g. a 429 from a prior window).
	const url = ENDPOINT_PATH + '?_t=' + String(Date.now());
	let response: Response;
	try {
		response = await env.fetch(url, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				Authorization: 'Bearer ' + access_token,
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			body: JSON.stringify({ access_token }),
		});
	} catch (err) {
		throw new ComposerConfigError(
			'fetch_composer_config: fetch threw — ' +
				(err instanceof Error ? err.message : String(err)),
			'fetch_failed',
		);
	}

	if (response.status === 401 || response.status === 403) {
		throw new ComposerConfigError(
			'fetch_composer_config: unauthorized (' + String(response.status) + ')',
			'unauthorized',
		);
	}

	if (!response.ok) {
		throw new ComposerConfigError(
			'fetch_composer_config: server returned ' + String(response.status),
			'fetch_failed',
		);
	}

	let body: unknown;
	try {
		body = await response.json();
	} catch (err) {
		throw new ComposerConfigError(
			'fetch_composer_config: response body was not JSON — ' +
				(err instanceof Error ? err.message : String(err)),
			'invalid_response',
		);
	}

	if (!is_composer_config(body)) {
		throw new ComposerConfigError(
			'fetch_composer_config: response did not match expected shape',
			'invalid_response',
		);
	}

	return body;
}

function is_composer_config(value: unknown): value is ComposerConfig {
	if (!value || typeof value !== 'object') return false;
	const v = value as Record<string, unknown>;
	if (!v.companions || typeof v.companions !== 'object') return false;
	if (v.postFormats !== null && !Array.isArray(v.postFormats)) return false;
	if (!Array.isArray(v.xfnRels)) return false;
	if (!Array.isArray(v.existingCategories)) return false;
	if (!Array.isArray(v.existingTags)) return false;
	if (!v.bridgyHostMap || typeof v.bridgyHostMap !== 'object') return false;
	if (!v.siteSettings || typeof v.siteSettings !== 'object') return false;
	return true;
}

/**
 * Post Kinds kind slugs Outpost's composer variants map onto. Mirrors the
 * `default_kinds` registry in Post Kinds for IndieWeb's class-taxonomy.php.
 */
export type PostKindSlug =
	| 'note'
	| 'article'
	| 'reply'
	| 'like'
	| 'repost'
	| 'bookmark'
	| 'rsvp'
	| 'checkin'
	| 'listen'
	| 'watch'
	| 'read'
	| 'event'
	| 'photo'
	| 'video'
	| 'review'
	| 'favorite'
	| 'jam'
	| 'wish'
	| 'mood'
	| 'acquisition'
	| 'drink'
	| 'eat'
	| 'recipe'
	| 'play'
	| 'audio'
	| 'quote'
	| 'tag'
	| 'weather'
	| 'exercise'
	| 'trip'
	| 'itinerary'
	| 'follow'
	| 'issue'
	| 'question'
	| 'sleep'
	| 'craft';

/**
 * Spreadable `pkiw-kind` hint for h-entry submissions.
 *
 * Returns the explicit-kind vendor property when the Post Kinds companion
 * is active on the site, and an empty object otherwise — other Micropub
 * servers should not receive a vendor property they can't act on. Usage:
 *
 *   const base: HEntryProperties = {
 *     ...,
 *     ...pkiw_kind_hint(composerConfig, 'jam'),
 *   };
 */
export function pkiw_kind_hint(
	config: ComposerConfig | undefined,
	kind: PostKindSlug,
): { 'pkiw-kind'?: string } {
	return config?.companions['post-kinds'] === 'active' ? { 'pkiw-kind': kind } : {};
}
