import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	discover_media_endpoint,
	upload_media,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from '../../lib/micropub';
import { process_photo, PhotoError } from '../../lib/photo';
import {
	MediaPicker,
	all_entries_have_alt,
	type MediaEntry,
} from '../media-picker';
import type { StoredToken } from '../../lib/token-store';
import { pkiw_kind_hint, type ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { useMoreOpen } from '../../lib/composer-prefs';
import { GeocodePicker } from '../geocode-picker';
import { geo_uri, type GeocodeResult } from '../../lib/geocode';
import { Drawer } from '../drawer';
import {
	MorePanel,
	useMorePanelValues,
	merge_more_values,
} from '../more-panel';

/**
 * Recipe mode — structured cooking-recipe form.
 *
 * Per Post Kinds + h-recipe microformat: a recipe is an h-entry with
 * additional properties: `name` (recipe title), `ingredient` (array of
 * ingredient lines), `instructions` (array of instruction step lines),
 * `yield` (servings), `duration` (ISO 8601 duration string).
 *
 * Outpost's UI keeps the input simple: ingredients and instructions are
 * single textareas with one item per line. The submit handler splits on
 * newlines into the array form Micropub expects. Users who want richer
 * structure (servings sub-quantities, equipment lists, nutrition info) hit
 * the limit of what makes sense in a mobile-first composer; that's why the
 * original review labeled this "better as a separate plugin." Outpost
 * ships a usable v1 here and leaves room for the dedicated recipe-plugin
 * niche to grow alongside.
 *
 * `duration` is collected as a friendly minute count and converted to ISO
 * 8601 (`PT45M`) at submit time. The Post Kinds plugin and h-recipe-aware
 * themes both expect ISO 8601 for this field.
 */

export interface RecipeModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering-endpoint' }
	| { kind: 'processing-photo' }
	| { kind: 'uploading-photo'; current: number; total: number }
	| { kind: 'posting' }
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

/**
 * Convert a friendly minute count into an ISO 8601 duration string.
 *
 * Examples: 45 → "PT45M"; 90 → "PT1H30M"; 60 → "PT1H"; 0/empty → "".
 *
 * Done client-side because Post Kinds + most h-recipe themes expect ISO
 * 8601 in the `duration` property and re-rendering minutes on every read
 * is wasteful.
 */
export function minutes_to_iso8601_duration(minutes: number): string {
	if (!Number.isFinite(minutes) || minutes <= 0) return '';
	const total = Math.round(minutes);
	const hours = Math.floor(total / 60);
	const remaining = total % 60;
	if (hours === 0) return 'PT' + String(remaining) + 'M';
	if (remaining === 0) return 'PT' + String(hours) + 'H';
	return 'PT' + String(hours) + 'H' + String(remaining) + 'M';
}

export function RecipeMode({ token, micropubEnv, composerConfig }: RecipeModeProps) {
	const [name, setName] = useState('');
	const [ingredients_text, setIngredientsText] = useState('');
	const [instructions_text, setInstructionsText] = useState('');
	const [recipe_yield, setRecipeYield] = useState('');
	const [duration_minutes, setDurationMinutes] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues, resetMoreValues] = useMorePanelValues(composerConfig);
	const [more_open, setMoreOpen] = useMoreOpen();
	const [picked_location, setPickedLocation] = useState<GeocodeResult | null>(null);
	const [venue_name, setVenueName] = useState('');
	const [media_entries, setMediaEntries] = useState<MediaEntry[]>([]);
	const [media_endpoint, setMediaEndpoint] = useState<string | null>(null);

	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_name = name.trim();
		const trimmed_yield = recipe_yield.trim();
		const trimmed_content = content.trim();

		// Split ingredient/instruction textareas on newlines and discard
		// empty lines. Users naturally write one item per line in mobile
		// composers and the array shape is what h-recipe themes consume.
		const ingredients = ingredients_text
			.split(/\r?\n/)
			.map((s) => s.trim())
			.filter((s) => s.length > 0);
		const instructions = instructions_text
			.split(/\r?\n/)
			.map((s) => s.trim())
			.filter((s) => s.length > 0);

		// Minimum viable recipe: name + at least one ingredient + at least
		// one instruction. Less than that isn't a recipe, it's a Note.
		if (!trimmed_name || ingredients.length === 0 || instructions.length === 0) {
			setStatus({
				kind: 'error',
				message: 'Recipe needs a title, at least one ingredient, and at least one step.',
			});
			return;
		}

		// Alt-text discipline for the recipe photo. Mirrors PhotoMode and
		// Doing: every entry needs alt text or an explicit decorative mark.
		if (media_entries.length > 0 && !all_entries_have_alt(media_entries)) {
			setStatus({
				kind: 'error',
				message:
					'Every photo needs alt text, or mark it decorative. Decorative photos submit empty alt to indicate they\'re purely visual.',
			});
			return;
		}

		const minutes_num = duration_minutes.trim() === '' ? 0 : Number(duration_minutes);
		if (duration_minutes.trim() !== '' && (!Number.isFinite(minutes_num) || minutes_num < 0)) {
			setStatus({
				kind: 'error',
				message: 'Total time must be a non-negative number of minutes.',
			});
			return;
		}
		const iso_duration = minutes_to_iso8601_duration(minutes_num);

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering-endpoint' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			// Process + upload any attached photo. Same pipeline as PhotoMode
			// and Doing: EXIF strip + downscale + JPEG re-encode, then a POST
			// per photo to the media endpoint, collecting Location URLs.
			const uploaded_photo_urls: string[] = [];
			let alt_values: string[] = [];
			if (media_entries.length > 0) {
				setStatus({ kind: 'processing-photo' });
				const processed_blobs: Blob[] = [];
				for (const entry of media_entries) {
					const processed = await process_photo(entry.file);
					processed_blobs.push(processed.blob);
				}

				let resolved_media_endpoint = media_endpoint;
				if (!resolved_media_endpoint) {
					setStatus({ kind: 'discovering-endpoint' });
					resolved_media_endpoint = await discover_media_endpoint(
						micropub_endpoint,
						token.accessToken,
						micropubEnv,
					);
					setMediaEndpoint(resolved_media_endpoint);
				}

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
							mediaEndpoint: resolved_media_endpoint,
						},
						micropubEnv,
					);
					uploaded_photo_urls.push(upload.location);
				}
				alt_values = media_entries.map((e) => (e.decorative ? '' : e.alt.trim()));
			}

			const photo_props: Partial<HEntryProperties> =
				uploaded_photo_urls.length === 0
					? {}
					: uploaded_photo_urls.length === 1
						? {
								photo: uploaded_photo_urls[0]!,
								'mp-photo-alt': alt_values[0] ?? '',
							}
						: { photo: uploaded_photo_urls, 'mp-photo-alt': alt_values };

			const trimmed_venue = venue_name.trim();
			const base: HEntryProperties = {
				name: trimmed_name,
				ingredient: ingredients,
				...pkiw_kind_hint(composerConfig, 'recipe'),
				instructions,
				...(trimmed_yield ? { yield: trimmed_yield } : {}),
				...(iso_duration ? { duration: iso_duration } : {}),
				...(trimmed_content ? { content: trimmed_content } : {}),
				...(picked_location
					? { location: geo_uri(picked_location.lat, picked_location.lon) }
					: {}),
				...(trimmed_venue ? { 'mp-place-name': trimmed_venue } : {}),
				...photo_props,
			};
			const properties = merge_more_values(base, more_values);

			setStatus({ kind: 'posting' });
			try {
				const result = await post_h_entry(
					{
						properties,
						accessToken: token.accessToken,
						micropubEndpoint: micropub_endpoint,
					},
					micropubEnv,
				);
				setStatus({
					kind: 'posted',
					...(result.location ? { location: result.location } : {}),
				});
				mark_posted_once();
				setName('');
				setIngredientsText('');
				setInstructionsText('');
				setRecipeYield('');
				setDurationMinutes('');
				setContent('');
				resetMoreValues();
				setPickedLocation(null);
				setVenueName('');
				for (const entry of media_entries) {
					URL.revokeObjectURL(entry.preview_url);
				}
				setMediaEntries([]);
				return;
			} catch (post_err) {
				if (is_network_error(post_err)) {
					try {
						await enqueue({
							source: 'recipe',
							properties,
							accessToken: token.accessToken,
							micropubEndpoint: micropub_endpoint,
						});
						setStatus({ kind: 'queued' });
						return;
					} catch (_q_err) {
						// Fall through to error display.
					}
				}
				throw post_err;
			}
		} catch (err) {
			const message =
				err instanceof PhotoError
					? err.message
					: err instanceof MicropubError
						? err.code + ': ' + err.message
						: err instanceof Error
							? err.message
							: 'Unknown error';
			setStatus({ kind: 'error', message });
		}
	};

	const submitting =
		status.kind === 'discovering-endpoint' ||
		status.kind === 'processing-photo' ||
		status.kind === 'uploading-photo' ||
		status.kind === 'posting';
	const submit_label =
		status.kind === 'discovering-endpoint'
			? 'Finding endpoint…'
			: status.kind === 'processing-photo'
				? 'Processing photo…'
				: status.kind === 'uploading-photo'
					? `Uploading ${String(status.current)}/${String(status.total)}…`
					: status.kind === 'posting'
						? 'Posting…'
						: 'Post recipe';
	const can_submit =
		!!name.trim() &&
		ingredients_text.trim().length > 0 &&
		instructions_text.trim().length > 0 &&
		(media_entries.length === 0 || all_entries_have_alt(media_entries));

	return (
		<section class="outpost-card" aria-labelledby="outpost-recipe-mode-title">
			<h2 id="outpost-recipe-mode-title" class="outpost-card__title">
				Recipe
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit} novalidate>
				<label class="outpost-label" for="outpost-recipe-name">
					Recipe title <span class="outpost-required">(required)</span>
				</label>
				<input
					id="outpost-recipe-name"
					class="outpost-input"
					type="text"
					value={name}
					onInput={(event): void => setName((event.target as HTMLInputElement).value)}
					required
					disabled={submitting}
				/>

				<label class="outpost-label" for="outpost-recipe-ingredients">
					Ingredients <span class="outpost-required">(one per line, required)</span>
				</label>
				<textarea
					id="outpost-recipe-ingredients"
					class="outpost-textarea"
					rows={6}
					value={ingredients_text}
					onInput={(event): void =>
						setIngredientsText((event.target as HTMLTextAreaElement).value)
					}
					placeholder={'2 cups flour\n1 tsp salt\n3 tbsp olive oil'}
					required
					disabled={submitting}
				/>

				<label class="outpost-label" for="outpost-recipe-instructions">
					Instructions <span class="outpost-required">(one step per line, required)</span>
				</label>
				<textarea
					id="outpost-recipe-instructions"
					class="outpost-textarea"
					rows={6}
					value={instructions_text}
					onInput={(event): void =>
						setInstructionsText((event.target as HTMLTextAreaElement).value)
					}
					placeholder={'Whisk flour and salt.\nAdd olive oil.\nKnead 5 minutes.'}
					required
					disabled={submitting}
				/>

				<label class="outpost-label" for="outpost-recipe-yield">
					Yield (optional)
				</label>
				<input
					id="outpost-recipe-yield"
					class="outpost-input"
					type="text"
					value={recipe_yield}
					onInput={(event): void =>
						setRecipeYield((event.target as HTMLInputElement).value)
					}
					placeholder="e.g., 4 servings"
					disabled={submitting}
				/>

				<label class="outpost-label" for="outpost-recipe-duration">
					Total time, minutes (optional)
				</label>
				<input
					id="outpost-recipe-duration"
					class="outpost-input"
					type="number"
					min={0}
					step={1}
					inputMode="numeric"
					value={duration_minutes}
					onInput={(event): void =>
						setDurationMinutes((event.target as HTMLInputElement).value)
					}
					disabled={submitting}
				/>

				<label class="outpost-label" for="outpost-recipe-content">
					Notes / story (optional)
				</label>
				<textarea
					id="outpost-recipe-content"
					class="outpost-textarea"
					rows={3}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting}
				/>

				<MediaPicker
					entries={media_entries}
					onChange={setMediaEntries}
					disabled={submitting}
					idPrefix="outpost-recipe-media"
					emptyLabel="Attach a photo (optional)"
					nonEmptyLabel="Add more photos"
				/>

				<GeocodePicker
					idPrefix="outpost-recipe"
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
							'Recipe posted.'
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
							micropubEndpoint={endpoint}
							{...(micropubEnv ? { micropubEnv } : {})}
							xfnTargetUrl={null}
							disabled={submitting}
							idPrefix="outpost-recipe"
						/>
					</Drawer>
				)}

				<div class="outpost-form-actions">
					<button
						class="outpost-button"
						type="submit"
						disabled={submitting || !can_submit}
					>
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
