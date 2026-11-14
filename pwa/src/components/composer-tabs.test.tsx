import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { render } from 'preact';
import { ComposerTabs, CONFIG_RETRY_DELAYS_MS } from './composer-tabs';
import type { StoredToken, TokenStoreEnvironment } from '../lib/token-store';
import { CACHE_KEY, write_cache, type StartScreen } from '../lib/appearance';
import { stash_share_target } from '../lib/share-target';
import { DRAFT_SAVE_DELAY_MS } from '../lib/drafts';
import { START_SCREEN_WAIT_MS } from '../lib/start-screen';

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

/** This device already knows the person's start screen. */
function prefer_start(start: StartScreen): void {
	write_cache({ mode: 'system', source: 'outpost', css: '', start, me: mock_token.me });
}

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	// The tab-framework tests below start where 1.0.24 launched: on Post.
	prefer_start('note');
});

afterEach(() => {
	render(null, root);
	root.remove();
	localStorage.clear();
	sessionStorage.clear();
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
	return Array.from(root.querySelectorAll('.outpost-tab')) as HTMLButtonElement[];
}

function panels(): HTMLDivElement[] {
	return Array.from(root.querySelectorAll('.outpost-mode-panel')) as HTMLDivElement[];
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
	it('renders the seven composer modes as a named group of buttons', () => {
		mount();
		const group = root.querySelector('[role="group"][aria-label="Composer modes"]');
		const buttons = Array.from(group?.querySelectorAll('button') ?? []);

		expect(buttons).toHaveLength(7);
		expect(buttons.map((button) => button.textContent)).toEqual([
			'Post',
			'Reply',
			'Photo',
			'Doing',
			'Life',
			'Recipe',
			'About',
		]);
		expect(root.querySelector('[role="tab"]')).toBeNull();
		expect(root.querySelector('[role="tablist"]')).toBeNull();
		expect(root.querySelector('[role="tabpanel"]')).toBeNull();
	});

	it('marks only Post as current by default', () => {
		mount();
		expect(tabs()[0]?.getAttribute('aria-current')).toBe('true');
		expect(tabs().filter((button) => button.hasAttribute('aria-current'))).toHaveLength(1);
	});

	it('connects every native button to an existing mounted panel', () => {
		mount();
		expect(panels()).toHaveLength(7);
		for (const button of tabs()) {
			expect(button.tagName).toBe('BUTTON');
			expect(button.type).toBe('button');
			expect(button.hasAttribute('tabindex')).toBe(false);
			const panel_id = button.getAttribute('aria-controls');
			expect(panel_id).toBeTruthy();
			expect(root.querySelector(`#${panel_id ?? ''}`)).not.toBeNull();
		}
	});

	it('clicking Photo makes it the only current mode and leaves focus on its button', async () => {
		mount();
		const photo = tabs()[2]!;
		photo.focus();
		photo.click();
		await flush();

		expect(photo.getAttribute('aria-current')).toBe('true');
		expect(tabs().filter((button) => button.hasAttribute('aria-current'))).toEqual([photo]);
		expect(panels().filter((panel) => !panel.hasAttribute('hidden')).map((panel) => panel.id)).toEqual([
			'outpost-panel-photo',
		]);
		expect(panels().filter((panel) => panel.hasAttribute('hidden'))).toHaveLength(6);
		expect(document.activeElement).toBe(photo);
	});

	it('does not change selection for tab-pattern navigation keys', async () => {
		mount();
		for (const key of ['ArrowRight', 'ArrowLeft', 'Home', 'End']) {
			press_key(tabs()[0]!, key);
		}
		await flush();
		expect(tabs()[0]?.getAttribute('aria-current')).toBe('true');
		expect(tabs().filter((button) => button.hasAttribute('aria-current'))).toHaveLength(1);
	});

	it('preserves typed Post text while switching to Photo and back', async () => {
		mount();
		const content = root.querySelector('#outpost-note-content') as HTMLTextAreaElement;
		content.value = 'Draft text that must survive';
		content.dispatchEvent(new Event('input', { bubbles: true }));
		await flush();

		tabs()[2]!.click();
		await flush();
		tabs()[0]!.click();
		await flush();

		expect((root.querySelector('#outpost-note-content') as HTMLTextAreaElement).value).toBe(
			'Draft text that must survive',
		);
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
				appearanceEnv={{ fetch: () => Promise.reject(new TypeError('offline')) }}
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
		expect(notice_buttons()).toEqual(['Sign in again']);
		expect(notice()?.textContent).toContain('sign-in expired');

		window.dispatchEvent(new Event('online'));
		await new Promise((resolve) => setTimeout(resolve, 30));
		expect(calls()).toBe(1);
		expect(notice()).not.toBeNull();
	});

	it('tells people their unsent text stays and nothing posts on its own after signing in again', async () => {
		const { env } = config_site([rejected]);
		mount_with(env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());
		expect(notice()?.textContent).toContain('stays on this device');
		expect(notice()?.textContent).toContain('Nothing posts until you tap');
		expect(notice()?.textContent).not.toContain('copy anything');
	});

	it('still says to copy text first when this device cannot keep drafts', async () => {
		const full = {
			getItem: (): string | null => null,
			setItem: (): void => {
				throw new DOMException('full', 'QuotaExceededError');
			},
			removeItem: (): void => {},
		};
		const { env } = config_site([rejected]);
		render(
			<ComposerTabs
				token={mock_token}
				tokenStore={mock_token_store()}
				composerConfigEnv={env}
				queueEnv={{ indexedDB: new IDBFactory() }}
				appearanceEnv={{ fetch: () => Promise.reject(new TypeError('offline')) }}
				draftStorage={full}
			/>,
			root,
		);
		await vi.waitFor(() => expect(notice()).not.toBeNull());
		expect(notice()?.textContent).toContain('copy anything');
		expect(notice()?.textContent).not.toContain('stays on this device');
	});

	it('does not clear drafts when signing in again', async () => {
		const reload_spy = vi.fn();
		vi.spyOn(window, 'location', 'get').mockReturnValue({
			...window.location,
			reload: reload_spy,
		} as Location);
		localStorage.setItem(
			'outpost:drafts',
			JSON.stringify({ v: 1, me: 'https://example.test/', modes: { note: { savedAt: 1, fields: { content: 'kept' }, meta: {} } } }),
		);
		const { env } = config_site([rejected]);
		mount_with(env);
		await vi.waitFor(() => expect(notice_buttons()).toEqual(['Sign in again']));
		const button = Array.from(notice()?.querySelectorAll('button') ?? []).find(
			(b) => b.textContent === 'Sign in again',
		) as HTMLButtonElement;
		button.click();
		await vi.waitFor(() => expect(reload_spy).toHaveBeenCalled());
		expect(localStorage.getItem('outpost:drafts')).toContain('kept');
		vi.restoreAllMocks();
	});

	it('treats a 403 as a permission limit, not an expired sign-in, and does not retry it', async () => {
		const forbidden = (): Promise<Response> => Promise.resolve(new Response('{}', { status: 403 }));
		const { env, calls } = config_site([forbidden, loaded]);
		mount_with(env);
		await vi.waitFor(() => expect(notice()).not.toBeNull());
		expect(notice()?.textContent).not.toContain('expired');
		expect(notice()?.textContent).toContain("didn't allow");
		expect(notice_buttons()).toEqual([]);

		window.dispatchEvent(new Event('online'));
		await new Promise((resolve) => setTimeout(resolve, 30));
		expect(calls()).toBe(1);
	});
});

