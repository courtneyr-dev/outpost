/**
 * Strategy runner — walks the iOS strategy chain.
 *
 * Each strategy returns 'fired' / 'rejected' / 'aborted'. The runner:
 *
 *   - 'fired'   → stop, return result with the strategy that succeeded
 *   - 'aborted' → stop, return result (user explicitly cancelled — do
 *                 NOT fall through to next strategy)
 *   - 'rejected' → continue to next strategy
 *
 * If the chain exhausts without firing or aborting, the runner reports
 * outcome='aborted' with strategy='manual' as a defensive default. In
 * practice every default chain ends with 'manual' which always returns
 * 'fired' or 'aborted' — the chain-exhausted case shouldn't fire in
 * production.
 */

import { try_navigator_share_files } from './strategies/navigator-share-files';
import { try_app_url_scheme } from './strategies/app-url-scheme';
import { try_web_intent } from './strategies/web-intent';
import { try_manual_fallback } from './strategies/manual-fallback';
import type {
	IosStrategyEnvironment,
	IosStrategyFn,
	StrategyOutcome,
} from './strategies/types';
import type { IntentPayloadIos, IosStrategyKind } from './types';

export interface StrategyRunResult {
	outcome: StrategyOutcome;
	strategy: IosStrategyKind;
}

/** Default dispatch table — strategy kind → handler function. */
export const DEFAULT_DISPATCH: Record<IosStrategyKind, IosStrategyFn> = {
	navigator_share_files: try_navigator_share_files,
	app_url_scheme:        try_app_url_scheme,
	web_intent:            try_web_intent,
	manual:                try_manual_fallback,
};

/**
 * Walk the chain in order, return the first non-rejected result.
 *
 * `dispatch` is injectable so tests can substitute synthetic strategy
 * functions per-kind without monkey-patching imports.
 */
export async function run_strategy_chain(
	chain: ReadonlyArray<IosStrategyKind>,
	payload: IntentPayloadIos,
	env: IosStrategyEnvironment,
	dispatch: Record<IosStrategyKind, IosStrategyFn> = DEFAULT_DISPATCH,
): Promise<StrategyRunResult> {
	for ( const strategy of chain ) {
		const handler = dispatch[ strategy ];
		if ( ! handler ) {
			continue;
		}
		const outcome = await handler( payload, env );
		if ( outcome !== 'rejected' ) {
			return { outcome, strategy };
		}
	}
	// Chain exhausted — defensive default. Default platform configs
	// always include 'manual' as the last entry, so this branch
	// fires only when a malformed chain is provided.
	return { outcome: 'aborted', strategy: 'manual' };
}
