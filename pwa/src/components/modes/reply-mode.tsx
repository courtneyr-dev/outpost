import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from '../../lib/micropub';
import { fetch_preview, PreviewError, type PreviewResult } from '../../lib/preview';
import { is_safe_http_url } from '../../lib/url-validation';
import type { StoredToken } from '../../lib/token-store';

/**
 * Reply mode — h-entry with a target URL plus optional content.
 *
 * Phase C1 + C1b ship four post kinds that share the same form shape
 * (URL + optional content + target-property name) under a single tab,
 * switched via a radio picker:
 *
 *   - Reply    → in-reply-to  (content REQUIRED)
 *   - Like     → like-of      (content optional)
 *   - Repost   → repost-of    (content optional — annotation/commentary)
 *   - Bookmark → bookmark-of  (content optional — annotation)
 *
 * RSVP and Follow defer to a future C1c — RSVP needs an extra rsvp:
 * yes/no/maybe control; Follow's spec is contested across servers.
 *
 * Optional preview step (Show preview button) runs the B2 preview
 * endpoint to surface citation context (page title) before submission.
 *
 * State persists across tab switches (the parent ComposerTabs renders
 * all panels eagerly and toggles visibility). Variant selection,
 * URL, and content all survive tab switches.
 */

export interface ReplyModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
}

type Variant = 'reply' | 'like' | 'repost' | 'bookmark';

type VariantProperty = 'in-reply-to' | 'like-of' | 'repost-of' | 'bookmark-of';

interface VariantConfig {
	label: string;
	property: VariantProperty;
	contentRequired: boolean;
	targetLabel: string;
	contentLabel: string;
	submitLabel: string;
	previewIntro: string;
}

const VARIANTS: Record<Variant, VariantConfig> = {
	reply: {
		label: 'Reply',
		property: 'in-reply-to',
		contentRequired: true,
		targetLabel: 'In reply to',
		contentLabel: 'Your reply',
		submitLabel: 'Post reply',
		previewIntro: 'Replying to:',
	},
	like: {
		label: 'Like',
		property: 'like-of',
		contentRequired: false,
		targetLabel: 'Like of',
		contentLabel: 'Optional note',
		submitLabel: 'Post like',
		previewIntro: 'Liking:',
	},
	repost: {
		label: 'Repost',
		property: 'repost-of',
		contentRequired: false,
		targetLabel: 'Repost of',
		contentLabel: 'Optional commentary',
		submitLabel: 'Post repost',
		previewIntro: 'Reposting:',
	},
	bookmark: {
		label: 'Bookmark',
		property: 'bookmark-of',
		contentRequired: false,
		targetLabel: 'Bookmark of',
		contentLabel: 'Optional note',
		submitLabel: 'Post bookmark',
		previewIntro: 'Bookmarking:',
	},
};

const VARIANT_ORDER: Variant[] = ['reply', 'like', 'repost', 'bookmark'];

type Status =
	| { kind: 'idle' }
	| { kind: 'fetching-preview' }
	| { kind: 'preview-ready'; preview: PreviewResult }
	| { kind: 'discovering-endpoint' }
	| { kind: 'posting' }
	| { kind: 'posted'; location: string }
	| { kind: 'error'; message: string };

export function ReplyMode({ token, micropubEnv }: ReplyModeProps) {
	const [variant, setVariant] = useState<Variant>('reply');
	const [target_url, setTargetUrl] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [preview, setPreview] = useState<PreviewResult | null>(null);

	const config = VARIANTS[variant];

	const handle_preview = async (event: Event): Promise<void> => {
		event.preventDefault();
		if (!is_safe_http_url(target_url)) {
			setStatus({ kind: 'error', message: 'Target URL must be http:// or https://.' });
			return;
		}
		setStatus({ kind: 'fetching-preview' });
		try {
			const result = await fetch_preview({
				url: target_url,
				accessToken: token.accessToken,
			});
			setPreview(result);
			setStatus({ kind: 'preview-ready', preview: result });
		} catch (err) {
			const message =
				err instanceof PreviewError
					? err.code + ': ' + err.message
					: err instanceof Error
						? err.message
						: 'Unknown error';
			setStatus({ kind: 'error', message });
		}
	};

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_content = content.trim();
		const trimmed_url = target_url.trim();
		if (!trimmed_url) return;
		if (config.contentRequired && !trimmed_content) return;
		if (!is_safe_http_url(trimmed_url)) {
			setStatus({ kind: 'error', message: 'Target URL must be http:// or https://.' });
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
			setContent('');
			setTargetUrl('');
			setPreview(null);
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
	const fetching_preview = status.kind === 'fetching-preview';
	const can_submit =
		!!target_url.trim() && (!config.contentRequired || !!content.trim());
	const submit_label =
		status.kind === 'discovering-endpoint'
			? 'Finding endpoint…'
			: status.kind === 'posting'
				? 'Posting…'
				: config.submitLabel;

	return (
		<section class="outpost-card" aria-labelledby="outpost-reply-mode-title">
			<h2 id="outpost-reply-mode-title" class="outpost-card__title">
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
								name="outpost-reply-variant"
								value={id}
								checked={variant === id}
								onChange={(): void => setVariant(id)}
								disabled={submitting || fetching_preview}
							/>
							<span>{VARIANTS[id].label}</span>
						</label>
					))}
				</fieldset>

				<label class="outpost-label" for="outpost-reply-target">
					{config.targetLabel}
				</label>
				<input
					id="outpost-reply-target"
					class="outpost-input"
					type="url"
					value={target_url}
					onInput={(event): void => setTargetUrl((event.target as HTMLInputElement).value)}
					placeholder="https://example.com/post"
					inputMode="url"
					autoComplete="url"
					autoCapitalize="none"
					spellcheck={false}
					required
				/>

				{preview && (
					<aside class="outpost-citation" aria-label="Preview">
						<small class="outpost-status">{config.previewIntro}</small>
						<p>
							<strong>{preview.title ?? 'Untitled page'}</strong>
							<br />
							<a href={preview.finalUrl} target="_blank" rel="noopener noreferrer">
								{preview.finalUrl}
							</a>
						</p>
					</aside>
				)}

				<label class="outpost-label" for="outpost-reply-content">
					{config.contentLabel}
				</label>
				<textarea
					id="outpost-reply-content"
					class="outpost-textarea"
					rows={5}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting || fetching_preview}
					required={config.contentRequired}
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
						disabled={submitting || fetching_preview || !can_submit}
					>
						{submit_label}
					</button>
					<button
						class="outpost-button outpost-button--secondary"
						type="button"
						onClick={handle_preview}
						disabled={submitting || fetching_preview || !target_url.trim()}
					>
						{fetching_preview ? 'Fetching…' : 'Show preview'}
					</button>
				</div>
			</form>
		</section>
	);
}
