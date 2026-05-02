import { useEffect, useState } from 'preact/hooks';
import type { ComposerConfig } from '../lib/composer-config';
import { discover_syndication_targets, type SyndicationTarget } from '../lib/micropub';
import type { MicropubEnvironment } from '../lib/micropub';
import type { StoredToken } from '../lib/token-store';

/**
 * The "More" pull-out panel.
 *
 * Phase C5. Renders below each mode's main form as a collapsible
 * `<details>` element (zero-JS expand/collapse, native focus/keyboard
 * handling, fully accessible). Inside the panel, fields are conditional
 * on the composer-config response — fields whose backing companion
 * plugin is absent are hidden entirely so the panel doesn't show stale
 * affordances for plugins the user doesn't have.
 *
 * Always-on fields (no companion needed):
 *   - Categories (free-text comma-split → category[])
 *   - Slug (mp-slug)
 *
 * Companion-gated fields:
 *   - Post Format selector (Post Formats for Block Themes)
 *   - Yoast focus keyphrase (Yoast SEO)
 *   - XFN relationship picker (Link Extension for XFN — only shown when
 *     the parent mode passes a `xfnTargetUrl` prop, since rels target a
 *     specific link, not the whole post)
 *   - Syndication target checkboxes (loaded async from the Micropub
 *     ?q=syndicate-to query; gated on the query returning >= 1 target)
 *
 * The Accessibility Checker integration is post-publish (not in this
 * panel) — modes show a "View accessibility report" link in their
 * success message when the plugin is detected.
 *
 * Returns the field values as a `MorePanelValues` object via the
 * `onChange` prop. Modes merge these into their HEntryProperties on
 * submit. Each mode owns when to clear them (after a successful post).
 */

export interface MorePanelValues {
	/** WP post_category terms — autocompleted from existing, or freshly typed. */
	categories: string[];
	/** WP post_tag terms — autocompleted from existing, or freshly typed. */
	tags: string[];
	/** Trimmed slug (or null if empty). */
	slug: string | null;
	/** Selected post format slug, or null when not chosen / unavailable. */
	postFormat: string | null;
	/** Yoast focus keyphrase (trimmed; null when empty). */
	yoastFocusKw: string | null;
	/** Selected XFN rels (subset of composerConfig.xfnRels). */
	xfnRels: string[];
	/** Selected syndication target UIDs (subset of fetched targets). */
	syndicateTo: string[];
}

export const empty_more_values = (): MorePanelValues => ({
	categories: [],
	tags: [],
	slug: null,
	postFormat: null,
	yoastFocusKw: null,
	xfnRels: [],
	syndicateTo: [],
});

export interface MorePanelProps {
	token: StoredToken;
	composerConfig: ComposerConfig;
	values: MorePanelValues;
	onChange: (values: MorePanelValues) => void;
	/**
	 * Discovered Micropub endpoint URL. Required to fetch syndication
	 * targets. Modes that haven't yet discovered the endpoint pass
	 * `null` and the syndication section just hides until they do.
	 */
	micropubEndpoint: string | null;
	micropubEnv?: MicropubEnvironment;
	/**
	 * The reply / target URL the XFN rels apply to. When null the XFN
	 * picker hides (XFN is meaningless without a target link).
	 */
	xfnTargetUrl?: string | null;
	/** Disable inputs while the parent is mid-submit. */
	disabled?: boolean;
	/** DOM-id prefix so multiple modes can render the panel without id collisions. */
	idPrefix: string;
}

