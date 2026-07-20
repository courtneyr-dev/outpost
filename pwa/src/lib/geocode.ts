/**
 * Client for Outpost's geocode endpoint (`/wp-json/outpost/v1/geocode`).
 *
 * The Checkin variant in Listen mode needs lat/lon coordinates to construct
 * a `geo:lat,lon` URI for the h-entry's location property. Browsers can't
 * customize User-Agent, and Nominatim's usage policy requires it, so the
 * lookup goes through Outpost's server proxy which handles caching, rate
 * limiting, and identification.
 *
 * Request shape: POST with `q` and `access_token` form fields. Auth follows
 * the same pattern as media lookup — bearer in the Authorization header and
 * request body so managed-WP hosts that strip the header still see the token
 * without exposing it in the URL.
 */

export interface GeocodeResult {
	lat: number;
	lon: number;
	displayName: string;
	/** Nominatim's "type" field — e.g. "city", "park", "restaurant". */
	type: string;
}

export interface GeocodeResponse {
	results: GeocodeResult[];
	cached: boolean;
	attribution: string;
}

export class GeocodeError extends Error {
	constructor(
		message: string,
		public readonly code:
			| 'invalid_query'
			| 'rate_limited'
			| 'unauthorized'
			| 'geocode_failed'
			| 'fetch_failed',
		public readonly retryAfter?: number,
	) {
		super(message);
		this.name = 'GeocodeError';
	}
}

export interface GeocodeEnvironment {
	fetch: typeof fetch;
	location: { origin: string };
}

const default_env: GeocodeEnvironment = {
	fetch: globalThis.fetch.bind(globalThis),
	location: { origin: globalThis.location?.origin ?? '' },
};

export interface GeocodeParams {
	query: string;
	accessToken: string;
}

/**
 * Look up `query` against the geocode endpoint. Returns the parsed result
 * array on success or throws GeocodeError with a discriminating code.
 */
export async function geocode(
	params: GeocodeParams,
	env: GeocodeEnvironment = default_env,
): Promise<GeocodeResponse> {
	const base = env.location.origin || '';
	const url = new URL('/wp-json/outpost/v1/geocode', base || 'http://localhost');
	const body = new URLSearchParams();
	body.append('q', params.query);
	body.append('access_token', params.accessToken);

	let response: Response;
	try {
		response = await env.fetch(url.toString(), {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				Authorization: 'Bearer ' + params.accessToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			credentials: 'omit',
			body: body.toString(),
		});
	} catch (err) {
		throw new GeocodeError(
			'geocode: fetch threw — ' + (err instanceof Error ? err.message : String(err)),
			'fetch_failed',
		);
	}

	if (response.status === 401 || response.status === 403) {
		throw new GeocodeError(
			'geocode: unauthorized (' + String(response.status) + ').',
			'unauthorized',
		);
	}

	if (response.status === 429) {
		const json = (await response.json().catch(() => ({}))) as {
			retryAfter?: number;
			data?: { retryAfter?: number };
		};
		const retry = Number(json.retryAfter ?? json.data?.retryAfter ?? 60);
		throw new GeocodeError(
			'geocode: rate-limited; try again later.',
			'rate_limited',
			retry,
		);
	}

	if (response.status === 400) {
		throw new GeocodeError(
			'geocode: invalid query.',
			'invalid_query',
		);
	}

	if (!response.ok) {
		throw new GeocodeError(
			'geocode: server returned ' + String(response.status),
			'geocode_failed',
		);
	}

	const data = (await response.json()) as Partial<GeocodeResponse>;
	if (!Array.isArray(data.results)) {
		throw new GeocodeError(
			'geocode: response missing results array.',
			'geocode_failed',
		);
	}

	return {
		results: data.results,
		cached: Boolean(data.cached),
		attribution: typeof data.attribution === 'string' ? data.attribution : '',
	};
}

/**
 * Build a `geo:` URI from a coordinate pair. RFC 5870 — used as the
 * h-entry `location` property when the user picks a checkin result.
 *
 * Coordinates are rounded to 5 decimal places (~1.1 m precision) to keep
 * the URI compact without losing meaningful accuracy.
 */
export function geo_uri(lat: number, lon: number): string {
	const round = (n: number): string => (Math.round(n * 100000) / 100000).toString();
	return `geo:${round(lat)},${round(lon)}`;
}
