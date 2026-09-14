import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { render } from 'preact';
import { ComposerTabs, CONFIG_RETRY_DELAYS_MS } from './composer-tabs';
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
	it('renders seven tabs with the expected labels', () => {
		mount();
		const labels = tabs().map((t) => t.textContent);
		expect(labels).toEqual(['Post', 'Reply', 'Photo', 'Doing', 'Life', 'Recipe', 'About']);
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
		const last = tabs().length - 1;
		expect(tabs()[last]?.getAttribute('aria-selected')).toBe('true');
		press_key(tabs()[last]!, 'ArrowRight');
		await flush();
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('true');
	});

	it('wraps from first tab back to last with ArrowLeft', async () => {
		mount();
		press_key(tabs()[0]!, 'ArrowLeft');
		await flush();
		const last = tabs().length - 1;
		expect(tabs()[last]?.getAttribute('aria-selected')).toBe('true');
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
		const last = tabs().length - 1;
		expect(tabs()[last]?.getAttribute('aria-selected')).toBe('true');
	});

	it('ignores keys other than arrows / Home / End', async () => {
		mount();
		press_key(tabs()[0]!, 'a');
		press_key(tabs()[0]!, 'Enter');
		await flush();
		expect(tabs()[0]?.getAttribute('aria-selected')).toBe('true');
	});
});

describe('ComposerTabs: composer-config notice', () => {
	const valid_config = {
		companions: {
			'post-kinds': 'absent',
			'post-formats': 'absent',
			xfn: 'absent',
			'syndication-links': 'absent',
			yoast: 'absent',
			activitypub: 'absent',
			'accessibility-checker': 'absent',
			'rss-chat-routing': 'absent',
		},
		postFormats: null,
		xfnRels: [],
		existingCategories: [],
		existingTags: [],
		bridgyHostMap: {},
		siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'note' },
	};

	const unreachable = (): Promise<Response> => Promise.reject(new TypeError('Failed to fetch'));
	const loaded = (): Promise<Response> =>
		Promise.resolve(
			new Response(JSON.stringify(valid_config), {
				status: 200,
				headers: { 'Content-Type': 'application/json' },
			}),
		);
	const rejected = (): Promise<Response> => Promise.resolve(new Response('{}', { status: 401 }));

	function config_site(answers: Array<() => Promise<Response>>): {
		env: { fetch: typeof fetch };
		calls: () => number;
	} {
		let count = 0;
		const env = {
			fetch: ((): Promise<Response> => {
				const answer = answers[Math.min(count, answers.length - 1)]!;
				count += 1;
				return answer();
			}) as typeof fetch,
		};
		return { env, calls: () => count };
	}

	function mount_with(env: { fetch: typeof fetch }): void {
		render(
			<ComposerTabs
				token={mock_token}
				tokenStore={mock_token_store()}
				composerConfigEnv={env}
				queueEnv={{ indexedDB: new IDBFactory() }}
			/>,
			root,
		);
	}

	function notice(): HTMLElement | null {
		return root.querySelector('.outpost-config-error');
	}

	function notice_buttons(): Array<string | null> {
		return Array.from(notice()?.querySelectorAll('button') ?? []).map((b) => b.textContent);
	}

	afterEach(() => {
		vi.useRealTimers();
	});

	it('offers Try again, not sign-out, when the site is unreachable', async () => {
		mount_with(config_site([unreachable]).env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());
		expect(notice_buttons()).toEqual(['Try again']);
	});

	it('fetches again when the browser comes back online and clears the notice', async () => {
		const { env, calls } = config_site([unreachable, loaded]);
		mount_with(env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());

		window.dispatchEvent(new Event('online'));
		await vi.waitFor(() => expect(notice()).toBeNull());
		expect(calls()).toBe(2);
	});

	it('Try again fetches immediately', async () => {
		const { env, calls } = config_site([unreachable, loaded]);
		mount_with(env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());

		(notice()?.querySelector('button') as HTMLButtonElement).click();
		await vi.waitFor(() => expect(notice()).toBeNull());
		expect(calls()).toBe(2);
	});

	it('retries on its own after the first backoff delay', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
		const { env, calls } = config_site([unreachable, loaded]);
		mount_with(env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());
		await vi.waitFor(() => expect(vi.getTimerCount()).toBeGreaterThan(0));

		// vi.waitFor advances fake timers while it polls, so check at half the delay.
		await vi.advanceTimersByTimeAsync(CONFIG_RETRY_DELAYS_MS[0]! / 2);
		expect(calls()).toBe(1);
		await vi.advanceTimersByTimeAsync(CONFIG_RETRY_DELAYS_MS[0]! / 2);
		await vi.waitFor(() => expect(notice()).toBeNull());
		expect(calls()).toBe(2);
	});

	it('stops retrying after the last backoff delay', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
		const { env, calls } = config_site([unreachable]);
		mount_with(env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());
		for (let i = 0; i < CONFIG_RETRY_DELAYS_MS.length; i++) {
			await vi.waitFor(() => expect(calls()).toBe(i + 1));
			await vi.waitFor(() => expect(vi.getTimerCount()).toBeGreaterThan(0));
			await vi.advanceTimersByTimeAsync(CONFIG_RETRY_DELAYS_MS[i]!);
		}
		await vi.waitFor(() => expect(calls()).toBe(CONFIG_RETRY_DELAYS_MS.length + 1));
		await vi.advanceTimersByTimeAsync(10 * 60_000);
		expect(calls()).toBe(CONFIG_RETRY_DELAYS_MS.length + 1);
		expect(notice_buttons()).toEqual(['Try again']);
	});

	it('keeps sign-out for a rejected token and does not retry it', async () => {
		const { env, calls } = config_site([rejected, loaded]);
		mount_with(env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());
		expect(notice_buttons()).toEqual(['Sign out + back in']);

		window.dispatchEvent(new Event('online'));
		await new Promise((resolve) => setTimeout(resolve, 30));
		expect(calls()).toBe(1);
		expect(notice()).not.toBeNull();
	});
});
