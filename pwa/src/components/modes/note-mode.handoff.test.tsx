import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { NoteMode } from './note-mode';
import type { StoredToken } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';
import type { ComposerConfig } from '../../lib/composer-config';

const token: StoredToken = {
	accessToken: 'tk',
	tokenType: 'Bearer',
	scope: 'create update',
	me: 'https://example.test/',
	storedAt: 0,
};

function config(draftEndpoint: string | null): ComposerConfig {
	return {
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
		draftEndpoint,
		recentDrafts: true,
		postFormats: null,
		xfnRels: [],
		existingCategories: [],
		existingTags: [],
		bridgyHostMap: {},
		siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'note' },
	};
}

let root: HTMLDivElement;

beforeEach(() => {
	localStorage.clear();
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
});

function save_draft_button(): HTMLButtonElement | undefined {
	return Array.from(root.querySelectorAll('button')).find(
		(b) => b.textContent === 'Save as WordPress draft'
	);
}

describe('NoteMode draft handoff', () => {
	it('saves a draft without syndication and links to that post in the WordPress editor', async () => {
		const origin = window.location.origin;
		const edit_url = `${origin}/wp-admin/post.php?post=72&action=edit`;
		const posts: URLSearchParams[] = [];
		const env: MicropubEnvironment = {
			fetch: async (_input, init) => {
				posts.push(new URLSearchParams(String(init?.body)));
				return new Response(null, {
					status: 201,
					headers: {
						Location: `${origin}/?p=72`,
						'X-Outpost-Draft-Edit': edit_url,
					},
				});
			},
		};
		render(
			<NoteMode
				token={token}
				micropubEnv={env}
				composerConfig={config(
					`${origin}/wp-json/micropub/1.0/endpoint`
				)}
			/>,
			root
		);

		const title = root.querySelector(
			'#outpost-note-title'
		) as HTMLInputElement;
		title.value = 'For review';
		title.dispatchEvent(new Event('input', { bubbles: true }));
		const body = root.querySelector(
			'#outpost-note-content'
		) as HTMLTextAreaElement;
		body.value = 'Please check this';
		body.dispatchEvent(new Event('input', { bubbles: true }));
		await new Promise((resolve) => setTimeout(resolve, 0));
		save_draft_button()?.click();

		await vi.waitFor(() =>
			expect(root.querySelector('.outpost-status a')).not.toBeNull()
		);
		const link = root.querySelector(
			'.outpost-status a'
		) as HTMLAnchorElement;
		expect(link.textContent).toBe('Open draft in WordPress');
		expect(link.getAttribute('href')).toBe(edit_url);
		expect(posts).toHaveLength(1);
		expect(posts[0]?.get('post-status')).toBe('draft');
		expect(posts[0]?.has('mp-syndicate-to[]')).toBe(false);
		expect(posts[0]?.has('mp-syndicate-to')).toBe(false);
	});

	it('links to the editor when Micropub saves an ordinary submit as a draft', async () => {
		const origin = window.location.origin;
		const endpoint = `${origin}/wp-json/micropub/1.0/endpoint`;
		const edit_url = `${origin}/wp-admin/post.php?post=73&action=edit`;
		const env: MicropubEnvironment = {
			fetch: async (_input, init) =>
				init?.method === 'POST'
					? new Response(null, {
							status: 201,
							headers: {
								Location: `${origin}/?p=73`,
								'X-Outpost-Draft-Edit': edit_url,
							},
						})
					: new Response('<html></html>', {
							headers: { Link: `<${endpoint}>; rel="micropub"` },
						}),
		};
		render(
			<NoteMode
				token={token}
				micropubEnv={env}
				composerConfig={config(null)}
			/>,
			root
		);

		const body = root.querySelector(
			'#outpost-note-content'
		) as HTMLTextAreaElement;
		body.value = 'Contributor note';
		body.dispatchEvent(new Event('input', { bubbles: true }));
		await new Promise((resolve) => setTimeout(resolve, 0));
		(root.querySelector('form') as HTMLFormElement).requestSubmit();

		await vi.waitFor(() =>
			expect(root.querySelector('.outpost-status a')).not.toBeNull()
		);
		const status = root.querySelector('.outpost-status') as HTMLElement;
		expect(status.textContent).toContain('Draft saved.');
		expect(status.textContent).not.toContain('Posted');
		expect(
			(root.querySelector('.outpost-status a') as HTMLAnchorElement).getAttribute(
				'href'
			)
		).toBe(edit_url);
	});

	it('saves a recent draft from its own form, so Enter in the draft title never publishes', async () => {
		const origin = window.location.origin;
		const draft_calls: URLSearchParams[] = [];
		vi.stubGlobal(
			'fetch',
			async (_input: RequestInfo | URL, init?: RequestInit) => {
				const fields = new URLSearchParams(String(init?.body));
				draft_calls.push(fields);
				if (fields.get('id')) {
					return Response.json({
						id: 31,
						title: fields.get('title') ?? 'Resume me',
						content: 'Draft body',
						editUrl: `${origin}/wp-admin/post.php?post=31&action=edit`,
						version: 'v1',
					});
				}
				return Response.json({
					items: [{ id: 31, title: 'Resume me' }],
					hasNext: false,
				});
			}
		);
		const publishes: string[] = [];
		const env: MicropubEnvironment = {
			fetch: async (input) => {
				publishes.push(String(input));
				return new Response(null, { status: 201 });
			},
		};
		try {
			render(
				<NoteMode
					token={token}
					micropubEnv={env}
					composerConfig={config(null)}
				/>,
				root
			);
			const composer = root.querySelector(
				'#outpost-note-content'
			) as HTMLTextAreaElement;
			composer.value = 'Unrelated note in progress';
			composer.dispatchEvent(new Event('input', { bubbles: true }));
			await new Promise((resolve) => setTimeout(resolve, 0));

			Array.from(root.querySelectorAll('button'))
				.find((b) => b.textContent === 'Recent WordPress drafts')
				?.click();
			const draft_button = await vi.waitFor(() => {
				const match = Array.from(root.querySelectorAll('button')).find(
					(b) => b.textContent === 'Resume me' && !b.disabled
				);
				if (!match) throw new Error('draft list not loaded');
				return match;
			});
			draft_button.click();
			const draft_title = await vi.waitFor(() => {
				const input = root.querySelector(
					'#outpost-draft-title'
				) as HTMLInputElement | null;
				if (!input || input.disabled) throw new Error('draft not open');
				return input;
			});

			const composer_form = composer.form;
			expect(draft_title.form).not.toBeNull();
			expect(draft_title.form).not.toBe(composer_form);

			draft_title.value = 'Renamed';
			draft_title.dispatchEvent(new Event('input', { bubbles: true }));
			await new Promise((resolve) => setTimeout(resolve, 0));
			// Enter in a text field submits the field's own form.
			draft_title.form?.requestSubmit();

			await vi.waitFor(() =>
				expect(draft_calls.some((f) => f.get('title') === 'Renamed')).toBe(
					true
				)
			);
			expect(publishes).toEqual([]);
			expect(composer.value).toBe('Unrelated note in progress');
		} finally {
			vi.unstubAllGlobals();
		}
	});

	it('offers no draft save when the endpoint is on another origin', () => {
		render(
			<NoteMode
				token={token}
				composerConfig={config(
					'https://elsewhere.example/wp-json/micropub/1.0/endpoint'
				)}
			/>,
			root
		);

		expect(save_draft_button()).toBeUndefined();
	});
});
