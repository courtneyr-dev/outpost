import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { ListenMode, DOING_VARIANT_LABELS } from './listen-mode';
import type { StoredToken } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';
import type { MediaLookupEnvironment } from '../../lib/media-lookup';
import { fetch_source_preview, type PreviewEnvironment } from '../../lib/preview';
import { stash_share_target } from '../../lib/share-target';
import type { ComposerConfig } from '../../lib/composer-config';
import { write_draft } from '../../lib/drafts';

// T10-S3-03: count every source-preview call, whichever environment it uses.
vi.mock('../../lib/preview', async (importOriginal) => {
	const mod = await importOriginal<typeof import('../../lib/preview')>();
	return { ...mod, fetch_source_preview: vi.fn(mod.fetch_source_preview) };
});

const mock_token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

let root: HTMLDivElement;
let posted_bodies: string[];

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	posted_bodies = [];
});

afterEach(() => {
	render(null, root);
	root.remove();
});

/**
 * Discovery GET returns a Link header; create POSTs are captured into
 * posted_bodies and succeed with 201 + Location.
 */
function make_env(): MicropubEnvironment {
	return {
		fetch: async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
			if (init?.method === 'POST') {
				posted_bodies.push(String(init.body ?? ''));
				return new Response('', {
					status: 201,
					headers: { Location: 'https://example.test/2026/07/02/created' },
				});
			}
			return new Response('<html></html>', {
				status: 200,
				headers: { Link: '<https://example.test/mp>; rel="micropub"' },
			});
		},
	};
}

function mount(env: MicropubEnvironment): void {
	render(<ListenMode token={mock_token} micropubEnv={env} />, root);
}

