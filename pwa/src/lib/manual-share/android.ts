/**
 * Android share handler.
 *
 * Consumes the {@see IntentPayloadAndroid} returned by the server and
 * executes the Android-side share flow. Three layers (CLAUDE.md F10
 * #2):
 *
 *   1. Clipboard write — universal safety net (Doc 1 §4.5). Always
 *      runs first; even when the intent succeeds and EXTRA_TEXT is
 *      honored, the clipboard backup means the user can paste if
 *      anything drops the text.
 *   2. `navigator.share()` with files — preferred path. Works in
 *      installed PWAs on Android Chrome 85+, integrates with the
 *      system share sheet, supports image/video files.
 *   3. `intent_url` fallback — when navigator.share isn't available,
 *      doesn't support files, throws, or returns false. Two-tap UX
 *      is the worst case (clipboard + manual app-open instructions).
 *
 * After the share resolves (or the fallback navigates), the handler
 * fires telemetry to `/manual-share/intent/log` so the audit log
 * outcome is recorded. F12 will use that record for completion-tracking;
 * F13 surfaces it in admin notices and composer UI.
 *
 * Environment is injectable so Vitest tests can stub navigator,
 * clipboard, fetch, and the telemetry POSTer without touching globals.
 */

import { fill_intent_url } from './intent-url';
import type {
	IntentPayloadAndroid,
	ShareOutcome,
	ShareStrategy,
	ShareTelemetry,
	SharedFile,
} from './types';

export interface AndroidShareEnvironment {
	/** Subset of `navigator.share` we depend on. */
	navigator_share?: ( data: ShareData ) => Promise<void>;
	/** `navigator.canShare` for files-capability detection. */
	navigator_can_share?: ( data: ShareData ) => boolean;
	/** `navigator.clipboard.writeText`. */
	clipboard_write_text?: ( text: string ) => Promise<void>;
	/** Used to fetch image URLs into Blobs before passing to navigator.share. */
	fetch?: typeof fetch;
	/** Hard navigation primitive (window.location.href = ...). Tests stub this. */
	navigate?: ( url: string ) => void;
	/** POST telemetry to /manual-share/intent/log. Tests stub this. */
	post_telemetry?: ( telemetry: ShareTelemetry ) => Promise<void>;
}

export interface AndroidShareResult {
	post_id: number;
	audit_log_id: string;
	strategy: ShareStrategy;
	outcome: ShareOutcome;
	error?: string;
}

/**
 * Execute the Android share flow for a given intent payload + post id.
 *
 * Always returns a result describing what happened — never throws.
 * The outcome field tells the caller (composer UI) whether to show a
 * success message, a "complete the share manually" hint, or an error.
 */
export async function execute_android_share(
	payload: IntentPayloadAndroid,
	post_id: number,
	env: AndroidShareEnvironment = default_environment(),
): Promise<AndroidShareResult> {
	// Layer 1 — clipboard. Universal, fires regardless of strategy.
	try {
		if ( env.clipboard_write_text ) {
			await env.clipboard_write_text( payload.clipboard_text );
		}
	} catch ( err ) {
		// Clipboard failure is not fatal — continue with the share.
		// The user just won't have the caption pre-pasted in their
		// destination app.
	}

	// The server-decided primary strategy. If it's `intent_url`, we
	// skip the navigator.share attempt and go straight to the URL
	// (because the platform has explicitly declared web_intent as
	// its caption_via — e.g. X, Threads, Pinterest, Reddit). For
	// everything else, navigator.share is preferred.
	if ( payload.intent_strategy === 'intent_url' ) {
		return navigate_to_fallback( payload, post_id, env, 'intent_url' );
	}

	// Layer 2 — navigator.share with files.
	const native_result = await try_native_share( payload, post_id, env );
	if ( native_result.outcome === 'fired' ) {
		await report_telemetry( native_result, env );
		return native_result;
	}

	// Layer 3 — fallback URL navigation.
	if ( payload.fallback_url ) {
		return navigate_to_fallback( payload, post_id, env, 'intent_url' );
	}

	// Worst case — clipboard already wrote, but no path to fire a share.
	const two_tap: AndroidShareResult = {
		post_id,
		audit_log_id: payload.audit_log_id,
		strategy: 'two_tap_fallback',
		outcome: 'aborted',
		error: 'No share path available — clipboard wrote, user must open app manually.',
	};
	await report_telemetry( two_tap, env );
	return two_tap;
}

