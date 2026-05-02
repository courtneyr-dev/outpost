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
	| { kind: 'posted'; location: string }
	| { kind: 'error'; message: string };

export function ListenMode({ token, micropubEnv }: ListenModeProps) {
	const [variant, setVariant] = useState<Variant>('listen');
	const [target_url, setTargetUrl] = useState('');
	const [place_name, setPlaceName] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);

	const config = VARIANTS[variant];

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

			const properties: HEntryProperties = {
				[config.property]: trimmed_url,
				...(trimmed_content ? { content: trimmed_content } : {}),
				...(variant === 'checkin' && trimmed_name ? { name: trimmed_name } : {}),
			};

			setStatus({ kind: 'posting' });
			const result = await post_h_entry(
				{
					properties,
					accessToken: token.accessToken,
					micropubEndpoint: micropub_endpoint,
				},
				micropubEnv,
			);
			setStatus({ kind: 'posted', location: result.location });
			setTargetUrl('');
			setPlaceName('');
			setContent('');
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

				<label class="outpost-label" for="outpost-listen-content">
					{config.contentLabel}
				</label>
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
						Posted to{' '}
						<a href={status.location} target="_blank" rel="noopener noreferrer">
							{status.location}
						</a>
					</p>
				)}

				<div class="outpost-form-actions">
					<button
						class="outpost-button"
						type="submit"
						disabled={submitting || !can_submit}
					>
						{submit_label}
					</button>
				</div>
			</form>
		</section>
	);
}
