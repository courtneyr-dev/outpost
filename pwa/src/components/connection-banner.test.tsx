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
		const msgs = root.querySelectorAll('.outpost-connection-banner__msg');
		expect(msgs.length).toBe(2);
		msgs.forEach((m) => expect((m as HTMLElement).hidden).toBe(true));
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
		expect(
			(root.querySelector('.outpost-connection-banner__msg--offline') as HTMLElement).hidden,
		).toBe(false);
		setOnline(true);
		act(() => {
			window.dispatchEvent(new Event('online'));
		});
		expect(
			(root.querySelector('.outpost-connection-banner__msg--offline') as HTMLElement).hidden,
		).toBe(true);
	});
});
