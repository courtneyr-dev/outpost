import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from 'preact';
import { LibraryPhotos } from './library-photos';
import { PhotoMode } from './modes/photo-mode';
import type { ComposerConfig } from '../lib/composer-config';
import type { StoredToken } from '../lib/token-store';

const origin = window.location.origin;

const fixture = {
	id: 5,
	title: 'Existing fixture image',
	caption: 'Original fixture caption',
	alt: '',
	embeddedAlt: 'Un cercle jaune sur fond bleu',
	thumbnail: origin + '/wp-content/uploads/fixture-150x150.jpg',
	editUrl: origin + '/wp-admin/post.php?post=5&action=edit',
};

let root: HTMLDivElement;
let fetch_stub: ReturnType<typeof vi.fn>;

function library_fetch(): ReturnType<typeof vi.fn> {
	return vi.fn(async (_url: string, init: RequestInit) => {
		const body = new URLSearchParams(String(init.body));
		if (body.has('attachmentId')) {
			return new Response(JSON.stringify({ id: 77, editUrl: origin + '/wp-admin/post.php?post=77&action=edit' }), { status: 201 });
		}
		return new Response(JSON.stringify({ items: [fixture] }), { status: 200 });
	});
}

async function flush(): Promise<void> {
	for (let i = 0; i < 3; i++) await new Promise((resolve) => setTimeout(resolve, 0));
}

function button(name: string): HTMLButtonElement {
	const found = Array.from(root.querySelectorAll('button')).find((b) => b.textContent?.trim() === name);
	if (!found) throw new Error('No button named ' + name);
	return found;
}

function choose_fixture(): void {
	const select = root.querySelector<HTMLSelectElement>('#outpost-library-image')!;
	select.value = '5';
	select.dispatchEvent(new Event('change', { bubbles: true }));
}

function type_alt(value: string): void {
	const alt = root.querySelector<HTMLTextAreaElement>('#outpost-library-alt')!;
	alt.value = value;
	alt.dispatchEvent(new Event('input', { bubbles: true }));
}

function last_body(): URLSearchParams {
	const calls = fetch_stub.mock.calls;
	return new URLSearchParams(String((calls[calls.length - 1] as [string, RequestInit])[1].body));
}

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	fetch_stub = library_fetch();
});

afterEach(() => {
	render(null, root);
	root.remove();
});

