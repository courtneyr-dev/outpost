import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type MicropubEnvironment,
} from '../../lib/micropub';
import { fetch_preview, PreviewError, type PreviewResult } from '../../lib/preview';
import { is_safe_http_url } from '../../lib/url-validation';
import type { StoredToken } from '../../lib/token-store';

/**
 * Reply mode — h-entry with content + `in-reply-to`.
 *
 * Phase C1 first variant: plain Reply (Reply, Like, Repost, Bookmark, RSVP,
 * Follow are all the same shape with different property names; C1b extends
 * this component with a sub-mode picker for the others).
 *
 * Optional preview step: user pastes the target URL, optionally clicks
 * "Show preview" to fetch the citation context (page title) via the B2
 * preview endpoint. Preview is informational — user can submit without
 * previewing if they want.
 *
 * Posting: form-encodes the h-entry with `content` and `in-reply-to`,
 * submits to the user's Micropub endpoint with the bearer token. The
 * `discover_micropub_endpoint` step is cached in component state for the
 * session, same as NoteMode.
 */

export interface ReplyModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'fetching-preview' }
	| { kind: 'preview-ready'; preview: PreviewResult }
	| { kind: 'discovering-endpoint' }
	| { kind: 'posting' }
	| { kind: 'posted'; location: string }
	| { kind: 'error'; message: string };

export function ReplyMode({ token, micropubEnv }: ReplyModeProps) {
	const [target_url, setTargetUrl] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [preview, setPreview] = useState<PreviewResult | null>(null);

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
		if (!trimmed_content || !trimmed_url) return;
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

			setStatus({ kind: 'posting' });
			const result = await post_h_entry(
				{
					properties: {
						content: trimmed_content,
						'in-reply-to': trimmed_url,
					},
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
	const submit_label =
		status.kind === 'discovering-endpoint'
			? 'Finding endpoint…'
			: status.kind === 'posting'
				? 'Posting…'
				: 'Post reply';

	return (
		<section class="outpost-card" aria-labelledby="outpost-reply-mode-title">
			<h2 id="outpost-reply-mode-title" class="outpost-card__title">
				Reply
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit}>
				<label class="outpost-label" for="outpost-reply-target">
					In reply to
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
						<small class="outpost-status">Replying to:</small>
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
					Your reply
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
					required
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
						disabled={submitting || fetching_preview || !content.trim() || !target_url.trim()}
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
