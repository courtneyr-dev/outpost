import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { render, type ComponentChild } from 'preact';
import { ReplyMode } from './reply-mode';
import { ListenMode } from './listen-mode';
import { LifeMode } from './life-mode';
import { RecipeMode } from './recipe-mode';
import { PhotoMode } from './photo-mode';
import type { StoredToken } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';
import type { MoodsEnvironment } from '../../lib/pkiw-moods';
import { DRAFT_SAVE_DELAY_MS, read_draft, write_draft } from '../../lib/drafts';

/**
 * One reload round-trip per mode: type, wait for the debounced save,
 * remount, and check the fields came back. Note mode's full behavior
 * (publish, sign-out, other account, storage denial) lives in
 * note-mode.drafts.test.tsx.
 */

const ME = 'https://example.test/';
const token: StoredToken = {
	accessToken: 'secret-bearer-value',
	tokenType: 'Bearer',
	scope: 'create',
	me: ME,
	storedAt: 0,
};

// Nothing in these tests should reach the network; a fetch fails loudly.
const no_network: MicropubEnvironment = {
	fetch: async (): Promise<Response> => {
		throw new Error('unexpected fetch');
	},
};
const no_moods: MoodsEnvironment = {
	fetch: (async (): Promise<Response> => new Response('', { status: 404 })) as typeof fetch,
};

let root: HTMLDivElement;
let original_create: typeof URL.createObjectURL;
let original_revoke: typeof URL.revokeObjectURL;

beforeEach(() => {
	localStorage.clear();
	sessionStorage.clear();
	root = document.createElement('div');
	document.body.appendChild(root);
	original_create = URL.createObjectURL;
	original_revoke = URL.revokeObjectURL;
	let n = 0;
	URL.createObjectURL = vi.fn(() => `blob:test-${String(++n)}`);
	URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
	render(null, root);
	root.remove();
	URL.createObjectURL = original_create;
	URL.revokeObjectURL = original_revoke;
});

function field(id: string): HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement {
	return root.querySelector('#' + id) as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
}

function type(id: string, value: string): void {
	const el = field(id);
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

async function wait_for_save(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, DRAFT_SAVE_DELAY_MS + 50));
}

