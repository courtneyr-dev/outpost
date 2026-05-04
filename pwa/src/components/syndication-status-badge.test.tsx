/**
 * Tests for SyndicationStatusBadge — visual state coverage + a11y.
 */

import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { render } from 'preact';
import { h } from 'preact';
import { SyndicationStatusBadge, type SyndicationStatus } from './syndication-status-badge';

let root: HTMLDivElement;

beforeEach( () => {
	root = document.createElement( 'div' );
	document.body.appendChild( root );
} );

afterEach( () => {
	render( null, root );
	root.remove();
} );

interface BadgeCase {
	status: SyndicationStatus;
	summary: { total: number; complete: number; pending: number; abandoned: number };
	expected_glyph: string;
	expected_text: string;
	expected_aria: string;
}

const cases: BadgeCase[] = [
	{
		status:         'no_syndication',
		summary:        { total: 0, complete: 0, pending: 0, abandoned: 0 },
		expected_glyph: '—',
		expected_text:  'none',
		expected_aria:  'No syndication',
	},
	{
		status:         'complete',
		summary:        { total: 2, complete: 2, pending: 0, abandoned: 0 },
		expected_glyph: '✓',
		expected_text:  '2/2',
		expected_aria:  'Syndication complete: 2 platforms',
	},
	{
		status:         'partial',
		summary:        { total: 3, complete: 1, pending: 2, abandoned: 0 },
		expected_glyph: '⏳',
		expected_text:  '1/3',
		expected_aria:  'Syndication partial: 1 of 3 completed',
	},
	{
		status:         'pending',
		summary:        { total: 2, complete: 0, pending: 2, abandoned: 0 },
		expected_glyph: '⏳',
		expected_text:  '0/2',
		expected_aria:  'Syndication pending: 2 platforms',
	},
	{
		status:         'abandoned',
		summary:        { total: 1, complete: 0, pending: 0, abandoned: 1 },
		expected_glyph: '⚠',
		expected_text:  'abandoned',
		expected_aria:  'Syndication abandoned: 1 platforms',
	},
];

describe( 'SyndicationStatusBadge', () => {
	it.each( cases )( '$status renders $expected_glyph with text "$expected_text"', ( c ) => {
		render(
			h( SyndicationStatusBadge, { status: c.status, summary: c.summary } ),
			root,
		);

		const badge = root.querySelector( '[data-status]' ) as HTMLElement;
		expect( badge.getAttribute( 'data-status' ) ).toBe( c.status );
		expect( badge.querySelector( '.outpost-syndication-badge__glyph' )?.textContent ).toBe(
			c.expected_glyph,
		);
		expect( badge.querySelector( '.outpost-syndication-badge__text' )?.textContent ).toBe(
			c.expected_text,
		);
		expect( badge.getAttribute( 'aria-label' ) ).toBe( c.expected_aria );
	} );

	it( 'glyph is aria-hidden so screen readers skip it', () => {
		render(
			h( SyndicationStatusBadge, {
				status:  'complete',
				summary: { total: 1, complete: 1, pending: 0, abandoned: 0 },
			} ),
			root,
		);

		const glyph = root.querySelector( '.outpost-syndication-badge__glyph' );
		expect( glyph?.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
	} );

	it( 'badge has role="img" for the composite glyph+text', () => {
		render(
			h( SyndicationStatusBadge, {
				status:  'pending',
				summary: { total: 1, complete: 0, pending: 1, abandoned: 0 },
			} ),
			root,
		);

		const badge = root.querySelector( '[data-status]' );
		expect( badge?.getAttribute( 'role' ) ).toBe( 'img' );
	} );

	it( 'class includes the status-specific suffix for theme styling', () => {
		render(
			h( SyndicationStatusBadge, {
				status:  'partial',
				summary: { total: 2, complete: 1, pending: 1, abandoned: 0 },
			} ),
			root,
		);

		const badge = root.querySelector( '[data-status]' );
		expect( badge?.className ).toContain( 'outpost-syndication-badge--partial' );
	} );
} );
