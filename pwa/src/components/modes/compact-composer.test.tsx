/**
 * Compact pickers and the publish row (mockup review P1–P4,
 * docs/product/pwa-ui-2026-11-07/mockup-review-2026-11-08.md).
 *
 * Layout itself is measured in the real-browser captures; these tests pin
 * the structure the CSS hangs on and the copy the hint shows.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import type { JSX } from 'preact';
import { NoteMode } from './note-mode';
import { ReplyMode } from './reply-mode';
import { ListenMode } from './listen-mode';
import { PhotoMode } from './photo-mode';
import { LifeMode } from './life-mode';
import { RecipeMode } from './recipe-mode';
import type { StoredToken } from '../../lib/token-store';

const token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

let root: HTMLDivElement;

beforeEach(() => {
	localStorage.clear();
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
	localStorage.clear();
});

async function flush(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
}

function mount(node: JSX.Element): void {
	render(node, root);
}

function radios(name: string): HTMLInputElement[] {
	return Array.from(root.querySelectorAll(`input[name="${name}"]`));
}

function pick(name: string, value: string): void {
	const radio = radios(name).find((r) => r.value === value);
	if (!radio) throw new Error(`no ${name} radio for ${value}`);
	radio.click();
}

function set_value(id: string, value: string): void {
	const el = root.querySelector('#' + id) as HTMLInputElement | HTMLTextAreaElement | null;
	if (!el) throw new Error('no field #' + id);
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

function submit(): HTMLButtonElement {
	return root.querySelector('button[type="submit"]') as HTMLButtonElement;
}

/** The hint text the submit button is described by, or '' when it has none. */
function hint(): string {
	const ids = submit().getAttribute('aria-describedby');
	if (!ids) return '';
	return ids
		.split(/\s+/)
		.map((id) => root.querySelector('#' + id)?.textContent ?? '')
		.join(' ')
		.trim();
}

/** Every element that would hide an option: a closed disclosure or `hidden`. */
function hidden_ancestor(el: Element): Element | null {
	for (let node = el.parentElement; node && node !== root; node = node.parentElement) {
		if (node.hasAttribute('hidden')) return node;
		if (node.tagName === 'DETAILS' && !(node as HTMLDetailsElement).open) return node;
	}
	return null;
}

describe('P1/P2: compact type pickers keep every type visible', () => {
	it('Doing shows all 15 types in one compact radio group, none behind a disclosure', () => {
		mount(<ListenMode token={token} />);
		const group = radios('outpost-listen-variant');
		expect(group).toHaveLength(15);
		const fieldset = group[0]?.closest('fieldset');
		expect(fieldset?.classList.contains('outpost-variant-picker--compact')).toBe(true);
		for (const radio of group) {
			expect(radio.closest('fieldset')).toBe(fieldset);
			expect(hidden_ancestor(radio)).toBeNull();
		}
	});

	it('Reply shows all 11 types in one compact radio group, none behind a disclosure', () => {
		mount(<ReplyMode token={token} />);
		const group = radios('outpost-reply-variant');
		expect(group).toHaveLength(11);
		const fieldset = group[0]?.closest('fieldset');
		expect(fieldset?.classList.contains('outpost-variant-picker--compact')).toBe(true);
		for (const radio of group) {
			expect(hidden_ancestor(radio)).toBeNull();
		}
	});

	it('a type late in the list stays selected and visible after picking it', async () => {
		mount(<ListenMode token={token} />);
		pick('outpost-listen-variant', 'audio');
		await flush();
		const audio = radios('outpost-listen-variant').find((r) => r.value === 'audio');
		expect(audio?.checked).toBe(true);
		expect(audio && hidden_ancestor(audio)).toBeNull();
	});
});

describe('P3: Post styles are icon-and-label radio tiles', () => {
	it('renders five native radios in one tile row, each named by its visible text', () => {
		mount(<NoteMode token={token} />);
		const group = radios('outpost-note-variant');
		expect(group.map((r) => r.value)).toEqual(['article', 'note', 'status', 'aside', 'quote']);
		const fieldset = group[0]?.closest('fieldset');
		expect(fieldset?.classList.contains('outpost-variant-picker--tiles')).toBe(true);
		const names = group.map((r) => r.closest('label')?.textContent?.trim());
		expect(names).toEqual(['Article', 'Note', 'Status', 'Aside', 'Quote']);
	});

	it('each tile carries a decorative icon that screen readers skip', () => {
		mount(<NoteMode token={token} />);
		for (const radio of radios('outpost-note-variant')) {
			const svg = radio.closest('label')?.querySelector('svg');
			expect(svg, `icon for ${radio.value}`).not.toBeNull();
			expect(svg?.getAttribute('aria-hidden')).toBe('true');
			expect(svg?.getAttribute('focusable')).toBe('false');
		}
	});
});

