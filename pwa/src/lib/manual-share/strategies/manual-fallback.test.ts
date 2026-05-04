/**
 * Tests for try_manual_fallback — the always-succeed worst case.
 */

import { describe, expect, it, vi } from 'vitest';
import { try_manual_fallback } from './manual-fallback';
import type { IosStrategyEnvironment, ManualModalProps } from './types';
import type { IntentPayloadIos } from '../types';

function build_payload( overrides: Partial<IntentPayloadIos> = {} ): IntentPayloadIos {
	return {
		platform:        'instagram-feed',
		platform_label:  'Instagram',
		files:           [ { url: 'https://x.example/img.jpg', alt: 'photo', mime: 'image/jpeg' } ],
		caption:         'Hello',
		clipboard_text:  'Hello',
		ios_strategy:    [ 'manual' ],
		app_url_scheme:  null,
		web_intent_url:  null,
		in_pwa_mode:     false,
		after_share:     'prompt_for_silo_url',
		audit_log_id:    'a1',
		source_url:      'https://blog.example/p/1',
		...overrides,
	};
}

describe( 'try_manual_fallback', () => {
	it( "returns 'aborted' when env.show_manual_modal is missing", async () => {
		// No modal renderer means no UX path; runner should stop.
		const env: IosStrategyEnvironment = { navigate: vi.fn() };
		const result = await try_manual_fallback( build_payload(), env );
		expect( result ).toBe( 'aborted' );
	} );

	it( "passes correct props to show_manual_modal and returns its outcome", async () => {
		let captured: ManualModalProps | undefined;
		const env: IosStrategyEnvironment = {
			navigate: vi.fn(),
			show_manual_modal: async ( props ) => {
				captured = props;
				return 'fired';
			},
		};

		const result = await try_manual_fallback( build_payload(), env );

		expect( result ).toBe( 'fired' );
		expect( captured?.platform_label ).toBe( 'Instagram' );
		expect( captured?.platform_id ).toBe( 'instagram-feed' );
		expect( captured?.first_image_url ).toBe( 'https://x.example/img.jpg' );
		expect( captured?.app_homepage_url ).toBe( 'https://www.instagram.com' );
	} );

	it( "passes null first_image_url when payload has no files", async () => {
		let captured: ManualModalProps | undefined;
		const env: IosStrategyEnvironment = {
			navigate: vi.fn(),
			show_manual_modal: async ( props ) => {
				captured = props;
				return 'fired';
			},
		};

		await try_manual_fallback( build_payload( { files: [] } ), env );

		expect( captured?.first_image_url ).toBeNull();
	} );

	it( "passes null app_homepage_url for unknown platforms", async () => {
		let captured: ManualModalProps | undefined;
		const env: IosStrategyEnvironment = {
			navigate: vi.fn(),
			show_manual_modal: async ( props ) => {
				captured = props;
				return 'fired';
			},
		};

		await try_manual_fallback(
			build_payload( { platform: 'totally-fake-platform' } ),
			env,
		);

		expect( captured?.app_homepage_url ).toBeNull();
	} );

	it( "propagates 'aborted' from the modal", async () => {
		const env: IosStrategyEnvironment = {
			navigate: vi.fn(),
			show_manual_modal: async () => 'aborted',
		};

		const result = await try_manual_fallback( build_payload(), env );
		expect( result ).toBe( 'aborted' );
	} );
} );
