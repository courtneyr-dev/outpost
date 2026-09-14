import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { LifeMode, type LifeModeProps } from './life-mode';
import type { StoredToken } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';
import type { ComposerConfig } from '../../lib/composer-config';
import { MOODS_CACHE_KEY, type MoodsEnvironment } from '../../lib/pkiw-moods';
import { list } from '../../lib/offline-queue';
import en_us from '../../../../tests/fixtures/pkiw/moods-en_US.json';
import en_gb from '../../../../tests/fixtures/pkiw/moods-en_GB.json';

const token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

function composer_config(post_kinds: 'active' | 'inactive' | 'absent'): ComposerConfig {
	return {
		companions: {
			'post-kinds': post_kinds,
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
}

let root: HTMLDivElement;
let posted: URLSearchParams[];

beforeEach(() => {
	localStorage.clear();
	root = document.createElement('div');
	document.body.appendChild(root);
	posted = [];
});

afterEach(() => {
	render(null, root);
	root.remove();
});

function online_micropub(): MicropubEnvironment {
	return {
		fetch: async (_input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
			if (init?.method === 'POST') {
				posted.push(new URLSearchParams(String(init.body ?? '')));
				return new Response('', {
					status: 201,
					headers: { Location: 'https://example.test/2026/09/13/mood' },
				});
			}
			return new Response('<html></html>', {
				status: 200,
				headers: { Link: '<https://example.test/mp>; rel="micropub"' },
			});
		},
	};
}

const offline_micropub: MicropubEnvironment = {
	fetch: async (): Promise<Response> => {
		throw new TypeError('Failed to fetch');
	},
};

/** A moods site that answers each request with the next responder (the last one repeats). */
function moods_site(...answers: Array<() => Response | Promise<Response>>): {
	env: MoodsEnvironment;
	calls: string[];
} {
	const calls: string[] = [];
	return {
		calls,
		env: {
			fetch: (async (input: RequestInfo | URL): Promise<Response> => {
				calls.push(String(input));
				const answer = answers[Math.min(calls.length - 1, answers.length - 1)]!;
				return answer();
			}) as typeof fetch,
		},
	};
}

const ok = (body: unknown) => (): Response =>
	new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
const status = (code: number) => (): Response =>
	new Response(JSON.stringify({ code: 'x', message: 'x' }), { status: code });
const network_down = (): Response => {
	throw new TypeError('Failed to fetch');
};

function mount(props: Partial<LifeModeProps>): void {
	render(<LifeMode token={token} micropubEnv={online_micropub()} {...props} />, root);
}

async function flush(times = 3): Promise<void> {
	for (let i = 0; i < times; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
}

function mood_input(): HTMLInputElement {
	return root.querySelector('#outpost-life-primary') as HTMLInputElement;
}

function suggestions(): string[] {
	return Array.from(root.querySelectorAll('#outpost-life-mood-suggestions option')).map(
		(option) => option.getAttribute('value') ?? '',
	);
}

function type(value: string): void {
	const input = mood_input();
	input.value = value;
	input.dispatchEvent(new Event('input', { bubbles: true }));
}

function submit(): void {
	root.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

function expect_no_error_ui(): void {
	const alert = root.querySelector('[role="alert"]') as HTMLElement;
	expect(alert.hidden).toBe(true);
	expect(alert.textContent).toBe('');
}

describe('LifeMode mood suggestions from Post Kinds', () => {
	it('renders the labels of a mocked /moods response as datalist options on the mood field', async () => {
		const site = moods_site(ok(en_us));
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });
		await flush();

		expect(site.calls).toHaveLength(1);
		expect(site.calls[0]).toContain('/wp-json/post-kinds-indieweb/v1/moods');
		expect(suggestions()).toEqual(en_us.moods.map((m) => m.label));
		expect(mood_input().getAttribute('list')).toBe('outpost-life-mood-suggestions');
	});

	it.each([
		['en_US', en_us, 'Energized', 'Energised'],
		['en_GB', en_gb, 'Energised', 'Energized'],
	])('follows the %s response spelling with no client-side respelling', async (_l, fixture, shown, absent) => {
		const site = moods_site(ok(fixture));
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });
		await flush();

		expect(suggestions()).toEqual(fixture.moods.map((m) => m.label));
		expect(suggestions()).toContain(shown);
		expect(suggestions()).not.toContain(absent);
	});

	it('sends a picked label unchanged as Micropub mood, with no key property', async () => {
		const site = moods_site(ok(en_gb));
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });
		await flush();

		type('Energised');
		await flush(1);
		submit();
		await flush(6);

		expect(posted).toHaveLength(1);
		expect(posted[0]!.get('mood')).toBe('Energised');
		expect(posted[0]!.getAll('mood')).toHaveLength(1);
		expect(Array.from(posted[0]!.keys()).some((k) => k.includes('mood-key'))).toBe(false);
	});

	it('allows and preserves a custom value that is not in the list', async () => {
		let release: () => void = () => undefined;
		const gate = new Promise<void>((resolve) => {
			release = resolve;
		});
		const site = moods_site(async () => {
			await gate;
			return ok(en_us)();
		});
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });

		// Typed before the suggestions arrive; their arrival must not touch it.
		type('Frazzled, but coping');
		await flush(1);
		release();
		await flush();

		expect(suggestions()).not.toContain('Frazzled, but coping');
		expect(suggestions().length).toBeGreaterThan(0);
		expect(mood_input().value).toBe('Frazzled, but coping');

		submit();
		await flush(6);
		expect(posted[0]!.get('mood')).toBe('Frazzled, but coping');
	});

	it.each([
		['401', status(401)],
		['403', status(403)],
		['404', status(404)],
		['a network failure', network_down],
	])('falls back to free text with no error UI on %s', async (_label, answer) => {
		const site = moods_site(answer);
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });
		await flush();

		expect(site.calls).toHaveLength(1);
		expect(root.querySelector('datalist')).toBeNull();
		expect(mood_input().hasAttribute('list')).toBe(false);
		expect_no_error_ui();

		type('focused');
		await flush(1);
		submit();
		await flush(6);
		expect(posted[0]!.get('mood')).toBe('focused');
	});

	it('clears a stored copy when the route answers 404 (Post Kinds downgraded)', async () => {
		localStorage.setItem(MOODS_CACHE_KEY, JSON.stringify(en_us));
		const site = moods_site(status(404));
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });
		await flush();

		expect(root.querySelector('datalist')).toBeNull();
		expect(localStorage.getItem(MOODS_CACHE_KEY)).toBeNull();
	});

	it('refetches and replaces suggestions when the version changes', async () => {
		localStorage.setItem(MOODS_CACHE_KEY, JSON.stringify(en_us));
		const site = moods_site(ok(en_gb), ok(en_us));
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });

		// The stored copy shows first, then the site's newer version replaces it.
		expect(suggestions()).toContain('Energized');
		await flush();
		expect(site.calls).toHaveLength(1);
		expect(suggestions()).toContain('Energised');
		expect(JSON.parse(localStorage.getItem(MOODS_CACHE_KEY)!).version).toBe(en_gb.version);

		// Coming back online asks again and picks up the setting change back to en_US.
		window.dispatchEvent(new Event('online'));
		await flush();
		expect(site.calls).toHaveLength(2);
		expect(suggestions()).toContain('Energized');
		expect(JSON.parse(localStorage.getItem(MOODS_CACHE_KEY)!).version).toBe(en_us.version);
	});

	it('keeps the stored copy when the version is unchanged', async () => {
		localStorage.setItem(MOODS_CACHE_KEY, JSON.stringify(en_gb));
		const site = moods_site(ok(en_gb));
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });
		await flush();

		expect(site.calls).toHaveLength(1);
		expect(suggestions()).toEqual(en_gb.moods.map((m) => m.label));
	});

	it('makes no request and shows no suggestions when Post Kinds is not active', async () => {
		localStorage.setItem(MOODS_CACHE_KEY, JSON.stringify(en_us));
		const site = moods_site(ok(en_us));
		mount({ composerConfig: composer_config('inactive'), moodsEnv: site.env });
		await flush();

		expect(site.calls).toHaveLength(0);
		expect(root.querySelector('datalist')).toBeNull();
	});

	it('offers mood suggestions only on the Mood variant', async () => {
		const site = moods_site(ok(en_us));
		mount({ composerConfig: composer_config('active'), moodsEnv: site.env });
		await flush();
		expect(suggestions().length).toBeGreaterThan(0);

		(root.querySelector('input[name="outpost-life-variant"][value="weather"]') as HTMLInputElement).click();
		await flush(1);
		expect(root.querySelector('datalist')).toBeNull();
		expect(mood_input().hasAttribute('list')).toBe(false);
	});

	it('leaves the accessible name and label of the mood field unchanged', async () => {
		const describe_field = (): Record<string, string | null> => {
			const input = mood_input();
			const label = root.querySelector('label[for="outpost-life-primary"]');
			return {
				id: input.id,
				label: label?.textContent ?? null,
				ariaLabel: input.getAttribute('aria-label'),
				ariaLabelledby: input.getAttribute('aria-labelledby'),
				ariaDescribedby: input.getAttribute('aria-describedby'),
				placeholder: input.getAttribute('placeholder'),
				type: input.getAttribute('type'),
				role: input.getAttribute('role'),
			};
		};

		mount({ composerConfig: composer_config('absent') });
		await flush();
		const without = describe_field();
		render(null, root);

		mount({ composerConfig: composer_config('active'), moodsEnv: moods_site(ok(en_gb)).env });
		await flush();
		expect(suggestions().length).toBeGreaterThan(0);
		expect(describe_field()).toEqual(without);
		expect(without.label).toBe('How are you feeling?');
	});
});

