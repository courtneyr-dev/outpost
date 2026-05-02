import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { ComposerTabs } from './composer-tabs';
import type { StoredToken, TokenStoreEnvironment } from '../lib/token-store';

const mock_token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

function mock_token_store(): TokenStoreEnvironment {
	return {
		indexedDB: globalThis.indexedDB,
		crypto: globalThis.crypto,
	};
}

let root: HTMLDivElement;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
});

function mock_composer_config_env(): { fetch: typeof fetch } {
	// Stub fetch so the composer-config request never hits the network in tests.
	// Returns a never-resolving promise — the catch handler in ComposerTabs
	// suppresses the rejection on unmount, and tests don't depend on the
	// More panel rendering.
	return {
		fetch: ((): Promise<Response> => new Promise(() => {})) as typeof fetch,
	};
}

function mount(): void {
	render(
		<ComposerTabs
			token={mock_token}
			tokenStore={mock_token_store()}
			composerConfigEnv={mock_composer_config_env()}
		/>,
		root,
	);
}

function tabs(): HTMLButtonElement[] {
	return Array.from(root.querySelectorAll('[role="tab"]')) as HTMLButtonElement[];
}

function panels(): HTMLDivElement[] {
	return Array.from(root.querySelectorAll('[role="tabpanel"]')) as HTMLDivElement[];
}

function press_key(target: HTMLElement, key: string): void {
	const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
	target.dispatchEvent(event);
}

/**
 * Preact batches state updates via microtask. After dispatching an event
 * that triggers setState, await this helper before asserting on the DOM.
 */
async function flush(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('ComposerTabs', () => {
	it('renders five tabs with the expected labels', () => {
		mount();
		const labels = tabs().map((t) => t.textContent);
		expect(labels).toEqual(['Post', 'Reply', 'Photo', 'Doing', 'About']);
	});

	it('selects Note by default', () => {
		mount();
		const [note, ...rest] = tabs();
		expect(note?.getAttribute('aria-selected')).toBe('true');
		expect(note?.tabIndex).toBe(0);
		for (const tab of rest) {
			expect(tab.getAttribute('aria-selected')).toBe('false');
			expect(tab.tabIndex).toBe(-1);
		}
	});

	it('shows only the active panel; hides the others', () => {
		mount();
		const all = panels();
		expect(all[0]?.hasAttribute('hidden')).toBe(false); // Note
		for (let i = 1; i < all.length; i++) {
			expect(all[i]?.hasAttribute('hidden')).toBe(true);
		}
	});

	it('updates aria-controls/aria-labelledby pairing per tab+panel', () => {
		mount();
		const all_tabs = tabs();
		const all_panels = panels();
		expect(all_tabs.length).toBe(all_panels.length);
		for (let i = 0; i < all_tabs.length; i++) {
			const tab = all_tabs[i]!;
			const panel = all_panels[i]!;
			expect(tab.getAttribute('aria-controls')).toBe(panel.id);
			expect(panel.getAttribute('aria-labelledby')).toBe(tab.id);
		}
	});

	it('changes selection on tab click', async () => {
		mount();
		const reply = tabs()[1]!;
		reply.click();
		await flush();
		expect(tabs()[1]?.getAttribute('aria-selected')).toBe('true');
		expect(tabs()[1]?.tabIndex).toBe(0);
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('false');
		expect(panels()[1]?.hasAttribute('hidden')).toBe(false);
		expect(panels()[0]?.hasAttribute('hidden')).toBe(true);
	});

	it('moves selection right with ArrowRight', async () => {
		mount();
		press_key(tabs()[0]!, 'ArrowRight');
		await flush();
		expect(tabs()[1]?.getAttribute('aria-selected')).toBe('true');
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('false');
	});

	it('moves selection left with ArrowLeft', async () => {
		mount();
		press_key(tabs()[0]!, 'ArrowRight');
		await flush();
		press_key(tabs()[1]!, 'ArrowLeft');
		await flush();
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('true');
	});

	it('wraps from last tab back to first with ArrowRight', async () => {
		mount();
		press_key(tabs()[0]!, 'End');
		await flush();
		expect(tabs()[4]?.getAttribute('aria-selected')).toBe('true');
		press_key(tabs()[4]!, 'ArrowRight');
		await flush();
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('true');
	});

	it('wraps from first tab back to last with ArrowLeft', async () => {
		mount();
		press_key(tabs()[0]!, 'ArrowLeft');
		await flush();
		expect(tabs()[4]?.getAttribute('aria-selected')).toBe('true');
	});

	it('jumps to first tab with Home', async () => {
		mount();
		tabs()[3]!.click();
		await flush();
		press_key(tabs()[3]!, 'Home');
		await flush();
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('true');
	});

	it('jumps to last tab with End', async () => {
		mount();
		press_key(tabs()[0]!, 'End');
		await flush();
		expect(tabs()[4]?.getAttribute('aria-selected')).toBe('true');
	});

	it('ignores keys other than arrows / Home / End', async () => {
		mount();
		press_key(tabs()[0]!, 'a');
		press_key(tabs()[0]!, 'Enter');
		await flush();
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('true');
	});
});
