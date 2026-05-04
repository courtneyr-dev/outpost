/**
 * Shared types for the manual-share Android intent flow.
 *
 * Mirrors the server-side `Outpost_Manual_Share_Intent_Payload_Builder`
 * shape so the PHP and TypeScript layers stay in sync. Fields here
 * MUST match the keys returned by `build_for_android()` in
 * includes/companions/manual-share/class-intent-payload-builder.php.
 */

/** A single attached file the PWA fetches and passes to navigator.share. */
export interface SharedFile {
	url: string;
	alt: string;
	mime: string;
}

/** Strategies the PWA reports back via the audit-log telemetry endpoint. */
export type ShareStrategy = 'navigator_share' | 'intent_url' | 'two_tap_fallback';

/** Outcome codes the PWA reports for the audit log. */
export type ShareOutcome = 'fired' | 'rejected' | 'aborted' | 'unknown';

/** Server-decided after-share UX hint per platform. F12 will surface. */
export type AfterShareBehavior = 'mark_done' | 'prompt_for_silo_url' | 'silent';

/** Full intent payload returned from POST /manual-share/intent on Android. */
export interface IntentPayloadAndroid {
	platform: string;
	platform_label: string;
	files: SharedFile[];
	caption: string;
	clipboard_text: string;
	intent_strategy: 'navigator_share' | 'intent_url';
	fallback_url: string;
	after_share: AfterShareBehavior;
	audit_log_id: string;
	source_url: string;
}

/** F9 stub response shape (returned for iOS / desktop until F11). */
export interface IntentStubResponse {
	status: 'stub';
	message: string;
	platform_id: string;
	post_id: number;
}

/** Discriminated union of the two response shapes the controller returns. */
export type IntentResponse = IntentPayloadAndroid | IntentStubResponse;

/** Type guard: is this an Android intent payload (not a stub)? */
export function is_android_payload( resp: IntentResponse ): resp is IntentPayloadAndroid {
	return ( resp as IntentStubResponse ).status !== 'stub';
}

/** Outcome reported back to /manual-share/intent/log telemetry endpoint. */
export interface ShareTelemetry {
	post_id: number;
	audit_log_id: string;
	outcome: ShareOutcome;
}
