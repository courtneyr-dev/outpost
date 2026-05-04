/**
 * Tests for ReminderRollup.
 */

import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { h } from 'preact';
import { ReminderRollup } from './reminder-rollup';
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

function build_post( overrides: Partial<PendingPost> = {} ): PendingPost {
	return {
		post_id:    42,
		post_title: 'Sample',
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

describe( 'ReminderRollup', () => {
	it( 'renders empty state when count is 0', () => {
		render(
			h( ReminderRollup, {
				pending:        [],
				count:          0,
				can_snooze_all: true,
				on_open_post:   vi.fn(),
				on_snooze_all:  vi.fn().mockResolvedValue( undefined ),
			} ),
			root,
		);

		expect( root.querySelector( '.outpost-reminder-rollup--empty' ) ).not.toBeNull();
		expect( root.textContent ).toContain( 'No pending' );
	} );

	it( 'renders count and one row per pending post', () => {
		render(
			h( ReminderRollup, {
				pending:        [ build_post(), build_post( { post_id: 100, post_title: 'Other' } ) ],
				count:          2,
				can_snooze_all: true,
				on_open_post:   vi.fn(),
				on_snooze_all:  vi.fn().mockResolvedValue( undefined ),
			} ),
			root,
		);

		expect( root.querySelectorAll( '.outpost-reminder-rollup__item' ).length ).toBe( 2 );
		expect( root.textContent ).toContain( '2 pending' );
	} );

	it( "calls on_open_post when View clicked", () => {
		const on_open_post = vi.fn();
		render(
			h( ReminderRollup, {
				pending:        [ build_post() ],
				count:          1,
				can_snooze_all: true,
				on_open_post,
				on_snooze_all:  vi.fn().mockResolvedValue( undefined ),
			} ),
			root,
		);

		const view_btn = root.querySelector( '[data-action="view-post"]' ) as HTMLButtonElement;
		view_btn.click();

		expect( on_open_post ).toHaveBeenCalledWith( 42 );
	} );

	it( 'sorts posts by oldest pending first', () => {
		const newer = build_post( {
			post_id:    100,
			post_title: 'Newer',
			entries:    [
				{
					id:           'e2',
					platform_id:  'facebook',
					fired_at:     new Date( Date.now() - 10 * 60 * 1000 ).toISOString(),
					strategy:     'navigator_share',
					outcome:      'unknown',
					completed_at: null,
					silo_url:     null,
				},
			],
		} );
		const older = build_post( {
			post_id:    50,
			post_title: 'Older',
			entries:    [
				{
					id:           'e3',
					platform_id:  'tiktok',
					fired_at:     new Date( Date.now() - 24 * 3600 * 1000 ).toISOString(),
					strategy:     'app_url_scheme',
					outcome:      'unknown',
					completed_at: null,
					silo_url:     null,
				},
			],
		} );

		render(
			h( ReminderRollup, {
				pending:        [ newer, older ],
				count:          2,
				can_snooze_all: true,
				on_open_post:   vi.fn(),
				on_snooze_all:  vi.fn().mockResolvedValue( undefined ),
			} ),
			root,
		);

		const items = root.querySelectorAll( '.outpost-reminder-rollup__item' );
		expect( items[0]?.getAttribute( 'data-post-id' ) ).toBe( '50' );
		expect( items[1]?.getAttribute( 'data-post-id' ) ).toBe( '100' );
	} );

	it( 'disables Snooze all when can_snooze_all is false', () => {
		render(
			h( ReminderRollup, {
				pending:        [ build_post() ],
				count:          1,
				can_snooze_all: false,
				on_open_post:   vi.fn(),
				on_snooze_all:  vi.fn().mockResolvedValue( undefined ),
			} ),
			root,
		);

		const snooze_trigger = root.querySelector( '[data-action="open-snooze"]' ) as HTMLButtonElement;
		expect( snooze_trigger.disabled ).toBe( true );
		expect( root.textContent ).toContain( 'Snooze all was used recently' );
	} );

	it( 'opens snooze menu when Snooze all clicked', async () => {
		render(
			h( ReminderRollup, {
				pending:        [ build_post() ],
				count:          1,
				can_snooze_all: true,
				on_open_post:   vi.fn(),
				on_snooze_all:  vi.fn().mockResolvedValue( undefined ),
			} ),
			root,
		);

		const trigger = root.querySelector( '[data-action="open-snooze"]' ) as HTMLButtonElement;
		trigger.click();
		await new Promise( ( r ) => setTimeout( r, 0 ) );

		expect( root.querySelector( '.outpost-snooze-menu' ) ).not.toBeNull();
	} );
} );
