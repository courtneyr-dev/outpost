/**
 * Client-side API helpers for the F12 phase-2 capture flow.
 *
 *   GET  /wp-json/outpost/v1/manual-share/pending
 *   POST /wp-json/outpost/v1/manual-share/capture
 *
 * Both helpers take an injectable `CaptureApiEnvironment` so tests
 * can stub fetch + bearer-token retrieval without touching globals
 * or IndexedDB. Production callers use `default_capture_api_environment()`.
 */

export interface PendingEntry {
	id: string;
	platform_id: string;
	fired_at: string;
	strategy: string;
	outcome: string;
	completed_at: string | null;
	silo_url: string | null;
}

export interface PendingPost {
	post_id: number;
	post_title: string;
	permalink: string;
	entries: PendingEntry[];
}

export interface PendingResponse {
	pending: PendingPost[];
}

export interface CaptureSubmitInput {
	post_id: number;
	audit_log_id: string;
	silo_url: string;
	confirm_mismatch?: boolean;
}

export interface CaptureRecordedResponse {
	status: 'recorded';
	audit_log_id: string;
	silo_url: string;
	platform_id: string;
	syndication_links: Array<{
		platform_id: string;
		url: string;
		added_at: string;
		source: string;
	}>;
	mismatch_confirmed: boolean;
}

export interface CaptureMismatchResponse {
	status: 'mismatch_warning';
	platform_id: string;
	silo_url: string;
	message: string;
}

export interface CaptureErrorResponse {
	status: 'error';
	code: string;
	message: string;
}

export type CaptureResponse =
	| CaptureRecordedResponse
	| CaptureMismatchResponse
	| CaptureErrorResponse;

export interface CaptureApiEnvironment {
	fetch: typeof fetch;
	/** Bearer token for the Authorization header. */
	bearer_token?: string;
	/** Override the API base — defaults to `/wp-json/outpost/v1`. */
	api_base?: string;
}

/**
 * Fetch the requesting user's pending captures.
 */
export async function fetch_pending(
	env: CaptureApiEnvironment,
): Promise<PendingResponse> {
	const base = env.api_base ?? '/wp-json/outpost/v1';
	const headers: Record<string, string> = { Accept: 'application/json' };
	if ( env.bearer_token ) {
		headers.Authorization = `Bearer ${ env.bearer_token }`;
	}
	const response = await env.fetch( `${ base }/manual-share/pending`, {
		method: 'GET',
		headers,
		credentials: 'omit',
	} );
	if ( ! response.ok ) {
		throw new Error( `Pending captures fetch failed (${ response.status })` );
	}
	return ( await response.json() ) as PendingResponse;
}

/**
 * Submit a captured silo URL. Surfaces three outcomes:
 *
 *   - 'recorded'         — server wrote the syndication link
 *   - 'mismatch_warning' — domain doesn't match expected; caller can
 *                          re-submit with `confirm_mismatch: true`
 *   - 'error'            — validation error; caller surfaces to user
 */
export async function submit_capture(
	input: CaptureSubmitInput,
	env: CaptureApiEnvironment,
): Promise<CaptureResponse> {
	const base = env.api_base ?? '/wp-json/outpost/v1';
	const headers: Record<string, string> = {
		'Content-Type': 'application/json',
		Accept:         'application/json',
	};
	if ( env.bearer_token ) {
		headers.Authorization = `Bearer ${ env.bearer_token }`;
	}
	const response = await env.fetch( `${ base }/manual-share/capture`, {
		method:      'POST',
		headers,
		credentials: 'omit',
		body:        JSON.stringify( input ),
	} );
	const data = await response.json().catch( () => ( {} ) );

	if ( response.ok ) {
		return data as CaptureRecordedResponse | CaptureMismatchResponse;
	}

	const code    = ( data as { code?: string } ).code ?? 'http_error';
	const message =
		( data as { message?: string } ).message
		?? `Capture failed (${ response.status })`;
	return {
		status:  'error',
		code,
		message,
	};
}

/**
 * Default environment built from globals. Production callers pass
 * the IndieAuth bearer token from token-store.
 */
export function default_capture_api_environment( bearer_token?: string ): CaptureApiEnvironment {
	const env: CaptureApiEnvironment = {
		fetch: typeof fetch === 'undefined' ? ( () => Promise.reject( new Error( 'fetch unavailable' ) ) ) as typeof fetch : fetch,
	};
	if ( bearer_token !== undefined && bearer_token !== '' ) {
		env.bearer_token = bearer_token;
	}
	return env;
}
