/**
 * Pending-syndication reminder for the block editor.
 *
 * The PHP side hooks `admin_notices`, but WordPress prints that output
 * inside `<div class="wrap hide-if-js block-editor-no-js">` — the no-JS
 * fallback container, hidden whenever the editor loads. So the reminder
 * only ever reached classic-editor users. `Outpost_Pending_Syndication_Notice`
 * attaches the same data to this bundle as `window.outpostPendingSyndication`,
 * and this module turns it into a real `core/notices` notice.
 *
 * Strings arrive pre-localized from PHP (platform labels, plural message,
 * relative times) so this module doesn't reimplement the label map.
 *
 * @file
 */

import { dispatch } from '@wordpress/data';

/** Store name, not an import — avoids a hard @wordpress/notices dependency. */
const NOTICES_STORE = 'core/notices';

/** Stable id so re-renders replace rather than stack notices. */
export const PENDING_SYNDICATION_NOTICE_ID = 'outpost-pending-syndication';

/**
 * Is this a payload worth rendering?
 *
 * @param {unknown} payload Value read off the global scope.
 * @return {boolean} True when the payload has at least one platform.
 */
export function isRenderablePayload( payload ) {
	return (
		!! payload &&
		typeof payload === 'object' &&
		typeof payload.message === 'string' &&
		payload.message !== '' &&
		Array.isArray( payload.platforms ) &&
		payload.platforms.length > 0
	);
}

/**
 * Flatten the payload into the notice body.
 *
 * @param {Object} payload Payload from PHP.
 * @return {string} Notice content.
 */
export function buildNoticeContent( payload ) {
	const list = payload.platforms
		.map( ( platform ) =>
			platform.firedHuman
				? `${ platform.label } — ${ platform.firedHuman }`
				: platform.label
		)
		.join( ', ' );

	return list ? `${ payload.message } ${ list }` : payload.message;
}

/**
 * Read the payload and raise the notice.
 *
 * @param {Object}   [env]          Injected environment (tests pass stubs
 *                                  rather than touching globals).
 * @param {Object}   [env.scope]    Global object carrying the payload.
 * @param {Function} [env.dispatch] `@wordpress/data` dispatch.
 * @return {boolean} True when a notice was created.
 */
export function registerPendingSyndicationNotice( env = {} ) {
	const scope = env.scope ?? ( typeof window === 'undefined' ? {} : window );
	const dispatchStore = env.dispatch ?? dispatch;

	const payload = scope.outpostPendingSyndication;
	if ( ! isRenderablePayload( payload ) ) {
		return false;
	}

	const actions =
		typeof payload.composerUrl === 'string' && payload.composerUrl !== ''
			? [
					{
						label: payload.actionLabel,
						url: payload.composerUrl,
					},
			  ]
			: [];

	dispatchStore( NOTICES_STORE ).createNotice(
		'warning',
		buildNoticeContent( payload ),
		{
			id: PENDING_SYNDICATION_NOTICE_ID,
			isDismissible: true,
			actions,
		}
	);

	return true;
}
