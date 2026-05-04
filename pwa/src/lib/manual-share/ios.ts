/**
 * iOS share handler — orchestrates the strategy chain.
 *
 * Mirrors {@link execute_android_share} (F10): clipboard always writes
 * first, then the strategy chain runs, then telemetry posts the actual
 * outcome to the audit-log endpoint.
 *
 * iOS-specific decisions (CLAUDE.md F11):
 *
 *   - Skip `navigator_share_files` from the chain when not running as
 *     an installed PWA. iOS Safari 16.4+ supports the API only inside
 *     installed PWAs; calling it in a regular tab silently drops the
 *     `files` parameter (degrading to caption-only share).
 *   - Strategy outcomes 'fired'/'rejected'/'aborted' map directly to
 *     the audit-log labels. The runner picks the first non-rejected
 *     strategy; the handler reports that strategy via telemetry.
 */

import { run_strategy_chain, type StrategyRunResult } from './strategy-runner';
import type { IntentPayloadIos, IosStrategyKind, ShareTelemetry } from './types';
import type { IosStrategyEnvironment } from './strategies/types';

export interface IosShareEnvironment extends IosStrategyEnvironment {
	clipboard_write_text?: ( text: string ) => Promise<void>;
	post_telemetry?: ( telemetry: ShareTelemetry ) => Promise<void>;
}

export interface IosShareResult {
	post_id: number;
	audit_log_id: string;
	strategy: IosStrategyKind;
	outcome: StrategyRunResult['outcome'];
	error?: string;
}

/**
 * Execute the iOS share flow. Always returns a result describing what
 * happened — never throws.
 */
export async function execute_ios_share(
	payload: IntentPayloadIos,
	post_id: number,
	env: IosShareEnvironment,
): Promise<IosShareResult> {
	// Layer 1 — clipboard. Universal, fires regardless of strategy.
	try {
		if ( env.clipboard_write_text ) {
			await env.clipboard_write_text( payload.clipboard_text );
		}
	} catch {
		// Clipboard failure is not fatal — continue with the strategy.
	}

	const effective_chain = filter_chain_by_capabilities( payload, env );

	const run_result = await run_strategy_chain( effective_chain, payload, env );

	const result: IosShareResult = {
		post_id,
		audit_log_id: payload.audit_log_id,
		strategy:     run_result.strategy,
		outcome:      run_result.outcome,
	};

	if ( env.post_telemetry ) {
		try {
			await env.post_telemetry( {
				post_id,
				audit_log_id: payload.audit_log_id,
				outcome:      run_result.outcome === 'fired' ? 'fired' : run_result.outcome,
			} );
		} catch {
			// Telemetry failures are not fatal.
		}
	}

	return result;
}

/**
 * Strip `navigator_share_files` from the chain when:
 *
 *   - The PWA is not installed (in_pwa_mode === false), OR
 *   - The env has no navigator_share function at all.
 *
 * Skipping this strategy in non-PWA contexts saves the cost of
 * fetching files into Blobs only to discover the share API drops them.
 */
function filter_chain_by_capabilities(
	payload: IntentPayloadIos,
	env: IosShareEnvironment,
): IosStrategyKind[] {
	const has_native_share = !! env.navigator_share;
	const should_attempt_native = payload.in_pwa_mode && has_native_share;

	return payload.ios_strategy.filter( ( strategy ) => {
		if ( strategy === 'navigator_share_files' && ! should_attempt_native ) {
			return false;
		}
		return true;
	} );
}
