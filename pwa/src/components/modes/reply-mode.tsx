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
import type { ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { peek_share_target, consume_share_target } from '../../lib/share-target';
import { VoiceButton } from '../voice-button';
import { CharCounter } from '../char-counter';
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
	composerConfig?: ComposerConfig;
}

type Variant =
	| 'reply'
	| 'like'
	| 'repost'
	| 'bookmark'
	| 'rsvp'
	| 'follow'
	| 'wishlist'
	| 'tag'
	| 'issue';

type VariantProperty =
	| 'in-reply-to'
	| 'like-of'
	| 'repost-of'
	| 'bookmark-of'
	| 'follow-of'
	| 'wishlist-of'
	| 'tag-of'
	| 'issue-of';

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
	rsvp: {
		// RSVP per IndieWeb: h-entry with `in-reply-to` (event URL) + `rsvp`
		// (yes/no/maybe/interested). Submit handler adds the rsvp value.
		label: 'RSVP',
		property: 'in-reply-to',
		contentRequired: false,
		targetLabel: 'Event URL',
		contentLabel: 'Optional note',
		submitLabel: 'Post RSVP',
		previewIntro: 'RSVPing to:',
	},
	follow: {
		// Follow per IndieWeb: h-entry with `follow-of` (target person URL).
		// Spec is contested across servers; some prefer `mention-of`.
		// `follow-of` is the most common convention as of 2026.
		label: 'Follow',
		property: 'follow-of',
		contentRequired: false,
		targetLabel: 'Person or feed URL',
		contentLabel: 'Optional note',
		submitLabel: 'Post follow',
		previewIntro: 'Following:',
	},
	wishlist: {
		// Post Kinds: `wishlist-of` (item URL), with optional commentary.
		// Same shape as Bookmark — only the post-kind label differs.
		label: 'Wishlist',
		property: 'wishlist-of',
		contentRequired: false,
		targetLabel: 'Wishlist item URL',
		contentLabel: 'Why you want it (optional)',
		submitLabel: 'Post wishlist',
		previewIntro: 'Wishing for:',
	},
	tag: {
		// Post Kinds: `tag-of` (URL of the page being tagged). Used as a
		// public bookmark-like signal, often paired with categories so the
		// post itself surfaces under the tag taxonomy.
		label: 'Tag',
		property: 'tag-of',
		contentRequired: false,
		targetLabel: 'URL to tag',
		contentLabel: 'Optional note (use More options → Categories for tags)',
		submitLabel: 'Post tag',
		previewIntro: 'Tagging:',
	},
	issue: {
		// Post Kinds: `issue-of` (URL of the project / page the issue is for).
		// Body holds the issue description.
		label: 'Issue',
		property: 'issue-of',
		contentRequired: true,
		targetLabel: 'Repository or project URL',
		contentLabel: 'Issue description',
		submitLabel: 'Post issue',
		previewIntro: 'Filing issue against:',
	},
};

const VARIANT_ORDER: Variant[] = [
	'reply',
	'like',
	'repost',
	'bookmark',
	'rsvp',
	'follow',
	'wishlist',
	'tag',
	'issue',
];

type RsvpValue = 'yes' | 'no' | 'maybe' | 'interested';
const RSVP_VALUES: RsvpValue[] = ['yes', 'no', 'maybe', 'interested'];

type Status =
	| { kind: 'idle' }
	| { kind: 'fetching-preview' }
	| { kind: 'preview-ready'; preview: PreviewResult }
	| { kind: 'discovering-endpoint' }
	| { kind: 'posting' }
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

function consume_share_target_for_reply(): {
	url?: string;
	title?: string;
	content?: string;
	variant?: Variant;
} | null {
	const data = peek_share_target();
	if (!data || data.tab !== 'reply') return null;
	consume_share_target();
	const out: { url?: string; title?: string; content?: string; variant?: Variant } = {};
	if (data.url) out.url = data.url;
	if (data.title) out.title = data.title;
	if (data.content) out.content = data.content;
	if (
		data.replyVariant &&
		(['reply', 'like', 'repost', 'bookmark', 'rsvp', 'follow'] as Variant[]).includes(
			data.replyVariant as Variant,
		)
	) {
		out.variant = data.replyVariant as Variant;
	}
	return out;
}