describe('P4: full-width publish button at the end of the form', () => {
	const cases: Array<[string, () => JSX.Element]> = [
		['Post', () => <NoteMode token={token} />],
		['Reply', () => <ReplyMode token={token} />],
		['Doing', () => <ListenMode token={token} />],
		['Photo', () => <PhotoMode token={token} />],
		['Life', () => <LifeMode token={token} />],
		['Recipe', () => <RecipeMode token={token} />],
	];

	for (const [tab, node] of cases) {
		it(`${tab}: the publish button is the last control in the form and is the primary style`, () => {
			mount(node());
			const button = submit();
			const form = button.closest('form');
			expect(button.classList.contains('outpost-button--publish')).toBe(true);
			expect(button.classList.contains('outpost-button--secondary')).toBe(false);
			const controls = Array.from(form?.querySelectorAll('button, input, select, textarea') ?? []);
			expect(controls[controls.length - 1]).toBe(button);
		});
	}
});

describe('P4: a one-line hint names the missing required fields', () => {
	it('Post › Article names Title and Body, then only what is still empty', async () => {
		mount(<NoteMode token={token} />);
		expect(submit().textContent).toBe('Post article');
		expect(submit().disabled).toBe(true);
		expect(hint()).toBe('Still needed: Title and Body.');
		set_value('outpost-note-content', 'Words');
		await flush();
		expect(hint()).toBe('Still needed: Title.');
		set_value('outpost-note-title', 'A title');
		await flush();
		expect(submit().disabled).toBe(false);
		expect(hint()).toBe('');
	});

	it('Post › Note needs only its text', async () => {
		mount(<NoteMode token={token} />);
		pick('outpost-note-variant', 'note');
		await flush();
		expect(hint()).toBe('Still needed: Text.');
	});

	it('Reply names the URL, then the reply text only for Reply', async () => {
		mount(<ReplyMode token={token} />);
		expect(hint()).toBe('Still needed: URL and Your reply.');
		set_value('outpost-reply-target', 'https://example.test/post');
		await flush();
		expect(hint()).toBe('Still needed: Your reply.');
		pick('outpost-reply-variant', 'like');
		await flush();
		expect(hint()).toBe('');
		expect(submit().disabled).toBe(false);
	});

	it('Doing names the target for URL kinds and the main field for the rest', async () => {
		mount(<ListenMode token={token} />);
		expect(hint()).toBe('Still needed: Track or album URL.');
		pick('outpost-listen-variant', 'eat');
		await flush();
		expect(hint()).toBe('Still needed: Food.');
		pick('outpost-listen-variant', 'event');
		await flush();
		expect(hint()).toBe('Still needed: Event name.');
	});

	it('Photo asks for a photo first', () => {
		mount(<PhotoMode token={token} />);
		expect(submit().disabled).toBe(true);
		expect(hint()).toBe('Still needed: A photo.');
	});

	it('Life names the field for the selected type', async () => {
		mount(<LifeMode token={token} />);
		expect(hint()).toBe('Still needed: Mood.');
		pick('outpost-life-variant', 'trip');
		await flush();
		expect(hint()).toBe('Still needed: Destination.');
	});

	it('Recipe lists every empty required field', async () => {
		mount(<RecipeMode token={token} />);
		expect(hint()).toBe('Still needed: Recipe title, Ingredients, and Instructions.');
		set_value('outpost-recipe-name', 'Soup');
		await flush();
		expect(hint()).toBe('Still needed: Ingredients and Instructions.');
	});

	it('the hint region stays mounted while empty so its id never dangles', async () => {
		mount(<ReplyMode token={token} />);
		const region = root.querySelector('.outpost-publish__hint');
		expect(region).not.toBeNull();
		set_value('outpost-reply-target', 'https://example.test/post');
		pick('outpost-reply-variant', 'like');
		await flush();
		expect(root.querySelector('.outpost-publish__hint')).toBe(region);
		expect(submit().hasAttribute('aria-describedby')).toBe(false);
	});
});