describe('LibraryPhotos chooser', () => {
	beforeEach(async () => {
		render(
			<LibraryPhotos token="t" libraryUrl={origin + '/wp-admin/upload.php'} env={{ fetch: fetch_stub as unknown as typeof fetch }} />,
			root,
		);
		await flush();
		choose_fixture();
		await flush();
	});

	it('shows the kept caption and hands cropping off to WordPress', () => {
		expect(root.querySelector('dd')?.textContent).toBe('Original fixture caption');
		const edit = Array.from(root.querySelectorAll('a')).find((a) => a.textContent?.includes('Edit or crop this image'));
		expect(edit?.getAttribute('href')).toBe(fixture.editUrl);
		expect(edit?.getAttribute('target')).toBe('_blank');
	});

	it('offers embedded alt as a suggestion the author applies', async () => {
		const alt = root.querySelector<HTMLTextAreaElement>('#outpost-library-alt')!;
		expect(alt.value).toBe('');
		expect(button('Save photo as WordPress draft').disabled).toBe(true);

		button('Use embedded alt text').click();
		await flush();
		expect(alt.value).toBe('Un cercle jaune sur fond bleu');

		button('Save photo as WordPress draft').click();
		await flush();
		expect(last_body().get('alt')).toBe('Un cercle jaune sur fond bleu');
		expect(last_body().get('decorative')).toBe('false');
		const open = Array.from(root.querySelectorAll('a')).find((a) => a.textContent === 'Open photo draft in WordPress');
		expect(open?.getAttribute('href')).toBe(origin + '/wp-admin/post.php?post=77&action=edit');
		expect(document.activeElement).toBe(open);
	});

	it('treats a stored alt that WordPress copied from the file as a suggestion', async () => {
		fetch_stub.mockImplementation(async () =>
			new Response(JSON.stringify({ items: [{ ...fixture, alt: fixture.embeddedAlt }] }), { status: 200 }),
		);
		render(null, root);
		render(<LibraryPhotos token="t" libraryUrl="" env={{ fetch: fetch_stub as unknown as typeof fetch }} />, root);
		await flush();
		choose_fixture();
		await flush();
		expect(root.querySelector<HTMLTextAreaElement>('#outpost-library-alt')!.value).toBe('');
		expect(button('Use embedded alt text')).toBeTruthy();
	});

	it('pre-fills an alt someone wrote', async () => {
		fetch_stub.mockImplementation(async () =>
			new Response(JSON.stringify({ items: [{ ...fixture, alt: 'Written in the Media Library' }] }), { status: 200 }),
		);
		render(null, root);
		render(<LibraryPhotos token="t" libraryUrl="" env={{ fetch: fetch_stub as unknown as typeof fetch }} />, root);
		await flush();
		choose_fixture();
		await flush();
		expect(root.querySelector<HTMLTextAreaElement>('#outpost-library-alt')!.value).toBe('Written in the Media Library');
		expect(button('Use embedded alt text')).toBeTruthy();
	});

	it('saves explicit text as typed', async () => {
		type_alt('  A yellow circle, é  ');
		await flush();
		button('Save photo as WordPress draft').click();
		await flush();
		expect(last_body().get('alt')).toBe('A yellow circle, é');
	});

	it('saves a decorative choice with an empty alt', async () => {
		type_alt('Typed then abandoned');
		const decorative = root.querySelector<HTMLInputElement>('.outpost-checkbox input[type=checkbox]')!;
		decorative.click();
		await flush();
		expect(root.querySelector<HTMLTextAreaElement>('#outpost-library-alt')!.disabled).toBe(true);
		button('Save photo as WordPress draft').click();
		await flush();
		expect(last_body().get('alt')).toBe('');
		expect(last_body().get('decorative')).toBe('true');
	});
});

describe('PhotoMode with the chooser', () => {
	const token: StoredToken = { accessToken: 'test-token', tokenType: 'Bearer', scope: 'create', me: 'https://example.test/', storedAt: 0 };
	const config = {
		companions: {},
		mediaLibraryUrl: origin + '/wp-admin/upload.php?mode=grid',
		postFormats: null,
		xfnRels: [],
		existingCategories: [],
		existingTags: [],
		bridgyHostMap: {},
		siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'note' },
	} as unknown as ComposerConfig;

	it('keeps the chooser outside the publish form', async () => {
		vi.stubGlobal('fetch', fetch_stub);
		try {
			render(<PhotoMode token={token} composerConfig={config} />, root);
			const publish_form = root.querySelector('form')!;
			let submitted = 0;
			publish_form.addEventListener('submit', () => submitted++);

			button('Choose from WordPress Media Library').click();
			await flush();

			const search = root.querySelector<HTMLInputElement>('#outpost-library-search')!;
			expect(publish_form.contains(search)).toBe(false);
			expect(search.form?.getAttribute('role')).toBe('search');
			expect(root.querySelector<HTMLSelectElement>('#outpost-library-image')!.form).toBeNull();

			choose_fixture();
			await flush();
			expect(root.querySelector<HTMLTextAreaElement>('#outpost-library-alt')!.form).toBeNull();
			expect(button('Choose from WordPress Media Library').type).toBe('button');
			expect(submitted).toBe(0);
		} finally {
			vi.unstubAllGlobals();
		}
	});

	it('hides the chooser for users without upload_files', () => {
		render(<PhotoMode token={token} composerConfig={{ ...config, mediaLibraryUrl: null }} />, root);
		expect(Array.from(root.querySelectorAll('button')).some((b) => b.textContent?.includes('Media Library'))).toBe(false);
	});
});
