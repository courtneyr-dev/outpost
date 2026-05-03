import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from '../../lib/micropub';
import { is_safe_http_url } from '../../lib/url-validation';
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

type Variant = 'listen' | 'watch' | 'read' | 'play' | 'checkin';

type VariantProperty = 'listen-of' | 'watch-of' | 'read-of' | 'play-of' | 'location';

interface VariantConfig {
	label: string;
	property: VariantProperty;
	targetLabel: string;
	contentLabel: string;
	submitLabel: string;
}

const VARIANTS: Record<Variant, VariantConfig> = {
	listen: {
		label: 'Listen',
		property: 'listen-of',
		targetLabel: 'Track or album URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post listen',
	},
	watch: {
		label: 'Watch',
		property: 'watch-of',
		targetLabel: 'Movie or show URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post watch',
	},
	read: {
		label: 'Read',
		property: 'read-of',
		targetLabel: 'Book URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post read',
	},
	play: {
		label: 'Play',
		property: 'play-of',
		targetLabel: 'Game URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post play',
	},
	checkin: {
		label: 'Checkin',
		property: 'location',
		targetLabel: 'Place URL',
		contentLabel: 'Optional note',
		submitLabel: 'Post checkin',
	},
};

const VARIANT_ORDER: Variant[] = ['listen', 'watch', 'read', 'play', 'checkin'];

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
	const [place_name, setPlaceName] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());
	const [more_open, setMoreOpen] = useState(false);

	const config = VARIANTS[variant];
	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';
	const trimmed_target_url_for_xfn = target_url.trim();

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_content = content.trim();
		const trimmed_url = target_url.trim();
		const trimmed_name = place_name.trim();
		if (!trimmed_url) return;
		if (!is_safe_http_url(trimmed_url)) {
			setStatus({ kind: 'error', message: 'URL must be http:// or https://.' });
			return;
		}

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering-endpoint' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			const base: HEntryProperties = {
				[config.property]: trimmed_url,
				...(trimmed_content ? { content: trimmed_content } : {}),
				...(variant === 'checkin' && trimmed_name ? { name: trimmed_name } : {}),
			};
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
				setPlaceName('');
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
						setPlaceName('');
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
	const can_submit = !!target_url.trim();

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
					type="url"
					value={target_url}
					onInput={(event): void => setTargetUrl((event.target as HTMLInputElement).value)}
					placeholder="https://example.com/…"
					inputMode="url"
					autoComplete="url"
					autoCapitalize="none"
					spellcheck={false}
					required
				/>

				{variant === 'checkin' && (
					<>
						<label class="outpost-label" for="outpost-listen-place-name">
							Place name (optional)
						</label>
						<input
							id="outpost-listen-place-name"
							class="outpost-input"
							type="text"
							value={place_name}
							onInput={(event): void =>
								setPlaceName((event.target as HTMLInputElement).value)
							}
							disabled={submitting}
						/>
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

				{status.kind === 'error' && (
					<div class="outpost-error" role="alert">
						{status.message}
					</div>
				)}

				{status.kind === 'posted' && (
					<p class="outpost-status" aria-live="polite">
						{status.location ? (
							<>
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
							</>
						) : (
							'Posted successfully.'
						)}
					</p>
				)}

				{status.kind === 'queued' && (
					<p class="outpost-status" aria-live="polite">
						Saved for later. Outpost will post this when you&apos;re back online.
					</p>
				)}

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