export function ReplyMode({ token, micropubEnv, composerConfig }: ReplyModeProps) {
	const initial_share = consume_share_target_for_reply();
	const [variant, setVariant] = useState<Variant>(initial_share?.variant ?? 'reply');
	const [target_url, setTargetUrl] = useState(initial_share?.url ?? '');
	const [title, setTitle] = useState(initial_share?.title ?? '');
	const [content, setContent] = useState(initial_share?.content ?? '');
	const [rsvp_value, setRsvpValue] = useState<RsvpValue>('yes');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [preview, setPreview] = useState<PreviewResult | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());
	const [more_open, setMoreOpen] = useState(false);
	const [picked_location, setPickedLocation] = useState<GeocodeResult | null>(null);
	const [venue_name, setVenueName] = useState('');

	const config = VARIANTS[variant];
	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';
	const trimmed_target_url = target_url.trim();

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

			const trimmed_venue = venue_name.trim();
			const trimmed_title = title.trim();
			const base: HEntryProperties = {
				[config.property]: trimmed_url,
				...(trimmed_title ? { name: trimmed_title } : {}),
				...(trimmed_content ? { content: trimmed_content } : {}),
				...(variant === 'rsvp' ? { rsvp: rsvp_value } : {}),
				...(picked_location
					? { location: geo_uri(picked_location.lat, picked_location.lon) }
					: {}),
				...(trimmed_venue ? { 'mp-place-name': trimmed_venue } : {}),
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
				setContent('');
				setTitle('');
				setTargetUrl('');
				setPickedLocation(null);
				setVenueName('');
				setPreview(null);
				setMoreValues(empty_more_values());
				return;
			} catch (post_err) {
				if (is_network_error(post_err)) {
					try {
						await enqueue({
							source: 'reply',
							properties,
							accessToken: token.accessToken,
							micropubEndpoint: micropub_endpoint,
						});
						setStatus({ kind: 'queued' });
						setContent('');
						setTargetUrl('');
						setPreview(null);
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

			<form class="outpost-form-row" onSubmit={handle_submit} novalidate>
				<fieldset class="outpost-variant-picker">
					<legend class="outpost-label">Type</legend>
					{VARIANT_ORDER.map((id) => (
						<label key={id} class="outpost-radio">
							<input
								type="radio"
								name="outpost-reply-variant"
								value={id}
								checked={variant === id}
								onChange={(): void => {
									setVariant(id);
									// Clear any lingering success/error banner — it
									// belongs to the previous variant's submission.
									setStatus({ kind: 'idle' });
								}}
								disabled={submitting || fetching_preview}
							/>
							<span>{VARIANTS[id].label}</span>
						</label>
					))}
				</fieldset>

				<label class="outpost-label" for="outpost-reply-title">
					Title <span class="outpost-required">(optional)</span>
				</label>
				<input
					id="outpost-reply-title"
					class="outpost-input"
					type="text"
					value={title}
					onInput={(event): void => setTitle((event.target as HTMLInputElement).value)}
					autoCapitalize="sentences"
					autoComplete="off"
					disabled={submitting}
				/>

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

				{variant === 'rsvp' && (
					<fieldset class="outpost-variant-picker">
						<legend class="outpost-label">Response</legend>
						{RSVP_VALUES.map((value) => (
							<label key={value} class="outpost-radio">
								<input
									type="radio"
									name="outpost-rsvp-value"
									value={value}
									checked={rsvp_value === value}
									onChange={(): void => setRsvpValue(value)}
									disabled={submitting || fetching_preview}
								/>
								<span>{value.charAt(0).toUpperCase() + value.slice(1)}</span>
							</label>
						))}
					</fieldset>
				)}

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

				<div class="outpost-textarea-row">
					<label class="outpost-label" for="outpost-reply-content">
						{config.contentLabel}
					</label>
					<VoiceButton
						onTranscript={(text): void =>
							setContent((c) =>
								c.length > 0 && !/\s$/.test(c) ? c + ' ' + text : c + text,
							)
						}
						disabled={submitting || fetching_preview}
					/>
				</div>
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
				<CharCounter
					value={content}
					{...(more_values.syndicateTo.some((u) => /brid\.gy\/publish\/twitter/.test(u))
						? { limit: 280 }
						: {})}
				/>

				<GeocodePicker
					idPrefix="outpost-reply"
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
							'Posted, but the server did not return a link. Check your site to confirm it published.'
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
							xfnTargetUrl={trimmed_target_url || null}
							disabled={submitting || fetching_preview}
							idPrefix="outpost-reply"
						/>
					</Drawer>
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
					{composerConfig && (
						<button
							class="outpost-button outpost-button--secondary"
							type="button"
							onClick={(): void => setMoreOpen(true)}
							disabled={submitting || fetching_preview}
						>
							More options
						</button>
					)}
				</div>
			</form>
		</section>
	);
}
