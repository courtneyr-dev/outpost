import { missing_fields_hint } from '../lib/publish-hint';

/**
 * The publish action at the end of every composing form: a full-width
 * primary button with the variant's own label, and above it a one-line
 * hint naming the required fields that are still empty.
 *
 * The hint paragraph stays mounted while empty so its id never dangles;
 * the button points at it only when it has something to say. It isn't a
 * live region: it changes as people type, and announcing every change
 * would talk over them. It's visible text directly above the button and
 * the button's description.
 */
export interface PublishButtonProps {
	idPrefix: string;
	label: string;
	disabled: boolean;
	/** Visible names of the empty required fields, in form order. */
	missing: readonly string[];
	/** True while a submission runs; the hint is hidden then. */
	busy: boolean;
}

export function PublishButton({
	idPrefix,
	label,
	disabled,
	missing,
	busy,
}: PublishButtonProps): preact.JSX.Element {
	const hint_id = `${idPrefix}-publish-hint`;
	const hint = busy ? '' : missing_fields_hint(missing);
	return (
		<div class="outpost-publish">
			<p
				id={hint_id}
				class={`outpost-publish__hint${hint ? '' : ' outpost-publish__hint--empty'}`}
			>
				{hint}
			</p>
			<button
				class="outpost-button outpost-button--publish"
				type="submit"
				disabled={disabled}
				{...(hint ? { 'aria-describedby': hint_id } : {})}
			>
				{label}
			</button>
		</div>
	);
}
