import type { DraftSaveState } from '../lib/drafts';

/**
 * One line under a mode's form that says what happened to the draft.
 *
 * Not a live region: "Draft saved" after every pause in typing would
 * interrupt a screen reader mid-sentence. The paragraph stays mounted and
 * only its text changes, so it can be read on demand.
 */
const MESSAGES: Record<DraftSaveState, string> = {
	idle: '',
	restored: 'Draft restored from this device. Nothing posts until you post it.',
	saved: 'Draft saved',
	unavailable: "This device can't keep drafts right now. Copy anything you want to keep.",
};

export function DraftStatus({ state }: { state: DraftSaveState }) {
	return <p class="outpost-help outpost-draft-status">{MESSAGES[state]}</p>;
}
