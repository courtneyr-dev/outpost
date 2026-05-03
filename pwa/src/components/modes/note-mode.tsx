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
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { peek_share_target, consume_share_target } from '../../lib/share-target';
import { VoiceButton } from '../voice-button';
import { GeocodePicker } from '../geocode-picker';
import { geo_uri, type GeocodeResult } from '../../lib/geocode';
import { CharCounter } from '../char-counter';
import { Drawer } from '../drawer';
import {
	MorePanel,
	empty_more_values,
	merge_more_values,
	type MorePanelValues,
} from '../more-panel';

/**
 * Note mode — the unified writing tab.
 *
 * Phase C5c merges the previous Article tab into Note as a fifth
 * variant. Five radios at the top of the tab pick the post style; the
 * rest of the form adapts to what that style needs (title field for
 * Article, otherwise just content textarea):
 *
 *   - Note    — default. h-entry with content only. Format auto-inferred
 *               by the bridge (status for ≤ 280 chars, standard else).
 *   - Status  — content only. mp-post-format=status forced.
 *   - Aside   — content only. mp-post-format=aside forced. (The bridge
 *               never auto-infers aside, so this variant is the only way
 *               to get one.)
 *   - Article — title + content. mp-post-format=standard forced. Title
 *               is the IndieWeb structural marker that distinguishes an
 *               article from a note.
 *   - Quote   — content only. mp-post-format=quote forced. The Post
 *               Kinds plugin auto-classifies as Quote kind once the
 *               format taxonomy is set.
 *
 * Why one tab instead of two: every other multi-shape post kind in
 * Outpost (Reply with 6 variants, Doing with 5) lives under a single
 * tab. Splitting Article off was internally inconsistent, and most
 * mobile composing is short — the tab strip drops from 5 to 4 and
 * each tab gets ~25% more touch surface.
 *
 * Precedence: variant.postFormat sets the default `mp-post-format` on
 * the base h-entry. If the user picks a different format in the More
 * pull-out, that wins (last-write in merge_more_values).
 *
 * Endpoint caching: discovers the micropub endpoint on first post,
 * holds it in component state for the session.
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
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

type Variant = 'note' | 'status' | 'aside' | 'article' | 'quote';

interface VariantConfig {
	label: string;
	heading: string;
	submitLabel: string;
	postFormat: 'status' | 'aside' | 'standard' | 'quote' | null;
	requiresTitle: boolean;
	contentLabel: string;
	contentRows: number;
}

const VARIANTS: Record<Variant, VariantConfig> = {
	note: {
		label: 'Note',
		heading: 'Note',
		submitLabel: 'Post note',
		postFormat: null,
		requiresTitle: false,
		contentLabel: "What's on your mind?",
		contentRows: 5,
	},
	status: {
		label: 'Status',
		heading: 'Status',
		submitLabel: 'Post status',
		postFormat: 'status',
		requiresTitle: false,
		contentLabel: "What's happening?",
		contentRows: 4,
	},
	aside: {
		label: 'Aside',
		heading: 'Aside',
		submitLabel: 'Post aside',
		postFormat: 'aside',
		requiresTitle: false,
		contentLabel: 'Quick aside',
		contentRows: 4,
	},
	article: {
		label: 'Article',
		heading: 'Article',
		submitLabel: 'Post article',
		postFormat: 'standard',
		requiresTitle: true,
		contentLabel: 'Body',
		contentRows: 12,
	},
	quote: {
		label: 'Quote',
		heading: 'Quote',
		submitLabel: 'Post quote',
		postFormat: 'quote',
		requiresTitle: false,
		contentLabel: 'Quote text',
		contentRows: 5,
	},
};

const VARIANT_ORDER: Variant[] = ['article', 'note', 'status', 'aside', 'quote'];

/**
 * Read share-target intake if it's tagged for the Post tab. Drains
 * the stash only on tab match — peek-then-consume so the parallel
 * ReplyMode mount can still see its own data.
 */
function consume_share_target_for_note(): {
	variant?: Variant;
	title?: string;
	content?: string;
} | null {
	const data = peek_share_target();
	if (!data || data.tab !== 'note') return null;
	consume_share_target(); // clear the stash now that we're claiming it
	const out: { variant?: Variant; title?: string; content?: string } = {};
	if (data.variant && (['note', 'article', 'status', 'aside', 'quote'] as Variant[]).includes(data.variant)) {
		out.variant = data.variant;
	}
	if (data.title) out.title = data.title;
	if (data.content) out.content = data.content;
	return out;
}

