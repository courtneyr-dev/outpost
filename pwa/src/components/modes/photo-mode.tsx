import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	discover_media_endpoint,
	upload_media,
	post_h_entry,
	MicropubError,
	type MicropubEnvironment,
} from '../../lib/micropub';
import { process_photo, PhotoError } from '../../lib/photo';
import type { StoredToken } from '../../lib/token-store';
import type { ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { VoiceButton } from '../voice-button';
import {
	MorePanel,
	empty_more_values,
	merge_more_values,
	type MorePanelValues,
} from '../more-panel';

/**
 * Photo mode — pick a photo, type alt text, post.
 *
 * Pipeline:
 *   1. User picks a file via the system file picker (iOS Safari shows
 *      camera + photo library options). Accepted MIME types: JPEG, PNG,
 *      WebP, GIF, AVIF (SVG explicitly excluded).
 *   2. The picked image is loaded into a canvas, downscaled to fit
 *      2048 on the long edge, and re-encoded as JPEG at quality 0.9.
 *      Side effect: EXIF metadata (including GPS) is stripped — there's
 *      no path for it to survive the canvas round-trip.
 *   3. Discover the Micropub media endpoint via `?q=config` (cached in
 *      component state for the session, alongside the regular Micropub
 *      endpoint).
 *   4. Upload the processed blob to the media endpoint as
 *      multipart/form-data; the response Location header is the URL of
 *      the uploaded media.
 *   5. Post an h-entry with `photo` (the media URL), `mp-photo-alt` (the
 *      alt text — required by the Hard Contract; structural, not
 *      configurable), and optional `content` (caption).
 *
 * Required alt text: the Submit button stays disabled until the alt text
 * field has content. CLAUDE.md: "Required alt text on photos. Structural,
 * not configurable. Decorative toggle submits alt=''."
 *
 * (Decorative toggle defers to a future C2b — every photo posted today
 * must have a real alt-text description.)
 */

export interface PhotoModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'processing-photo' }
	| { kind: 'discovering-endpoints' }
	| { kind: 'uploading-photo' }
	| { kind: 'posting' }
	| { kind: 'posted'; location: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

export function PhotoMode({ token, micropubEnv, composerConfig }: PhotoModeProps) {
	const [file, setFile] = useState<File | null>(null);
	const [preview_url, setPreviewUrl] = useState<string | null>(null);
	const [alt, setAlt] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [micropub_endpoint, setMicropubEndpoint] = useState<string | null>(null);
	const [media_endpoint, setMediaEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());

	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';

	const handle_file_change = (event: Event): void => {
		const input = event.target as HTMLInputElement;
		const picked = input.files?.[0] ?? null;
		setFile(picked);
		if (preview_url) {
			URL.revokeObjectURL(preview_url);
		}
		if (picked) {
			setPreviewUrl(URL.createObjectURL(picked));
			// Clear any prior posted-state when a new file is picked.
			if (status.kind === 'posted' || status.kind === 'error') {
				setStatus({ kind: 'idle' });
			}
		} else {
			setPreviewUrl(null);
		}
	};

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_alt = alt.trim();
		const trimmed_content = content.trim();
		if (!file || !trimmed_alt) return;

		try {
			setStatus({ kind: 'processing-photo' });
			const processed = await process_photo(file);

			let mp = micropub_endpoint;
			let media = media_endpoint;
			if (!mp || !media) {
				setStatus({ kind: 'discovering-endpoints' });
				if (!mp) {
					mp = await discover_micropub_endpoint(token.me, micropubEnv);
					setMicropubEndpoint(mp);
				}
				if (!media) {
					media = await discover_media_endpoint(mp, token.accessToken, micropubEnv);
					setMediaEndpoint(media);
				}
			}

			setStatus({ kind: 'uploading-photo' });
			const upload = await upload_media(
				{
					blob: processed.blob,
					filename: 'photo.jpg',
					accessToken: token.accessToken,
					mediaEndpoint: media,
				},
				micropubEnv,
			);

			setStatus({ kind: 'posting' });
			const base = {
				photo: upload.location,
				'mp-photo-alt': trimmed_alt,
				...(trimmed_content ? { content: trimmed_content } : {}),
			};
			const properties = merge_more_values(base, more_values);
			try {
				const result = await post_h_entry(
					{
						properties,
						accessToken: token.accessToken,
						micropubEndpoint: mp,
					},
					micropubEnv,
				);
				setStatus({ kind: 'posted', location: result.location });
				mark_posted_once();
			} catch (post_err) {
				if (is_network_error(post_err)) {
					await enqueue({
						source: 'photo',
						properties,
						accessToken: token.accessToken,
						micropubEndpoint: mp,
					});
					setStatus({ kind: 'queued' });
				} else {
					throw post_err;
				}
			}

			// Reset form for next post.
			if (preview_url) URL.revokeObjectURL(preview_url);
			setFile(null);
			setPreviewUrl(null);
			setAlt('');
			setContent('');
			setMoreValues(empty_more_values());
		} catch (err) {
			let message: string;
			if (err instanceof PhotoError) {
				message = err.code + ': ' + err.message;
			} else if (err instanceof MicropubError) {
				message = err.code + ': ' + err.message;
			} else if (err instanceof Error) {
				message = err.message;
			} else {
				message = 'Unknown error';
			}
			setStatus({ kind: 'error', message });
		}
	};

	const submitting =
		status.kind === 'processing-photo' ||
		status.kind === 'discovering-endpoints' ||
		status.kind === 'uploading-photo' ||
		status.kind === 'posting';

	const submit_label =
		status.kind === 'processing-photo'
			? 'Preparing photo…'
			: status.kind === 'discovering-endpoints'
				? 'Finding endpoints…'
				: status.kind === 'uploading-photo'
					? 'Uploading…'
					: status.kind === 'posting'
						? 'Posting…'
						: 'Post photo';

	const can_submit = !!file && !!alt.trim() && !submitting;

	return (
		<section class="outpost-card" aria-labelledby="outpost-photo-mode-title">
			<h2 id="outpost-photo-mode-title" class="outpost-card__title">
				Photo
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit}>
				<label class="outpost-label" for="outpost-photo-file">
					Photo
				</label>
				<input
					id="outpost-photo-file"
					class="outpost-input"
					type="file"
					accept="image/jpeg,image/png,image/webp,image/gif,image/avif"
					onChange={handle_file_change}
					disabled={submitting}
				/>

				{preview_url && (
					<img
						class="outpost-photo-preview"
						src={preview_url}
						alt={alt || 'Selected photo (alt text not yet entered)'}
					/>
				)}

				<label class="outpost-label" for="outpost-photo-alt">
					Alt text <span class="outpost-required">(required)</span>
				</label>
				<textarea
					id="outpost-photo-alt"
					class="outpost-textarea"
					rows={3}
					value={alt}
					onInput={(event): void => setAlt((event.target as HTMLTextAreaElement).value)}
					placeholder="Describe what's in the photo for screen readers"
					disabled={submitting}
					required
				/>

				<div class="outpost-textarea-row">
					<label class="outpost-label" for="outpost-photo-content">
						Caption (optional)
					</label>
					<VoiceButton
						onTranscript={(text): void =>
							setContent((c) =>
								c.length > 0 && !/\s$/.test(c) ? c + ' ' + text : c + text,
							)
						}
						disabled={submitting}
					/>
				</div>
				<textarea
					id="outpost-photo-content"
					class="outpost-textarea"
					rows={3}
					value={content}
					onInput={(event): void => setContent((event.target as HTMLTextAreaElement).value)}
					disabled={submitting}
				/>

				{status.kind === 'error' && (
					<div class="outpost-error" role="alert">
						{status.message}
					</div>
				)}

				{status.kind === 'posted' && (
					<p class="outpost-status" aria-live="polite">
						Posted to{' '}
						<a href={status.location} target="_blank" rel="noopener noreferrer">
							{status.location}
						</a>
						{a11y_active && (
							<>
								{' · '}
								<a href={`${status.location}?edac_view=1`} target="_blank" rel="noopener noreferrer">
									View accessibility report
								</a>
							</>
						)}
					</p>
				)}

				{status.kind === 'queued' && (
					<p class="outpost-status" aria-live="polite">
						Saved for later. Outpost will post this when you&apos;re back online.
					</p>
				)}

				{composerConfig && (
					<MorePanel
						token={token}
						composerConfig={composerConfig}
						values={more_values}
						onChange={setMoreValues}
						micropubEndpoint={micropub_endpoint}
						{...(micropubEnv ? { micropubEnv } : {})}
						disabled={submitting}
						idPrefix="outpost-photo"
					/>
				)}

				<div class="outpost-form-actions">
					<button class="outpost-button" type="submit" disabled={!can_submit}>
						{submit_label}
					</button>
				</div>
			</form>
		</section>
	);
}
