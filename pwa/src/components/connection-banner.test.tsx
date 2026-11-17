import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from 'preact';
import { act } from 'preact/test-utils';
import { ConnectionBanner } from './connection-banner';

let root: HTMLDivElement;
beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
});
afterEach(() => {
	render(null, root);
	root.remove();
});

const setOnline = (value: boolean) =>
	Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => value });

describe('ConnectionBanner', () => {
	it('shows nothing while online without data saver', () => {
		setOnline(true);
		act(() => {
			render(<ConnectionBanner />, root);
		});
		// Both live regions stay mounted at all times (so iOS VoiceOver reliably
		// picks up later text changes) — the empty state is an empty, zero-space
		// element, not one removed from the DOM via `hidden`.
		const msgs = root.querySelectorAll('.outpost-connection-banner__msg');
		expect(msgs.length).toBe(2);
		msgs.forEach((m) => {
			expect((m as HTMLElement).hidden).toBe(false);
			expect((m as HTMLElement).classList.contains('outpost-connection-banner__msg--empty')).toBe(
				true,
			);
			expect((m as HTMLElement).textContent).toBe('');
		});
	});

	it('shows the offline message after an offline event and hides it on online', () => {
		setOnline(true);
		act(() => {
			render(<ConnectionBanner />, root);
		});
		setOnline(false);
		act(() => {
			window.dispatchEvent(new Event('offline'));
		});
		const offline_msg = root.querySelector(
			'.outpost-connection-banner__msg--offline',
		) as HTMLElement;
		expect(offline_msg.classList.contains('outpost-connection-banner__msg--empty')).toBe(false);
		expect(offline_msg.textContent).toContain("You're offline");
		setOnline(true);
		act(() => {
			window.dispatchEvent(new Event('online'));
		});
		expect(offline_msg.classList.contains('outpost-connection-banner__msg--empty')).toBe(true);
		expect(offline_msg.textContent).toBe('');
	});
});
