import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_note,
	MicropubError,
	type MicropubEnvironment,
} from '../lib/micropub';
import { clear_token, type StoredToken, type TokenStoreEnvironment } from '../lib/token-store';

/**
 * Minimal note-posting form.
 *
 * B1's user-visible surface — proves the IndieAuth → Micropub round-trip
 * works end-to-end on real staging. Replaces the B0b ComposerPlaceholder.
 *
 * Phase C lands the actual composer modes (Note, Reply, Photo, Listen
 * group, Article); this form is the foundation NoteMode will extend.
 *
 * Endpoint caching: discovers the micropub endpoint on first post, holds
 * it in component state for subsequent posts within the same mount.
 * Re-mounts (sign-out+in, page reload, A2HS launch) re-discover; the cost
 * is one extra HTTP request per session, traded for keeping the IDB
 * schema stable. B1c+ may persist micropub_endpoint in the token meta
 * if discovery latency becomes a UX issue.
 */

export interface NoteFormProps {
	token: StoredToken;
	tokenStore: TokenStoreEnvironment;
	micropubEnv?: MicropubEnvironment;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering' }
	| { kind: 'posting' }
	| { kind: 'posted'; location: string }
	| { kind: 'error'; message: string };

export function NoteForm({ token, tokenStore, micropubEnv }: NoteFormProps) {
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);

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
			const result = await post_note(
				{
					content: trimmed,
					accessToken: token.accessToken,
					micropubEndpoint: micropub_endpoint,
				},
				micropubEnv,
			);
			setStatus({ kind: 'posted', location: result.location });
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

	const submitting = status.kind === 'discovering' || status.kind === 'posting';
	const button_label =
		status.kind === 'discovering'
			? 'Finding endpoint…'
			: status.kind === 'posting'
				? 'Posting…'
				: 'Post note';

	return (
		<section class="outpost-card" aria-labelledby="outpost-note-form-title">
			<h1 id="outpost-note-form-title" class="outpost-card__title">
				Post a note
			</h1>
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
					</p>
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
