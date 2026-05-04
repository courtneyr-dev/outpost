/**
 * Tests for PendingSyndicationsPanel.
 */

import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { h } from 'preact';
import { PendingSyndicationsPanel } from './pending-syndications-panel';
import type { PendingPost } from '../lib/manual-share/capture-api';

let root: HTMLDivElement;

beforeEach( () => {
	root = document.createElement( 'div' );
	document.body.appendChild( root );
} );

afterEach( () => {
	render( null, root );
	root.remove();
} );

function build_pending( overrides: Partial<PendingPost> = {} ): PendingPost {
	return {
		post_id:    42,
		post_title: 'Sunset photo',
		permalink:  'https://example.com/posts/42',
		entries:    [
			{
				id:           'e1',
				platform_id:  'instagram-feed',
				fired_at:     new Date( Date.now() - 5 * 60 * 1000 ).toISOString(),
				strategy:     'navigator_share',
				outcome:      'unknown',
				completed_at: null,
				silo_url:     null,
			},
		],
		...overrides,
	};
}

describe( 'PendingSyndicationsPanel', () => {
	it( 'renders nothing when pending list is empty', () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [],
				api_env: { fetch: globalThis.fetch },
			} ),
			root,
		);

		expect( root.querySelector( '.outpost-pending-syndications' ) ).toBeNull();
	} );

	it( 'renders one row per pending entry', () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [
					build_pending(),
					build_pending( {
						post_id:    100,
						post_title: 'Other post',
						entries:    [
							{
								id:           'e2',
								platform_id:  'facebook',
								fired_at:     new Date( Date.now() - 60 * 60 * 1000 ).toISOString(),
								strategy:     'navigator_share',
								outcome:      'unknown',
								completed_at: null,
								silo_url:     null,
							},
						],
					} ),
				],
				api_env: { fetch: globalThis.fetch },
			} ),
			root,
		);

		const rows = root.querySelectorAll( '.outpost-pending-syndications__item' );
		expect( rows.length ).toBe( 2 );
	} );

	it( 'renders post title + platform label + relative time', () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [ build_pending() ],
				api_env: { fetch: globalThis.fetch },
			} ),
			root,
		);

		expect( root.textContent ).toContain( 'Sunset photo' );
		expect( root.textContent ).toContain( 'Instagram' );
		expect( root.textContent ).toMatch( /\d+m ago/ );
	} );

	it( "humanizes unknown platform_id to title case", () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [
					build_pending( {
						entries: [
							{
								id:           'e1',
								platform_id:  'custom-vsco',
								fired_at:     new Date().toISOString(),
								strategy:     'navigator_share',
								outcome:      'unknown',
								completed_at: null,
								silo_url:     null,
							},
						],
					} ),
				],
				api_env: { fetch: globalThis.fetch },
			} ),
			root,
		);

		const platform_text = root.querySelector( '.outpost-pending-syndications__platform' )?.textContent;
		expect( platform_text ).toBe( 'Custom Vsco' );
	} );

	it( 'shows Add URL button on each row by default', () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [ build_pending() ],
				api_env: { fetch: globalThis.fetch },
			} ),
			root,
		);

		const add_btn = root.querySelector( '[data-action="add-url"]' );
		expect( add_btn ).not.toBeNull();
	} );

	it( 'opens the capture form when Add URL clicked', async () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [ build_pending() ],
				api_env: { fetch: globalThis.fetch },
			} ),
			root,
		);

		const add_btn = root.querySelector( '[data-action="add-url"]' ) as HTMLButtonElement;
		add_btn.click();
		// Allow Preact to flush the useState update.
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		const form = root.querySelector( '.outpost-syndication-capture-form' );
		expect( form ).not.toBeNull();
	} );

	it( 'renders Skip button when on_dismiss_entry handler provided', () => {
		render(
			h( PendingSyndicationsPanel, {
				pending:          [ build_pending() ],
				api_env:          { fetch: globalThis.fetch },
				on_dismiss_entry: vi.fn(),
			} ),
			root,
		);

		const skip_btn = root.querySelector( '[data-action="skip"]' );
		expect( skip_btn ).not.toBeNull();
	} );

	it( 'omits Skip button when on_dismiss_entry not provided', () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [ build_pending() ],
				api_env: { fetch: globalThis.fetch },
			} ),
			root,
		);

		const skip_btn = root.querySelector( '[data-action="skip"]' );
		expect( skip_btn ).toBeNull();
	} );

	it( 'platform_labels override changes display text', () => {
		render(
			h( PendingSyndicationsPanel, {
				pending: [ build_pending() ],
				api_env: { fetch: globalThis.fetch },
				platform_labels: {
					'instagram-feed': 'Custom IG Label',
				},
			} ),
			root,
		);

		const platform_text = root.querySelector( '.outpost-pending-syndications__platform' )?.textContent;
		expect( platform_text ).toBe( 'Custom IG Label' );
	} );
} );
