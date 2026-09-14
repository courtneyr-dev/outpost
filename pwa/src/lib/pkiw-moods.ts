/**
 * Mood suggestions from Post Kinds for IndieWeb.
 *
 * Post Kinds owns the mood vocabulary and the site's "Mood label spelling"
 * setting (PKIW #207). It serves the resolved labels at
 * `/wp-json/post-kinds-indieweb/v1/moods` (edit_posts):
 *
 *   { "spelling", "locale", "version", "moods": [ { "key", "label", "variants" } ] }
 *
 * Outpost shows `moods[].label` as suggestions on the Life > Mood field and
 * keeps no mood list or spelling map of its own. The field stays free text,
 * and whatever the person types or picks is sent unchanged as Micropub `mood`.
 *
 * Auth: the route is GET-only, and IndieAuth reads a token only from the
 * Authorization header or a form-encoded POST `access_token`. Managed-WP
 * hosts (GoDaddy) strip the header, so the request is a POST carrying the
 * token in the body with `?_method=GET`, which WordPress's REST server
 * dispatches as the GET route. The token never goes in the URL, and
 * `credentials: 'omit'` keeps the wp-admin cookie out of it.
 *
 * Every failure degrades to plain free text. 401, 403 and 404 (older Post
 * Kinds without the route) and a response that doesn't match the contract
 * mean "no suggestions"; a network error or 5xx keeps the last good copy so
 * a composer opened offline still suggests.
 */

import { useEffect, useState } from 'preact/hooks';

export interface PkiwMood {
	key: string;
	label: string;
	variants: string[];
}

export interface PkiwMoodsResponse {
	spelling: string;
	locale: string;
	version: string;
	moods: PkiwMood[];
}

export interface MoodsEnvironment {
	fetch: typeof fetch;
}

export type MoodsFetchResult =
	| { kind: 'ok'; data: PkiwMoodsResponse }
	/** The site answered and has no vocabulary for this token: 401, 403, 404 or an off-contract body. */
	| { kind: 'unsupported' }
	/** The site couldn't be reached or failed: keep whatever copy we have. */
	| { kind: 'unavailable' };

export const MOODS_PATH = '/wp-json/post-kinds-indieweb/v1/moods';
export const MOODS_CACHE_KEY = 'outpost.pkiw.moods';

const default_env: MoodsEnvironment = {
	fetch: (...args) => globalThis.fetch(...args),
};

export async function fetch_pkiw_moods(
	access_token: string,
	env: MoodsEnvironment = default_env,
): Promise<MoodsFetchResult> {
	const url = MOODS_PATH + '?_method=GET&_t=' + String(Date.now());
	let response: Response;
	try {
		response = await env.fetch(url, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				Authorization: 'Bearer ' + access_token,
				'Content-Type': 'application/x-www-form-urlencoded',
				Accept: 'application/json',
			},
			body: new URLSearchParams({ access_token }).toString(),
		});
	} catch {
		return { kind: 'unavailable' };
	}

	if (response.status === 401 || response.status === 403 || response.status === 404) {
		return { kind: 'unsupported' };
	}
	if (!response.ok) {
		return { kind: 'unavailable' };
	}

	let body: unknown;
	try {
		body = await response.json();
	} catch {
		return { kind: 'unsupported' };
	}
	return is_moods_response(body) ? { kind: 'ok', data: body } : { kind: 'unsupported' };
}

function is_string_array(value: unknown): value is string[] {
	return Array.isArray(value) && value.every((item) => typeof item === 'string');
}

function is_mood(value: unknown): value is PkiwMood {
	if (!value || typeof value !== 'object') return false;
	const v = value as Record<string, unknown>;
	return typeof v.key === 'string' && typeof v.label === 'string' && is_string_array(v.variants);
}

export function is_moods_response(value: unknown): value is PkiwMoodsResponse {
	if (!value || typeof value !== 'object') return false;
	const v = value as Record<string, unknown>;
	return (
		typeof v.spelling === 'string' &&
		typeof v.locale === 'string' &&
		typeof v.version === 'string' &&
		v.version !== '' &&
		Array.isArray(v.moods) &&
		v.moods.every(is_mood)
	);
}

/** The last good response, or null when none is stored or storage is unavailable. */
export function read_cached_moods(): PkiwMoodsResponse | null {
	try {
		const raw = globalThis.localStorage?.getItem(MOODS_CACHE_KEY);
		if (!raw) return null;
		const parsed: unknown = JSON.parse(raw);
		return is_moods_response(parsed) ? parsed : null;
	} catch {
		return null;
	}
}

export function write_cached_moods(data: PkiwMoodsResponse | null): void {
	try {
		if (data) {
			globalThis.localStorage?.setItem(MOODS_CACHE_KEY, JSON.stringify(data));
		} else {
			globalThis.localStorage?.removeItem(MOODS_CACHE_KEY);
		}
	} catch {
		// Storage can throw in private browsing; suggestions just won't survive a reload.
	}
}

/** Labels in Post Kinds' display order, exactly as served. */
export function mood_labels(data: PkiwMoodsResponse | null): string[] {
	if (!data) return [];
	const seen = new Set<string>();
	for (const mood of data.moods) {
		if (mood.label !== '') seen.add(mood.label);
	}
	return Array.from(seen);
}

/**
 * Whether Post Kinds is active, as far as the composer knows. `unknown` means
 * composer-config hasn't loaded, which is the normal state of a composer
 * opened offline.
 */
export type PkiwStatus = 'active' | 'inactive' | 'unknown';

/**
 * Mood suggestions for the composer.
 *
 * - `active`: starts from the stored copy, asks the site on mount and when
 *   the browser comes back online, and replaces the copy when `version`
 *   differs.
 * - `unknown`: the stored copy only, with no request.
 * - `inactive`: none.
 */
export function useMoodSuggestions(
	status: PkiwStatus,
	access_token: string,
	env?: MoodsEnvironment,
): string[] {
	const [data, setData] = useState<PkiwMoodsResponse | null>(read_cached_moods);
	const [attempt, setAttempt] = useState(0);
	const active = status === 'active';

	useEffect(() => {
		if (!active) return undefined;
		const on_online = (): void => setAttempt((n) => n + 1);
		window.addEventListener('online', on_online);
		return (): void => window.removeEventListener('online', on_online);
	}, [active]);

	useEffect(() => {
		if (!active) return undefined;
		let cancelled = false;
		void fetch_pkiw_moods(access_token, env).then((result) => {
			if (cancelled) return;
			if (result.kind === 'ok') {
				setData((current) => {
					if (current?.version === result.data.version) return current;
					write_cached_moods(result.data);
					return result.data;
				});
			} else if (result.kind === 'unsupported') {
				write_cached_moods(null);
				setData(null);
			}
		});
		return (): void => {
			cancelled = true;
		};
	}, [active, access_token, env, attempt]);

	return status === 'inactive' ? [] : mood_labels(data);
}
