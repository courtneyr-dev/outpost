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

	describe('Tab containment', () => {
		// jsdom has no layout, so every element reports a null offsetParent.
		beforeEach(() => {
			Object.defineProperty(HTMLElement.prototype, 'offsetParent', {
				configurable: true,
				get() {
					return document.body;
				},
			});
		});
		afterEach(() => {
			delete (HTMLElement.prototype as { offsetParent?: unknown }).offsetParent;
		});

		function mount_with_outside(): { outside: HTMLButtonElement; label: HTMLElement; save: HTMLButtonElement } {
			render(
				<>
					<button type="button" id="outside">
						Behind the scrim
					</button>
					<Drawer open onClose={vi.fn()} title="Recent drafts">
						<button type="button" id="save">
							Save
						</button>
						<p id="label" tabIndex={-1}>
							Your drafts, page 2
						</p>
					</Drawer>
				</>,
				root,
			);
			return {
				outside: root.querySelector('#outside') as HTMLButtonElement,
				label: root.querySelector('#label') as HTMLElement,
				save: root.querySelector('#save') as HTMLButtonElement,
			};
		}

		function tab(shiftKey = false): KeyboardEvent {
			const event = new KeyboardEvent('keydown', { key: 'Tab', shiftKey, cancelable: true });
			window.dispatchEvent(event);
			return event;
		}

		it('brings focus back into the drawer when it sits outside', async () => {
			const { outside } = mount_with_outside();
			await flush();
			outside.focus();

			expect(tab().defaultPrevented).toBe(true);
			expect(document.activeElement?.getAttribute('aria-label')).toBe('Close');

			outside.focus();
			expect(tab(true).defaultPrevented).toBe(true);
			expect(document.activeElement?.id).toBe('save');
		});

		it('wraps from a focused label that has no control after it', async () => {
			const { label } = mount_with_outside();
			await flush();
			label.focus();

			expect(tab().defaultPrevented).toBe(true);
			expect(document.activeElement?.getAttribute('aria-label')).toBe('Close');
		});
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
