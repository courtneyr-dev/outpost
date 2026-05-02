import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type MicropubEnvironment,
} from '../../lib/micropub';
import { clear_token, type StoredToken, type TokenStoreEnvironment } from '../../lib/token-store';
import type { ComposerConfig } from '../../lib/composer-config';
import {
	MorePanel,
	empty_more_values,
	merge_more_values,
	type MorePanelValues,
} from '../more-panel';

/**
 * Note mode — short-form posting (h-entry with content, no name).
 *
 * The mode that lands first in Phase C: takes B1's NoteForm and slots it
 * into the tab framework. Visual + behavioral shape is unchanged from
 * B1; only the heading element shifts from `<h1>` (page-level) to `<h2>`
 * (panel-level under the tablist).
 *
 * Endpoint caching: discovers the micropub endpoint on first post,
 * holds it in component state for the session. Re-mounts (sign-out+in,
 * page reload, A2HS launch) re-discover. State persists across tab
 * switches because the parent ComposerTabs renders all panels eagerly
 * and toggles visibility via the `hidden` attribute.
 *
 * IndieWeb shape: posts an h-entry with `content` only — no `name`
 * property. The Article mode (Phase C5) handles titled long-form posts.
 */

export interface NoteModeProps {
	token: StoredToken;
	tokenStore: TokenStoreEnvironment;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering' }
	| { kind: 'posting' }
	| { kind: 'posted'; location: string }
	| { kind: 'error'; message: string };

export function NoteMode({ token, tokenStore, micropubEnv, composerConfig }: NoteModeProps) {
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());

	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';

	const handle_signout = async (event: Event): Promise<void> => {
		event.preventDefault();
		await clear_token(tokenStore);
		window.location.reload();
	};

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed = content.trim();
		if (!trimmed) return;

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			setStatus({ kind: 'posting' });
			const properties = merge_more_values({ content: trimmed }, more_values);
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

	const submitting = status.kind === 'discovering' || status.kind === 'posting';
	const button_label =
		status.kind === 'discovering'
			? 'Finding endpoint…'
			: status.kind === 'posting'
				? 'Posting…'
				: 'Post note';

	return (
		<section class="outpost-card" aria-labelledby="outpost-note-mode-title">
			<h2 id="outpost-note-mode-title" class="outpost-card__title">
				Note
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code> · scope <code>{token.scope || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit}>
				<label class="outpost-label" for="outpost-note-content">
					What's on your mind?
				</label>
				<textarea
					id="outpost-note-content"
					class="outpost-textarea"
					rows={5}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting}
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
						idPrefix="outpost-note"
					/>
				)}

				<div class="outpost-form-actions">
					<button
						class="outpost-button"
						type="submit"
						disabled={submitting || !content.trim()}
					>
						{button_label}
					</button>
					<button
						class="outpost-button outpost-button--secondary"
						type="button"
						onClick={handle_signout}
						disabled={submitting}
					>
						Sign out
					</button>
				</div>
			</form>
		</section>
	);
}
