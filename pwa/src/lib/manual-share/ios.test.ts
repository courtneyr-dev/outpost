/**
 * Tests for execute_ios_share — the orchestrator.
 */

import { describe, expect, it, vi } from 'vitest';
import { execute_ios_share } from './ios';
import type { IosShareEnvironment } from './ios';
import type { IntentPayloadIos } from './types';

function build_payload( overrides: Partial<IntentPayloadIos> = {} ): IntentPayloadIos {
	return {
		platform:        'instagram-feed',
		platform_label:  'Instagram',
		files:           [ { url: 'https://x.example/img.jpg', alt: '', mime: 'image/jpeg' } ],
		caption:         'Hello',
		clipboard_text:  'Hello\n\nphoto alt',
		ios_strategy:    [ 'navigator_share_files', 'app_url_scheme', 'manual' ],
		app_url_scheme:  'instagram://library?AssetPath=',
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

describe( 'execute_ios_share', () => {
	it( 'writes clipboard before the strategy chain runs', async () => {
		const calls: string[] = [];
		const env: IosShareEnvironment = {
			clipboard_write_text: async ( t ) => {
				calls.push( `clipboard:${ t }` );
			},
			navigator_share: async () => {
				calls.push( 'share' );
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            () => {
				calls.push( 'navigate' );
			},
		};

		await execute_ios_share( build_payload(), 42, env );

		expect( calls[0] ).toBe( 'clipboard:Hello\n\nphoto alt' );
	} );

	it( 'fires navigator.share when in PWA mode', async () => {
		const env: IosShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     vi.fn( async () => {} ),
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
		};

		const result = await execute_ios_share( build_payload(), 42, env );

		expect( env.navigator_share ).toHaveBeenCalled();
		expect( result.outcome ).toBe( 'fired' );
		expect( result.strategy ).toBe( 'navigator_share_files' );
	} );

	it( 'skips navigator_share_files when not in PWA mode', async () => {
		// The chain still walks app_url_scheme + manual; navigator.share
		// is filtered out before the runner sees it.
		const navigate_calls: string[] = [];
		let visibility_callback: ( () => void ) | undefined;
		const env: IosShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     vi.fn( async () => {} ),
			navigate:            ( url ) => {
				navigate_calls.push( url );
				visibility_callback?.();
			},
			add_visibility_listener: ( cb ) => {
				visibility_callback = cb;
				return () => {};
			},
			set_timeout: ( cb, ms ) => globalThis.setTimeout( cb, ms ),
			clear_timeout: ( h ) => globalThis.clearTimeout( h as number ),
		};

		const result = await execute_ios_share(
			build_payload( { in_pwa_mode: false } ),
			42,
			env,
		);

		expect( env.navigator_share ).not.toHaveBeenCalled();
		expect( result.strategy ).toBe( 'app_url_scheme' );
		expect( navigate_calls ).toEqual( [ 'instagram://library?AssetPath=' ] );
	} );

	it( 'skips navigator_share_files when env has no navigator_share', async () => {
		// PWA detection said yes but the navigator implementation is missing.
		const env: IosShareEnvironment = {
			clipboard_write_text: async () => {},
			navigate:            vi.fn(),
			add_visibility_listener: () => () => {},
			set_timeout: vi.fn( ( cb: () => void ) => {
				cb();
				return 'h';
			} ) as unknown as ( cb: () => void, ms: number ) => unknown,
			clear_timeout: vi.fn(),
			show_manual_modal: async () => 'fired',
		};

		const result = await execute_ios_share( build_payload(), 42, env );

		// Falls through navigator_share_files (no env fn), then app_url_scheme
		// rejects via timeout, then manual fires.
		expect( result.strategy ).toBe( 'manual' );
	} );

	it( 'falls through to manual when scheme + share both fail', async () => {
		// set_timeout fires synchronously to settle the app-url-scheme
		// race deterministically without real timers.
		const env: IosShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share: async () => {
				const err = new Error( 'fail' );
				err.name  = 'NotAllowedError';
				throw err;
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
			add_visibility_listener: () => () => {},
			set_timeout: ( ( cb: () => void ) => {
				cb();
				return 'h';
			} ) as unknown as ( cb: () => void, ms: number ) => unknown,
			clear_timeout: vi.fn(),
			show_manual_modal: async () => 'fired',
		};

		const result = await execute_ios_share( build_payload(), 42, env );

		expect( result.strategy ).toBe( 'manual' );
		expect( result.outcome ).toBe( 'fired' );
	} );

	it( "stops at navigator.share AbortError ('aborted') without falling through", async () => {
		const env: IosShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share: async () => {
				const err = new Error( 'cancelled' );
				err.name  = 'AbortError';
				throw err;
			},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
			show_manual_modal:   async () => 'fired',
		};

		const result = await execute_ios_share( build_payload(), 42, env );

		expect( result.outcome ).toBe( 'aborted' );
		expect( result.strategy ).toBe( 'navigator_share_files' );
	} );

	it( 'posts telemetry with audit_log_id + outcome', async () => {
		const telemetry_calls: { audit_log_id: string; outcome: string }[] = [];
		const env: IosShareEnvironment = {
			clipboard_write_text: async () => {},
			navigator_share:     async () => {},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
			post_telemetry: async ( t ) => {
				telemetry_calls.push( {
					audit_log_id: t.audit_log_id,
					outcome:      t.outcome,
				} );
			},
		};

		await execute_ios_share( build_payload(), 42, env );

		expect( telemetry_calls ).toHaveLength( 1 );
		expect( telemetry_calls[0] ).toEqual( {
			audit_log_id: 'a1',
			outcome:      'fired',
		} );
	} );

	it( 'does not throw when clipboard fails', async () => {
		const env: IosShareEnvironment = {
			clipboard_write_text: async () => {
				throw new Error( 'NotAllowedError' );
			},
			navigator_share:     async () => {},
			navigator_can_share: () => true,
			fetch:               vi.fn( async () => blob_response() ) as unknown as typeof fetch,
			navigate:            vi.fn(),
		};

		const result = await execute_ios_share( build_payload(), 42, env );
		expect( result.outcome ).toBe( 'fired' );
	} );
} );