describe('LifeMode mood suggestions offline', () => {
	it('reuses the last good copy in a composer opened offline and still queues the post', async () => {
		localStorage.setItem(MOODS_CACHE_KEY, JSON.stringify(en_gb));
		const site = moods_site(network_down);
		// Offline, composer-config never loads, so no composerConfig reaches the mode.
		mount({ micropubEnv: offline_micropub, moodsEnv: site.env });
		await flush();

		expect(site.calls).toHaveLength(0);
		expect(suggestions()).toEqual(en_gb.moods.map((m) => m.label));

		type('Honoured');
		await flush(1);
		submit();
		await flush(10);

		expect(root.textContent).toContain('Saved for later');
		expect_no_error_ui();
		const entries = await list();
		expect(entries.some((entry) => entry.properties.mood === 'Honoured')).toBe(true);
	});

	it('keeps the last good copy when the moods request fails while Post Kinds is active', async () => {
		localStorage.setItem(MOODS_CACHE_KEY, JSON.stringify(en_us));
		const site = moods_site(network_down);
		mount({ composerConfig: composer_config('active'), micropubEnv: offline_micropub, moodsEnv: site.env });
		await flush();

		expect(site.calls).toHaveLength(1);
		expect(suggestions()).toEqual(en_us.moods.map((m) => m.label));
		expect_no_error_ui();
	});

	it('shows no suggestions offline when nothing was ever fetched, and submit still queues', async () => {
		mount({ micropubEnv: offline_micropub, moodsEnv: moods_site(network_down).env });
		await flush();
		expect(root.querySelector('datalist')).toBeNull();

		type('calm-ish');
		await flush(1);
		submit();
		await flush(10);
		expect(root.textContent).toContain('Saved for later');
	});
});
