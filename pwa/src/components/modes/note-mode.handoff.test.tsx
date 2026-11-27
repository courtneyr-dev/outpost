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
