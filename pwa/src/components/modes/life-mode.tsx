import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from '../../lib/micropub';
import type { StoredToken } from '../../lib/token-store';
import type { ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
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
 * Life mode — content-only post kinds: Mood, Weather, Exercise.
 *
 * These post kinds don't reference an external URL; they're personal
 * statements about state of being or activity. Distinct from the Doing tab
 * (which is URL-anchored: listen-of, watch-of, …) and from Note (which is a
 * generic thought-of-the-moment without a typed property).
 *
 * Per Post Kinds:
 *   - Mood     → h-entry `mood` property = textual emotional state.
 *   - Weather  → h-entry `weather` property = textual conditions.
 *   - Exercise → h-entry `exercise` property = textual activity description.
 *
 * Each variant has a primary text input that becomes the property value, plus
 * an optional `content` body for additional context. No URL field.
 */

export interface LifeModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

type Variant = 'mood' | 'weather' | 'exercise';

interface VariantConfig {
	label: string;
	property: 'mood' | 'weather' | 'exercise';
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
	exercise: {
		label: 'Exercise',
		property: 'exercise',
		primaryLabel: 'What activity?',
		primaryPlaceholder: 'e.g., 30-minute walk, 2-mile run',
		contentLabel: 'How did it feel? (optional)',
		submitLabel: 'Post exercise',
	},
};

const VARIANT_ORDER: Variant[] = ['mood', 'weather', 'exercise'];

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering-endpoint' }
	| { kind: 'posting' }
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

export function LifeMode({ token, micropubEnv, composerConfig }: LifeModeProps) {
	const [variant, setVariant] = useState<Variant>('mood');
	const [primary_value, setPrimaryValue] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());
	const [more_open, setMoreOpen] = useState(false);
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
			const base: HEntryProperties = {
				[config.property]: trimmed_primary,
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
				setPrimaryValue('');
				setContent('');
				setMoreValues(empty_more_values());
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
						setMoreValues(empty_more_values());
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
				{config.label}
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
								onChange={(): void => setVariant(id)}
								disabled={submitting}
							/>
							<span>{VARIANTS[id].label}</span>
						</label>
					))}
				</fieldset>

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
