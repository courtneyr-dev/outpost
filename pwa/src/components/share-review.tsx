import type { ShareTargetData } from '../lib/share-target';
import type { SharedPhotos, ShareInboxState } from '../lib/share-inbox';

/**
 * Review a share before it reaches a composer form (T10-S3-05).
 *
 * A share (system share sheet, bookmarklet, Shortcut, or a link carrying
 * share parameters) can come from any page, so the composer holds it here
 * until the person chooses "Use shared content" or "Discard shared content".
 * Nothing from it is saved as a draft, previewed, posted, or queued before
 * that choice. When two photo shares are waiting, the person picks which to
 * keep first.
 *
 * Shared text is rendered as text (Preact escapes it); nothing here fetches
 * or follows a shared link.
 */
export interface ShareReviewProps {
	/** The held intake from the share dispatch, if any. */
	share: ShareTargetData | null;
	/** Photo shares waiting in the share inbox. */
	inbox: ShareInboxState;
	/** Status for the region's own live region (for example, a refused share). */
	status: string;
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

function Summary({ share, photos }: { share: ShareTargetData | null; photos: SharedPhotos | null }) {
	const title = share?.title || photos?.title || '';
	const text = share?.content || photos?.text || '';
	const rows: Array<[string, string]> = [];
	if (title) rows.push(['Title', title]);
	if (text) rows.push(['Text', text]);
	if (share?.url) rows.push(['Link', share.url]);
	if (photos) rows.push(['Photos', photo_names(photos)]);
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
	share,
	inbox,
	status,
	onUse,
	onDiscard,
	onKeepEarlier,
	onUseNew,
	onDiscardBoth,
}: ShareReviewProps) {
	const conflict = inbox.pending !== null && inbox.candidate !== null;
	const heading_id = 'outpost-share-review-title';
	const target = share ? TAB_LABELS[share.tab] : 'Photo';

	return (
		<section
			class="outpost-card outpost-share-review"
			aria-labelledby={heading_id}
			data-outpost-share-review=""
		>
			{conflict ? (
				<>
					<h2 id={heading_id} class="outpost-card__title">
						Two shares are waiting
					</h2>
					<p>Choose which photo share to keep. Nothing is saved or posted until you use it.</p>
					<h3>Earlier share</h3>
					<Summary share={null} photos={inbox.pending} />
					<h3>New share</h3>
					<Summary share={null} photos={inbox.candidate} />
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
						Something was shared to the {target} form. Nothing from it is saved, previewed, or posted until
						you choose Use shared content.
					</p>
					<Summary share={share} photos={inbox.pending} />
					<div class="outpost-form-actions">
						<button class="outpost-button" type="button" onClick={onUse}>
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
