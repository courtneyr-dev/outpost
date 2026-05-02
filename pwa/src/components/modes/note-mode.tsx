import { useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
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
 * Phase C5b adds a Status / Aside variant picker so the two most-common
 * mobile post styles are one tap away instead of buried in the More
 * pull-out's Post Format dropdown:
 *
 *   - Note   — default. Lets the bridge's auto-inference pick the
 *              format (status for ≤ 280 chars, standard otherwise).
 *   - Status — forces `mp-post-format=status` regardless of length.
 *   - Aside  — forces `mp-post-format=aside`. The bridge's auto-
 *              inference never picks aside, so this variant is the
 *              only way to mark a post as one.
 *
 * The variant only affects the mp-post-format property; the h-entry
 * shape stays content-only. If the user picks an explicit format in
 * the More pull-out, that wins (precedence in merge_more_values).
 *
 * Endpoint caching: discovers the micropub endpoint on first post,
 * holds it in component state for the session.
 *
 * IndieWeb shape: posts an h-entry with `content` only — no `name`
 * property. The Article mode (Phase C4) handles titled long-form posts.
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

type Variant = 'note' | 'status' | 'aside';

interface VariantConfig {
	label: string;
	heading: string;
	submitLabel: string;
	postFormat: 'status' | 'aside' | null;
}

const VARIANTS: Record<Variant, VariantConfig> = {
	note: { label: 'Note', heading: 'Note', submitLabel: 'Post note', postFormat: null },
	status: {
		label: 'Status',
		heading: 'Status',
		submitLabel: 'Post status',
		postFormat: 'status',
	},
	aside: {
		label: 'Aside',
		heading: 'Aside',
		submitLabel: 'Post aside',
		postFormat: 'aside',
	},
};

const VARIANT_ORDER: Variant[] = ['note', 'status', 'aside'];

export function NoteMode({ token, tokenStore, micropubEnv, composerConfig }: NoteModeProps) {
	const [variant, setVariant] = useState<Variant>('note');
	const [content, setContent] = useState('');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());

	const config = VARIANTS[variant];
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
			const base: HEntryProperties = {
				content: trimmed,
				...(config.postFormat ? { 'mp-post-format': config.postFormat } : {}),
			};
			const properties = merge_more_values(base, more_values);
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
				: config.submitLabel;

	return (
		<section class="outpost-card" aria-labelledby="outpost-note-mode-title">
			<h2 id="outpost-note-mode-title" class="outpost-card__title">
				{config.heading}
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code> · scope <code>{token.scope || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit}>
				<fieldset class="outpost-variant-picker">
					<legend class="outpost-label">Style</legend>
					{VARIANT_ORDER.map((id) => (
						<label key={id} class="outpost-radio">
							<input
								type="radio"
								name="outpost-note-variant"
								value={id}
								checked={variant === id}
								onChange={(): void => setVariant(id)}
								disabled={submitting}
							/>
							<span>{VARIANTS[id].label}</span>
						</label>
					))}
				</fieldset>

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
