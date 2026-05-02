/**
 * Install-prompt state — small localStorage helpers for the
 * "Add to Home Screen" prompt UI.
 *
 * Phase D3. Three flags persist across page loads:
 *
 *   - outpost.has_posted        — set after the first successful post.
 *                                 The prompt only shows once the user
 *                                 has shown actual intent (a post)
 *                                 rather than nagging on first visit.
 *   - outpost.install_dismissed — set when the user taps "Not now" /
 *                                 "Got it". Suppresses the prompt
 *                                 forever for that device.
 *
 * localStorage is the right tier here: per-device, persistent across
 * sessions, no need for IDB ceremony for a string flag.
 */

const KEY_HAS_POSTED = 'outpost.has_posted';
const KEY_INSTALL_DISMISSED = 'outpost.install_dismissed';

function safe_get(key: string): string | null {
	try {
		return globalThis.localStorage?.getItem(key) ?? null;
	} catch (_err) {
		// localStorage can throw in private-browsing or sandboxed iframes —
		// treat as unavailable.
		return null;
	}
}

function safe_set(key: string, value: string): void {
	try {
		globalThis.localStorage?.setItem(key, value);
	} catch (_err) {
		// no-op
	}
}

export function mark_posted_once(): void {
	if (safe_get(KEY_HAS_POSTED) !== '1') {
		safe_set(KEY_HAS_POSTED, '1');
	}
}

export function has_posted(): boolean {
	return safe_get(KEY_HAS_POSTED) === '1';
}

export function mark_install_dismissed(): void {
	safe_set(KEY_INSTALL_DISMISSED, '1');
}

export function was_install_dismissed(): boolean {
	return safe_get(KEY_INSTALL_DISMISSED) === '1';
}

/**
 * Already-installed detection.
 *
 *   - display-mode: standalone — Android Chrome PWA, iOS A2HS launch.
 *   - navigator.standalone — older iOS Safari signal.
 *
 * When the page is loaded as a standalone PWA, we never want to show
 * the install prompt again (the install already happened).
 */
export function is_running_standalone(): boolean {
	if (typeof window === 'undefined') return false;
	try {
		if (window.matchMedia?.('(display-mode: standalone)').matches) {
			return true;
		}
	} catch (_err) {
		// no-op
	}
	const nav = window.navigator as Navigator & { standalone?: boolean };
	return nav.standalone === true;
}

/**
 * Heuristic UA check for iOS Safari. Programmatic A2HS doesn't exist
 * on iOS, so the prompt has to fall back to "tap Share, then Add to
 * Home Screen" instructions instead of a button.
 */
export function is_ios_safari(): boolean {
	if (typeof navigator === 'undefined') return false;
	const ua = navigator.userAgent || '';
	const is_ios = /iPhone|iPad|iPod/.test(ua);
	const is_safari = /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
	return is_ios && is_safari;
}
