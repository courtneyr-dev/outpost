import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from '../../lib/micropub';
import type { StoredToken } from '../../lib/token-store';
import { pkiw_kind_hint, type ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { useMoreOpen } from '../../lib/composer-prefs';
import { peek_share_target, consume_share_target } from '../../lib/share-target';
import { VoiceButton } from '../voice-button';
import { GeocodePicker } from '../geocode-picker';
import { geo_uri, type GeocodeResult } from '../../lib/geocode';
import { Drawer } from '../drawer';
import {
	MorePanel,
	useMorePanelValues,
	merge_more_values,
} from '../more-panel';

/**
 * Life mode — content-only post kinds: Mood, Weather, Sleep, Trip,
 * Itinerary, Question.
 *
 * These post kinds don't reference an external URL; they're personal
 * statements about state of being (or, for Question, a prompt for
 * replies). Distinct from the Doing tab (which is activity-anchored,
 * including Exercise) and from Note (which is a generic
 * thought-of-the-moment without a typed property).
 *
 * Per Post Kinds, each variant maps its primary input to the h-entry
 * property sharing its name: `mood`, `weather`, `sleep`, `trip`,
 * `itinerary`, `question` (rendered as p-<property> in microformats2).
 *
 * Exercise used to live here but moved to the Doing tab (v0.1.63) — its
 * primary axis is activity-as-event with optional venue, so it shares
 * shape with Eat/Drink rather than Mood/Weather.
 *
 * Each variant has a primary text input that becomes the property value, plus
 * an optional `content` body for additional context. No URL field.
 */

export interface LifeModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

type Variant = 'mood' | 'weather' | 'sleep' | 'trip' | 'itinerary' | 'question';

interface VariantConfig {
	label: string;
	property: 'mood' | 'weather' | 'sleep' | 'trip' | 'itinerary' | 'question';
	primaryLabel: string;
	primaryPlaceholder: string;
	contentLabel: string;
	submitLabel: string;
}

const VARIANTS: Record<Variant, VariantConfig> = {
	mood: {
		label: 'Mood',
		property: 'mood',
		primaryLabel: 'How are you feeling?',
		primaryPlaceholder: 'e.g., focused, tired, hopeful',
		contentLabel: 'More context (optional)',
		submitLabel: 'Post mood',
	},
	weather: {
		label: 'Weather',
		property: 'weather',
		primaryLabel: 'Conditions',
		primaryPlaceholder: 'e.g., 72°F and sunny, light breeze',
		contentLabel: 'Notes (optional)',
		submitLabel: 'Post weather',
	},
	sleep: {
		// Post Kinds: h-entry `sleep` property (p-sleep) — a passive
		// sleep-time log. Same primary + optional-context shape as Mood.
		label: 'Sleep',
		property: 'sleep',
		primaryLabel: 'How did you sleep?',
		primaryPlaceholder: 'e.g., 7h 40m, restless after 3am',
		contentLabel: 'Notes (optional)',
		submitLabel: 'Post sleep',
	},
	trip: {
		// Post Kinds: h-entry `trip` property (p-trip) — a geographic
		// journey from one place to another, described as text.
		label: 'Trip',
		property: 'trip',
		primaryLabel: 'Where are you headed?',
		primaryPlaceholder: 'e.g., Chicago to Detroit by train',
		contentLabel: 'Notes (optional)',
		submitLabel: 'Post trip',
	},
	itinerary: {
		// Post Kinds: h-entry `itinerary` property (p-itinerary) — the
		// scheduled legs of a trip (flights, trains, transit).
		label: 'Itinerary',
		property: 'itinerary',
		primaryLabel: 'Legs',
		primaryPlaceholder: 'e.g., ORD → DTW 9:05am, DTW → YYZ 1:20pm',
		contentLabel: 'Notes (optional)',
		submitLabel: 'Post itinerary',
	},
	question: {
		// Post Kinds: h-entry `question` property (p-question) — a post
		// soliciting answers or replies. The question itself is the
		// primary value; details go in the optional body.
		label: 'Question',
		property: 'question',
		primaryLabel: "What's your question?",
		primaryPlaceholder: 'e.g., Best static-site host in 2026?',
		contentLabel: 'Details (optional)',
		submitLabel: 'Post question',
	},
};

const VARIANT_ORDER: Variant[] = ['mood', 'weather', 'sleep', 'trip', 'itinerary', 'question'];

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering-endpoint' }
	| { kind: 'posting' }
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

/**
 * Drain the share-target stash only when this Life tab is the intended
 * target (F6 dispatch with mode=mood/weather/sleep/trip/itinerary/question).
 * Mirrors the Note / Reply / Doing drain pattern so an unrelated tab never
 * consumes it — and so a Life-tagged stash doesn't linger and re-apply.
 */
function consume_share_target_for_life(): { variant?: Variant; content?: string } {
	const data = peek_share_target();
	if (!data || data.tab !== 'life') return {};
	consume_share_target();
	const out: { variant?: Variant; content?: string } = {};
	if (data.lifeVariant) out.variant = data.lifeVariant;
	if (data.content) out.content = data.content;
	return out;
}

export function LifeMode({ token, micropubEnv, composerConfig }: LifeModeProps) {
	const initial_share = consume_share_target_for_life();
	const [variant, setVariant] = useState<Variant>(initial_share.variant ?? 'mood');
	const [title, setTitle] = useState('');
	const [primary_value, setPrimaryValue] = useState('');
	const [content, setContent] = useState(initial_share.content ?? '');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues, resetMoreValues] = useMorePanelValues(composerConfig);
	const [more_open, setMoreOpen] = useMoreOpen();
	const [picked_location, setPickedLocation] = useState<GeocodeResult | null>(null);
	const [venue_name, setVenueName] = useState('');

	const config = VARIANTS[variant];
	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_primary = primary_value.trim();
		const trimmed_content = content.trim();
		if (!trimmed_primary) return;

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering-endpoint' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			const trimmed_venue = venue_name.trim();
			const trimmed_title = title.trim();
			const base: HEntryProperties = {
				[config.property]: trimmed_primary,
				// Life property names equal their Post Kinds kind slugs
				// one-for-one, so the property doubles as the hint value.
				...pkiw_kind_hint(composerConfig, config.property),
				...(trimmed_title ? { name: trimmed_title } : {}),
				...(trimmed_content ? { content: trimmed_content } : {}),
				...(picked_location
					? { location: geo_uri(picked_location.lat, picked_location.lon) }
					: {}),
				...(trimmed_venue ? { 'mp-place-name': trimmed_venue } : {}),
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
				setTitle('');
				setPrimaryValue('');
				setContent('');
				resetMoreValues();
				setPickedLocation(null);
				setVenueName('');
				return;
			} catch (post_err) {
				if (is_network_error(post_err)) {
					try {
						await enqueue({
							source: 'life',
							properties,
							accessToken: token.accessToken,
							micropubEndpoint: micropub_endpoint,
						});
						setStatus({ kind: 'queued' });
						setPrimaryValue('');
						setContent('');
						resetMoreValues();
						return;
					} catch (_q_err) {
						// Queue write failed; surface the original post error below.
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
	const can_submit = !!primary_value.trim();

	return (
		<section class="outpost-card" aria-labelledby="outpost-life-mode-title">
			<h2 id="outpost-life-mode-title" class="outpost-card__title">
				Life
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit} novalidate>
				<fieldset class="outpost-variant-picker">
					<legend class="outpost-label">Type</legend>
					{VARIANT_ORDER.map((id) => (
						<label key={id} class="outpost-radio">
							<input
								type="radio"
								name="outpost-life-variant"
								value={id}
								checked={variant === id}
								onChange={(): void => {
									setVariant(id);
									// Clear any lingering success/error banner — it
									// belongs to the previous variant's submission.
									setStatus({ kind: 'idle' });
								}}
								disabled={submitting}
							/>
							<span>{VARIANTS[id].label}</span>
						</label>
					))}
				</fieldset>

				<label class="outpost-label" for="outpost-life-title">
					Title <span class="outpost-required">(optional)</span>
				</label>
				<input
					id="outpost-life-title"
					class="outpost-input"
					type="text"
					value={title}
					onInput={(event): void => setTitle((event.target as HTMLInputElement).value)}
					autoCapitalize="sentences"
					autoComplete="off"
					disabled={submitting}
				/>

				<label class="outpost-label" for="outpost-life-primary">
					{config.primaryLabel}
				</label>
				<input
					id="outpost-life-primary"
					class="outpost-input"
					type="text"
					value={primary_value}
					onInput={(event): void =>
						setPrimaryValue((event.target as HTMLInputElement).value)
					}
					placeholder={config.primaryPlaceholder}
					required
					disabled={submitting}
				/>

				<div class="outpost-textarea-row">
					<label class="outpost-label" for="outpost-life-content">
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
					id="outpost-life-content"
					class="outpost-textarea"
					rows={3}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting}
				/>

				<GeocodePicker
					idPrefix="outpost-life"
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
							micropubEndpoint={endpoint}
							{...(micropubEnv ? { micropubEnv } : {})}
							xfnTargetUrl={null}
							disabled={submitting}
							idPrefix="outpost-life"
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
