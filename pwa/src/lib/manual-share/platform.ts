/**
 * Platform detection for manual-share routing.
 *
 * The server already classifies the request via User-Agent (see
 * `Outpost_Manual_Share_Controller::detect_platform()` in PHP). The PWA
 * runs the same classification client-side so it can pick a strategy
 * before issuing the request — important for iOS where Safari sends a
 * Mac-shaped UA in some configurations and the display-mode hint is
 * the disambiguator.
 *
 * Two layers of evidence:
 *
 *   1. `navigator.userAgent` — primary signal. Recognises "Android",
 *      "iPhone", "iPad", "iPod" anywhere in the UA.
 *   2. iPadOS 13+ on Safari sends a desktop-Mac UA by default. We layer
 *      `(pointer: coarse)` + `navigator.maxTouchPoints > 1` as a
 *      fallback heuristic to catch those cases.
 *
 * The detector is injectable for tests via the optional `PlatformEnvironment`
 * argument — the same pattern A1/B0a/B1 followed for crypto/fetch/IDB.
 */

export type Platform = 'android' | 'ios' | 'desktop';

export interface PlatformEnvironment {
	user_agent: string;
	max_touch_points: number;
	matches_pointer_coarse: boolean;
}

/** Build a default environment from the live `navigator` + `matchMedia`. */
export function default_environment(): PlatformEnvironment {
	const nav: Navigator | undefined = typeof navigator === 'undefined' ? undefined : navigator;
	const ua = nav?.userAgent ?? '';
	const max_touch_points = nav?.maxTouchPoints ?? 0;
	const matches_pointer_coarse =
		typeof window !== 'undefined' && typeof window.matchMedia === 'function'
			? window.matchMedia( '(pointer: coarse)' ).matches
			: false;
	return { user_agent: ua, max_touch_points, matches_pointer_coarse };
}

/**
 * Classify the platform. iOS detection uses UA first, then the
 * touch-points + pointer-coarse fallback for iPadOS 13+ which
 * defaults to a Mac-shaped UA on Safari.
 */
export function detect_platform( env: PlatformEnvironment = default_environment() ): Platform {
	const ua = env.user_agent;
	if ( /Android/i.test( ua ) ) {
		return 'android';
	}
	if ( /iPhone|iPad|iPod/i.test( ua ) ) {
		return 'ios';
	}
	// iPadOS 13+ desktop-UA fallback: a Mac-shaped UA paired with
	// > 1 touch point + coarse pointer is overwhelmingly an iPad.
	if (
		/Macintosh/i.test( ua )
		&& env.max_touch_points > 1
		&& env.matches_pointer_coarse
	) {
		return 'ios';
	}
	return 'desktop';
}
