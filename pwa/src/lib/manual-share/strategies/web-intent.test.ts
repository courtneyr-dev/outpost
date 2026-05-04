/**
 * Tests for try_web_intent — navigate to platform intent URL.
 */

import { describe, expect, it, vi } from 'vitest';
import { try_web_intent } from './web-intent';
import type { IosStrategyEnvironment } from './types';
import type { IntentPayloadIos } from '../types';

function build_payload( overrides: Partial<IntentPayloadIos> = {} ): IntentPayloadIos {
	return {
		platform:        'threads',
		platform_label:  'Threads',
		files:           [],
		caption:         'Hello',
		clipboard_text:  'Hello',
		ios_strategy:    [ 'web_intent' ],
		app_url_scheme:  null,
		web_intent_url:  'https://www.threads.net/intent/post?text=Hello',
		in_pwa_mode:     false,
		after_share:     'prompt_for_silo_url',
		audit_log_id:    'a1',
		source_url:      'https://blog.example/p/1',
		...overrides,
	};
}

describe( 'try_web_intent', () => {
	it( "returns 'rejected' when payload.web_intent_url is null", async () => {
		const env: IosStrategyEnvironment = { navigate: vi.fn() };
		const result = await try_web_intent(
			build_payload( { web_intent_url: null } ),
			env,
		);
		expect( result ).toBe( 'rejected' );
		expect( env.navigate ).not.toHaveBeenCalled();
	} );

	it( "navigates and returns 'fired'", async () => {
		const env: IosStrategyEnvironment = { navigate: vi.fn() };
		const result = await try_web_intent( build_payload(), env );

		expect( env.navigate ).toHaveBeenCalledWith( 'https://www.threads.net/intent/post?text=Hello' );
		expect( result ).toBe( 'fired' );
	} );

	it( "returns 'rejected' when navigate throws", async () => {
		const env: IosStrategyEnvironment = {
			navigate: () => {
				throw new Error( 'navigation blocked' );
			},
		};

		const result = await try_web_intent( build_payload(), env );
		expect( result ).toBe( 'rejected' );
	} );
} );
