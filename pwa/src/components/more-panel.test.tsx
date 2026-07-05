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

	function make_config(postKinds: 'active' | 'inactive'): ComposerConfig {
		return {
			companions: {
				'post-kinds': postKinds,
				'post-formats': 'absent',
				xfn: 'inactive',
				'syndication-links': 'absent',
				yoast: 'absent',
				activitypub: 'absent',
				'accessibility-checker': 'absent',
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
	) {
		return {
			token,
			composerConfig: make_config(postKinds),
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
});
