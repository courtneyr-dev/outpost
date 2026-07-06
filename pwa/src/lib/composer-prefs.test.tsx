import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from 'preact';
import {
	read_more_open,
	write_more_open,
	useMoreOpen,
} from './composer-prefs';

const KEY = 'outpost.composer.more-open';

beforeEach(() => {
	globalThis.localStorage.clear();
});

describe('read_more_open / write_more_open', () => {
	it('defaults to false when nothing is stored', () => {
		expect(read_more_open()).toBe(false);
	});

	it('round-trips true', () => {
		write_more_open(true);
		expect(globalThis.localStorage.getItem(KEY)).toBe('1');
		expect(read_more_open()).toBe(true);
	});

	it('round-trips false', () => {
		write_more_open(true);
		write_more_open(false);
		expect(globalThis.localStorage.getItem(KEY)).toBe('0');
		expect(read_more_open()).toBe(false);
	});

	it('treats any non-"1" value as closed', () => {
		globalThis.localStorage.setItem(KEY, 'yes');
		expect(read_more_open()).toBe(false);
	});
});

describe('useMoreOpen', () => {
	let root: HTMLDivElement;

	beforeEach(() => {
		root = document.createElement('div');
		document.body.appendChild(root);
	});

	afterEach(() => {
		render(null, root);
		root.remove();
	});

	function Harness(): preact.JSX.Element {
		const [open, set_open] = useMoreOpen();
		return (
			<div>
				<span data-testid="state">{open ? 'open' : 'closed'}</span>
				<button data-testid="open" onClick={() => set_open(true)}>
					open
				</button>
				<button data-testid="close" onClick={() => set_open(false)}>
					close
				</button>
			</div>
		);
	}

	const state = (): string =>
		root.querySelector('[data-testid="state"]')?.textContent ?? '';

	const flush = (): Promise<void> =>
		new Promise((resolve) => setTimeout(resolve, 0));

	it('initializes from stored preference', () => {
		write_more_open(true);
		render(<Harness />, root);
		expect(state()).toBe('open');
	});

	it('initializes closed when unset', () => {
		render(<Harness />, root);
		expect(state()).toBe('closed');
	});

	it('setter updates state and persists', async () => {
		render(<Harness />, root);
		(
			root.querySelector('[data-testid="open"]') as HTMLButtonElement
		).click();
		await flush();
		expect(state()).toBe('open');
		expect(read_more_open()).toBe(true);

		(
			root.querySelector('[data-testid="close"]') as HTMLButtonElement
		).click();
		await flush();
		expect(state()).toBe('closed');
		expect(read_more_open()).toBe(false);
	});
});
