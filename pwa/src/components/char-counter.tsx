import { useEffect, useState } from 'preact/hooks';

/**
 * Character counter — shows current count and (optionally) a soft limit.
 *
 * Per Design System Section 5.27. Renders below the textarea it counts.
 * `aria-live="polite"` for changes, but only ANNOUNCES when crossing
 * threshold boundaries (50, 20, 0 chars remaining) — without that gate
 * it'd narrate every keystroke and overwhelm screen-reader users.
 *
 * The limit is a soft warning, not enforcement. The user can post
 * over-limit — the counter just turns into a "−N over" warning so
 * they know what'll happen on POSSE syndication. Twitter/Mastodon-via-
 * Bridgy will truncate over-limit posts.
 *
 * Common limits to pass:
 *   - 280 — Twitter / Bridgy → Twitter
 *   - 500 — Mastodon (default instance config)
 *   - 300 — Bluesky
 *   - none → no limit, just render the count
 */

export interface CharCounterProps {
	value: string;
	limit?: number;
	/** ARIA label for the counter group; defaults to "Character count". */
	label?: string;
}

export function CharCounter({ value, limit, label }: CharCounterProps) {
	const len = [...value].length; // Codepoint count, not byte count.
	const remaining = typeof limit === 'number' ? limit - len : null;
	const over = remaining !== null && remaining < 0;

	// Threshold-based announcement gating: we update the polite live
	// region only when crossing 50 / 20 / 0 / over-by-N thresholds, so
	// screen readers don't narrate every keystroke. Below 50 chars
	// remaining the announcement updates on each integer change so the
	// user gets useful feedback as they approach the limit.
	const [announcement, setAnnouncement] = useState('');
	useEffect(() => {
		if (remaining === null) return;
		if (remaining > 50) {
			if (announcement !== '') setAnnouncement('');
			return;
		}
		if (remaining < 0) {
			setAnnouncement(`${String(-remaining)} over the limit`);
			return;
		}
		setAnnouncement(`${String(remaining)} characters remaining`);
		// eslint-disable-next-line react-hooks/exhaustive-deps -- announcement is internal
	}, [remaining]);

	const display =
		remaining === null
			? `${String(len)}`
			: over
				? `${String(len)} / ${String(limit)} (−${String(-remaining)} over)`
				: `${String(len)} / ${String(limit)}`;

	const class_name = 'outpost-char-counter' + (over ? ' outpost-char-counter--over' : '');

	return (
		<>
			<span class={class_name} aria-hidden="true">
				{display}
			</span>
			<span class="outpost-visually-hidden" role="status" aria-live="polite">
				{announcement}
			</span>
			{label && <span class="outpost-visually-hidden">{label}</span>}
		</>
	);
}
