/**
 * Tests for try_navigator_share_files strategy (iOS).
 */

import { describe, expect, it, vi } from 'vitest';
import { try_navigator_share_files } from './navigator-share-files';
import type { IosStrategyEnvironment } from './types';
import type { IntentPayloadIos } from '../types';

function build_payload( overrides: Partial<IntentPayloadIos> = {} ): IntentPayloadIos {
	return {
		platform:        'instagram-feed',
		platform_label:  'Instagram',
		files:           [ { url: 'https://x.example/img.jpg', alt: '', mime: 'image/jpeg' } ],
		caption:         'Hello',
		clipboard_text:  'Hello',
		ios_strategy:    [ 'navigator_share_files', 'app_url_scheme', 'manual' ],
		app_url_scheme:  'instagram://',
		web_intent_url:  null,
		in_pwa_mode:     true,
		after_share:     'prompt_for_silo_url',
		audit_log_id:    'a1',
		source_url:      'https://blog.example/p/1',
		...overrides,
	};
}

function blob_response(): Response {
	return new Response( new Blob( [ 'fake' ], { type: 'image/jpeg' } ), { status: 200 } );
}

describe( 'try_navigator_share_files', () => {
	it( "returns 'rejected' when navigator_share is missing", async () => {
		const env: IosStrategyEnvironment = { navigate: vi.fn() };
		const result = await try_navigator_share_files( build_payload(), env );
		expect( result ).toBe( 'rejected' );
	} );

	it( "returns 'rejected' when payload has no files", async () => {
		const env: IosStrategyEnvironment = {
			navigator_share: vi.fn( async () => {} ),
			fetch:           vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:        vi.fn(),
		};
		const result = await try_navigator_share_files(
			build_payload( { files: [] } ),
			env,
		);
		expect( result ).toBe( 'rejected' );
		expect( env.navigator_share ).not.toHaveBeenCalled();
	} );

	it( "returns 'rejected' when fetch fails", async () => {
		const env: IosStrategyEnvironment = {
			navigator_share: vi.fn( async () => {} ),
			fetch:           vi.fn( async () => {
				throw new Error( 'down' );
			} ) as unknown as typeof fetch,
			navigate:        vi.fn(),
		};
		const result = await try_navigator_share_files( build_payload(), env );
		expect( result ).toBe( 'rejected' );
	} );

	it( "returns 'rejected' when canShare rejects files", async () => {
		const env: IosStrategyEnvironment = {
			navigator_share:     vi.fn( async () => {} ),
			navigator_can_share: vi.fn( () => false ),
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
		};
		const result = await try_navigator_share_files( build_payload(), env );
		expect( result ).toBe( 'rejected' );
	} );

	it( "returns 'fired' when navigator.share resolves", async () => {
		const share_calls: ShareData[] = [];
		const env: IosStrategyEnvironment = {
			navigator_share: async ( data ) => {
				share_calls.push( data );
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
		};

		const result = await try_navigator_share_files( build_payload(), env );

		expect( result ).toBe( 'fired' );
		expect( share_calls[0]?.title ).toBe( 'Hello' );
		expect( ( share_calls[0]?.files ?? [] ).length ).toBe( 1 );
	} );

	it( "returns 'aborted' on AbortError (user cancelled)", async () => {
		const env: IosStrategyEnvironment = {
			navigator_share: async () => {
				const err = new Error( 'cancelled' );
				err.name  = 'AbortError';
				throw err;
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
		};

		const result = await try_navigator_share_files( build_payload(), env );
		expect( result ).toBe( 'aborted' );
	} );

	it( "returns 'rejected' on non-AbortError throw", async () => {
		const env: IosStrategyEnvironment = {
			navigator_share: async () => {
				throw new Error( 'something else' );
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
		};

		const result = await try_navigator_share_files( build_payload(), env );
		expect( result ).toBe( 'rejected' );
	} );
} );
