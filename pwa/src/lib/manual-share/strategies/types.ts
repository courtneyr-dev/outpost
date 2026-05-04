/**
 * Shared types for the iOS strategy chain.
 *
 * Each strategy is a function (payload, env) → Promise<StrategyOutcome>.
 * The runner walks the chain and stops at the first 'fired' or 'aborted'.
 */

import type { IntentPayloadIos } from '../types';

export type StrategyOutcome = 'fired' | 'rejected' | 'aborted';

/** Manual-fallback modal callback shape. */
export interface ManualModalProps {
	platform_label: string;
	platform_id: string;
	caption: string;
	clipboard_text: string;
	first_image_url: string | null;
	app_homepage_url: string | null;
}

export interface IosStrategyEnvironment {
	/** `navigator.share` — only set when the API is available AND in PWA mode. */
	navigator_share?: ( data: ShareData ) => Promise<void>;
	/** `navigator.canShare` — used to verify files-capability before share. */
	navigator_can_share?: ( data: ShareData ) => boolean;
	/** Fetch URLs into Blobs for navigator.share files payload. */
	fetch?: typeof fetch;
	/** Hard navigation primitive (window.location.href = ...). */
	navigate: ( url: string ) => void;
	/**
	 * Subscribe to visibility-change events. Returns an unsubscribe fn.
	 * Used by the app-url-scheme strategy's success heuristic.
	 */
	add_visibility_listener?: ( cb: () => void ) => () => void;
	/**
	 * window.setTimeout abstraction so tests can synchronously fire the
	 * timeout. Returns an opaque handle.
	 */
	set_timeout?: ( cb: () => void, ms: number ) => unknown;
	/** Clear a timeout handle. */
	clear_timeout?: ( handle: unknown ) => void;
	/**
	 * Render the manual-fallback modal, return a Promise that resolves
	 * when the user dismisses (Done/Save/Open actions). Tests stub.
	 */
	show_manual_modal?: ( props: ManualModalProps ) => Promise<StrategyOutcome>;
}

export type IosStrategyFn = (
	payload: IntentPayloadIos,
	env: IosStrategyEnvironment,
) => Promise<StrategyOutcome>;
