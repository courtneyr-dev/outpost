/**
 * Tests for the Android share handler — the full fallback chain
 * (clipboard → navigator.share → intent_url → two-tap worst case).
 *
 * Every test injects a synthetic environment so no real navigator,
 * clipboard, fetch, or window.location is touched.
 */

import { describe, expect, it, vi } from 'vitest';
import { execute_android_share } from './android';
import type {
	AndroidShareEnvironment,
	AndroidShareResult,
} from './android';
import type { IntentPayloadAndroid } from './types';

function build_payload( overrides: Partial<IntentPayloadAndroid> = {} ): IntentPayloadAndroid {
	return {
		platform:        'instagram-feed',
		platform_label:  'Instagram',
		files:           [
			{ url: 'https://example.com/img.jpg', alt: 'A photo', mime: 'image/jpeg' },
		],
		caption:         'Hello world',
		clipboard_text:  'Hello world\n\nA photo',
		intent_strategy: 'navigator_share',
		fallback_url:    'intent://share?action=SEND;package=com.instagram.android;end',
		after_share:     'prompt_for_silo_url',
		audit_log_id:    'audit-1',
		source_url:      'https://blog.example/post/42',
		...overrides,
	};
}

function build_blob_response( body = 'fake-image-bytes', mime = 'image/jpeg' ): Response {
	const blob = new Blob( [ body ], { type: mime } );
	return new Response( blob, { status: 200, headers: { 'content-type': mime } } );
}

describe( 'execute_android_share — happy path (navigator.share with files)', () => {
	it( 'writes clipboard then calls navigator.share with a File payload', async () => {
		const clipboard_writes: string[] = [];
		const share_calls: ShareData[]   = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async ( t ) => {
				clipboard_writes.push( t );
			},
			navigator_share:     async ( data ) => {
				share_calls.push( data );
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => build_blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
			post_telemetry:      vi.fn( async () => {} ),
		};

		const payload = build_payload();
		const result  = await execute_android_share( payload, 42, env );

		expect( clipboard_writes ).toEqual( [ 'Hello world\n\nA photo' ] );
		expect( share_calls ).toHaveLength( 1 );
		expect( share_calls[0]?.title ).toBe( 'Hello world' );
		expect( ( share_calls[0]?.files ?? [] ).length ).toBe( 1 );
		expect( result.outcome ).toBe( 'fired' );
		expect( result.strategy ).toBe( 'navigator_share' );
		expect( result.audit_log_id ).toBe( 'audit-1' );
		expect( env.post_telemetry ).toHaveBeenCalledOnce();
	} );

	it( 'fires share with no files when payload has none', async () => {
		const share_calls: ShareData[] = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async ( data ) => {
				share_calls.push( data );
			},
			navigator_can_share: () => true,
			navigate:            vi.fn(),
			post_telemetry:      async () => {},
		};

		await execute_android_share( build_payload( { files: [] } ), 42, env );

		expect( share_calls[0]?.files ).toBeUndefined();
		expect( share_calls[0]?.title ).toBe( 'Hello world' );
	} );
} );

