/**
 * Manual-fallback strategy — the worst-case UX that always succeeds.
 *
 * Renders a modal saying "Image and caption ready. Open [Platform]
 * manually." with three actions:
 *
 *   - Save Image (only when files present) — invokes navigator.share
 *     WITHOUT files to trigger iOS's native save UI, OR triggers a
 *     download depending on platform. The strategy doesn't decide;
 *     the modal's `on_save_image` handler does.
 *   - Open App — sets window.location.href to the platform's
 *     homepage URL (best-effort scheme; the browser may or may not
 *     open the app).
 *   - Done — close the modal.
 *
 * The strategy returns 'fired' when the user dismisses with Done /
 * Save / Open (any explicit action is success), 'aborted' when they
 * close the modal without action (escape/backdrop tap).
 *
 * Tests inject `env.show_manual_modal` to bypass actual DOM mounting
 * and directly resolve with a deterministic outcome.
 */

import type { IosStrategyFn, ManualModalProps } from './types';

const PLATFORM_HOMEPAGE: Record<string, string> = {
	'instagram-feed':     'https://www.instagram.com',
	'instagram-stories':  'https://www.instagram.com',
	'facebook':           'https://www.facebook.com',
	'x-twitter':          'https://twitter.com',
	'linkedin':           'https://www.linkedin.com',
	'threads':            'https://www.threads.net',
	'tiktok':             'https://www.tiktok.com',
	'pinterest':          'https://www.pinterest.com',
	'reddit-manual':      'https://www.reddit.com',
	'flickr-manual':      'https://www.flickr.com',
	'tumblr':             'https://www.tumblr.com',
};

export const try_manual_fallback: IosStrategyFn = async ( payload, env ) => {
	if ( ! env.show_manual_modal ) {
		// Manual fallback is the LAST entry in every default chain. If
		// the env doesn't supply a modal renderer, there's no UX path;
		// report 'aborted' so the runner stops.
		return 'aborted';
	}

	const props: ManualModalProps = {
		platform_label:    payload.platform_label,
		platform_id:       payload.platform,
		caption:           payload.caption,
		clipboard_text:    payload.clipboard_text,
		first_image_url:   payload.files[0]?.url ?? null,
		app_homepage_url:  PLATFORM_HOMEPAGE[ payload.platform ] ?? null,
	};

	return await env.show_manual_modal( props );
};
