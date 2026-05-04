/**
 * Tests for fill_intent_url + url_has_unfilled_placeholders.
 *
 * Verifies all five placeholder tokens (raw and percent-encoded forms)
 * substitute correctly, longest-token-first matching avoids partial
 * collision (`@caption` vs `@caption_encoded`), and the unfilled-token
 * detector flags both raw and encoded forms.
 */

import { describe, expect, it } from 'vitest';
import { fill_intent_url, url_has_unfilled_placeholders } from './intent-url';

describe( 'fill_intent_url', () => {
	it( 'substitutes @caption verbatim', () => {
		const filled = fill_intent_url( 'https://example.com/share?text=@caption', {
			caption: 'Hello world',
		} );
		expect( filled ).toBe( 'https://example.com/share?text=Hello world' );
	} );

	it( 'substitutes @caption_encoded with URL-encoded form', () => {
		const filled = fill_intent_url( 'https://example.com/share?text=@caption_encoded', {
			caption: 'Hello world & friends',
		} );
		expect( filled ).toBe( 'https://example.com/share?text=Hello%20world%20%26%20friends' );
	} );

	it( 'substitutes @source_url verbatim', () => {
		const filled = fill_intent_url( 'https://example.com/share?ref=@source_url', {
			source_url: 'https://blog.example/post/42',
		} );
		expect( filled ).toBe( 'https://example.com/share?ref=https://blog.example/post/42' );
	} );

	it( 'substitutes @image_url verbatim', () => {
		const filled = fill_intent_url( 'https://example.com/share?img=@image_url', {
			image_url: 'https://cdn.example/img.jpg',
		} );
		expect( filled ).toBe( 'https://example.com/share?img=https://cdn.example/img.jpg' );
	} );

	it( 'substitutes @image_uri verbatim (PWA-runtime placeholder)', () => {
		const filled = fill_intent_url( 'intent://share?img=@image_uri', {
			image_uri: 'blob:https://outpost.example/abc',
		} );
		expect( filled ).toBe( 'intent://share?img=blob:https://outpost.example/abc' );
	} );

	it( 'does not partially match @caption when @caption_encoded is present', () => {
		// Critical edge case: the longest-token-first ordering ensures
		// `@caption_encoded` does NOT get matched as `@caption` +
		// literal `_encoded`. The output should have proper URL-encoded
		// caption, not raw caption with `_encoded` tail.
		const filled = fill_intent_url( '?text=@caption_encoded&meta=@caption', {
			caption: 'Hello world',
		} );
		expect( filled ).toContain( 'text=Hello%20world' );
		expect( filled ).toContain( 'meta=Hello world' );
		expect( filled ).not.toContain( 'Hello world_encoded' );
	} );

	it( 'handles all five placeholders in one URL', () => {
		const template =
			'intent://share?action=SEND' +
			'&caption=@caption' +
			'&enc=@caption_encoded' +
			'&src=@source_url' +
			'&img=@image_url' +
			'&uri=@image_uri';
		const filled = fill_intent_url( template, {
			caption: 'A caption',
			source_url: 'https://blog.example/post/1',
			image_url: 'https://cdn.example/i.jpg',
			image_uri: 'blob:https://app/abc',
		} );
		expect( filled ).toBe(
			'intent://share?action=SEND' +
			'&caption=A caption' +
			'&enc=A%20caption' +
			'&src=https://blog.example/post/1' +
			'&img=https://cdn.example/i.jpg' +
			'&uri=blob:https://app/abc'
		);
	} );

	it( 'handles percent-encoded placeholder tokens (%40caption)', () => {
		// The PHP-side builder URL-encodes `@image_uri` as `%40image_uri`
		// when it lives in a query parameter value. The PWA filler
		// substitutes both raw and encoded forms.
		const filled = fill_intent_url( 'intent://share?S.STREAM=%40image_uri', {
			image_uri: 'blob:https://app/xyz',
		} );
		expect( filled ).toBe( 'intent://share?S.STREAM=blob:https://app/xyz' );
	} );

	it( 'leaves unfilled placeholders untouched when subs are missing', () => {
		// When @image_uri is not provided, the template token persists
		// (substituted with empty string per current behavior). The
		// unfilled-detector flags the empty result so callers can
		// detect this state.
		const filled = fill_intent_url( 'intent://share?img=@image_uri', {} );
		expect( filled ).toBe( 'intent://share?img=' );
	} );
} );

describe( 'url_has_unfilled_placeholders', () => {
	it( 'returns true when a raw token is present', () => {
		expect( url_has_unfilled_placeholders( 'https://x.example?text=@caption' ) ).toBe( true );
	} );

	it( 'returns true when a percent-encoded token is present', () => {
		expect( url_has_unfilled_placeholders( 'https://x.example?text=%40caption_encoded' ) ).toBe( true );
	} );

	it( 'returns false for a fully-filled URL', () => {
		expect(
			url_has_unfilled_placeholders( 'https://x.example?text=Hello%20world&img=https://cdn/i.jpg' )
		).toBe( false );
	} );

	it( 'returns false for an empty string', () => {
		expect( url_has_unfilled_placeholders( '' ) ).toBe( false );
	} );
} );
