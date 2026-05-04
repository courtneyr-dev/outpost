/**
 * Tests for SyndicationCaptureForm — F12 inline URL prompt.
 */

import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { h } from 'preact';
import { SyndicationCaptureForm } from './syndication-capture-form';
import type { CaptureResponse } from '../lib/manual-share/capture-api';

let root: HTMLDivElement;

beforeEach( () => {
	root = document.createElement( 'div' );
	document.body.appendChild( root );
} );

afterEach( () => {
	render( null, root );
	root.remove();
} );

function default_props( overrides: Partial<Parameters<typeof SyndicationCaptureForm>[0]> = {} ) {
	return {
		post_id:        42,
		audit_log_id:   'a1',
		platform_id:    'instagram-feed',
		platform_label: 'Instagram',
		api_env:        { fetch: globalThis.fetch },
		on_recorded:    vi.fn(),
		on_cancel:      vi.fn(),
		...overrides,
	};
}

async function flush_promises(): Promise<void> {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'SyndicationCaptureForm', () => {
	it( 'renders the platform label in the input label text', () => {
		const props = default_props();
		render( h( SyndicationCaptureForm, props ), root );

		const label = root.querySelector( '.outpost-syndication-capture-form__label-text' );
		expect( label?.textContent ).toContain( 'Instagram' );
	} );

	it( 'shows error when submitting empty input', async () => {
		const submit_fn = vi.fn();
		const props     = default_props( { submit_fn } );
		render( h( SyndicationCaptureForm, props ), root );

		const form = root.querySelector( 'form' ) as HTMLFormElement;
		form.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		await flush_promises();

		const error = root.querySelector( '.outpost-syndication-capture-form__error' );
		expect( error?.textContent ).toContain( 'Please paste' );
		expect( submit_fn ).not.toHaveBeenCalled();
	} );

	it( 'calls submit_fn on form submit and on_recorded on success', async () => {
		const submit_fn = vi.fn().mockResolvedValue( {
			status:             'recorded',
			audit_log_id:       'a1',
			silo_url:           'https://example.com/p/abc',
			platform_id:        'instagram-feed',
			syndication_links:  [],
			mismatch_confirmed: false,
		} satisfies CaptureResponse );
		const on_recorded = vi.fn();
		const props       = default_props( { submit_fn, on_recorded } );
		render( h( SyndicationCaptureForm, props ), root );

		const input = root.querySelector( 'input[type="url"]' ) as HTMLInputElement;
		input.value = 'https://example.com/p/abc';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		// Allow Preact to flush the state update before submit.
		await flush_promises();

		const form = root.querySelector( 'form' ) as HTMLFormElement;
		form.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		await flush_promises();
		await flush_promises();

		expect( submit_fn ).toHaveBeenCalledOnce();
		const call = submit_fn.mock.calls[0]!;
		expect( ( call[0] as { silo_url: string } ).silo_url ).toBe( 'https://example.com/p/abc' );
		expect( ( call[0] as { confirm_mismatch?: boolean } ).confirm_mismatch ).toBe( false );
		expect( on_recorded ).toHaveBeenCalledWith( 'https://example.com/p/abc' );
	} );

	it( 'shows mismatch warning when server returns mismatch_warning', async () => {
		const submit_fn = vi.fn().mockResolvedValue( {
			status:      'mismatch_warning',
			platform_id: 'instagram-feed',
			silo_url:    'https://twitter.com/x',
			message:     'Domain mismatch — confirm to proceed',
		} satisfies CaptureResponse );
		const props = default_props( { submit_fn } );
		render( h( SyndicationCaptureForm, props ), root );

		const input = root.querySelector( 'input[type="url"]' ) as HTMLInputElement;
		input.value = 'https://twitter.com/x';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		await flush_promises();

		const form = root.querySelector( 'form' ) as HTMLFormElement;
		form.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		await flush_promises();
		await flush_promises();

		const warning = root.querySelector( '.outpost-syndication-capture-form__warning' );
		expect( warning?.textContent ).toContain( 'mismatch' );
		const confirm_btn = root.querySelector( '[data-action="confirm-mismatch"]' );
		expect( confirm_btn ).not.toBeNull();
	} );

	it( "resubmits with confirm_mismatch=true when user clicks 'Save anyway'", async () => {
		const submit_fn = vi.fn()
			.mockResolvedValueOnce( {
				status:      'mismatch_warning',
				platform_id: 'instagram-feed',
				silo_url:    'https://twitter.com/x',
				message:     'Mismatch',
			} satisfies CaptureResponse )
			.mockResolvedValueOnce( {
				status:             'recorded',
				audit_log_id:       'a1',
				silo_url:           'https://twitter.com/x',
				platform_id:        'instagram-feed',
				syndication_links:  [],
				mismatch_confirmed: true,
			} satisfies CaptureResponse );
		const on_recorded = vi.fn();
		const props       = default_props( { submit_fn, on_recorded } );
		render( h( SyndicationCaptureForm, props ), root );

		const input = root.querySelector( 'input[type="url"]' ) as HTMLInputElement;
		input.value = 'https://twitter.com/x';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		await flush_promises();

		const form = root.querySelector( 'form' ) as HTMLFormElement;
		form.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		await flush_promises();
		await flush_promises();

		const confirm_btn = root.querySelector( '[data-action="confirm-mismatch"]' ) as HTMLButtonElement;
		confirm_btn.click();
		await flush_promises();
		await flush_promises();

		expect( submit_fn ).toHaveBeenCalledTimes( 2 );
		const second_call = submit_fn.mock.calls[1]!;
		expect( ( second_call[0] as { confirm_mismatch?: boolean } ).confirm_mismatch ).toBe( true );
		expect( on_recorded ).toHaveBeenCalledWith( 'https://twitter.com/x' );
	} );

	it( "shows error when server returns 'error' status", async () => {
		const submit_fn = vi.fn().mockResolvedValue( {
			status:  'error',
			code:    'invalid_scheme',
			message: 'Only http and https URLs are accepted.',
		} satisfies CaptureResponse );
		const props = default_props( { submit_fn } );
		render( h( SyndicationCaptureForm, props ), root );

		const input = root.querySelector( 'input[type="url"]' ) as HTMLInputElement;
		input.value = 'javascript:alert(1)';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		await flush_promises();

		const form = root.querySelector( 'form' ) as HTMLFormElement;
		form.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
		await flush_promises();
		await flush_promises();

		const error = root.querySelector( '.outpost-syndication-capture-form__error' );
		expect( error?.textContent ).toContain( 'http' );
	} );

	it( 'calls on_cancel when Cancel button clicked', () => {
		const on_cancel = vi.fn();
		const props     = default_props( { on_cancel } );
		render( h( SyndicationCaptureForm, props ), root );

		const cancel_btn = root.querySelector( '[data-action="cancel"]' ) as HTMLButtonElement;
		cancel_btn.click();

		expect( on_cancel ).toHaveBeenCalledOnce();
	} );
} );
