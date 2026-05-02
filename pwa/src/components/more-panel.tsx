import { useEffect, useState } from 'preact/hooks';
import type { ComposerConfig, TermSuggestion } from '../lib/composer-config';
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

	// Bridgy auto-suggest: when the Reply / Doing target URL host matches a
	// known silo, the matching publish target gets surfaced as a separate
	// pre-checked syndication chip. Distinct from the user's Micropub-
	// configured syndicate-to list (which loads async above) so the user
	// can see WHY the chip appeared (it's contextual to the target URL).
	const bridgy_target = ((): SyndicationTarget | null => {
		if (!xfnTargetUrl) return null;
		try {
			const u = new URL(xfnTargetUrl);
			const match = composerConfig.bridgyHostMap[u.host.toLowerCase()];
			return match ? { uid: match.uid, name: match.name } : null;
		} catch (_err) {
			return null;
		}
	})();

	// When a Bridgy target appears for a fresh URL, default to checked.
	useEffect(() => {
		if (!bridgy_target) return;
		if (values.syndicateTo.includes(bridgy_target.uid)) return;
		onChange({
			...values,
			syndicateTo: [...values.syndicateTo, bridgy_target.uid],
		});
		// eslint-disable-next-line react-hooks/exhaustive-deps -- only react to URL host change, not every value mutation
	}, [bridgy_target?.uid]);

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

	// New-term input state — typed name, separate from values until the
	// user explicitly taps "Add" so accidental typing doesn't create
	// terms. Once added, the name moves into values.{categories|tags}
	// and the input clears.
	const [new_category, setNewCategory] = useState('');
	const [new_tag, setNewTag] = useState('');

	const toggle_category = (name: string): void => {
		const next = values.categories.includes(name)
			? values.categories.filter((c) => c !== name)
			: [...values.categories, name];
		onChange({ ...values, categories: next });
	};

	const toggle_tag = (name: string): void => {
		const next = values.tags.includes(name)
			? values.tags.filter((t) => t !== name)
			: [...values.tags, name];
		onChange({ ...values, tags: next });
	};

	const add_new_category = (): void => {
		const trimmed = new_category.trim();
		if (!trimmed) return;
		if (values.categories.includes(trimmed)) {
			setNewCategory('');
			return;
		}
		onChange({ ...values, categories: [...values.categories, trimmed] });
		setNewCategory('');
	};

	const add_new_tag = (): void => {
		const trimmed = new_tag.trim();
		if (!trimmed) return;
		if (values.tags.includes(trimmed)) {
			setNewTag('');
			return;
		}
		onChange({ ...values, tags: [...values.tags, trimmed] });
		setNewTag('');
	};

	const remove_term = (kind: 'categories' | 'tags', name: string): void => {
		onChange({
			...values,
			[kind]: values[kind].filter((t) => t !== name),
		});
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

	const slug_display = values.slug ?? '';
	const post_format_display = values.postFormat ?? '';
	const focuskw_display = values.yoastFocusKw ?? '';

	return (
		<div class="outpost-more-panel__body">
				<TermPicker
					legend="Categories"
					existing={composerConfig.existingCategories}
					selected={values.categories}
					onToggle={toggle_category}
					onRemove={(name): void => remove_term('categories', name)}
					newValue={new_category}
					onNewChange={setNewCategory}
					onNewAdd={add_new_category}
					disabled={!!disabled}
					idPrefix={`${idPrefix}-categories`}
				/>

				<TermPicker
					legend="Tags"
					existing={composerConfig.existingTags}
					selected={values.tags}
					onToggle={toggle_tag}
					onRemove={(name): void => remove_term('tags', name)}
					newValue={new_tag}
					onNewChange={setNewTag}
					onNewAdd={add_new_tag}
					disabled={!!disabled}
					idPrefix={`${idPrefix}-tags`}
				/>

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
					<fieldset class="outpost-field-group">
						<legend class="outpost-label">Focus keyphrase (Yoast SEO)</legend>
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
							aria-label="Focus keyphrase"
						/>
					</fieldset>
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

				{bridgy_target && (
					<fieldset class="outpost-syndication-picker outpost-bridgy-suggest">
						<legend class="outpost-label">Suggested (from target URL)</legend>
						<label class="outpost-checkbox">
							<input
								type="checkbox"
								checked={values.syndicateTo.includes(bridgy_target.uid)}
								onChange={(): void => toggle_syndication(bridgy_target.uid)}
								disabled={disabled}
							/>
							<span>{bridgy_target.name}</span>
						</label>
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
	);
}

/**
 * TermPicker — chip UI for picking from existing terms + adding new ones.
 *
 * Existing terms render as toggleable checkboxes (visible at a glance,
 * tap to include). Newly-added names that don't match an existing term
 * render as removable pills with a "(new)" marker — visually distinct
 * so the user sees they're creating something. The new-name input is
 * separate from the chip list with an explicit "Add" button so creation
 * is a deliberate action, not a side effect of typing in the field.
 *
 * Reused for both Categories and Tags in MorePanel.
 */
interface TermPickerProps {
	legend: string;
	existing: TermSuggestion[];
	selected: string[];
	onToggle: (name: string) => void;
	onRemove: (name: string) => void;
	newValue: string;
	onNewChange: (raw: string) => void;
	onNewAdd: () => void;
	disabled: boolean;
	idPrefix: string;
}

function TermPicker(props: TermPickerProps) {
	const {
		legend,
		existing,
		selected,
		onToggle,
		onRemove,
		newValue,
		onNewChange,
		onNewAdd,
		disabled,
		idPrefix,
	} = props;

	const existing_names = new Set(existing.map((e) => e.name));
	// Names the user added that don't already exist on the site — render
	// these as removable pills with a "(new)" marker.
	const new_chips = selected.filter((name) => !existing_names.has(name));

	const handle_new_keydown = (event: KeyboardEvent): void => {
		if (event.key === 'Enter') {
			event.preventDefault();
			onNewAdd();
		}
	};

	const singular = legend.toLowerCase().endsWith('s')
		? legend.toLowerCase().slice(0, -1)
		: legend.toLowerCase();
	const summary_count =
		selected.length > 0 ? ` — ${String(selected.length)} selected` : '';

	return (
		<details class="outpost-term-picker">
			<summary class="outpost-term-picker__summary">
				{legend}
				{summary_count && <span class="outpost-term-picker__count">{summary_count}</span>}
			</summary>
			<div class="outpost-term-picker__body">
				{existing.length === 0 ? (
					<p class="outpost-help">None on this site yet — add a new one below.</p>
				) : (
					<div class="outpost-chip-list">
						{existing.map((term) => (
							<label key={term.slug} class="outpost-checkbox">
								<input
									type="checkbox"
									checked={selected.includes(term.name)}
									onChange={(): void => onToggle(term.name)}
									disabled={disabled}
								/>
								<span>{term.name}</span>
							</label>
						))}
					</div>
				)}

				{new_chips.length > 0 && (
					<div class="outpost-chip-list outpost-chip-list--new">
						{new_chips.map((name) => (
							<button
								key={name}
								type="button"
								class="outpost-new-chip"
								onClick={(): void => onRemove(name)}
								disabled={disabled}
								aria-label={`Remove new ${singular} ${name}`}
							>
								{name} <span aria-hidden="true">(new) ✕</span>
							</button>
						))}
					</div>
				)}

				<div class="outpost-term-picker__add">
					<input
						id={`${idPrefix}-new`}
						class="outpost-input"
						type="text"
						value={newValue}
						onInput={(e): void => onNewChange((e.target as HTMLInputElement).value)}
						onKeyDown={handle_new_keydown}
						placeholder={`Add a new ${singular}…`}
						disabled={disabled}
					/>
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						onClick={onNewAdd}
						disabled={disabled || !newValue.trim()}
					>
						Add
					</button>
				</div>
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
