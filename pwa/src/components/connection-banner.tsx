/**
 * Connection banner — a purely CSS-reactive connectivity indicator.
 *
 * There is no online/offline JavaScript in this component. `prop-for-that`
 * samples connectivity in the PWA entry and keeps `--live-online` (1/0) and
 * `--live-net-save-data` (1/0) current on `:root`; CSS reveals the matching
 * message via `@container style()` (see structure.css). Keeping the reaction
 * path in the stylesheet is the whole point — the component just renders the
 * two messages, always present in the DOM, hidden until CSS shows one.
 *
 * Complements the offline *queue* (queue-badge.tsx): this says "you're offline
 * right now," the badge shows how many posts are waiting to send.
 *
 * `--live-net-save-data` comes from the Network Information API (Chromium-only),
 * so the Data Saver message only appears there; the offline message works
 * everywhere via `navigator.onLine`.
 *
 * Accessibility: each message carries `role="status"`. Visibility flips via CSS
 * `display`, and screen-reader announcement of a display change is best-effort —
 * a JS-mirrored `aria-live` update is the follow-up if guaranteed announcement
 * is needed.
 */
export function ConnectionBanner() {
	return (
		<div class="outpost-connection-banner">
			<p
				class="outpost-connection-banner__msg outpost-connection-banner__msg--offline"
				role="status"
			>
				You're offline. Posts will be queued and sent when you reconnect.
			</p>
			<p
				class="outpost-connection-banner__msg outpost-connection-banner__msg--save-data"
				role="status"
			>
				Data Saver is on — showing the lightweight view.
			</p>
		</div>
	);
}