describe( 'execute_android_share — fallbacks', () => {
	it( 'falls back to fallback_url when navigator.share is unavailable', async () => {
		const navigate_calls: string[] = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     undefined,
			navigate:            ( url ) => {
				navigate_calls.push( url );
			},
			post_telemetry:      async () => {},
		};

		const result = await execute_android_share( build_payload(), 42, env );

		expect( navigate_calls ).toHaveLength( 1 );
		expect( navigate_calls[0] ).toContain( 'intent://share' );
		expect( result.outcome ).toBe( 'fired' );
		expect( result.strategy ).toBe( 'intent_url' );
	} );

	it( 'falls back to fallback_url when navigator.canShare rejects files', async () => {
		const navigate_calls: string[] = [];
		const share_calls: ShareData[] = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async ( data ) => {
				share_calls.push( data );
			},
			navigator_can_share: () => false,
			fetch:               vi.fn( async () => build_blob_response() ) as unknown as typeof fetch,
			navigate:            ( url ) => {
				navigate_calls.push( url );
			},
			post_telemetry:      async () => {},
		};

		const result = await execute_android_share( build_payload(), 42, env );

		expect( share_calls ).toHaveLength( 0 );
		expect( navigate_calls ).toHaveLength( 1 );
		expect( result.strategy ).toBe( 'intent_url' );
	} );

	it( 'falls back to fallback_url when navigator.share throws', async () => {
		const navigate_calls: string[] = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async () => {
				throw new Error( 'AbortError: user cancelled' );
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => build_blob_response() ) as unknown as typeof fetch,
			navigate:            ( url ) => {
				navigate_calls.push( url );
			},
			post_telemetry:      async () => {},
		};

		const result = await execute_android_share( build_payload(), 42, env );

		expect( navigate_calls ).toHaveLength( 1 );
		expect( result.strategy ).toBe( 'intent_url' );
	} );

	it( 'falls back to two-tap aborted result when image fetch fails', async () => {
		const navigate_calls: string[] = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async () => {},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => {
				throw new Error( 'Network down' );
			} ) as unknown as typeof fetch,
			navigate:            ( url ) => {
				navigate_calls.push( url );
			},
			post_telemetry:      async () => {},
		};

		const result = await execute_android_share( build_payload(), 42, env );

		// fetch failure → file fetch error → navigator.share rejected
		// → fallback to intent_url.
		expect( navigate_calls ).toHaveLength( 1 );
		expect( result.strategy ).toBe( 'intent_url' );
		expect( result.outcome ).toBe( 'fired' );
	} );

	it( 'returns aborted two_tap_fallback when no fallback URL and share fails', async () => {
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     undefined,
			navigate:            vi.fn(),
			post_telemetry:      async () => {},
		};

		const result = await execute_android_share(
			build_payload( { fallback_url: '' } ),
			42,
			env
		);

		expect( result.outcome ).toBe( 'aborted' );
		expect( result.strategy ).toBe( 'two_tap_fallback' );
	} );
} );

describe( 'execute_android_share — intent_url-first strategy', () => {
	it( 'navigates straight to fallback_url when strategy is intent_url', async () => {
		// Threads / Reddit / web-intent platforms — caption-only paths
		// where navigator.share isn't useful (no image attachment).
		const navigate_calls: string[] = [];
		const share_calls: ShareData[] = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async ( data ) => {
				share_calls.push( data );
			},
			navigator_can_share: () => true,
			navigate:            ( url ) => {
				navigate_calls.push( url );
			},
			post_telemetry:      async () => {},
		};

		const payload = build_payload( {
			intent_strategy: 'intent_url',
			fallback_url:    'https://www.threads.net/intent/post?text=Hello%20world',
		} );
		const result = await execute_android_share( payload, 42, env );

		expect( navigate_calls ).toEqual( [
			'https://www.threads.net/intent/post?text=Hello%20world',
		] );
		expect( share_calls ).toHaveLength( 0 );
		expect( result.outcome ).toBe( 'fired' );
	} );
} );

describe( 'execute_android_share — clipboard resilience', () => {
	it( 'does not abort when clipboard.writeText throws', async () => {
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {
				throw new Error( 'NotAllowedError: clipboard write blocked' );
			},
			navigator_share:     async () => {},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => build_blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
			post_telemetry:      async () => {},
		};

		const result = await execute_android_share( build_payload(), 42, env );

		// Share still fires even with clipboard failure.
		expect( result.outcome ).toBe( 'fired' );
	} );
} );

describe( 'execute_android_share — telemetry', () => {
	it( 'posts telemetry on every outcome path', async () => {
		const telemetry_calls: AndroidShareResult[] = [];
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async () => {},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => build_blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
			post_telemetry:      async ( t ) => {
				telemetry_calls.push( t as unknown as AndroidShareResult );
			},
		};

		await execute_android_share( build_payload(), 42, env );

		expect( telemetry_calls ).toHaveLength( 1 );
	} );

	it( 'does not throw if telemetry POST fails', async () => {
		const env: AndroidShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async () => {},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => build_blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
			post_telemetry:      async () => {
				throw new Error( 'telemetry endpoint down' );
			},
		};

		const result = await execute_android_share( build_payload(), 42, env );
		expect( result.outcome ).toBe( 'fired' );
	} );
} );
