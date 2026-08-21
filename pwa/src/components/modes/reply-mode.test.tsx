import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { ReplyMode } from './reply-mode';
import type { StoredToken } from '../../lib/token-store';

const mock_token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

let root: HTMLDivElement;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
});

function mount(): void {
	render(<ReplyMode token={mock_token} />, root);
}

async function flush(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
}

function title_text(): string | null {
	return root.querySelector('.outpost-card__title')?.textContent ?? null;
}

function submit_button(): HTMLButtonElement {
	return root.querySelector('button[type="submit"]') as HTMLButtonElement;
}

function input_for(id: string): HTMLInputElement | HTMLTextAreaElement {
	return root.querySelector('#' + id) as HTMLInputElement | HTMLTextAreaElement;
}

function set_value(el: HTMLInputElement | HTMLTextAreaElement, value: string): void {
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

function variant_radios(): HTMLInputElement[] {
	return Array.from(
		root.querySelectorAll('input[name="outpost-reply-variant"]'),
	) as HTMLInputElement[];
}

function click_variant(value: string): void {
	const radio = variant_radios().find((r) => r.value === value);
	if (!radio) throw new Error('no variant radio for ' + value);
	radio.click();
}

function rsvp_radios(): HTMLInputElement[] {
	return Array.from(
		root.querySelectorAll('input[name="outpost-rsvp-value"]'),
	) as HTMLInputElement[];
}

describe('ReplyMode variant picker', () => {
	it('renders 11 variant radios in order', () => {
		mount();
		const values = variant_radios().map((r) => r.value);
		expect(values).toEqual([
			'reply',
			'like',
			'favorite',
			'repost',
			'bookmark',
			'rsvp',
			'follow',
			'wishlist',
			'tag',
			'acquisition',
			'issue',
		]);
	});

	it('Reply is selected by default', () => {
		mount();
		expect(variant_radios()[0]?.checked).toBe(true);
		expect(title_text()).toBe('Reply');
	});

	it('switching variant updates the heading', async () => {
		mount();
		const [, like_radio] = variant_radios();
		like_radio?.click();
		await flush();
		expect(title_text()).toBe('Like');
	});

	it('switching variant updates the submit button label', async () => {
		mount();
		expect(submit_button().textContent).toBe('Post reply');
		click_variant('repost');
		await flush();
		expect(submit_button().textContent).toBe('Post repost');
		click_variant('bookmark');
		await flush();
		expect(submit_button().textContent).toBe('Post bookmark');
		click_variant('rsvp');
		await flush();
		expect(submit_button().textContent).toBe('Post RSVP');
		click_variant('follow');
		await flush();
		expect(submit_button().textContent).toBe('Post follow');
		click_variant('favorite');
		await flush();
		expect(submit_button().textContent).toBe('Post favorite');
		click_variant('acquisition');
		await flush();
		expect(submit_button().textContent).toBe('Post acquisition');
	});

	it('Reply variant requires both URL and content for submit', async () => {
		mount();
		const url_input = input_for('outpost-reply-target') as HTMLInputElement;
		const submit = submit_button();

		// URL only — Reply still disabled because content empty
		set_value(url_input, 'https://example.test/post');
		await flush();
		expect(submit.disabled).toBe(true);

		// Add content — now enabled
		set_value(input_for('outpost-reply-content'), 'My reply');
		await flush();
		expect(submit.disabled).toBe(false);
	});

	it('Like variant only requires URL (content optional)', async () => {
		mount();
		const url_input = input_for('outpost-reply-target') as HTMLInputElement;
		click_variant('like');
		await flush();

		set_value(url_input, 'https://example.test/post');
		await flush();
		expect(submit_button().disabled).toBe(false);
	});

	it('Follow variant only requires URL', async () => {
		mount();
		const url_input = input_for('outpost-reply-target') as HTMLInputElement;
		click_variant('follow');
		await flush();

		set_value(url_input, 'https://example.test/profile');
		await flush();
		expect(submit_button().disabled).toBe(false);
	});

	it('updates target-input label per variant', async () => {
		mount();
		const label = (): string | null =>
			root.querySelector('label[for="outpost-reply-target"]')?.textContent ?? null;

		expect(label()).toBe('In reply to');
		click_variant('like');
		await flush();
		expect(label()).toBe('Like of');
		click_variant('favorite');
		await flush();
		expect(label()).toBe('Favorite of');
		click_variant('repost');
		await flush();
		expect(label()).toBe('Repost of');
		click_variant('bookmark');
		await flush();
		expect(label()).toBe('Bookmark of');
		click_variant('rsvp');
		await flush();
		expect(label()).toBe('Event URL');
		click_variant('follow');
		await flush();
		expect(label()).toBe('Person or feed URL');
		click_variant('acquisition');
		await flush();
		expect(label()).toBe('Item URL');
	});

	it('shows the RSVP yes/no/maybe/interested picker only when RSVP is selected', async () => {
		mount();
		expect(rsvp_radios().length).toBe(0); // not visible on Reply

		click_variant('rsvp');
		await flush();
		const values = rsvp_radios().map((r) => r.value);
		expect(values).toEqual(['yes', 'no', 'maybe', 'interested']);
		expect(rsvp_radios()[0]?.checked).toBe(true); // yes is default

		click_variant('reply');
		await flush();
		expect(rsvp_radios().length).toBe(0);
	});

	it('clears the success banner when switching variants', async () => {
		// Regression: the 2026-07-02 UX pass posted a Like, switched to
		// another variant, and read the lingering "Posted to" banner as
		// that variant's success. The banner belongs to the submission
		// that produced it — a variant switch must reset it.
		const env = {
			fetch: async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
				if (init?.method === 'POST') {
					return new Response('', {
						status: 201,
						headers: { Location: 'https://example.test/2026/07/02/like-1' },
					});
				}
				// Endpoint discovery GET against token.me.
				return new Response('<html></html>', {
					status: 200,
					headers: { Link: '<https://example.test/mp>; rel="micropub"' },
				});
			},
		};
		render(<ReplyMode token={mock_token} micropubEnv={env} />, root);

		variant_radios()[1]?.click(); // Like — URL only
		await flush();
		set_value(input_for('outpost-reply-target'), 'https://example.test/some-post/');
		await flush();
		root
			.querySelector('form')
			?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await flush();
		await flush();

		const banner = root.querySelector('p.outpost-status') as HTMLParagraphElement;
		expect(banner.hidden).toBe(false);
		expect(banner.textContent).toContain('Posted to');

		variant_radios()[3]?.click(); // Bookmark
		await flush();
		expect(banner.hidden).toBe(true);
	});
});