export function NoteMode({ token, tokenStore, micropubEnv, composerConfig }: NoteModeProps) {
	// Web Share Target intake (Phase E0): when this tab is the share
	// destination, consume the stashed payload as the initial state.
	// One-shot — sessionStorage clears on read.
	const initial_share = consume_share_target_for_note();

	// Variant precedence: share-target intake (when posting from a share)
	// > site-admin default (Outpost_Settings → defaultPostVariant)
	// > hard-coded 'article'.
	const initial_variant: Variant =
		initial_share?.variant ??
		(composerConfig?.siteSettings?.defaultPostVariant as Variant | undefined) ??
		'article';
	const [variant, setVariant] = useState<Variant>(initial_variant);
	const [title, setTitle] = useState(initial_share?.title ?? '');
	const [content, setContent] = useState(initial_share?.content ?? '');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());
	// Optional location attached to the h-entry — populated by the
	// GeocodePicker. Two parts:
	//   - venue_name: free-text venue label, sent as `mp-place-name`.
	//   - picked_location: optional OSM coordinates, sent as
	//     `location: geo:lat,lon` per RFC 5870.
	// Either can be set independently. User can attach just a venue name
	// ("the gym"), just coordinates, or both.
	const [picked_location, setPickedLocation] = useState<GeocodeResult | null>(null);
	const [venue_name, setVenueName] = useState('');

	const [more_open, setMoreOpen] = useState(false);
	const config = VARIANTS[variant];
	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';

	const handle_signout = async (event: Event): Promise<void> => {
		event.preventDefault();
		await clear_token(tokenStore);
		window.location.reload();
	};

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_content = content.trim();
		const trimmed_title = title.trim();
		if (!trimmed_content) return;
		if (config.requiresTitle && !trimmed_title) return;

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			setStatus({ kind: 'posting' });
			const trimmed_venue = venue_name.trim();
			const base: HEntryProperties = {
				content: trimmed_content,
				// h-entry name (post_title) is now sent for ALL variants
				// when filled, not just Article. Lets WordPress admin lists,
				// search, and RSS treat the post as fully-titled even when
				// the theme renders the variant without a heading.
				...(trimmed_title ? { name: trimmed_title } : {}),
				...(config.postFormat ? { 'mp-post-format': config.postFormat } : {}),
				...(picked_location
					? { location: geo_uri(picked_location.lat, picked_location.lon) }
					: {}),
				...(trimmed_venue ? { 'mp-place-name': trimmed_venue } : {}),
			};
			const properties = merge_more_values(base, more_values);

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
				setContent('');
				setTitle('');
				setMoreValues(empty_more_values());
				setPickedLocation(null);
				setVenueName('');
				return;
			} catch (post_err) {
				if (is_network_error(post_err)) {
					try {
						await enqueue({
							source: 'note',
							properties,
							accessToken: token.accessToken,
							micropubEndpoint: micropub_endpoint,
						});
						setStatus({ kind: 'queued' });
						setContent('');
						setTitle('');
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

	const submitting = status.kind === 'discovering' || status.kind === 'posting';
	const can_submit =
		!!content.trim() && (!config.requiresTitle || !!title.trim()) && !submitting;
	const button_label =
		status.kind === 'discovering'
			? 'Finding endpoint…'
			: status.kind === 'posting'
				? 'Posting…'
				: config.submitLabel;

	const textarea_class =
		config.contentRows >= 10 ? 'outpost-textarea outpost-textarea--tall' : 'outpost-textarea';

	return (
		<section class="outpost-card" aria-labelledby="outpost-note-mode-title">
			<h2 id="outpost-note-mode-title" class="outpost-card__title">
				{config.heading}
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code> · scope <code>{token.scope || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit} novalidate>
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

				{/* Title is always shown — Outpost stores a `name` (post_title)
				 *   on every post even when the front-end theme wouldn't
				 *   render it. WordPress relies on post_title for the
				 *   admin list, search, RSS, and many third-party plugins,
				 *   so empty titles are second-class data. Article variant
				 *   keeps `required`; the others mark the field optional. */}
				<label class="outpost-label" for="outpost-note-title">
					Title{' '}
					<span class="outpost-required">
						{config.requiresTitle ? '(required)' : '(optional)'}
					</span>
				</label>
				<input
					id="outpost-note-title"
					class="outpost-input"
					type="text"
					value={title}
					onInput={(event): void =>
						setTitle((event.target as HTMLInputElement).value)
					}
					autoCapitalize="sentences"
					spellcheck={true}
					disabled={submitting}
					required={config.requiresTitle}
					autoComplete="off"
				/>

				<div class="outpost-textarea-row">
					<label class="outpost-label" for="outpost-note-content">
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
					id="outpost-note-content"
					class={textarea_class}
					rows={config.contentRows}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting}
					required
				/>
				<CharCounter
					value={content}
					{...(more_values.syndicateTo.some((u) => /brid\.gy\/publish\/twitter/.test(u))
						? { limit: 280 }
						: {})}
				/>
				{variant === 'article' && (
					<p class="outpost-help">
						Markdown and HTML pass through as-is. Your site renders them based on
						its own filters (Jetpack Markdown, WP-Markdown, or plain `wpautop`).
					</p>
				)}

				<GeocodePicker
					idPrefix="outpost-note"
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
							disabled={submitting}
							idPrefix="outpost-note"
						/>
					</Drawer>
				)}

				<div class="outpost-form-actions">
					<button
						class="outpost-button"
						type="submit"
						disabled={!can_submit}
					>
						{button_label}
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
