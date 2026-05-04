/**
 * iOS web intent strategy.
 *
 * Navigates to `payload.web_intent_url` (substituted server-side with
 * @caption_encoded / @source_url / @image_url). The browser opens the
 * URL — typically a platform's web compose page (twitter.com/intent/tweet,
 * threads.net/intent/post, etc.) — and the user finishes the post in
 * the platform's web/native UI.
 *
 * iOS doesn't give a callback when the URL load completes successfully
 * vs. fails. We treat invocation-without-throw as 'fired' since the
 * navigation is already in flight; the audit log records that we
 * navigated and the user reports actual completion via F12+ (silo URL
 * capture).
 *
 * Same `env.navigate` primitive as the app-url-scheme strategy. A real
 * PWA may want to use `window.open` instead to preserve composer state
 * — that's a UI-layer concern (the composer integration in a future
 * session decides). The strategy itself just calls `env.navigate`.
 */

import type { IosStrategyFn } from './types';

export const try_web_intent: IosStrategyFn = async ( payload, env ) => {
	if ( ! payload.web_intent_url || '' === payload.web_intent_url ) {
		return 'rejected';
	}
	try {
		env.navigate( payload.web_intent_url );
		return 'fired';
	} catch {
		return 'rejected';
	}
};
