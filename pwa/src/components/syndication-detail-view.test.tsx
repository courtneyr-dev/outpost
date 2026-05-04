/**
 * Tests for SyndicationDetailView.
 */

import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { h } from 'preact';
import { SyndicationDetailView, type DetailEntry } from './syndication-detail-view';

let root: HTMLDivElement;

beforeEach( () => {
	root = document.createElement( 'div' );
	document.body.appendChild( root );
} );

afterEach( () => {
	render( null, root );
	root.remove();
} );

const ABANDONED_SENTINEL = '2200-01-01T00:00:00+00:00';

function entry( overrides: Partial<DetailEntry> = {} ): DetailEntry {
	return {
		id:                       'e1',
		platform_id:              'instagram-feed',
		fired_at:                 '2026-05-04T12:00:00+00:00',
		strategy:                 'navigator_share',
		outcome:                  'unknown',
		completed_at:             null,
		silo_url:                 null,
		reminder_dismissed_until: null,
		...overrides,
	};
}

function default_props( overrides: Partial<Parameters<typeof SyndicationDetailView>[0]> = {} ) {
	return {
		post_id:        42,
		post_title:     'Sunset over the lake',
		entries:        [ entry() ],
		api_env:        { fetch: globalThis.fetch },
		on_snooze:      vi.fn().mockResolvedValue( undefined ),
		on_un_abandon:  vi.fn().mockResolvedValue( undefined ),
		...overrides,
	};
}

describe( 'SyndicationDetailView', () => {
	it( 'renders post title in the header', () => {
		render( h( SyndicationDetailView, default_props() ), root );
		expect( root.textContent ).toContain( 'Sunset over the lake' );
	} );

	it( "labels pending entries with '⏳ Pending' and shows action buttons", () => {
		render( h( SyndicationDetailView, default_props() ), root );

		const item = root.querySelector( '[data-state="pending"]' );
		expect( item ).not.toBeNull();
		expect( item?.textContent ).toContain( '⏳ Pending' );
		expect( root.querySelector( '[data-action="add-url"]' ) ).not.toBeNull();
		expect( root.querySelector( '[data-action="snooze"]' ) ).not.toBeNull();
	} );

	it( "labels completed entries with '✓ Complete' and shows the silo URL", () => {
		render(
			h( SyndicationDetailView, default_props( {
				entries: [ entry( {
					completed_at: '2026-05-04T13:00:00+00:00',
					silo_url:     'https://example.com/p/abc',
				} ) ],
			} ) ),
			root,
		);

		const item = root.querySelector( '[data-state="complete"]' );
		expect( item ).not.toBeNull();
		expect( item?.textContent ).toContain( '✓ Complete' );
		const link = root.querySelector( '.outpost-syndication-detail__silo-link' ) as HTMLAnchorElement | null;
		expect( link?.href ).toBe( 'https://example.com/p/abc' );
	} );

	it( "labels abandoned entries with '⚠ Abandoned' and shows un-abandon action", () => {
		render(
			h( SyndicationDetailView, default_props( {
				entries: [ entry( { reminder_dismissed_until: ABANDONED_SENTINEL } ) ],
			} ) ),
			root,
		);

		const item = root.querySelector( '[data-state="abandoned"]' );
		expect( item ).not.toBeNull();
		expect( item?.textContent ).toContain( '⚠ Abandoned' );
		expect( root.querySelector( '[data-action="forget-reminder"]' ) ).not.toBeNull();
		expect( root.querySelector( '[data-action="add-url-anyway"]' ) ).not.toBeNull();
	} );

	it( 'shows the strategy label per entry', () => {
		render( h( SyndicationDetailView, default_props() ), root );
		expect( root.textContent ).toContain( 'navigator_share' );
	} );

	it( "opens capture form when 'Add URL...' clicked", async () => {
		render( h( SyndicationDetailView, default_props() ), root );

		const add_btn = root.querySelector( '[data-action="add-url"]' ) as HTMLButtonElement;
		add_btn.click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( root.querySelector( '.outpost-syndication-capture-form' ) ).not.toBeNull();
	} );

	it( "opens snooze menu when 'Snooze' clicked", async () => {
		render( h( SyndicationDetailView, default_props() ), root );

		const snooze_btn = root.querySelector( '[data-action="snooze"]' ) as HTMLButtonElement;
		snooze_btn.click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( root.querySelector( '.outpost-snooze-menu' ) ).not.toBeNull();
	} );

	it( "calls on_un_abandon when 'Forget reminder' clicked on abandoned entry", () => {
		const on_un_abandon = vi.fn().mockResolvedValue( undefined );
		render(
			h( SyndicationDetailView, default_props( {
				entries:       [ entry( { reminder_dismissed_until: ABANDONED_SENTINEL } ) ],
				on_un_abandon,
			} ) ),
			root,
		);

		const forget_btn = root.querySelector( '[data-action="forget-reminder"]' ) as HTMLButtonElement;
		forget_btn.click();

		expect( on_un_abandon ).toHaveBeenCalledWith( 42, 'e1' );
	} );

	it( 'renders one item per audit log entry', () => {
		render(
			h( SyndicationDetailView, default_props( {
				entries: [
					entry( { id: 'e1', platform_id: 'instagram-feed' } ),
					entry( { id: 'e2', platform_id: 'facebook' } ),
					entry( { id: 'e3', platform_id: 'tiktok' } ),
				],
			} ) ),
			root,
		);

		expect( root.querySelectorAll( '.outpost-syndication-detail__item' ).length ).toBe( 3 );
	} );
} );
