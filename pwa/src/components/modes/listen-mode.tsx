import { useEffect, useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from '../../lib/micropub';
import {
	geocode,
	geo_uri,
	GeocodeError,
	type GeocodeResult,
} from '../../lib/geocode';
import { is_safe_http_url, is_safe_location_value } from '../../lib/url-validation';
import type { StoredToken } from '../../lib/token-store';
import type { ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { VoiceButton } from '../voice-button';
import { Drawer } from '../drawer';
import {
	MorePanel,
	empty_more_values,
	merge_more_values,
	type MorePanelValues,
} from '../more-panel';

/**
 * Listen group — life-tracking posts that point at media or places.
 *
 * Five sub-modes under one tab, picked via radio:
 *   - Listen   → listen-of  (album/track URL)
 *   - Watch    → watch-of   (movie/show URL)
 *   - Read     → read-of    (book identifier URL)
 *   - Play     → play-of    (game URL)
 *   - Checkin  → location   (place URL or geo URI; place name optional)
 *
 * All five share the form shape: URL + optional content. Checkin adds an
 * optional Place name field that gets posted as `name`. None require text.
 *
 * Companion gating: these post kinds are most useful when Post Kinds for
 * IndieWeb is active (it adds proper post-type rendering). Without Post
 * Kinds, the posts still go through Micropub but render as generic notes.
 * Outpost doesn't currently runtime-detect Post Kinds at the PWA level —
 * the tab is always visible. C3b can add a "requires Post Kinds" notice
 * when companion detection is wired client-side.
 */

export interface ListenModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

type Variant =
	| 'listen'
	| 'watch'
	| 'read'
	| 'play'
	| 'game'
	| 'jam'
	| 'checkin'
	| 'eat'
	| 'drink';

type VariantProperty =
	| 'listen-of'
	| 'watch-of'
	| 'read-of'
	| 'play-of'
	| 'location'
	| 'eat-of'
	| 'drink-of';

/**
 * Per-variant feature flags drive conditional rendering and submit-payload
 * shape. Adding a feature is a row-level edit on VARIANTS rather than another
 * `variant === 'x'` branch in JSX — keeps the form rendering uniform.
 */
interface VariantConfig {
	label: string;
	property: VariantProperty;
	targetLabel: string;
	contentLabel: string;
	submitLabel: string;
	/** Whether the target URL is required. Eat/Drink may be context-free. */
	targetRequired: boolean;
	/** Show the per-kind primary input (artist for listen/jam, director for
	 *  watch, author for read, food/drink for eat/drink, place for checkin). */
	personLabel?: string;
	/** Property the personLabel maps to in the Micropub payload. */
	personProperty?: 'author' | 'name';
	/** Show the read-status dropdown (Read kind only). */
	hasReadStatus?: boolean;
	/** Show the rating input (media-consumption kinds). */
	hasRating?: boolean;
	/** Show the title input (Watch primarily). */
	hasTitle?: boolean;
	/** Show the OpenStreetMap geocode lookup (Checkin and Eat/Drink). */
	hasGeocode?: boolean;
}

const VARIANTS: Record<Variant, VariantConfig> = {
	listen: {
		label: 'Listen',
		property: 'listen-of',
		targetLabel: 'Track or album URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post listen',
		targetRequired: true,
		personLabel: 'Artist',
		personProperty: 'author',
		hasRating: true,
	},
	watch: {
		label: 'Watch',
		property: 'watch-of',
		targetLabel: 'Movie or show URL',
		contentLabel: 'Body (optional)',
		submitLabel: 'Post watch',
		targetRequired: true,
		hasTitle: true,
		personLabel: 'Director (optional)',
		personProperty: 'author',
		hasRating: true,
	},
	read: {
		label: 'Read',
		property: 'read-of',
		targetLabel: 'Book URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post read',
		targetRequired: true,
		personLabel: 'Author',
		personProperty: 'author',
		hasReadStatus: true,
		hasRating: true,
	},
	play: {
		label: 'Play',
		property: 'play-of',
		targetLabel: 'Game URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post play',
		targetRequired: true,
		hasRating: true,
	},
	game: {
		label: 'Game',
		// Per Post Kinds, Game and Play share the play-of property — they
		// render with different post-kind labels but the underlying h-entry
		// shape is identical.
		property: 'play-of',
		targetLabel: 'Game URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post game',
		targetRequired: true,
		hasRating: true,
	},
	jam: {
		// Same overlap as Game: Jam and Listen share listen-of, but the post
		// kind label is "Jam" — used when sharing a single track you're
		// currently into rather than logging an album you finished.
		label: 'Jam',
		property: 'listen-of',
		targetLabel: 'Track URL',
		contentLabel: 'Why this track?',
		submitLabel: 'Post jam',
		targetRequired: true,
		personLabel: 'Artist',
		personProperty: 'author',
		hasRating: true,
	},
	checkin: {
		label: 'Checkin',
		property: 'location',
		targetLabel: 'Location URL or geo:lat,lon',
		contentLabel: 'Optional note',
		submitLabel: 'Post checkin',
		targetRequired: true,
		personLabel: 'Place name (optional)',
		personProperty: 'name',
		hasGeocode: true,
	},
	eat: {
		// Post Kinds maps Eat to `eat-of` (food name string), with optional
		// location URL/coords as a sibling property. The "target URL" here is
		// the optional venue — the food-name input is the post's primary
		// content.
		label: 'Eat',
		property: 'eat-of',
		targetLabel: 'Venue URL or geo:lat,lon (optional)',
		contentLabel: 'Optional note',
		submitLabel: 'Post meal',
		targetRequired: false,
		personLabel: 'What did you eat?',
		personProperty: 'name', // posted as eat-of via property mapping below
		hasGeocode: true,
	},
	drink: {
		label: 'Drink',
		property: 'drink-of',
		targetLabel: 'Venue URL or geo:lat,lon (optional)',
		contentLabel: 'Optional note',
		submitLabel: 'Post drink',
		targetRequired: false,
		personLabel: 'What did you drink?',
		personProperty: 'name',
		hasGeocode: true,
	},
};

const VARIANT_ORDER: Variant[] = [
	'listen',
	'watch',
	'read',
	'play',
	'game',
	'jam',
	'checkin',
	'eat',
	'drink',
];

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering-endpoint' }
	| { kind: 'posting' }
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

export function ListenMode({ token, micropubEnv, composerConfig }: ListenModeProps) {
	const [variant, setVariant] = useState<Variant>('listen');
	const [target_url, setTargetUrl] = useState('');
	// Unified per-variant primary input. The `personLabel` config decides
	// what the user sees ("Artist" / "Director" / "Author" / "Place name" /
	// "What did you eat?"). The submit handler below routes the value to
	// the appropriate h-entry property based on `personProperty`.
	const [person_name, setPersonName] = useState('');
	const [watch_title, setWatchTitle] = useState('');
	const [read_status, setReadStatus] = useState<'' | 'to-read' | 'reading' | 'finished'>('');
	const [rating, setRating] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());
	const [more_open, setMoreOpen] = useState(false);

	// Geocode-search state, used by checkin / eat / drink (any variant whose
	// config has `hasGeocode: true`).
	const [geo_query, setGeoQuery] = useState('');
	const [geo_results, setGeoResults] = useState<GeocodeResult[]>([]);
	const [geo_searching, setGeoSearching] = useState(false);
	const [geo_error, setGeoError] = useState<string | null>(null);
	const [geo_attribution, setGeoAttribution] = useState('');

	// Stale geocode results from a previous variant would otherwise re-render
	// when the user comes back to a hasGeocode variant. Clear on switch.
	useEffect(() => {
		setGeoQuery('');
		setGeoResults([]);
		setGeoError(null);
	}, [variant]);

	const handle_geocode_search = async (event?: Event): Promise<void> => {
		event?.preventDefault();
		const q = geo_query.trim();
		if (q.length < 2) return;
		setGeoSearching(true);
		setGeoError(null);
		setGeoResults([]);
		try {
			const response = await geocode({
				query: q,
				accessToken: token.accessToken,
			});
			setGeoResults(response.results);
			setGeoAttribution(response.attribution);
			if (response.results.length === 0) {
				setGeoError('No matches. Try a different search.');
			}
		} catch (err) {
			const message =
				err instanceof GeocodeError
					? err.code === 'rate_limited'
						? `Too many lookups — try again in ${String(err.retryAfter ?? 60)}s.`
						: err.message
					: err instanceof Error
						? err.message
						: 'Search failed';
			setGeoError(message);
		} finally {
			setGeoSearching(false);
		}
	};

	const handle_geocode_pick = (result: GeocodeResult): void => {
		// Fill the URL field with a geo: URI (RFC 5870). For Checkin we also
		// fill the place name from the OSM display name; for Eat/Drink we
		// leave the food/drink name alone (the user's already typed what
		// they're eating; the location is contextual).
		setTargetUrl(geo_uri(result.lat, result.lon));
		if (variant === 'checkin') {
			setPersonName(result.displayName);
		}
		setGeoResults([]);
		setGeoQuery('');
	};

	const config = VARIANTS[variant];
	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';
	const trimmed_target_url_for_xfn = target_url.trim();

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_content = content.trim();
		const trimmed_url = target_url.trim();
		const trimmed_person = person_name.trim();
		const trimmed_watch_title = watch_title.trim();
		const trimmed_rating = rating.trim();

		// Eat/Drink can be context-free (just a food/drink name with no URL).
		// Checkin and the URL-anchored kinds still require something in the
		// target field. For Eat/Drink the food/drink input is the required
		// signal; for everything else the URL is.
		if (config.targetRequired) {
			if (!trimmed_url) return;
		} else if (!trimmed_person) {
			return; // Eat/Drink needs at least the food/drink name.
		}

		// URL validation only applies when a URL was actually entered.
		// Checkin and Eat/Drink accept geo:lat,lon URIs (from OSM lookup) as
		// well as http(s); the other variants are URL-only.
		if (trimmed_url) {
			const accepts_geo = variant === 'checkin' || variant === 'eat' || variant === 'drink';
			const url_ok = accepts_geo
				? is_safe_location_value(trimmed_url)
				: is_safe_http_url(trimmed_url);
			if (!url_ok) {
				const message = accepts_geo
					? 'Location must be http://, https://, or a geo:lat,lon URI.'
					: 'URL must be http:// or https://.';
				setStatus({ kind: 'error', message });
				return;
			}
		}

		// Rating sanity: numeric, 1-5 inclusive. Empty is valid (no rating).
		if (trimmed_rating !== '') {
			const r = Number(trimmed_rating);
			if (!Number.isFinite(r) || r < 1 || r > 5) {
				setStatus({ kind: 'error', message: 'Rating must be a number between 1 and 5.' });
				return;
			}
		}

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering-endpoint' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			// Build the h-entry properties. Routing is variant-aware:
			//
			//   - URL-anchored (listen, watch, read, play, game, jam):
			//     target URL → config.property; person → personProperty
			//     (artist/director/author).
			//   - checkin: target URL → location; person → name (place).
			//   - eat / drink: person → eat-of/drink-of (the food/drink IS
			//     the post kind's primary property); target URL → location
			//     (optional venue).
			const base: HEntryProperties = {};

			if (variant === 'eat' || variant === 'drink') {
				if (trimmed_person) {
					base[config.property] = trimmed_person;
				}
				if (trimmed_url) {
					base.location = trimmed_url;
				}
			} else {
				base[config.property] = trimmed_url;
				// Watch's standalone title input maps to h-entry name.
				if (config.hasTitle && trimmed_watch_title) {
					base.name = trimmed_watch_title;
				}
				// Person input — checkin uses h-entry name, others use author.
				if (config.personProperty && trimmed_person) {
					if (config.personProperty === 'name' && !base.name) {
						base.name = trimmed_person;
					} else if (config.personProperty === 'author') {
						base.author = trimmed_person;
					}
				}
			}

			if (trimmed_content) {
				base.content = trimmed_content;
			}
			if (config.hasReadStatus && read_status !== '') {
				base['read-status'] = read_status;
			}
			if (config.hasRating && trimmed_rating !== '') {
				base.rating = trimmed_rating;
			}
			const properties = merge_more_values(base, more_values, trimmed_url);

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
				setTargetUrl('');
				setPersonName('');
				setWatchTitle('');
				setReadStatus('');
				setRating('');
				setContent('');
				setMoreValues(empty_more_values());
				return;
			} catch (post_err) {
				if (is_network_error(post_err)) {
					try {
						await enqueue({
							source: 'listen',
							properties,
							accessToken: token.accessToken,
							micropubEndpoint: micropub_endpoint,
						});
						setStatus({ kind: 'queued' });
						setTargetUrl('');
						setPersonName('');
						setWatchTitle('');
						setReadStatus('');
						setRating('');
						setContent('');
						setMoreValues(empty_more_values());
						return;
					} catch (_q_err) {
						// Queue write failed; fall through to error display.
					}
				}
				throw post_err;
			}
		} catch (err) {
			const message =
				err instanceof MicropubError
					? err.code + ': ' + err.message
					: err instanceof Error
						? err.message
						: 'Unknown error';
			setStatus({ kind: 'error', message });
		}
	};

	const submitting = status.kind === 'discovering-endpoint' || status.kind === 'posting';
	const submit_label =
		status.kind === 'discovering-endpoint'
			? 'Finding endpoint…'
			: status.kind === 'posting'
				? 'Posting…'
				: config.submitLabel;
	// Variant-aware enable: URL-anchored kinds (listen/watch/etc.) need a URL;
	// content-shaped kinds (eat/drink) need their primary text input. Without
	// this branch, eat/drink users with a food name typed in but no venue URL
	// see a permanently-disabled submit and a `required` browser-validation
	// block — caught by both the code review and a11y audit.
	const can_submit = config.targetRequired
		? !!target_url.trim()
		: !!person_name.trim();

	return (
		<section class="outpost-card" aria-labelledby="outpost-listen-mode-title">
			<h2 id="outpost-listen-mode-title" class="outpost-card__title">
				{config.label}
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit}>
				<fieldset class="outpost-variant-picker">
					<legend class="outpost-label">Type</legend>
					{VARIANT_ORDER.map((id) => (
						<label key={id} class="outpost-radio">
							<input
								type="radio"
								name="outpost-listen-variant"
								value={id}
								checked={variant === id}
								onChange={(): void => setVariant(id)}
								disabled={submitting}
							/>
							<span>{VARIANTS[id].label}</span>
						</label>
					))}
				</fieldset>

				<label class="outpost-label" for="outpost-listen-target">
					{config.targetLabel}
				</label>
				<input
					id="outpost-listen-target"
					class="outpost-input"
					// Checkin accepts geo: URIs which browsers don't recognize as
					// valid `type=url` values; degrade to plain text there.
					type={variant === 'checkin' ? 'text' : 'url'}
					value={target_url}
					onInput={(event): void => setTargetUrl((event.target as HTMLInputElement).value)}
					placeholder={
						variant === 'checkin'
							? 'https://example.com/place/ or geo:29.12,-103.24'
							: 'https://example.com/…'
					}
					inputMode="url"
					autoComplete="url"
					autoCapitalize="none"
					spellcheck={false}
					required={config.targetRequired}
				/>

				{config.hasTitle && (
					<>
						<label class="outpost-label" for="outpost-listen-watch-title">
							Title (optional)
						</label>
						<input
							id="outpost-listen-watch-title"
							class="outpost-input"
							type="text"
							value={watch_title}
							onInput={(event): void =>
								setWatchTitle((event.target as HTMLInputElement).value)
							}
							disabled={submitting}
							placeholder="e.g., The Bear S2E3"
						/>
					</>
				)}

				{config.personLabel && (
					<>
						<label class="outpost-label" for="outpost-listen-person">
							{config.personLabel}
						</label>
						<input
							id="outpost-listen-person"
							class="outpost-input"
							type="text"
							value={person_name}
							onInput={(event): void =>
								setPersonName((event.target as HTMLInputElement).value)
							}
							disabled={submitting}
						/>
					</>
				)}

				{config.hasReadStatus && (
					<>
						<label class="outpost-label" for="outpost-listen-read-status">
							Reading status
						</label>
						<select
							id="outpost-listen-read-status"
							class="outpost-input"
							value={read_status}
							onChange={(event): void =>
								setReadStatus(
									(event.target as HTMLSelectElement)
										.value as typeof read_status,
								)
							}
							disabled={submitting}
						>
							<option value="">Not specified</option>
							<option value="to-read">To read</option>
							<option value="reading">Currently reading</option>
							<option value="finished">Finished</option>
						</select>
					</>
				)}

				{config.hasRating && (
					<>
						<label class="outpost-label" for="outpost-listen-rating">
							Rating (1–5, optional)
						</label>
						<input
							id="outpost-listen-rating"
							class="outpost-input"
							type="number"
							min={1}
							max={5}
							step={1}
							inputMode="numeric"
							value={rating}
							onInput={(event): void =>
								setRating((event.target as HTMLInputElement).value)
							}
							disabled={submitting}
						/>
					</>
				)}

				{config.hasGeocode && (
					<>
						<details class="outpost-collapsible">
							<summary class="outpost-collapsible__summary">
								Look up on OpenStreetMap
							</summary>
							<div class="outpost-collapsible__body">
								<label
									class="outpost-label"
									for="outpost-listen-geocode-query"
								>
									Search a place
								</label>
								<div class="outpost-form-inline">
									<input
										id="outpost-listen-geocode-query"
										class="outpost-input"
										type="search"
										value={geo_query}
										onInput={(event): void =>
											setGeoQuery((event.target as HTMLInputElement).value)
										}
										onKeyDown={(event): void => {
											if (event.key === 'Enter') {
												event.preventDefault();
												void handle_geocode_search();
											}
										}}
										placeholder="e.g. Big Bend National Park"
										disabled={submitting || geo_searching}
									/>
									<button
										type="button"
										class="outpost-button outpost-button--secondary"
										onClick={(): void => {
											void handle_geocode_search();
										}}
										disabled={
											submitting ||
											geo_searching ||
											geo_query.trim().length < 2
										}
									>
										{geo_searching ? 'Searching…' : 'Search'}
									</button>
								</div>

								{geo_error && (
									<p class="outpost-status outpost-status--warn" role="alert">
										{geo_error}
									</p>
								)}

								{geo_results.length > 0 && (
									<>
										<ul class="outpost-geocode-results">
											{geo_results.map((result) => (
												<li
													key={`${String(result.lat)},${String(result.lon)}`}
												>
													<button
														type="button"
														class="outpost-geocode-result"
														onClick={(): void => handle_geocode_pick(result)}
														disabled={submitting}
													>
														<span class="outpost-geocode-result__name">
															{result.displayName}
														</span>
														<span class="outpost-geocode-result__coords">
															{result.lat.toFixed(4)},{' '}
															{result.lon.toFixed(4)}
															{result.type ? ` · ${result.type}` : ''}
														</span>
													</button>
												</li>
											))}
										</ul>
										{geo_attribution && (
											<p class="outpost-geocode-attribution">
												{geo_attribution}
											</p>
										)}
									</>
								)}
							</div>
						</details>
					</>
				)}

				<div class="outpost-textarea-row">
					<label class="outpost-label" for="outpost-listen-content">
						{config.contentLabel}
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
					id="outpost-listen-content"
					class="outpost-textarea"
					rows={3}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting}
				/>

				{/* Live regions are rendered unconditionally so iOS VoiceOver
				    reliably picks up announcements; previously these mounted
				    only on state change, which the iOS AT misses. The empty
				    string content stays in the DOM as the no-op state. */}
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
							'Posted successfully.'
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
							xfnTargetUrl={trimmed_target_url_for_xfn || null}
							disabled={submitting}
							idPrefix="outpost-listen"
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
