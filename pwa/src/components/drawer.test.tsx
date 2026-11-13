import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from 'preact';
import { Drawer } from './drawer';

let root: HTMLDivElement;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
	document.querySelector('style[data-drawer-test]')?.remove();
	document.body.style.overflow = '';
});

function mount(open: boolean, onClose = vi.fn()): ReturnType<typeof vi.fn> {
	render(
		<Drawer open={open} onClose={onClose} title="More options">
			<p>Optional fields</p>
		</Drawer>,
		root,
	);
	return onClose;
}

function dialog(): HTMLDivElement {
	return root.querySelector('[role="dialog"]') as HTMLDivElement;
}

function scrim(): HTMLDivElement {
	return root.querySelector('.outpost-drawer-scrim') as HTMLDivElement;
}

async function flush(): Promise<void> {
	await new Promise((resolve) => requestAnimationFrame(() => resolve(undefined)));
	await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('Drawer', () => {
	it('hides the dialog and scrim when closed', () => {
		mount(false);
		expect(dialog().hasAttribute('hidden')).toBe(true);
		expect(scrim().hasAttribute('hidden')).toBe(true);
	});

	it('shows the dialog and scrim when open', () => {
		mount(true);
		expect(dialog().hasAttribute('hidden')).toBe(false);
		expect(scrim().hasAttribute('hidden')).toBe(false);
	});

	it('calls onClose from the Close button', () => {
		const on_close = mount(true);
		(root.querySelector('button[aria-label="Close"]') as HTMLButtonElement).click();
		expect(on_close).toHaveBeenCalledOnce();
	});

	it('calls onClose from Escape', async () => {
		const on_close = mount(true);
		await flush();
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		expect(on_close).toHaveBeenCalledOnce();
	});

	it('leaves page scrolling alone when the stylesheet is blocked', async () => {
		mount(true);
		await flush();
		expect(document.body.style.overflow).toBe('');
	});

	it('locks page scrolling only while a fixed drawer is open', async () => {
		const style = document.createElement('style');
		style.dataset.drawerTest = '';
		style.textContent = '.outpost-drawer { position: fixed; }';
		document.head.appendChild(style);

		mount(true);
		await flush();
		expect(document.body.style.overflow).toBe('hidden');

		mount(false);
		await flush();
		expect(document.body.style.overflow).toBe('');
	});
});
