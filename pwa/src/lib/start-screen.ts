/**
 * Which screen the composer opens to.
 *
 * Precedence, highest first:
 *
 *   1. Sign-in callback and recovery. index.tsx routes /post/auth/callback
 *      before the composer mounts, and an expired sign-in is a banner on
 *      whatever screen opens, so neither reaches this function.
 *   2. A share-sheet, bookmarklet, or iOS Shortcut launch: the tab its
 *      content belongs in (a shared photo opens Photo, a shared link keeps
 *      the tab and variant the dispatcher chose).
 *   3. Navigation the person already made. The composer decides once and
 *      never moves someone afterward, so a preference that arrives late or
 *      changes mid-session doesn't pull them off a draft.
 *   4. The person's saved start screen.
 *   5. Home.
 *
 * Returns null while the saved start screen is still unknown (a device
 * that has never stored it, waiting on the site's first answer). The
 * composer shows a loading line and stops waiting after
 * START_SCREEN_WAIT_MS.
 */

import type { StartScreen } from './appearance';
import type { ShareTargetData } from './share-target';

/** How long a first launch on a device waits for the site's answer. */
export const START_SCREEN_WAIT_MS = 1500;

export interface InitialScreenInput {
	share: Pick<ShareTargetData, 'tab'> | null;
	start: StartScreen | undefined;
	/** The saved start screen is known, or asking for it finished. */
	ready: boolean;
	/** The wait ran out; open Home rather than keep waiting. */
	timedOut: boolean;
}

export function initial_screen({ share, start, ready, timedOut }: InitialScreenInput): StartScreen | null {
	if (share) return share.tab;
	if (start) return start;
	if (ready || timedOut) return 'home';
	return null;
}