async function flush(times = 1): Promise<void> {
	for (let i = 0; i < times; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
}

function variant_radios(): HTMLInputElement[] {
	return Array.from(
		root.querySelectorAll('input[name="outpost-listen-variant"]'),
	) as HTMLInputElement[];
}

function input_for(id: string): HTMLInputElement {
	return root.querySelector('#' + id) as HTMLInputElement;
}

function set_value(el: HTMLInputElement, value: string): void {
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

function submit(): void {
	root
		.querySelector('form')
		?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

// VARIANT_ORDER: listen(0) watch(1) read(2) play(3) game(4) jam(5)
//                checkin(6) eat(7) drink(8) exercise(9)

describe('ListenMode variant-scoped field state', () => {
	it('a stale rating from Listen does not block or leak into a Drink post', async () => {
		// Regression for the 2026-07-02 Tier 2 pass (T2.26 → T2.27): a
		// rating of 9 entered on Listen kept validating after switching to
		// Drink — which has no rating field — surfacing a phantom
		// "Rating must be a number between 1 and 5." error on submit.
		const env = make_env();
		mount(env);

		// Listen: valid URL + out-of-range rating → validation error.
		set_value(input_for('outpost-listen-target'), 'https://example.test/track');
		set_value(input_for('outpost-listen-rating'), '9');
		await flush(); // let Preact commit the input state before submitting
		submit();
		await flush(2);
		expect(root.textContent).toContain('Rating must be a number between 1 and 5.');
		expect(posted_bodies.length).toBe(0);

		// Switch to Drink and post with only the drink name filled.
		variant_radios()[8]?.click();
		await flush();
		set_value(input_for('outpost-listen-person'), 'Coffee');
		await flush();
		submit();
		await flush(4);

		expect(root.textContent).not.toContain('Rating must be a number');
		expect(posted_bodies.length).toBe(1);
		expect(posted_bodies[0]).toContain('drink-of=Coffee');
		expect(posted_bodies[0]).not.toContain('rating=');
	});

	it('a stale video URL from Drink does not submit on Listen', async () => {
		// The video input only renders for hasGeocode variants; its state
		// must not ride along when posting from a variant without it.
		const env = make_env();
		mount(env);

		variant_radios()[8]?.click(); // drink — has the video field
		await flush();
		set_value(input_for('outpost-listen-video-url'), 'https://example.test/clip.mp4');
		await flush();

		variant_radios()[0]?.click(); // back to listen — no video field
		await flush();
		set_value(input_for('outpost-listen-target'), 'https://example.test/track');
		await flush();
		submit();
		await flush(4);

		expect(posted_bodies.length).toBe(1);
		expect(posted_bodies[0]).toContain('listen-of=');
		expect(posted_bodies[0]).not.toContain('video=');
	});
});

describe('ListenMode media lookup wiring', () => {
	function lookup_env(rows: unknown[]): MediaLookupEnvironment {
		return {
			fetch: async () =>
				new Response(JSON.stringify({ results: rows, kind: 'listen', notConfigured: false }), {
					status: 200,
					headers: { 'Content-Type': 'application/json' },
				}),
		};
	}

	it('renders the metadata lookup for the default Listen variant', () => {
		mount(make_env());
		expect(root.querySelector('.outpost-media-lookup__search')).toBeTruthy();
	});

	it('does not render the metadata lookup for Eat (no catalog to search)', async () => {
		mount(make_env());
		variant_radios()[7]?.click(); // eat
		await flush();
		expect(root.querySelector('.outpost-media-lookup__search')).toBeNull();
	});

	it('shows the Movie/TV toggle only for Watch', async () => {
		mount(make_env());
		variant_radios()[1]?.click(); // watch
		await flush();
		expect(root.querySelector('input[value="tv"]')).toBeTruthy();
		variant_radios()[0]?.click(); // back to listen (no toggle)
		await flush();
		expect(root.querySelector('input[value="tv"]')).toBeNull();
	});

	it('fills Title and Artist from a Listen lookup result', async () => {
		const env = lookup_env([
			{
				title: 'Discovery',
				cover: 'https://img.example/discovery.jpg',
				creator: 'Daft Punk',
				year: '2001',
				external_id: 'mbid-1',
				url: '',
			},
		]);
		render(<ListenMode token={mock_token} micropubEnv={make_env()} mediaLookupEnv={env} />, root);

		set_value(input_for('outpost-listen-listen-media-lookup-query'), 'Discovery');
		await flush();
		(root.querySelector('.outpost-media-lookup__search') as HTMLButtonElement).click();
		await flush(3);
		(root.querySelector('.outpost-media-lookup__result') as HTMLButtonElement).click();
		await flush(2);

		expect(input_for('outpost-listen-watch-title').value).toBe('Discovery');
		expect(input_for('outpost-listen-person').value).toBe('Daft Punk');
	});

	it('drops the lookup cover photo when the target is a Spotify (embeddable) URL', async () => {
		const env = lookup_env([
			{
				title: 'American Obituary',
				cover: 'https://img.example/cover.jpg',
				creator: 'U2',
				year: '',
				external_id: 'mbid-1',
				url: '',
			},
		]);
		render(<ListenMode token={mock_token} micropubEnv={make_env()} mediaLookupEnv={env} />, root);

		set_value(input_for('outpost-listen-listen-media-lookup-query'), 'American Obituary');
		await flush();
		(root.querySelector('.outpost-media-lookup__search') as HTMLButtonElement).click();
		await flush(3);
		(root.querySelector('.outpost-media-lookup__result') as HTMLButtonElement).click();
		await flush(2);

		// The Spotify player supplies album art, so the separately looked-up
		// cover must NOT also ride along as a photo (that duplicated the art in
		// a gallery below the card).
		set_value(input_for('outpost-listen-target'), 'https://open.spotify.com/track/3OeSl8lZH1');
		await flush();
		submit();
		await flush(2);

		expect(posted_bodies.length).toBe(1);
		expect(posted_bodies[0]).toContain('listen-of=');
		expect(posted_bodies[0]).not.toContain('photo=');
	});

	it('keeps the lookup cover photo for a non-embeddable target URL', async () => {
		const env = lookup_env([
			{
				title: 'Some Album',
				cover: 'https://img.example/cover.jpg',
				creator: 'Some Artist',
				year: '',
				external_id: 'mbid-2',
				url: '',
			},
		]);
		render(<ListenMode token={mock_token} micropubEnv={make_env()} mediaLookupEnv={env} />, root);

		set_value(input_for('outpost-listen-listen-media-lookup-query'), 'Some Album');
		await flush();
		(root.querySelector('.outpost-media-lookup__search') as HTMLButtonElement).click();
		await flush(3);
		(root.querySelector('.outpost-media-lookup__result') as HTMLButtonElement).click();
		await flush(2);

		set_value(input_for('outpost-listen-target'), 'https://example.com/album');
		await flush();
		submit();
		await flush(2);

		expect(posted_bodies.length).toBe(1);
		expect(posted_bodies[0]).toContain('photo=');
	});
});

describe('ListenMode share intake waits for the person (T10-S3-03)', () => {
	let preview_calls: string[];

	function preview_env(): PreviewEnvironment {
		return {
			fetch: async (input: RequestInfo | URL): Promise<Response> => {
				preview_calls.push(String(input));
				return new Response(
					JSON.stringify({ extracted: { 'p-name': 'Shared Track Title', 'p-author': 'Shared Artist' } }),
					{ status: 200, headers: { 'Content-Type': 'application/json' } },
				);
			},
		};
	}

	function mount_with_preview(): void {
		render(
			<ListenMode token={mock_token} micropubEnv={make_env()} previewEnv={preview_env()} />,
			root,
		);
	}

	function fill_button(): HTMLButtonElement | null {
		const found = Array.from(root.querySelectorAll('button')).find((b) =>
			/fill in details/i.test(b.textContent ?? ''),
		);
		return (found ?? null) as HTMLButtonElement | null;
	}

	beforeEach(() => {
		preview_calls = [];
		sessionStorage.clear();
		vi.mocked(fetch_source_preview).mockClear();
	});

	it('prefills the URL from a share but sends no preview request on arrival', async () => {
		stash_share_target({
			tab: 'listen',
			doingVariant: 'listen',
			url: 'https://open.spotify.com/track/0000000000000000000000',
			sourceId: 'spotify',
		});
		mount_with_preview();
		await flush(3);

		expect(input_for('outpost-listen-target').value).toBe(
			'https://open.spotify.com/track/0000000000000000000000',
		);
		expect(vi.mocked(fetch_source_preview)).not.toHaveBeenCalled();
		expect(preview_calls.length).toBe(0);
		expect(fill_button()).not.toBeNull();
	});

	it('sends exactly one preview request after the person asks for details', async () => {
		stash_share_target({
			tab: 'listen',
			doingVariant: 'listen',
			url: 'https://open.spotify.com/track/0000000000000000000000',
			sourceId: 'spotify',
		});
		mount_with_preview();
		await flush(2);

		fill_button()?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await flush(4);

		expect(vi.mocked(fetch_source_preview)).toHaveBeenCalledTimes(1);
		expect(preview_calls.length).toBe(1);
		expect(preview_calls[0]).toContain('/outpost/v1/preview');
		expect(input_for('outpost-listen-watch-title').value).toBe('Shared Track Title');
	});

	it('offers no source fetch when the share carried no source', async () => {
		stash_share_target({
			tab: 'listen',
			doingVariant: 'listen',
			url: 'https://example.test/track',
		});
		mount_with_preview();
		await flush(3);

		expect(vi.mocked(fetch_source_preview)).not.toHaveBeenCalled();
		expect(fill_button()).toBeNull();
	});
});

describe('ListenMode Comic variant (#243)', () => {
	const COVER = 'https://img.example/comic-cover.jpg';

	function config(post_kinds?: { comics: boolean }): ComposerConfig {
		return {
			companions: {
				'post-kinds': 'active',
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
			siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'article' },
			...(post_kinds ? { postKinds: post_kinds } : {}),
		};
	}

	function preview_env(extracted: Record<string, string>): PreviewEnvironment {
		return {
			fetch: async (): Promise<Response> =>
				new Response(JSON.stringify({ extracted }), {
					status: 200,
					headers: { 'Content-Type': 'application/json' },
				}),
		};
	}

	function mount_with(cfg: ComposerConfig | undefined, preview?: PreviewEnvironment): void {
		render(
			<ListenMode
				token={mock_token}
				micropubEnv={make_env()}
				{...(cfg ? { composerConfig: cfg } : {})}
				{...(preview ? { previewEnv: preview } : {})}
			/>,
			root,
		);
	}

	function variant_values(): string[] {
		return variant_radios().map((r) => r.value);
	}

	function variant_labels(): string[] {
		return variant_radios().map((r) => r.parentElement?.textContent ?? '');
	}

	function checked_variant(): string | undefined {
		return variant_radios().find((r) => r.checked)?.value;
	}

	async function pick(value: string): Promise<void> {
		variant_radios()
			.find((r) => r.value === value)
			?.click();
		await flush();
	}

	function fill_button(): HTMLButtonElement | undefined {
		return Array.from(root.querySelectorAll('button')).find((b) =>
			/fill in details/i.test(b.textContent ?? ''),
		);
	}

	function posted(): URLSearchParams {
		return new URLSearchParams(posted_bodies[0] ?? '');
	}

	/** A share with a source lands on Read; the person asks for details, which sets the cover. */
	async function share_and_fill(extracted: Record<string, string>): Promise<void> {
		stash_share_target({
			tab: 'listen',
			doingVariant: 'read',
			url: 'https://example.test/comics/issue-1',
			sourceId: 'unknown',
		});
		mount_with(config({ comics: true }), preview_env(extracted));
		await flush(2);
		fill_button()?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await flush(4);
	}

	beforeEach(() => {
		localStorage.clear();
		sessionStorage.clear();
	});

	it('is not offered when the server sends no postKinds capabilities', () => {
		mount_with(config());
		expect(variant_values()).not.toContain('comics');
		expect(variant_labels()).not.toContain('Comic');
	});

	it('is not offered when postKinds.comics is false', () => {
		mount_with(config({ comics: false }));
		expect(variant_values()).not.toContain('comics');
	});

	it('is not offered before the composer config loads', () => {
		mount_with(undefined);
		expect(variant_values()).not.toContain('comics');
	});

	it('is offered right after Read when postKinds.comics is true', () => {
		mount_with(config({ comics: true }));
		const values = variant_values();
		expect(values).toContain('comics');
		expect(values.indexOf('comics')).toBe(values.indexOf('read') + 1);
		expect(variant_labels()[values.indexOf('comics')]).toBe('Comic');
	});

	it('is left out of the Doing inventory Home lists as always there', () => {
		expect(DOING_VARIANT_LABELS).not.toContain('Comic');
		expect(DOING_VARIANT_LABELS).toContain('Read');
	});

	it('names the Comic URL in the publish hint until it is filled', async () => {
		mount_with(config({ comics: true }));
		await pick('comics');
		expect(root.textContent).toContain('Still needed: Comic URL.');
		set_value(input_for('outpost-listen-target'), 'https://example.test/comics/issue-1');
		await flush();
		expect(root.textContent).not.toContain('Still needed: Comic URL.');
	});

	it('sends a read-of entry with the comic fields and the comics kind hint', async () => {
		mount_with(config({ comics: true }));
		await pick('comics');
		set_value(input_for('outpost-listen-target'), 'https://example.test/comics/issue-1');
		set_value(input_for('outpost-listen-watch-title'), 'The Long Night');
		set_value(input_for('outpost-listen-person'), 'A. Writer, B. Artist');
		set_value(input_for('outpost-listen-comic-series'), ' Night Shift ');
		set_value(input_for('outpost-listen-comic-volume'), '2');
		set_value(input_for('outpost-listen-comic-issue'), '14');
		set_value(input_for('outpost-listen-comic-publisher'), 'Small Press');
		set_value(input_for('outpost-listen-comic-started'), '2026-11-01');
		set_value(input_for('outpost-listen-comic-finished'), '2026-11-03');
		const status = root.querySelector('#outpost-listen-read-status') as HTMLSelectElement;
		status.value = 'finished';
		status.dispatchEvent(new Event('change', { bubbles: true }));
		set_value(input_for('outpost-listen-rating'), '4');
		const content = root.querySelector('#outpost-listen-content') as HTMLTextAreaElement;
		content.value = 'Great finale.';
		content.dispatchEvent(new Event('input', { bubbles: true }));
		await flush();
		submit();
		await flush(4);

		expect(posted_bodies.length).toBe(1);
		const body = posted();
		expect(body.get('read-of')).toBe('https://example.test/comics/issue-1');
		expect(body.get('pkiw-kind')).toBe('comics');
		expect(body.get('name')).toBe('The Long Night');
		expect(body.get('author')).toBe('A. Writer, B. Artist');
		expect(body.get('mp-series')).toBe('Night Shift');
		expect(body.get('mp-volume')).toBe('2');
		expect(body.get('mp-issue-number')).toBe('14');
		expect(body.get('mp-publisher')).toBe('Small Press');
		expect(body.get('mp-started-at')).toBe('2026-11-01');
		expect(body.get('mp-finished-at')).toBe('2026-11-03');
		expect(body.get('read-status')).toBe('finished');
		expect(body.get('rating')).toBe('4');
		expect(body.get('content')).toBe('Great finale.');
		expect(body.has('mp-post-format')).toBe(false);
	});

	it('leaves out comic fields that are empty or only spaces', async () => {
		mount_with(config({ comics: true }));
		await pick('comics');
		set_value(input_for('outpost-listen-target'), 'https://example.test/comics/issue-1');
		set_value(input_for('outpost-listen-comic-series'), '   ');
		await flush();
		submit();
		await flush(4);

		expect(posted_bodies.length).toBe(1);
		const body = posted();
		for (const key of [
			'mp-series',
			'mp-volume',
			'mp-issue-number',
			'mp-publisher',
			'mp-started-at',
			'mp-finished-at',
		]) {
			expect(body.has(key), key).toBe(false);
		}
	});

	it('does not send comic fields from a Read post', async () => {
		mount_with(config({ comics: true }));
		await pick('comics');
		set_value(input_for('outpost-listen-comic-series'), 'Night Shift');
		await flush();
		await pick('read');
		set_value(input_for('outpost-listen-target'), 'https://example.test/books/1');
		await flush();
		submit();
		await flush(4);

		expect(posted_bodies.length).toBe(1);
		expect(posted().get('pkiw-kind')).toBe('read');
		expect(posted().has('mp-series')).toBe(false);
	});

	it('sends a source-preview cover as mp-cover-image with alt text, never as photo', async () => {
		await share_and_fill({ 'p-name': 'The Long Night', 'u-photo': COVER });
		await pick('comics');
		submit();
		await flush(4);

		expect(posted_bodies.length).toBe(1);
		const body = posted();
		expect(body.get('mp-cover-image')).toBe(COVER);
		expect(body.get('mp-cover-image-alt')).toBe('Cover of The Long Night');
		expect(body.has('photo')).toBe(false);
		expect(body.has('photo[]')).toBe(false);
	});

	it('omits the cover alt text when the comic has no title', async () => {
		await share_and_fill({ 'u-photo': COVER });
		await pick('comics');
		submit();
		await flush(4);

		const body = posted();
		expect(body.get('mp-cover-image')).toBe(COVER);
		expect(body.has('mp-cover-image-alt')).toBe(false);
		expect(body.has('photo')).toBe(false);
	});

	it('still sends a Read cover as photo', async () => {
		await share_and_fill({ 'p-name': 'A Book', 'u-photo': COVER });
		expect(checked_variant()).toBe('read');
		submit();
		await flush(4);

		const body = posted();
		expect(body.get('photo')).toBe(COVER);
		expect(body.has('mp-cover-image')).toBe(false);
		expect(body.has('mp-cover-image-alt')).toBe(false);
	});

	it('opens a saved comics draft on Listen when the site cannot build comic cards', async () => {
		write_draft(
			'listen',
			mock_token.me,
			{ target: 'https://example.test/comics/issue-1' },
			{ variant: 'comics' },
		);
		mount_with(config({ comics: false }));
		await flush();
		expect(checked_variant()).toBe('listen');
		expect(input_for('outpost-listen-comic-series')).toBeNull();
	});

	it('opens a saved comics draft on Comic once the config says comics are supported', async () => {
		write_draft(
			'listen',
			mock_token.me,
			{ target: 'https://example.test/comics/issue-1', comicSeries: 'Night Shift' },
			{ variant: 'comics' },
		);
		// The config arrives after the composer mounts, as it does in the app.
		mount_with(undefined);
		await flush();
		expect(checked_variant()).toBe('listen');
		mount_with(config({ comics: true }));
		await flush();
		expect(checked_variant()).toBe('comics');
		expect(input_for('outpost-listen-comic-series').value).toBe('Night Shift');
	});
});
