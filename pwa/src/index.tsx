/**
 * Outpost PWA entry point.
 *
 * B0a ships the bundle without wiring it into the shell — the build artefact
 * exists, tests are green, but `Outpost_PWA_Shell::render_shell()` doesn't
 * emit a `<script>` tag for it yet. B0b adds the manifest read + script tag
 * and replaces this stub with the actual mount logic.
 *
 * The route detection below uses `location.pathname` rather than reading the
 * shell's `data-outpost-route` attribute. Reasons:
 *   - The shell hard-codes `data-outpost-route="composer"` because PHP routes
 *     `composer`, `share-target`, and `auth-callback` to the same render path.
 *   - `location.pathname` is the only reliable way to tell which of those
 *     three the user actually landed on.
 *   - Same-origin pathname is trustworthy in a way URL parameters from
 *     bookmarklets aren't.
 */

type OutpostRoute = 'composer' | 'share-target' | 'auth-callback' | 'unknown';

export function detect_route(pathname: string): OutpostRoute {
	if (pathname.startsWith('/post/share-target')) {
		return 'share-target';
	}
	if (pathname.startsWith('/post/auth/callback')) {
		return 'auth-callback';
	}
	if (pathname === '/post/' || pathname === '/post') {
		return 'composer';
	}
	return 'unknown';
}

// B0b lands the actual mount. For now: detect the route, log it, no DOM work.
// This keeps the bundle non-empty so Vite produces a real entry chunk and the
// manifest has something for B0b to look up.
if (typeof window !== 'undefined') {
	const route = detect_route(window.location.pathname);
	// eslint-disable-next-line no-console
	console.info('[outpost] bundle loaded, route=' + route);
}
