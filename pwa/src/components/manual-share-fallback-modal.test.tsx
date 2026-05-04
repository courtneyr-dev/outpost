/**
 * Tests for ManualShareFallbackModal — pure presentational component.
 */

import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { h } from 'preact';
import { ManualShareFallbackModal } from './manual-share-fallback-modal';

let root: HTMLDivElement;

beforeEach( () => {
	root = document.createElement( 'div' );
	document.body.appendChild( root );
} );

afterEach( () => {
	render( null, root );
	root.remove();
} );

function default_props() {
	return {
		platform_label:   'Instagram',
		platform_id:      'instagram-feed',
		caption:          'Hello world',
		clipboard_text:   'Hello world',
		first_image_url:  'https://x.example/img.jpg',
		app_homepage_url: 'https://www.instagram.com',
	};
}

describe( 'ManualShareFallbackModal', () => {
	it( 'renders the platform label in the title', () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, { ...default_props(), on_dismiss } ),
			root,
		);

		const title = root.querySelector( '#outpost-manual-share-title' );
		expect( title?.textContent ).toContain( 'Instagram' );
	} );

	it( 'renders Save Image button when first_image_url is non-null', () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, { ...default_props(), on_dismiss } ),
			root,
		);

		const save_btn = root.querySelector( '[data-action="save-image"]' );
		expect( save_btn ).not.toBeNull();
	} );

	it( 'omits Save Image button when first_image_url is null', () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, {
				...default_props(),
				first_image_url: null,
				on_dismiss,
			} ),
			root,
		);

		const save_btn = root.querySelector( '[data-action="save-image"]' );
		expect( save_btn ).toBeNull();
	} );

	it( 'renders Open App link with the homepage URL when provided', () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, { ...default_props(), on_dismiss } ),
			root,
		);

		const open_link = root.querySelector( '[data-action="open-app"]' ) as HTMLAnchorElement | null;
		expect( open_link?.href ).toBe( 'https://www.instagram.com/' );
	} );

	it( "calls on_dismiss('fired') when Done is clicked", () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, { ...default_props(), on_dismiss } ),
			root,
		);

		const done_btn = root.querySelector( '[data-action="done"]' ) as HTMLButtonElement;
		done_btn.click();

		expect( on_dismiss ).toHaveBeenCalledWith( 'fired' );
	} );

	it( "calls on_dismiss('aborted') when close button is clicked", () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, { ...default_props(), on_dismiss } ),
			root,
		);

		const close_btn = root.querySelector( '[data-action="close"]' ) as HTMLButtonElement;
		close_btn.click();

		expect( on_dismiss ).toHaveBeenCalledWith( 'aborted' );
	} );

	it( "calls on_dismiss('fired') when Save Image is clicked", () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, { ...default_props(), on_dismiss } ),
			root,
		);

		const save_btn = root.querySelector( '[data-action="save-image"]' ) as HTMLButtonElement;
		save_btn.click();

		expect( on_dismiss ).toHaveBeenCalledWith( 'fired' );
	} );

	it( "uses dialog ARIA role for accessibility", () => {
		const on_dismiss = vi.fn();
		render(
			h( ManualShareFallbackModal, { ...default_props(), on_dismiss } ),
			root,
		);

		const dialog = root.querySelector( '[role="dialog"]' );
		expect( dialog ).not.toBeNull();
		expect( dialog?.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect( dialog?.getAttribute( 'aria-labelledby' ) ).toBe( 'outpost-manual-share-title' );
	} );
} );
