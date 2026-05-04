/**
 * Tests for try_app_url_scheme — visibility-change heuristic.
 */

import { describe, expect, it, vi } from 'vitest';
import {
	try_app_url_scheme,
	race_visibility_against_timeout,
	APP_URL_SCHEME_TIMEOUT_MS,
} from './app-url-scheme';
import type { IosStrategyEnvironment } from './types';
import type { IntentPayloadIos } from '../types';

function build_payload( overrides: Partial<IntentPayloadIos> = {} ): IntentPayloadIos {
	return {
		platform:        'instagram-feed',
		platform_label:  'Instagram',
		files:           [],
		caption:         'Hello',
		clipboard_text:  'Hello',
		ios_strategy:    [ 'app_url_scheme' ],
		app_url_scheme:  'instagram://library?AssetPath=',
		web_intent_url:  null,
		in_pwa_mode:     true,
		after_share:     'prompt_for_silo_url',
		audit_log_id:    'a1',
		source_url:      'https://blog.example/p/1',
		...overrides,
	};
}

describe( 'try_app_url_scheme', () => {
	it( "returns 'rejected' when payload.app_url_scheme is null", async () => {
		const env: IosStrategyEnvironment = { navigate: vi.fn() };
		const result = await try_app_url_scheme(
			build_payload( { app_url_scheme: null } ),
			env,
		);
		expect( result ).toBe( 'rejected' );
		expect( env.navigate ).not.toHaveBeenCalled();
	} );

	it( "returns 'fired' when visibility-change fires before timeout", async () => {
		const navigate_calls: string[] = [];
		let visibility_callback: ( () => void ) | undefined;
		const env: IosStrategyEnvironment = {
			navigate: ( url ) => {
				navigate_calls.push( url );
				// Simulate the app launching → visibility flips immediately.
				visibility_callback?.();
			},
			add_visibility_listener: ( cb ) => {
				visibility_callback = cb;
				return () => {
					visibility_callback = undefined;
				};
			},
			set_timeout: ( cb, ms ) => globalThis.setTimeout( cb, ms ),
			clear_timeout: ( h ) => globalThis.clearTimeout( h as number ),
		};

		const result = await try_app_url_scheme( build_payload(), env );

		expect( navigate_calls ).toEqual( [ 'instagram://library?AssetPath=' ] );
		expect( result ).toBe( 'fired' );
	} );

	it( "returns 'rejected' when timeout fires before visibility", async () => {
		// Use fake timers to deterministically fire timeout without
		// any visibility event.
		let timer_callback: ( () => void ) | undefined;
		const env: IosStrategyEnvironment = {
			navigate: vi.fn(),
			add_visibility_listener: () => () => {},
			set_timeout: ( cb ) => {
				timer_callback = cb;
				return 'handle';
			},
			clear_timeout: vi.fn(),
		};

		const promise = try_app_url_scheme( build_payload(), env );
		// Manually fire the timeout.
		timer_callback?.();
		const result = await promise;

		expect( result ).toBe( 'rejected' );
	} );

	it( 'invokes navigate with the app_url_scheme URL', async () => {
		const env: IosStrategyEnvironment = {
			navigate: vi.fn( () => {
				// Fire visibility immediately to settle the promise.
				visibility_callback?.();
			} ),
			add_visibility_listener: ( cb ) => {
				visibility_callback = cb;
				return () => {};
			},
			set_timeout: ( cb, ms ) => globalThis.setTimeout( cb, ms ),
			clear_timeout: ( h ) => globalThis.clearTimeout( h as number ),
		};
		let visibility_callback: ( () => void ) | undefined;

		await try_app_url_scheme(
			build_payload( { app_url_scheme: 'tiktok://share' } ),
			env,
		);

		expect( env.navigate ).toHaveBeenCalledWith( 'tiktok://share' );
	} );

	it( 'exports a conservative 1500ms default timeout', () => {
		expect( APP_URL_SCHEME_TIMEOUT_MS ).toBe( 1500 );
	} );

	it( 'race_visibility_against_timeout settles only once', async () => {
		// Visibility fires first, then timeout fires — second fire is a no-op.
		let timer_callback: ( () => void ) | undefined;
		let visibility_callback: ( () => void ) | undefined;
		const env: IosStrategyEnvironment = {
			navigate: () => {
				visibility_callback?.();
			},
			add_visibility_listener: ( cb ) => {
				visibility_callback = cb;
				return () => {};
			},
			set_timeout: ( cb ) => {
				timer_callback = cb;
				return 'handle';
			},
			clear_timeout: vi.fn(),
		};

		const result = await race_visibility_against_timeout( 'inst://', env, 1500 );
		// Fire timer after settle — should be ignored.
		timer_callback?.();

		expect( result ).toBe( 'fired' );
	} );
} );
