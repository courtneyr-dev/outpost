/**
 * Manual-fallback modal — the iOS worst-case UX.
 *
 * Rendered when the iOS strategy chain exhausts to `manual` (every
 * default chain terminates here). Tells the user "image and caption
 * ready — open [Platform] manually" with three actions:
 *
 *   - Save Image: triggers iOS native save UI via `on_save_image`
 *   - Open App: navigates to the platform's homepage URL
 *   - Done: dismisses the modal
 *
 * Pure presentational component — all behavior comes through props.
 * The strategy module wires these to real handlers; tests render with
 * synthetic handlers and assert click behavior.
 *
 * Hard Contract: zero color values. Layout-only structural CSS via the
 * existing `.outpost-*` token classes.
 */

import { type FunctionComponent } from 'preact';
import type { ManualModalProps, StrategyOutcome } from '../lib/manual-share/strategies/types';

export interface ManualShareFallbackModalProps extends ManualModalProps {
	on_dismiss: ( outcome: StrategyOutcome ) => void;
}

export const ManualShareFallbackModal: FunctionComponent<ManualShareFallbackModalProps> = ( {
	platform_label,
	first_image_url,
	app_homepage_url,
	on_dismiss,
} ) => {
	return (
		<div
			class="outpost-manual-share-modal"
			role="dialog"
			aria-modal="true"
			aria-labelledby="outpost-manual-share-title"
		>
			<div class="outpost-manual-share-modal__inner">
				<h2 id="outpost-manual-share-title" class="outpost-manual-share-modal__title">
					{ /* translators: %s: platform label (e.g. "Instagram"). */ }
					Share to { platform_label } manually
				</h2>
				<p class="outpost-manual-share-modal__hint">
					Your caption is on the clipboard. Open { platform_label } and paste it.
				</p>

				<div class="outpost-manual-share-modal__actions">
					{ first_image_url !== null && (
						<button
							type="button"
							class="outpost-manual-share-modal__action outpost-manual-share-modal__action--save"
							data-action="save-image"
							onClick={ () => on_dismiss( 'fired' ) }
						>
							Save image
						</button>
					) }
					{ app_homepage_url !== null && (
						<a
							class="outpost-manual-share-modal__action outpost-manual-share-modal__action--open"
							data-action="open-app"
							href={ app_homepage_url }
							target="_blank"
							rel="noopener noreferrer"
							onClick={ () => on_dismiss( 'fired' ) }
						>
							Open { platform_label }
						</a>
					) }
					<button
						type="button"
						class="outpost-manual-share-modal__action outpost-manual-share-modal__action--done"
						data-action="done"
						onClick={ () => on_dismiss( 'fired' ) }
					>
						Done
					</button>
				</div>

				<button
					type="button"
					class="outpost-manual-share-modal__close"
					data-action="close"
					aria-label="Cancel"
					onClick={ () => on_dismiss( 'aborted' ) }
				>
					×
				</button>
			</div>
		</div>
	);
};