describe('ComposerTabs: Home and the start screen', () => {
	const site = (body: Record<string, string>): { fetch: typeof fetch } => ({
		fetch: ((): Promise<Response> => Promise.resolve(new Response(JSON.stringify(body), { status: 200 }))) as typeof fetch,
	});
	const new_user_site = site({ mode_preference: 'system', style_source: 'outpost', start_screen: 'home' });

	function mount_home(appearanceEnv: { fetch: typeof fetch } = new_user_site): void {
		render(
			<ComposerTabs
				token={mock_token}
				tokenStore={mock_token_store()}
				composerConfigEnv={mock_composer_config_env()}
				queueEnv={{ indexedDB: new IDBFactory() }}
				appearanceEnv={appearanceEnv}
			/>,
			root,
		);
	}

	const home = (): HTMLElement | null => root.querySelector('.outpost-home');
	const tablist_wrap = (): HTMLElement => root.querySelector('.outpost-tablist-wrap') as HTMLElement;
	const visible_panels = (): string[] => panels().filter((p) => !p.hidden).map((p) => p.id);
	const footer_home = (): HTMLButtonElement | null => root.querySelector('.outpost-footer-nav__home');
	const card = (mode: string): HTMLButtonElement => root.querySelector(`.outpost-home-card[data-mode="${mode}"]`) as HTMLButtonElement;

	beforeEach(() => {
		localStorage.removeItem(CACHE_KEY);
	});

	it('a new user waits for the site, then lands on Home with no composer showing', async () => {
		mount_home();
		expect(root.textContent).toContain('Opening Outpost');
		expect(visible_panels()).toEqual([]);
		await vi.waitFor(() => expect(home()).not.toBeNull());
		expect(root.textContent).not.toContain('Opening Outpost');
		expect(tablist_wrap().hidden).toBe(true);
		expect(visible_panels()).toEqual([]);
		expect(footer_home()).toBeNull();
		expect(document.title).toBe('Home – Outpost');
		expect(root.querySelector('#outpost-home-title')?.textContent).toBe('What would you like to post?');
	});

	it('offers six cards, each a named button with a description', async () => {
		mount_home();
		await vi.waitFor(() => expect(home()).not.toBeNull());
		const cards = Array.from(root.querySelectorAll<HTMLButtonElement>('.outpost-home-card'));
		expect(cards.map((c) => c.dataset['mode'])).toEqual(['note', 'reply', 'photo', 'listen', 'life', 'recipe']);
		for (const c of cards) {
			expect(c.tagName).toBe('BUTTON');
			const name = document.getElementById(c.getAttribute('aria-labelledby') ?? '')?.textContent;
			const description = document.getElementById(c.getAttribute('aria-describedby') ?? '')?.textContent;
			expect(name).toBeTruthy();
			expect(description).toBeTruthy();
		}
		expect(root.querySelector('.outpost-home')?.textContent).toContain('Acquisition');
		expect(root.querySelector('.outpost-home')?.textContent).toContain('Exercise');
	});

	it('each card opens its composer tab and moves focus to that tab', async () => {
		for (const mode of ['note', 'reply', 'photo', 'listen', 'life', 'recipe']) {
			render(null, root);
			mount_home();
			await vi.waitFor(() => expect(home()).not.toBeNull());
			card(mode).click();
			await flush();
			await new Promise((r) => requestAnimationFrame(() => r(null)));
			expect(home()).toBeNull();
			expect(tablist_wrap().hidden).toBe(false);
			expect(visible_panels()).toEqual([`outpost-panel-${mode}`]);
			const tab = root.querySelector(`#outpost-tab-${mode}`);
			expect(tab?.getAttribute('aria-current')).toBe('true');
			expect(document.activeElement).toBe(tab);
		}
	});

	it('every tab, About included, has the Home link; it returns Home and focuses the heading', async () => {
		mount_home();
		await vi.waitFor(() => expect(home()).not.toBeNull());
		card('note').click();
		await flush();
		for (const tab of tabs()) {
			tab.click();
			await flush();
			expect(footer_home()?.textContent?.trim()).toBe('← Home');
			expect(footer_home()?.closest('nav')).not.toBeNull();
		}
		footer_home()?.click();
		await flush();
		await new Promise((r) => requestAnimationFrame(() => r(null)));
		expect(home()).not.toBeNull();
		expect(document.activeElement?.id).toBe('outpost-home-title');
		expect(footer_home()).toBeNull();
	});

	it('going Home keeps typed text and labels the tab that holds it', async () => {
		mount_home();
		await vi.waitFor(() => expect(home()).not.toBeNull());
		card('note').click();
		await flush();
		const body = root.querySelector<HTMLTextAreaElement>('#outpost-note-content') as HTMLTextAreaElement;
		body.value = 'Half a thought';
		body.dispatchEvent(new Event('input', { bubbles: true }));
		await new Promise((r) => setTimeout(r, DRAFT_SAVE_DELAY_MS + 50));
		footer_home()?.click();
		await vi.waitFor(() => expect(card('note').textContent).toContain('Unsent draft'));
		expect(card('reply').textContent).not.toContain('Unsent draft');
		const name_ids = card('note').getAttribute('aria-labelledby')?.split(' ') ?? [];
		expect(name_ids.map((id) => document.getElementById(id)?.textContent)).toEqual(['Post', 'Unsent draft']);
		card('note').click();
		await flush();
		expect((root.querySelector('#outpost-note-content') as HTMLTextAreaElement).value).toBe('Half a thought');
	});

	it('opens a saved start screen directly, without waiting', () => {
		prefer_start('reply');
		mount_home({ fetch: (() => new Promise(() => {})) as typeof fetch });
		expect(root.textContent).not.toContain('Opening Outpost');
		expect(visible_panels()).toEqual(['outpost-panel-reply']);
		expect(home()).toBeNull();
	});

	it('opens each of the eight saved start screens', async () => {
		for (const start of ['home', 'note', 'reply', 'photo', 'listen', 'life', 'recipe', 'about'] as const) {
			render(null, root);
			prefer_start(start);
			mount_home({ fetch: (() => new Promise(() => {})) as typeof fetch });
			if (start === 'home') {
				expect(home()).not.toBeNull();
				expect(visible_panels()).toEqual([]);
			} else {
				expect(visible_panels()).toEqual([`outpost-panel-${start}`]);
			}
		}
	});

	it('a share launch opens its tab even when another start screen is saved', async () => {
		prefer_start('reply');
		stash_share_target({ tab: 'photo' });
		mount_home({ fetch: (() => new Promise(() => {})) as typeof fetch });
		// The share's decision comes first and is the only thing showing (T10-S3-05 v1.2)…
		expect(root.querySelector('[data-outpost-share-review]')).not.toBeNull();
		expect(visible_panels()).toEqual([]);
		// …then its tab opens, not the saved start screen.
		Array.from(root.querySelectorAll('button'))
			.find((b) => (b.textContent ?? '').trim() === 'Discard shared content')
			?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await vi.waitFor(() => expect(visible_panels()).toEqual(['outpost-panel-photo']));
	});

	it("uses the site's saved start screen on a device that hasn't stored one", async () => {
		mount_home(site({ mode_preference: 'night', style_source: 'outpost', start_screen: 'life' }));
		await vi.waitFor(() => expect(visible_panels()).toEqual(['outpost-panel-life']));
		expect(home()).toBeNull();
	});

	it('stops waiting and opens Home when the site does not answer', async () => {
		mount_home({ fetch: (() => new Promise(() => {})) as typeof fetch });
		expect(root.textContent).toContain('Opening Outpost');
		await vi.waitFor(() => expect(home()).not.toBeNull(), { timeout: START_SCREEN_WAIT_MS + 1500 });
	});

	it('changing the start screen or appearance never moves the person', async () => {
		prefer_start('about');
		mount_home({ fetch: (() => new Promise(() => {})) as typeof fetch });
		const select = root.querySelector<HTMLSelectElement>('#outpost-about-prefs-start') as HTMLSelectElement;
		select.value = 'recipe';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		root.querySelector<HTMLInputElement>('input[name="outpost-about-prefs-mode"][value="night"]')?.click();
		await flush();
		await vi.waitFor(() => expect(document.body.classList.contains('outpost-mode-night')).toBe(true));
		expect(visible_panels()).toEqual(['outpost-panel-about']);
		expect(root.querySelector('#outpost-tab-about')?.getAttribute('aria-current')).toBe('true');
		expect(document.body.classList.contains('outpost-mode-night')).toBe(true);
		expect(JSON.parse(localStorage.getItem(CACHE_KEY) ?? '{}').start).toBe('recipe');
	});
});
