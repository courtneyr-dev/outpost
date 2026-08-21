import { useEffect, useRef, useState } from 'preact/hooks';
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
import { pkiw_kind_hint, type ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { useMoreOpen } from '../../lib/composer-prefs';
import { VoiceButton } from '../voice-button';
import { GeocodePicker } from '../geocode-picker';
import { geo_uri, type GeocodeResult } from '../../lib/geocode';
import { Drawer } from '../drawer';
import {
	MorePanel,
	empty_more_values,
	merge_more_values,
	type MorePanelValues,
} from '../more-panel';

/**
 * Photo mode — single photo or multi-photo gallery.
 *
 * Phase C2b extends C2's single-photo flow to galleries:
 *
 *   1. User picks one or many files via `<input type="file" multiple>`.
 *      Each entry gets a thumbnail, alt-text input, decorative toggle,
 *      remove button, and reorder controls.
 *   2. On submit, every entry runs through process_photo (canvas
 *      downscale + EXIF strip + JPEG re-encode) in sequence.
 *   3. Each processed blob uploads to the Micropub media endpoint;
 *      the resulting URL is collected.
 *   4. A single h-entry posts with `photo[]` (array of media URLs in
 *      gallery order) + `mp-photo-alt[]` (parallel alt-text array).
 *      Single-photo posts still send `photo` and `mp-photo-alt` as
 *      strings for backward compatibility with sites that don't yet
 *      handle the array shape.
 *
 * Auto-format mapping (Phase C5 bridge): 1 photo → image, 2+ →
 * gallery. Same auto-inference; same Post Format taxonomy.
 *
 * Required alt text remains structural (Hard Contract): each entry's
 * Decorative toggle, when checked, submits alt="" for that entry.
 * Otherwise the entry's Submit gates on a non-empty alt string.
 *
 * Reorder: per-entry Move up / Move down buttons (drag-and-drop on
 * mobile is finicky; explicit buttons work everywhere). The buttons
 * disable at array boundaries.
 */

export interface PhotoModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

interface PhotoEntry {
	id: string;
	file: File;
	preview_url: string;
	alt: string;
	decorative: boolean;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'processing-photo' }
	| { kind: 'discovering-endpoints' }
	| { kind: 'uploading-photo'; current: number; total: number }
	| { kind: 'posting' }
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

let next_id = 1;
const make_id = (): string => `photo-${String(next_id++)}`;

export function PhotoMode({ token, micropubEnv, composerConfig }: PhotoModeProps) {
	const [entries, setEntries] = useState<PhotoEntry[]>([]);
	const [name, setName] = useState('');
	const [content, setContent] = useState('');
	const [picked_location, setPickedLocation] = useState<GeocodeResult | null>(null);
	const [venue_name, setVenueName] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [micropub_endpoint, setMicropubEndpoint] = useState<string | null>(null);
	const [media_endpoint, setMediaEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());
	const [more_open, setMoreOpen] = useMoreOpen();

	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';

	// Revoke blob URLs on unmount. The previous cleanup captured `entries`
	// at mount (empty array) due to the empty deps array, so unmount
	// cleanup did nothing — ~4 MB of File-backed blob URL leaked per
	// picked photo until the page was unloaded. Tracking entries via a
	// ref lets the cleanup see the current list at unmount time without
	// re-running the effect on every keystroke.
	const entries_ref = useRef(entries);
	entries_ref.current = entries;
	useEffect(() => {
		return (): void => {
			for (const entry of entries_ref.current) {
				URL.revokeObjectURL(entry.preview_url);
			}
		};
	}, []);

	const handle_file_change = (event: Event): void => {
		const input = event.target as HTMLInputElement;
		const picked = input.files;
		if (!picked || picked.length === 0) return;
		const new_entries: PhotoEntry[] = [];
		for (const file of Array.from(picked)) {
			new_entries.push({
				id: make_id(),
				file,
				preview_url: URL.createObjectURL(file),
				alt: '',
				decorative: false,
			});
		}
		setEntries((prev) => [...prev, ...new_entries]);
		// Clear the file input so the user can pick the same file again
		// after removing it (browsers cache the previous selection
		// otherwise — picking the same filename does nothing).
		input.value = '';
		if (status.kind === 'posted' || status.kind === 'error' || status.kind === 'queued') {
			setStatus({ kind: 'idle' });
		}
	};

	const remove_entry = (id: string): void => {
		setEntries((prev) => {
			const target = prev.find((e) => e.id === id);
			if (target) URL.revokeObjectURL(target.preview_url);
			return prev.filter((e) => e.id !== id);
		});
	};

	const update_entry = (id: string, patch: Partial<PhotoEntry>): void => {
		setEntries((prev) =>
			prev.map((entry) => (entry.id === id ? { ...entry, ...patch } : entry)),
		);
	};

	const move_entry = (id: string, direction: -1 | 1): void => {
		setEntries((prev) => {
			const idx = prev.findIndex((e) => e.id === id);
			const target_idx = idx + direction;
			if (idx === -1 || target_idx < 0 || target_idx >= prev.length) return prev;
			const next = [...prev];
			[next[idx], next[target_idx]] = [next[target_idx]!, next[idx]!];
			return next;
		});
	};

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		if (entries.length === 0) return;
		// Each entry must have alt text OR be marked decorative.
		const incomplete = entries.find(
			(entry) => !entry.decorative && entry.alt.trim().length === 0,
		);
		if (incomplete) {
			setStatus({
				kind: 'error',
				message:
					'Every photo needs alt text, or mark it decorative. Decorative photos submit empty alt to indicate they\'re purely visual.',
			});
			return;
		}

		try {
			// Process every photo to a sanitized blob first, so a failing
			// photo doesn't leave half-uploaded media stranded.
			setStatus({ kind: 'processing-photo' });
			const processed_blobs: Blob[] = [];
			for (const entry of entries) {
				const processed = await process_photo(entry.file);
				processed_blobs.push(processed.blob);
			}

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

			// Upload each processed blob in sequence so the user sees
			// progress and a single failure doesn't cascade.
			const uploaded_urls: string[] = [];
			for (let i = 0; i < processed_blobs.length; i++) {
				setStatus({
					kind: 'uploading-photo',
					current: i + 1,
					total: processed_blobs.length,
				});
				const upload = await upload_media(
					{
						blob: processed_blobs[i]!,
						filename: `photo-${String(i + 1)}.jpg`,
						accessToken: token.accessToken,
						mediaEndpoint: media,
					},
					micropubEnv,
				);
				uploaded_urls.push(upload.location);
			}

			setStatus({ kind: 'posting' });
			// Single-photo posts retain the string-shape for back-compat.
			// Multi-photo posts use the array shape per Micropub spec.
			const photo_value = uploaded_urls.length === 1 ? uploaded_urls[0]! : uploaded_urls;
			const alt_array = entries.map((e) => (e.decorative ? '' : e.alt.trim()));
			const alt_value = alt_array.length === 1 ? alt_array[0]! : alt_array;
			const trimmed_content = content.trim();
			const trimmed_name = name.trim();
			const trimmed_venue = venue_name.trim();
			const base = {
				photo: photo_value,
				'mp-photo-alt': alt_value,
				...pkiw_kind_hint(composerConfig, 'photo'),
				...(trimmed_name ? { name: trimmed_name } : {}),
				...(trimmed_content ? { content: trimmed_content } : {}),
				...(picked_location
					? { location: geo_uri(picked_location.lat, picked_location.lon) }
					: {}),
				...(trimmed_venue ? { 'mp-place-name': trimmed_venue } : {}),
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
				setStatus({
					kind: 'posted',
					...(result.location ? { location: result.location } : {}),
				});
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

			// Reset for next post.
			for (const entry of entries) URL.revokeObjectURL(entry.preview_url);
			setEntries([]);
			setName('');
			setContent('');
			setMoreValues(empty_more_values());
			setPickedLocation(null);
			setVenueName('');
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
			? 'Preparing photos…'
			: status.kind === 'discovering-endpoints'
				? 'Finding endpoints…'
				: status.kind === 'uploading-photo'
					? `Uploading ${String(status.current)} of ${String(status.total)}…`
					: status.kind === 'posting'
						? 'Posting…'
						: entries.length > 1
							? 'Post gallery'
							: 'Post photo';

	const all_have_alt = entries.every(
		(entry) => entry.decorative || entry.alt.trim().length > 0,
	);
	const can_submit = entries.length > 0 && all_have_alt && !submitting;

	return (
		<section class="outpost-card" aria-labelledby="outpost-photo-mode-title">
			<h2 id="outpost-photo-mode-title" class="outpost-card__title">
				{entries.length > 1 ? 'Gallery' : 'Photo'}
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit} novalidate>
				<label class="outpost-label" for="outpost-photo-file">
					{entries.length === 0 ? 'Photo' : 'Add more photos'}
				</label>
				<input
					id="outpost-photo-file"
					class="outpost-input"
					type="file"
					accept="image/jpeg,image/png,image/webp,image/gif,image/avif"
					multiple
					onChange={handle_file_change}
					disabled={submitting}
				/>

				{entries.length > 0 && (
					<ul class="outpost-photo-list">
						{entries.map((entry, index) => (
							<li key={entry.id} class="outpost-photo-list__item">
								<img
									class="outpost-photo-list__thumb"
									src={entry.preview_url}
									alt={
										entry.alt ||
										`Photo ${String(index + 1)} (alt text not yet entered)`
									}
								/>
								<div class="outpost-photo-list__fields">
									<label
										class="outpost-label"
										for={`outpost-photo-alt-${entry.id}`}
									>
										Alt text{' '}
										{!entry.decorative && (
											<span class="outpost-required">(required)</span>
										)}
									</label>
									<textarea
										id={`outpost-photo-alt-${entry.id}`}
										class="outpost-textarea"
										rows={2}
										value={entry.alt}
										onInput={(event): void =>
											update_entry(entry.id, {
												alt: (event.target as HTMLTextAreaElement).value,
											})
										}
										placeholder="Describe what's in the photo for screen readers"
										disabled={submitting || entry.decorative}
									/>
									<label class="outpost-checkbox">
										<input
											type="checkbox"
											checked={entry.decorative}
											onChange={(event): void =>
												update_entry(entry.id, {
													decorative: (event.target as HTMLInputElement)
														.checked,
												})
											}
											disabled={submitting}
										/>
										<span>Decorative (no alt text needed)</span>
									</label>
								</div>
								<div class="outpost-photo-list__actions">
									<button
										type="button"
										class="outpost-button outpost-button--secondary"
										onClick={(): void => move_entry(entry.id, -1)}
										disabled={submitting || index === 0}
										aria-label={`Move photo ${String(index + 1)} earlier`}
									>
										<span aria-hidden="true">↑</span>
									</button>
									<button
										type="button"
										class="outpost-button outpost-button--secondary"
										onClick={(): void => move_entry(entry.id, 1)}
										disabled={submitting || index === entries.length - 1}
										aria-label={`Move photo ${String(index + 1)} later`}
									>
										<span aria-hidden="true">↓</span>
									</button>
									<button
										type="button"
										class="outpost-button outpost-button--secondary"
										onClick={(): void => remove_entry(entry.id)}
										disabled={submitting}
										aria-label={`Remove photo ${String(index + 1)}`}
									>
										Remove
									</button>
								</div>
							</li>
						))}
					</ul>
				)}

				<label class="outpost-label" for="outpost-photo-name">
					Title (optional)
				</label>
				<input
					id="outpost-photo-name"
					class="outpost-input"
					type="text"
					value={name}
					onInput={(event): void => setName((event.target as HTMLInputElement).value)}
					disabled={submitting}
				/>

				<div class="outpost-textarea-row">
					<label class="outpost-label" for="outpost-photo-content">
						Body (optional)
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

				<GeocodePicker
					idPrefix="outpost-photo"
					accessToken={token.accessToken}
					picked={picked_location}
					onPick={setPickedLocation}
					onClear={(): void => setPickedLocation(null)}
					venueName={venue_name}
					onVenueChange={setVenueName}
					disabled={submitting}
				/>

				{/* Persistent live regions so iOS VoiceOver picks up announcements. */}
				<div
					class="outpost-error"
					role="alert"
					aria-live="assertive"
					hidden={status.kind !== 'error'}
				>
					{status.kind === 'error' ? status.message : ''}
				</div>

				<p
					class="outpost-status"
					aria-live="polite"
					hidden={status.kind !== 'posted' && status.kind !== 'queued'}
				>
					{status.kind === 'posted' ? (
						status.location ? (
							<>
								Posted to{' '}
								<a href={status.location} target="_blank" rel="noopener noreferrer">
									{status.location}
								</a>
								{a11y_active && (
									<>
										{' · '}
										<a
											href={`${status.location}?edac_view=1`}
											target="_blank"
											rel="noopener noreferrer"
										>
											View accessibility report
										</a>
									</>
								)}
							</>
						) : (
							'Posted, but the server did not return a link. Check your site to confirm it published.'
						)
					) : status.kind === 'queued' ? (
						"Saved for later. Outpost will post this when you're back online."
					) : (
						''
					)}
				</p>

				{composerConfig && (
					<Drawer
						open={more_open}
						onClose={(): void => setMoreOpen(false)}
						title="More options"
					>
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
					</Drawer>
				)}

				<div class="outpost-form-actions">
					<button class="outpost-button" type="submit" disabled={!can_submit}>
						{submit_label}
					</button>
					{composerConfig && (
						<button
							class="outpost-button outpost-button--secondary"
							type="button"
							onClick={(): void => setMoreOpen(true)}
							disabled={submitting}
						>
							More options
						</button>
					)}
				</div>
			</form>
		</section>
	);
}
