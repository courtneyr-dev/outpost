import { useEffect, useState } from 'preact/hooks';

/**
 * Connection banner — a state-driven connectivity indicator.
 *
 * Listens for the browser's `online`/`offline` events and the Network
 * Information API's `change` event directly, and reflects that state as the
 * native `hidden` attribute on each message. Both messages are always
 * present in the DOM; `hidden` toggles which one (if any) is visible.
 *
 * Complements the offline *queue* (queue-badge.tsx): this says "you're offline
 * right now," the badge shows how many posts are waiting to send.
 *
 * `saveData` comes from the Network Information API (Chromium-only), so the
 * Data Saver message only appears there; the offline message works
 * everywhere via `navigator.onLine`.
 *
 * Accessibility: each message is a `role="status"` region that stays
 * mounted at all times — only its text content changes (empty string when
 * there's nothing to say). Toggling `hidden`/`display: none` to reveal a
 * live region is unreliable (notably on iOS VoiceOver, which frequently
 * misses a region that goes from removed-from-tree to present-with-text
 * in one step); a persistently-mounted region whose text mutates is the
 * reliable pattern. The empty state is styled to take up no visual space
 * via `.outpost-connection-banner__msg--empty` rather than removed from
 * the DOM.
 */
type Connection = {
	saveData?: boolean;
	addEventListener?: (type: string, listener: () => void) => void;
	removeEventListener?: (type: string, listener: () => void) => void;
};

const connection = (): Connection | undefined =>
	(navigator as Navigator & { connection?: Connection }).connection;

export function ConnectionBanner() {
	const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine);
	const [saveData, setSaveData] = useState(Boolean(connection()?.saveData));

	useEffect(() => {
		const goOnline = () => setOnline(true);
		const goOffline = () => setOnline(false);
		const netChange = () => setSaveData(Boolean(connection()?.saveData));
		window.addEventListener('online', goOnline);
		window.addEventListener('offline', goOffline);
		connection()?.addEventListener?.('change', netChange);
		return () => {
			window.removeEventListener('online', goOnline);
			window.removeEventListener('offline', goOffline);
			connection()?.removeEventListener?.('change', netChange);
		};
	}, []);

	return (
		<div class="outpost-connection-banner">
			<p
				class={`outpost-connection-banner__msg outpost-connection-banner__msg--offline${
					online ? ' outpost-connection-banner__msg--empty' : ''
				}`}
				role="status"
			>
				{online ? '' : "You're offline. Posts will be queued and sent when you reconnect."}
			</p>
			<p
				class={`outpost-connection-banner__msg outpost-connection-banner__msg--save-data${
					saveData ? '' : ' outpost-connection-banner__msg--empty'
				}`}
				role="status"
			>
				{saveData ? 'Data Saver is on — showing the lightweight view.' : ''}
			</p>
		</div>
	);
}