async function try_native_share(
	payload: IntentPayloadAndroid,
	post_id: number,
	env: AndroidShareEnvironment,
): Promise<AndroidShareResult> {
	if ( ! env.navigator_share ) {
		return {
			post_id,
			audit_log_id: payload.audit_log_id,
			strategy: 'navigator_share',
			outcome: 'rejected',
			error: 'navigator.share not available',
		};
	}

	let files: File[] = [];
	if ( payload.files.length > 0 && env.fetch ) {
		try {
			files = await fetch_files_as_blobs( payload.files, env.fetch );
		} catch ( err ) {
			return {
				post_id,
				audit_log_id: payload.audit_log_id,
				strategy: 'navigator_share',
				outcome: 'rejected',
				error: `File fetch failed: ${ err instanceof Error ? err.message : String( err ) }`,
			};
		}
	}

	const share_data: ShareData = {
		title: payload.caption,
		text:  payload.caption,
	};
	if ( files.length > 0 ) {
		share_data.files = files;
	}

	if ( env.navigator_can_share && ! env.navigator_can_share( share_data ) ) {
		return {
			post_id,
			audit_log_id: payload.audit_log_id,
			strategy: 'navigator_share',
			outcome: 'rejected',
			error: 'navigator.canShare rejected files payload',
		};
	}

	try {
		await env.navigator_share( share_data );
		return {
			post_id,
			audit_log_id: payload.audit_log_id,
			strategy: 'navigator_share',
			outcome: 'fired',
		};
	} catch ( err ) {
		return {
			post_id,
			audit_log_id: payload.audit_log_id,
			strategy: 'navigator_share',
			outcome: 'rejected',
			error: err instanceof Error ? err.message : String( err ),
		};
	}
}

async function fetch_files_as_blobs(
	descriptors: SharedFile[],
	fetcher: typeof fetch,
): Promise<File[]> {
	const promises = descriptors.map( async ( file ) => {
		const response = await fetcher( file.url );
		if ( ! response.ok ) {
			throw new Error( `Fetch ${ file.url } returned ${ response.status }` );
		}
		const blob = await response.blob();
		const name = filename_from_url( file.url );
		return new File( [ blob ], name, { type: file.mime } );
	} );
	return Promise.all( promises );
}

function filename_from_url( url: string ): string {
	try {
		const parsed = new URL( url );
		const tail   = parsed.pathname.split( '/' ).pop() ?? '';
		return tail !== '' ? tail : 'shared-file';
	} catch {
		return 'shared-file';
	}
}

function navigate_to_fallback(
	payload: IntentPayloadAndroid,
	post_id: number,
	env: AndroidShareEnvironment,
	strategy: ShareStrategy,
): AndroidShareResult {
	if ( ! payload.fallback_url || ! env.navigate ) {
		const result: AndroidShareResult = {
			post_id,
			audit_log_id: payload.audit_log_id,
			strategy,
			outcome: 'aborted',
			error: 'No fallback_url or no navigate primitive',
		};
		void report_telemetry( result, env );
		return result;
	}

	const url = fill_intent_url( payload.fallback_url, {
		caption:         payload.caption,
		source_url:      payload.source_url,
		image_url:       payload.files[0]?.url ?? '',
	} );

	env.navigate( url );

	const result: AndroidShareResult = {
		post_id,
		audit_log_id: payload.audit_log_id,
		strategy,
		outcome: 'fired',
	};
	void report_telemetry( result, env );
	return result;
}

async function report_telemetry(
	result: AndroidShareResult,
	env: AndroidShareEnvironment,
): Promise<void> {
	if ( ! env.post_telemetry ) {
		return;
	}
	try {
		await env.post_telemetry( {
			post_id:      result.post_id,
			audit_log_id: result.audit_log_id,
			outcome:      result.outcome,
		} );
	} catch {
		// Telemetry failures are not fatal. The audit log will record
		// `unknown` (the server's default) and the user-side share
		// outcome is unaffected.
	}
}

/**
 * Default environment built from globals. Browsers without
 * navigator.share leave that field undefined; the handler then
 * routes to fallback automatically.
 *
 * Implementation note (CLAUDE.md B0a #4): `exactOptionalPropertyTypes`
 * forbids explicit `undefined` assignment to `?:` fields. We build
 * the env via conditional spreads so absent capabilities map to
 * "key omitted" rather than "key set to undefined".
 */
export function default_environment(): AndroidShareEnvironment {
	const nav: Navigator | undefined = typeof navigator === 'undefined' ? undefined : navigator;
	const has_share = !! ( nav && typeof nav.share === 'function' );
	const has_can_share = !! ( nav && typeof nav.canShare === 'function' );
	const has_clipboard = !! ( nav && nav.clipboard && typeof nav.clipboard.writeText === 'function' );

	const env: AndroidShareEnvironment = {
		navigate: ( url: string ) => {
			if ( typeof window !== 'undefined' ) {
				window.location.href = url;
			}
		},
		post_telemetry: async ( telemetry ) => {
			if ( typeof fetch === 'undefined' ) {
				return;
			}
			await fetch( '/wp-json/outpost/v1/manual-share/intent/log', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify( telemetry ),
			} );
		},
	};
	if ( has_share && nav ) {
		env.navigator_share = ( data: ShareData ) => nav.share( data );
	}
	if ( has_can_share && nav ) {
		env.navigator_can_share = ( data: ShareData ) => nav.canShare( data );
	}
	if ( has_clipboard && nav ) {
		env.clipboard_write_text = ( t: string ) => nav.clipboard.writeText( t );
	}
	if ( typeof fetch !== 'undefined' ) {
		env.fetch = fetch;
	}
	return env;
}
