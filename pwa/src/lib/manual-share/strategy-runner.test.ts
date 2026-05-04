/**
 * Tests for the strategy runner — chain walk + termination semantics.
 */

import { describe, expect, it, vi } from 'vitest';
import { run_strategy_chain } from './strategy-runner';
import type { IosStrategyEnvironment, IosStrategyFn } from './strategies/types';
import type { IntentPayloadIos, IosStrategyKind } from './types';

function build_payload(): IntentPayloadIos {
	return {
		platform:        'instagram-feed',
		platform_label:  'Instagram',
		files:           [],
		caption:         'Hello',
		clipboard_text:  'Hello',
		ios_strategy:    [ 'navigator_share_files', 'app_url_scheme', 'manual' ],
		app_url_scheme:  null,
		web_intent_url:  null,
		in_pwa_mode:     false,
		after_share:     'prompt_for_silo_url',
		audit_log_id:    'a1',
		source_url:      'https://blog.example/p/1',
	};
}

const env: IosStrategyEnvironment = { navigate: vi.fn() };

describe( 'run_strategy_chain', () => {
	it( "stops at the first 'fired' strategy", async () => {
		const calls: IosStrategyKind[] = [];
		const dispatch: Record<IosStrategyKind, IosStrategyFn> = {
			navigator_share_files: async () => {
				calls.push( 'navigator_share_files' );
				return 'fired';
			},
			app_url_scheme: async () => {
				calls.push( 'app_url_scheme' );
				return 'fired';
			},
			web_intent: async () => 'fired',
			manual: async () => 'fired',
		};

		const result = await run_strategy_chain(
			[ 'navigator_share_files', 'app_url_scheme', 'manual' ],
			build_payload(),
			env,
			dispatch,
		);

		expect( result.outcome ).toBe( 'fired' );
		expect( result.strategy ).toBe( 'navigator_share_files' );
		expect( calls ).toEqual( [ 'navigator_share_files' ] );
	} );

	it( "falls through 'rejected' to the next strategy", async () => {
		const calls: IosStrategyKind[] = [];
		const dispatch: Record<IosStrategyKind, IosStrategyFn> = {
			navigator_share_files: async () => {
				calls.push( 'navigator_share_files' );
				return 'rejected';
			},
			app_url_scheme: async () => {
				calls.push( 'app_url_scheme' );
				return 'fired';
			},
			web_intent: async () => 'fired',
			manual: async () => 'fired',
		};

		const result = await run_strategy_chain(
			[ 'navigator_share_files', 'app_url_scheme', 'manual' ],
			build_payload(),
			env,
			dispatch,
		);

		expect( result.outcome ).toBe( 'fired' );
		expect( result.strategy ).toBe( 'app_url_scheme' );
		expect( calls ).toEqual( [ 'navigator_share_files', 'app_url_scheme' ] );
	} );

	it( "stops at 'aborted' WITHOUT falling through", async () => {
		// User cancelled — runner must respect that and not try the
		// next strategy. Otherwise tapping cancel on the share sheet
		// would chain into the URL scheme launch.
		const calls: IosStrategyKind[] = [];
		const dispatch: Record<IosStrategyKind, IosStrategyFn> = {
			navigator_share_files: async () => {
				calls.push( 'navigator_share_files' );
				return 'aborted';
			},
			app_url_scheme: async () => {
				calls.push( 'app_url_scheme' );
				return 'fired';
			},
			web_intent: async () => 'fired',
			manual: async () => 'fired',
		};

		const result = await run_strategy_chain(
			[ 'navigator_share_files', 'app_url_scheme', 'manual' ],
			build_payload(),
			env,
			dispatch,
		);

		expect( result.outcome ).toBe( 'aborted' );
		expect( result.strategy ).toBe( 'navigator_share_files' );
		expect( calls ).toEqual( [ 'navigator_share_files' ] );
	} );

	it( 'walks the entire chain when all strategies reject', async () => {
		const calls: IosStrategyKind[] = [];
		const dispatch: Record<IosStrategyKind, IosStrategyFn> = {
			navigator_share_files: async () => {
				calls.push( 'navigator_share_files' );
				return 'rejected';
			},
			app_url_scheme: async () => {
				calls.push( 'app_url_scheme' );
				return 'rejected';
			},
			web_intent: async () => {
				calls.push( 'web_intent' );
				return 'rejected';
			},
			manual: async () => {
				calls.push( 'manual' );
				return 'fired';
			},
		};

		const result = await run_strategy_chain(
			[ 'navigator_share_files', 'app_url_scheme', 'web_intent', 'manual' ],
			build_payload(),
			env,
			dispatch,
		);

		expect( result.outcome ).toBe( 'fired' );
		expect( result.strategy ).toBe( 'manual' );
		expect( calls ).toEqual( [
			'navigator_share_files',
			'app_url_scheme',
			'web_intent',
			'manual',
		] );
	} );

	it( "returns defensive 'aborted' when chain exhausts without firing", async () => {
		const dispatch: Record<IosStrategyKind, IosStrategyFn> = {
			navigator_share_files: async () => 'rejected',
			app_url_scheme:        async () => 'rejected',
			web_intent:            async () => 'rejected',
			manual:                async () => 'rejected',
		};

		const result = await run_strategy_chain(
			[ 'navigator_share_files', 'manual' ],
			build_payload(),
			env,
			dispatch,
		);

		expect( result.outcome ).toBe( 'aborted' );
		expect( result.strategy ).toBe( 'manual' );
	} );
} );
