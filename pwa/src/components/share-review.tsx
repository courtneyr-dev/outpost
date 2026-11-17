import type { ShareTargetData } from '../lib/share-target';
import type { SharedPhotos } from '../lib/share-inbox';

/**
 * Review a share before it reaches a composer form (T10-S3-05).
 *
 * A share (system share sheet, bookmarklet, Shortcut, or a link carrying
 * share parameters) can come from any page, so the composer holds it here
 * until the person chooses "Use shared content" or "Discard shared content".
 * Nothing from it is saved as a draft, previewed, posted, or queued before
 * that choice. When two shares are waiting (text, link, or photo, in any
 * mix), the person picks which to keep first. While this region is open it
 * is the only thing on the screen a person can act on.
 *
 * Shared text is rendered as text (Preact escapes it); nothing here fetches
 * or follows a shared link.
 */
/** One share waiting for a decision; photo bytes come from the share inbox. */
export interface WaitingShare {
	share: ShareTargetData;
	photos: SharedPhotos | null;
}

export interface ShareReviewProps {
	/** Waiting shares, earliest first: one for a review, two for a conflict. */
	shares: WaitingShare[];
	/** Status for the region's own live region (for example, a refused share). */
	status: string;
	/** The "Use shared content" button, so focus can move there after a conflict choice. */
	useButtonRef?: { current: HTMLButtonElement | null };
	onUse: () => void;
	onDiscard: () => void;
	onKeepEarlier: () => void;
	onUseNew: () => void;
	onDiscardBoth: () => void;
}

const TAB_LABELS: Record<ShareTargetData['tab'], string> = {
	note: 'Post',
	reply: 'Reply',
	photo: 'Photo',
	listen: 'Doing',
	life: 'Life',
	recipe: 'Recipe',
};

function photo_names(photos: SharedPhotos): string {
	const names = photos.files.map((f) => f.name);
	const count = names.length === 1 ? '1 photo' : `${String(names.length)} photos`;
	return `${count}: ${names.join(', ')}`;
}

function Summary({ share, photos }: WaitingShare) {
	const title = share.title || photos?.title || '';
	const text = share.content || photos?.text || '';
	const rows: Array<[string, string]> = [];
	if (title) rows.push(['Title', title]);
	if (text) rows.push(['Text', text]);
	if (share.url) rows.push(['Link', share.url]);
	if (photos) rows.push(['Photos', photo_names(photos)]);
	else if (share.sharedPhotos) rows.push(['Photos', 'Shared photos on this device']);
	if (rows.length === 0) return <p>This share carried no text, link, or photo.</p>;
	return (
		<dl class="outpost-share-review__summary">
			{rows.map(([label, value]) => (
				<div key={label}>
					<dt>{label}</dt>
					<dd>{value}</dd>
				</div>
			))}
		</dl>
	);
}

export function ShareReview({
	shares,
	status,
	useButtonRef,
	onUse,
	onDiscard,
	onKeepEarlier,
	onUseNew,
	onDiscardBoth,
}: ShareReviewProps) {
	const [earlier, candidate] = shares;
	const heading_id = 'outpost-share-review-title';
	if (!earlier) return null;

	return (
		<section
			class="outpost-card outpost-share-review"
			aria-labelledby={heading_id}
			data-outpost-share-review=""
		>
			{candidate ? (
				<>
					<h2 id={heading_id} class="outpost-card__title">
						Two shares are waiting
					</h2>
					<p>Choose which share to keep. Nothing is saved or posted until you use it.</p>
					<h3>Earlier share, for the {TAB_LABELS[earlier.share.tab]} form</h3>
					<Summary {...earlier} />
					<h3>New share, for the {TAB_LABELS[candidate.share.tab]} form</h3>
					<Summary {...candidate} />
					<div class="outpost-form-actions">
						<button class="outpost-button outpost-button--secondary" type="button" onClick={onKeepEarlier}>
							Keep earlier share
						</button>
						<button class="outpost-button outpost-button--secondary" type="button" onClick={onUseNew}>
							Use new share
						</button>
						<button class="outpost-button outpost-button--secondary" type="button" onClick={onDiscardBoth}>
							Discard both
						</button>
					</div>
				</>
			) : (
				<>
					<h2 id={heading_id} class="outpost-card__title">
						Shared content
					</h2>
					<p>
						Something was shared to the {TAB_LABELS[earlier.share.tab]} form. Nothing from it is saved,
						previewed, or posted until you choose Use shared content.
					</p>
					<Summary {...earlier} />
					<div class="outpost-form-actions">
						<button
							{...(useButtonRef ? { ref: useButtonRef } : {})}
							class="outpost-button"
							type="button"
							onClick={onUse}
						>
							Use shared content
						</button>
						<button class="outpost-button outpost-button--secondary" type="button" onClick={onDiscard}>
							Discard shared content
						</button>
					</div>
				</>
			)}
			<p class="outpost-status" role="status" aria-live="polite">
				{status}
			</p>
		</section>
	);
}