async function round_trip(node: () => ComponentChild, fill: () => void): Promise<void> {
	render(node(), root);
	fill();
	await wait_for_save();
	render(null, root);
	render(node(), root);
	await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('draft restore per mode', () => {
	it('Reply keeps the variant, reply target, title, and content', async () => {
		await round_trip(
			() => <ReplyMode token={token} micropubEnv={no_network} />,
			() => {
				(root.querySelector('input[name="outpost-reply-variant"][value="bookmark"]') as HTMLInputElement).click();
				type('outpost-reply-target', 'https://example.org/post');
				type('outpost-reply-title', 'Worth reading');
				type('outpost-reply-content', 'Saving this one');
			},
		);
		expect(
			(root.querySelector('input[name="outpost-reply-variant"][value="bookmark"]') as HTMLInputElement).checked,
		).toBe(true);
		expect(field('outpost-reply-target').value).toBe('https://example.org/post');
		expect(field('outpost-reply-title').value).toBe('Worth reading');
		expect(field('outpost-reply-content').value).toBe('Saving this one');
	});

	it('Doing keeps the person, rating, and content', async () => {
		await round_trip(
			() => <ListenMode token={token} micropubEnv={no_network} />,
			() => {
				type('outpost-listen-person', 'An artist');
				type('outpost-listen-rating', '4');
				type('outpost-listen-content', 'On repeat');
			},
		);
		expect(field('outpost-listen-person').value).toBe('An artist');
		expect(field('outpost-listen-rating').value).toBe('4');
		expect(field('outpost-listen-content').value).toBe('On repeat');
	});

	it('Life keeps the title, primary value, and content', async () => {
		await round_trip(
			() => <LifeMode token={token} micropubEnv={no_network} moodsEnv={no_moods} />,
			() => {
				type('outpost-life-title', 'Morning');
				type('outpost-life-primary', 'calm');
				type('outpost-life-content', 'Coffee first');
			},
		);
		expect(field('outpost-life-title').value).toBe('Morning');
		expect(field('outpost-life-primary').value).toBe('calm');
		expect(field('outpost-life-content').value).toBe('Coffee first');
	});

	it('Recipe keeps every text field', async () => {
		await round_trip(
			() => <RecipeMode token={token} micropubEnv={no_network} />,
			() => {
				type('outpost-recipe-name', 'Soup');
				type('outpost-recipe-ingredients', 'water\nsalt');
				type('outpost-recipe-instructions', 'boil');
				type('outpost-recipe-yield', '2 bowls');
				type('outpost-recipe-duration', '30');
				type('outpost-recipe-content', 'Winter food');
			},
		);
		expect(field('outpost-recipe-name').value).toBe('Soup');
		expect(field('outpost-recipe-ingredients').value).toBe('water\nsalt');
		expect(field('outpost-recipe-instructions').value).toBe('boil');
		expect(field('outpost-recipe-yield').value).toBe('2 bowls');
		expect(field('outpost-recipe-duration').value).toBe('30');
		expect(field('outpost-recipe-content').value).toBe('Winter food');
	});

	it('Photo keeps the title and caption text', async () => {
		await round_trip(
			() => <PhotoMode token={token} micropubEnv={no_network} />,
			() => {
				type('outpost-photo-name', 'Walk');
				type('outpost-photo-content', 'Late light');
			},
		);
		expect(field('outpost-photo-name').value).toBe('Walk');
		expect(field('outpost-photo-content').value).toBe('Late light');
	});

	it('Photo saves alt text and captions, and fills them into photos added again', async () => {
		render(<PhotoMode token={token} micropubEnv={no_network} />, root);
		const input = field('outpost-photo-file') as HTMLInputElement;
		const file = new File([new Uint8Array([1, 2, 3])], 'dog.jpg', { type: 'image/jpeg' });
		Object.defineProperty(input, 'files', { value: [file], configurable: true });
		input.dispatchEvent(new Event('change', { bubbles: true }));
		await new Promise((resolve) => setTimeout(resolve, 0));
		const alt = root.querySelector('[id^="outpost-photo-alt-"]') as HTMLTextAreaElement;
		const caption = root.querySelector('[id^="outpost-photo-caption-"]') as HTMLInputElement;
		alt.value = 'A brown dog asleep';
		alt.dispatchEvent(new Event('input', { bubbles: true }));
		caption.value = 'Nap time';
		caption.dispatchEvent(new Event('input', { bubbles: true }));
		await wait_for_save();

		// Reload: the File is gone, its descriptions are not.
		render(null, root);
		render(<PhotoMode token={token} micropubEnv={no_network} />, root);
		await new Promise((resolve) => setTimeout(resolve, 0));
		expect(root.querySelector('.outpost-draft-photos')?.textContent).toContain('1 photo');

		const again = field('outpost-photo-file') as HTMLInputElement;
		Object.defineProperty(again, 'files', { value: [file], configurable: true });
		again.dispatchEvent(new Event('change', { bubbles: true }));
		await new Promise((resolve) => setTimeout(resolve, 0));
		expect((root.querySelector('[id^="outpost-photo-alt-"]') as HTMLTextAreaElement).value).toBe(
			'A brown dog asleep',
		);
		expect((root.querySelector('[id^="outpost-photo-caption-"]') as HTMLInputElement).value).toBe('Nap time');
		expect(root.querySelector('.outpost-draft-photos')).toBeNull();
	});

	it("does not restore a draft written for another account in any mode", async () => {
		write_draft('recipe', 'https://other.test/', { name: 'Their soup' }, {}, localStorage);
		render(<RecipeMode token={token} micropubEnv={no_network} />, root);
		await new Promise((resolve) => setTimeout(resolve, 0));
		expect(field('outpost-recipe-name').value).toBe('');
		expect(read_draft('recipe', ME, localStorage)).toBeNull();
	});
});