export function MorePanel(props: MorePanelProps) {
	const {
		token,
		composerConfig,
		values,
		onChange,
		micropubEndpoint,
		micropubEnv,
		xfnTargetUrl,
		disabled,
		idPrefix,
	} = props;

	const [syndication_targets, setSyndicationTargets] = useState<SyndicationTarget[]>([]);
	const [syndication_status, setSyndicationStatus] = useState<
		'idle' | 'loading' | 'loaded' | 'error'
	>('idle');

	const post_formats_active = composerConfig.companions['post-formats'] === 'active';
	const post_formats_list = composerConfig.postFormats;
	const yoast_active = composerConfig.companions['yoast'] === 'active';
	const xfn_active = composerConfig.companions['xfn'] === 'active';

	// Lazy-load syndication targets when the panel knows the endpoint.
	useEffect(() => {
		if (!micropubEndpoint) return;
		if (syndication_status !== 'idle') return;
		let cancelled = false;
		setSyndicationStatus('loading');
		discover_syndication_targets(micropubEndpoint, token.accessToken, micropubEnv)
			.then((targets) => {
				if (cancelled) return;
				setSyndicationTargets(targets);
				setSyndicationStatus('loaded');
			})
			.catch(() => {
				if (cancelled) return;
				setSyndicationStatus('error');
			});
		return (): void => {
			cancelled = true;
		};
	}, [micropubEndpoint, syndication_status, token.accessToken, micropubEnv]);

	const split_csv = (raw: string): string[] =>
		raw
			.split(',')
			.map((s) => s.trim())
			.filter((s) => s.length > 0);

	const handle_categories = (raw: string): void => {
		onChange({ ...values, categories: split_csv(raw) });
	};

	const handle_tags = (raw: string): void => {
		onChange({ ...values, tags: split_csv(raw) });
	};

	const handle_slug = (raw: string): void => {
		const trimmed = raw.trim();
		onChange({ ...values, slug: trimmed.length > 0 ? trimmed : null });
	};

	const handle_post_format = (raw: string): void => {
		onChange({ ...values, postFormat: raw === '' ? null : raw });
	};

	const handle_focuskw = (raw: string): void => {
		const trimmed = raw.trim();
		onChange({ ...values, yoastFocusKw: trimmed.length > 0 ? trimmed : null });
	};

	const toggle_xfn_rel = (rel: string): void => {
		const next = values.xfnRels.includes(rel)
			? values.xfnRels.filter((r) => r !== rel)
			: [...values.xfnRels, rel];
		onChange({ ...values, xfnRels: next });
	};

	const toggle_syndication = (uid: string): void => {
		const next = values.syndicateTo.includes(uid)
			? values.syndicateTo.filter((u) => u !== uid)
			: [...values.syndicateTo, uid];
		onChange({ ...values, syndicateTo: next });
	};

	const categories_display = values.categories.join(', ');
	const tags_display = values.tags.join(', ');
	const slug_display = values.slug ?? '';
	const post_format_display = values.postFormat ?? '';
	const focuskw_display = values.yoastFocusKw ?? '';

	return (
		<details class="outpost-more-panel">
			<summary class="outpost-more-panel__summary">More options</summary>
			<div class="outpost-more-panel__body">
				<label class="outpost-label" for={`${idPrefix}-categories`}>
					Categories
				</label>
				<input
					id={`${idPrefix}-categories`}
					class="outpost-input"
					type="text"
					value={categories_display}
					onInput={(e): void =>
						handle_categories((e.target as HTMLInputElement).value)
					}
					list={`${idPrefix}-categories-list`}
					placeholder="Existing or new — comma separated"
					disabled={disabled}
				/>
				<datalist id={`${idPrefix}-categories-list`}>
					{composerConfig.existingCategories.map((cat) => (
						<option key={cat.slug} value={cat.name} />
					))}
				</datalist>

				<label class="outpost-label" for={`${idPrefix}-tags`}>
					Tags
				</label>
				<input
					id={`${idPrefix}-tags`}
					class="outpost-input"
					type="text"
					value={tags_display}
					onInput={(e): void => handle_tags((e.target as HTMLInputElement).value)}
					list={`${idPrefix}-tags-list`}
					placeholder="Existing or new — comma separated"
					disabled={disabled}
				/>
				<datalist id={`${idPrefix}-tags-list`}>
					{composerConfig.existingTags.map((tag) => (
						<option key={tag.slug} value={tag.name} />
					))}
				</datalist>

				<label class="outpost-label" for={`${idPrefix}-slug`}>
					Slug
				</label>
				<input
					id={`${idPrefix}-slug`}
					class="outpost-input"
					type="text"
					value={slug_display}
					onInput={(e): void => handle_slug((e.target as HTMLInputElement).value)}
					placeholder="custom-permalink-slug"
					disabled={disabled}
				/>

				{post_formats_active && post_formats_list && post_formats_list.length > 0 && (
					<>
						<label class="outpost-label" for={`${idPrefix}-post-format`}>
							Post Format
						</label>
						<select
							id={`${idPrefix}-post-format`}
							class="outpost-input"
							value={post_format_display}
							onChange={(e): void =>
								handle_post_format((e.target as HTMLSelectElement).value)
							}
							disabled={disabled}
						>
							<option value="">Auto (from post kind)</option>
							{post_formats_list.map((fmt) => (
								<option key={fmt} value={fmt}>
									{fmt}
								</option>
							))}
						</select>
					</>
				)}

				{yoast_active && (
					<>
						<label class="outpost-label" for={`${idPrefix}-focuskw`}>
							Focus keyphrase (Yoast SEO)
						</label>
						<input
							id={`${idPrefix}-focuskw`}
							class="outpost-input"
							type="text"
							value={focuskw_display}
							onInput={(e): void =>
								handle_focuskw((e.target as HTMLInputElement).value)
							}
							maxLength={191}
							disabled={disabled}
						/>
					</>
				)}

				{xfn_active && xfnTargetUrl && (
					<fieldset class="outpost-xfn-picker">
						<legend class="outpost-label">
							Relationship with <code>{xfnTargetUrl}</code>
						</legend>
						{composerConfig.xfnRels.map((rel) => (
							<label key={rel} class="outpost-checkbox">
								<input
									type="checkbox"
									checked={values.xfnRels.includes(rel)}
									onChange={(): void => toggle_xfn_rel(rel)}
									disabled={disabled}
								/>
								<span>{rel}</span>
							</label>
						))}
					</fieldset>
				)}

				{syndication_status === 'loaded' && syndication_targets.length > 0 && (
					<fieldset class="outpost-syndication-picker">
						<legend class="outpost-label">Send to</legend>
						{syndication_targets.map((target) => (
							<label key={target.uid} class="outpost-checkbox">
								<input
									type="checkbox"
									checked={values.syndicateTo.includes(target.uid)}
									onChange={(): void => toggle_syndication(target.uid)}
									disabled={disabled}
								/>
								<span>{target.name}</span>
							</label>
						))}
					</fieldset>
				)}

				{syndication_status === 'error' && (
					<p class="outpost-help">
						Couldn't load syndication targets. Posting still works; targets just
						won't apply.
					</p>
				)}
			</div>
		</details>
	);
}

