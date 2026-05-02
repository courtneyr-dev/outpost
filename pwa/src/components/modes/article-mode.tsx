import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type MicropubEnvironment,
} from '../../lib/micropub';
import type { StoredToken } from '../../lib/token-store';
import type { ComposerConfig } from '../../lib/composer-config';
import {
	MorePanel,
	empty_more_values,
	merge_more_values,
	type MorePanelValues,
} from '../more-panel';

/**
 * Article mode — long-form posts (h-entry with both `name` and `content`).
 *
 * The IndieWeb shape distinction: an h-entry with a `name` property IS an
 * article; one without is a note. So the title field isn't decorative —
 * it's the structural marker that makes this a different post kind from
 * the Note tab.
 *
 * Editor surface: a plain textarea, not a Block Editor instance. Two
 * reasons: the Block Editor is unwieldy on mobile (touch targets, popover
 * positioning, sidebar collapse), and the IndieWeb-typical Article author
 * already writes in markdown or HTML. Outpost stays neutral — sends raw
 * content. WordPress then runs `wpautop` + `the_content` filter on it; if
 * the site has Jetpack Markdown or WP-Markdown active, markdown gets
 * converted server-side. If not, paragraphs still flow correctly via
 * `wpautop`.
 *
 * Both fields are required. An article without a title is a note; without
 * body content there's nothing to publish. Submit is disabled until both
 * have non-whitespace content.
 *
 * Same status-state machine as Note / Reply / Photo / Listen
 * (idle → discovering-endpoint → posting → posted | error).
 */

export interface ArticleModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering-endpoint' }
	| { kind: 'posting' }
	| { kind: 'posted'; location: string }
	| { kind: 'error'; message: string };

export function ArticleMode({ token, micropubEnv, composerConfig }: ArticleModeProps) {
	const [title, setTitle] = useState('');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());

	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_title = title.trim();
		const trimmed_content = content.trim();
		if (!trimmed_title || !trimmed_content) return;

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering-endpoint' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			setStatus({ kind: 'posting' });
			const properties = merge_more_values(
				{
					name: trimmed_title,
					content: trimmed_content,
				},
				more_values,
			);
			const result = await post_h_entry(
				{
					properties,
					accessToken: token.accessToken,
					micropubEndpoint: micropub_endpoint,
				},
				micropubEnv,
			);
			setStatus({ kind: 'posted', location: result.location });
			setTitle('');
			setContent('');
			setMoreValues(empty_more_values());
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
				: 'Post article';
	const can_submit = !!title.trim() && !!content.trim();

	return (
		<section class="outpost-card" aria-labelledby="outpost-article-mode-title">
			<h2 id="outpost-article-mode-title" class="outpost-card__title">
				Article
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit}>
				<label class="outpost-label" for="outpost-article-title">
					Title
				</label>
				<input
					id="outpost-article-title"
					class="outpost-input"
					type="text"
					value={title}
					onInput={(event): void => setTitle((event.target as HTMLInputElement).value)}
					autoCapitalize="sentences"
					spellcheck={true}
					disabled={submitting}
					required
				/>

				<label class="outpost-label" for="outpost-article-content">
					Body
				</label>
				<textarea
					id="outpost-article-content"
					class="outpost-textarea outpost-textarea--tall"
					rows={12}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting}
					required
				/>
				<p class="outpost-help">
					Markdown and HTML pass through as-is. Your site renders them based on its
					own filters (Jetpack Markdown, WP-Markdown, or plain `wpautop`).
				</p>

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

				{composerConfig && (
					<MorePanel
						token={token}
						composerConfig={composerConfig}
						values={more_values}
						onChange={setMoreValues}
						micropubEndpoint={endpoint}
						{...(micropubEnv ? { micropubEnv } : {})}
						disabled={submitting}
						idPrefix="outpost-article"
					/>
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
