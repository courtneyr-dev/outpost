/**
 * iOS app URL scheme strategy.
 *
 * Sets `window.location.href = payload.app_url_scheme` and listens
 * for visibility-change / pagehide as a heuristic that the target
 * app launched. Conservative timeout (1500ms) — if no visibility
 * change in that window, assume the scheme failed (app not installed,
 * scheme deprecated, etc.) and report 'rejected' so the runner falls
 * through.
 *
 * Known limitations (CLAUDE.md F11 #5):
 *
 *   - iOS doesn't return useful errors for URL schemes. The visibility
 *     heuristic is the standard pattern but timing-sensitive — on some
 *     iOS versions, visibilitychange fires BEFORE the scheme actually
 *     launches the app (race condition).
 *   - Real-device verification is required to tune the timeout. F11
 *     ships 1500ms; tweak based on field reports.
 */

import type { IosStrategyFn, IosStrategyEnvironment } from './types';

export const APP_URL_SCHEME_TIMEOUT_MS = 1500;

export const try_app_url_scheme: IosStrategyFn = async ( payload, env ) => {
	if ( ! payload.app_url_scheme || '' === payload.app_url_scheme ) {
		return 'rejected';
	}
	return await race_visibility_against_timeout(
		payload.app_url_scheme,
		env,
		APP_URL_SCHEME_TIMEOUT_MS,
	);
};

/**
 * Fire `env.navigate(url)` then race a visibility-change signal against
 * a timeout. Visibility change → 'fired'; timeout → 'rejected'.
 *
 * Exported so tests can drive the race deterministically with a
 * fake-timers-style `set_timeout` injection.
 */
export async function race_visibility_against_timeout(
	url: string,
	env: IosStrategyEnvironment,
	timeout_ms: number,
): Promise<'fired' | 'rejected'> {
	const set_timeout = env.set_timeout ?? ( ( cb, ms ) => globalThis.setTimeout( cb, ms ) );
	const clear_timeout = env.clear_timeout ?? ( ( h ) => globalThis.clearTimeout( h as number ) );

	return new Promise<'fired' | 'rejected'>( ( resolve ) => {
		let resolved = false;
		let timer_handle: unknown = undefined;
		// eslint-disable-next-line prefer-const -- assigned after settle() is defined; a const initializer can be hit by synchronous callback re-entry (see CLAUDE.md F11 #10).
		let unsubscribe: ( () => void ) | undefined;

		const settle = ( outcome: 'fired' | 'rejected' ): void => {
			if ( resolved ) {
				return;
			}
			resolved = true;
			unsubscribe?.();
			if ( timer_handle !== undefined ) {
				clear_timeout( timer_handle );
			}
			resolve( outcome );
		};

		unsubscribe = env.add_visibility_listener
			? env.add_visibility_listener( () => settle( 'fired' ) )
			: undefined;

		// Synchronous test mocks may fire `cb` immediately during this
		// call; settle handles re-entry via the `resolved` guard above.
		timer_handle = set_timeout( () => settle( 'rejected' ), timeout_ms );

		// Fire the navigation AFTER subscribing — listener must be
		// installed before the scheme can trigger a visibility flip.
		// Skip if the synchronous timeout already fired.
		if ( ! resolved ) {
			env.navigate( url );
		}
	} );
}