/**
 * Merge MorePanelValues into an HEntryProperties shape.
 *
 * Per the exactOptionalPropertyTypes pattern (B0a Locked Decision),
 * we only include keys whose values are non-null so undefined never
 * lands on an optional field.
 */
export function merge_more_values<T>(
	properties: T,
	values: MorePanelValues,
	xfnTargetUrl?: string | null,
): T {
	const merged: Record<string, unknown> = { ...(properties as Record<string, unknown>) };
	// Tags use Micropub's standard `category[]` property — David Shanske's
	// Micropub plugin defaults to mapping that to post_tag taxonomy.
	if (values.tags.length > 0) {
		merged['category'] = values.tags;
	}
	// Outpost-specific `mp-categories[]` — the bridge calls
	// wp_set_post_categories() with auto-create so users can type new
	// category names from the composer.
	if (values.categories.length > 0) {
		merged['mp-categories'] = values.categories;
	}
	if (values.slug) {
		merged['mp-slug'] = values.slug;
	}
	if (values.postFormat) {
		merged['mp-post-format'] = values.postFormat;
	}
	if (values.yoastFocusKw) {
		merged['mp-yoast-focuskw'] = values.yoastFocusKw;
	}
	if (values.xfnRels.length > 0 && xfnTargetUrl) {
		merged['mp-xfn'] = values.xfnRels;
		merged['mp-xfn-target'] = xfnTargetUrl;
	}
	if (values.syndicateTo.length > 0) {
		merged['mp-syndicate-to'] = values.syndicateTo;
	}
	return merged as T;
}
