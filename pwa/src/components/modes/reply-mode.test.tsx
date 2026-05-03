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

function radios(): HTMLInputElement[] {
	return Array.from(root.querySelectorAll('input[type="radio"]')) as HTMLInputElement[];
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

function rsvp_radios(): HTMLInputElement[] {
	return Array.from(
		root.querySelectorAll('input[name="outpost-rsvp-value"]'),
	) as HTMLInputElement[];
}

describe('ReplyMode variant picker', () => {
	it('renders 9 variant radios in order', () => {
		mount();
		const values = variant_radios().map((r) => r.value);
		expect(values).toEqual([
			'reply',
			'like',
			'repost',
			'bookmark',
			'rsvp',
			'follow',
			'wishlist',
			'tag',
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
		variant_radios()[2]?.click(); // repost
		await flush();
		expect(submit_button().textContent).toBe('Post repost');
		variant_radios()[3]?.click(); // bookmark
		await flush();
		expect(submit_button().textContent).toBe('Post bookmark');
		variant_radios()[4]?.click(); // rsvp
		await flush();
		expect(submit_button().textContent).toBe('Post RSVP');
		variant_radios()[5]?.click(); // follow
		await flush();
		expect(submit_button().textContent).toBe('Post follow');
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
		variant_radios()[1]?.click(); // like
		await flush();

		set_value(url_input, 'https://example.test/post');
		await flush();
		expect(submit_button().disabled).toBe(false);
	});

	it('Follow variant only requires URL', async () => {
		mount();
		const url_input = input_for('outpost-reply-target') as HTMLInputElement;
		variant_radios()[5]?.click(); // follow
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
		variant_radios()[1]?.click();
		await flush();
		expect(label()).toBe('Like of');
		variant_radios()[2]?.click();
		await flush();
		expect(label()).toBe('Repost of');
		variant_radios()[3]?.click();
		await flush();
		expect(label()).toBe('Bookmark of');
		variant_radios()[4]?.click();
		await flush();
		expect(label()).toBe('Event URL');
		variant_radios()[5]?.click();
		await flush();
		expect(label()).toBe('Person or feed URL');
	});

	it('shows the RSVP yes/no/maybe/interested picker only when RSVP is selected', async () => {
		mount();
		expect(rsvp_radios().length).toBe(0); // not visible on Reply

		variant_radios()[4]?.click(); // rsvp
		await flush();
		const values = rsvp_radios().map((r) => r.value);
		expect(values).toEqual(['yes', 'no', 'maybe', 'interested']);
		expect(rsvp_radios()[0]?.checked).toBe(true); // yes is default

		variant_radios()[0]?.click(); // back to Reply
		await flush();
		expect(rsvp_radios().length).toBe(0);
	});
});
