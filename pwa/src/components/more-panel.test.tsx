import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, h } from 'preact';
import {
	MorePanel,
	merge_more_values,
	empty_more_values,
	type MorePanelValues,
} from './more-panel';
import type { ComposerConfig } from '../lib/composer-config';
import type { StoredToken } from '../lib/token-store';

describe('merge_more_values — pkiw-promote', () => {
	it('adds pkiw-promote when promoteToMain is true', () => {
		const values = { ...empty_more_values(), promoteToMain: true };
		const merged = merge_more_values(
			{} as Record<string, unknown>,
			values,
		) as Record<string, unknown>;
		expect(merged['pkiw-promote']).toBe(true);
	});

	it('omits pkiw-promote when promoteToMain is false', () => {
		const merged = merge_more_values(
			{} as Record<string, unknown>,
			empty_more_values(),
		) as Record<string, unknown>;
		expect('pkiw-promote' in merged).toBe(false);
	});

	it('empty_more_values defaults promoteToMain to false', () => {
		expect(empty_more_values().promoteToMain).toBe(false);
	});
});

describe('merge_more_values — mp-rss-chat-routing', () => {
	it('adds the property when an override is chosen', () => {
		const values = { ...empty_more_values(), rssChatRouting: 'exclude' as const };
		const merged = merge_more_values(
			{} as Record<string, unknown>,
			values,
		) as Record<string, unknown>;
		expect(merged['mp-rss-chat-routing']).toBe('exclude');
	});

	it('omits the property when following the site default', () => {
		const merged = merge_more_values(
			{} as Record<string, unknown>,
			empty_more_values(),
		) as Record<string, unknown>;
		expect('mp-rss-chat-routing' in merged).toBe(false);
	});

	it('empty_more_values defaults rssChatRouting to null', () => {
		expect(empty_more_values().rssChatRouting).toBeNull();
	});
});

describe('MorePanel — promote toggle', () => {
	let root: HTMLDivElement;

	beforeEach(() => {
		root = document.createElement('div');
		document.body.appendChild(root);
	});

	afterEach(() => {
		render(null, root);
		root.remove();
	});

	const token: StoredToken = {
		accessToken: 't',
		tokenType: 'Bearer',
		scope: 'create',
		me: 'https://example.com/',
		storedAt: 0,
	};

	function make_config(
		postKinds: 'active' | 'inactive',
		rssChatRouting: 'active' | 'absent' = 'absent',
	): ComposerConfig {
		return {
			companions: {
				'post-kinds': postKinds,
				'post-formats': 'absent',
				xfn: 'inactive',
				'syndication-links': 'absent',
				yoast: 'absent',
				activitypub: 'absent',
				'accessibility-checker': 'absent',
				'rss-chat-routing': rssChatRouting,
			},
			postFormats: null,
			xfnRels: [],
			existingCategories: [],
			existingTags: [],
			bridgyHostMap: {},
			siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'note' },
		};
	}

	function props(
		postKinds: 'active' | 'inactive',
		onChange: (v: MorePanelValues) => void,
		rssChatRouting: 'active' | 'absent' = 'absent',
	) {
		return {
			token,
			composerConfig: make_config(postKinds, rssChatRouting),
			values: empty_more_values(),
			onChange,
			micropubEndpoint: null,
			idPrefix: 'test',
		};
	}

	it('renders the promote toggle when Post Kinds is active and reports changes', () => {
		const onChange = vi.fn();
		render(h(MorePanel, props('active', onChange)), root);
		const cb = root.querySelector(
			'.outpost-post-kinds-surface input[type="checkbox"]',
		) as HTMLInputElement | null;
		expect(cb).not.toBeNull();
		cb!.checked = true;
		cb!.dispatchEvent(new Event('change', { bubbles: true }));
		expect(onChange).toHaveBeenCalledWith(
			expect.objectContaining({ promoteToMain: true }),
		);
	});

	it('hides the promote toggle when Post Kinds is not active', () => {
		render(h(MorePanel, props('inactive', vi.fn())), root);
		expect(root.querySelector('.outpost-post-kinds-surface')).toBeNull();
	});

	it('renders the rss.chat select when the routing companion is active and reports changes', () => {
		const onChange = vi.fn();
		render(h(MorePanel, props('inactive', onChange, 'active')), root);
		const select = root.querySelector(
			'.outpost-rss-chat-routing select',
		) as HTMLSelectElement | null;
		expect(select).not.toBeNull();
		select!.value = 'exclude';
		select!.dispatchEvent(new Event('change', { bubbles: true }));
		expect(onChange).toHaveBeenCalledWith(
			expect.objectContaining({ rssChatRouting: 'exclude' }),
		);
	});

	it('returns to the site default as null, not an empty string', () => {
		const onChange = vi.fn();
		render(
			h(MorePanel, {
				...props('inactive', onChange, 'active'),
				values: { ...empty_more_values(), rssChatRouting: 'include' },
			}),
			root,
		);
		const select = root.querySelector(
			'.outpost-rss-chat-routing select',
		) as HTMLSelectElement;
		select.value = '';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(onChange).toHaveBeenCalledWith(
			expect.objectContaining({ rssChatRouting: null }),
		);
	});

	it('hides the rss.chat select when the routing companion is absent', () => {
		render(h(MorePanel, props('inactive', vi.fn())), root);
		expect(root.querySelector('.outpost-rss-chat-routing')).toBeNull();
	});
});
