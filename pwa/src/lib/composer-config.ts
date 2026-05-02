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
	| 'accessibility-checker';

export interface TermSuggestion {
	slug: string;
	name: string;
}

export interface ComposerConfig {
	companions: Record<CompanionId, CompanionStatus>;
	postFormats: string[] | null;
	xfnRels: string[];
	existingCategories: TermSuggestion[];
	existingTags: TermSuggestion[];
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
	let response: Response;
	try {
		response = await env.fetch(ENDPOINT_PATH, {
			method: 'GET',
			headers: {
				Authorization: 'Bearer ' + access_token,
				Accept: 'application/json',
			},
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
	return true;
}
