import { describe, expect, it } from 'vitest';
import { START_SCREENS } from './appearance';
import { initial_screen } from './start-screen';

describe('initial_screen', () => {
	it('opens Home for someone without a saved start screen', () => {
		expect(initial_screen({ share: null, start: undefined, ready: true, timedOut: false })).toBe('home');
	});

	it('opens each of the eight saved start screens on a normal launch', () => {
		for (const start of START_SCREENS) {
			expect(initial_screen({ share: null, start, ready: true, timedOut: false })).toBe(start);
		}
	});

	it('a share launch outranks every saved start screen', () => {
		for (const start of START_SCREENS) {
			expect(initial_screen({ share: { tab: 'photo' }, start, ready: true, timedOut: false })).toBe('photo');
			expect(initial_screen({ share: { tab: 'reply' }, start, ready: true, timedOut: false })).toBe('reply');
		}
	});

	it('a share launch does not wait for the saved start screen', () => {
		expect(initial_screen({ share: { tab: 'listen' }, start: undefined, ready: false, timedOut: false })).toBe('listen');
	});

	it('waits while the saved start screen is unknown, then falls back to Home', () => {
		expect(initial_screen({ share: null, start: undefined, ready: false, timedOut: false })).toBeNull();
		expect(initial_screen({ share: null, start: undefined, ready: false, timedOut: true })).toBe('home');
	});
});
