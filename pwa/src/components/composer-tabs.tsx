import { useEffect, useRef, useState } from 'preact/hooks';
import type { JSX } from 'preact';
import { clear_token, type StoredToken, type TokenStoreEnvironment } from '../lib/token-store';
import type { MicropubEnvironment } from '../lib/micropub';
import {
	fetch_composer_config,
	type ComposerConfig,
	type ComposerConfigEnvironment,
} from '../lib/composer-config';
import { NoteMode } from './modes/note-mode';
import { ReplyMode } from './modes/reply-mode';
import { PhotoMode } from './modes/photo-mode';
import { ListenMode } from './modes/listen-mode';
import { LifeMode } from './modes/life-mode';
import { RecipeMode } from './modes/recipe-mode';
import { AboutTab } from './modes/about-tab';
import { QueueBadge } from './queue-badge';
import { InstallPrompt } from './install-prompt';
import type { OfflineQueueEnvironment } from '../lib/offline-queue';
import { peek_share_target } from '../lib/share-target';
import { useAppearance } from '../lib/use-appearance';
import type { AppearanceEnvironment } from '../lib/appearance';
import { AppHeader } from './app-header';
import { SettingsSheet } from './settings-sheet';
import { Scenery } from './scenery';
import { drafts_available, type DraftStorage } from '../lib/drafts';

/**
 * Composer tab framework — the WAI-ARIA tabs pattern for switching
 * between post kinds.
 *
 * Per the A11Y-CHECKLIST forward gate for Phase C composer-mode focus
 * management:
 *   - role="tablist" on the container with aria-label
 *   - role="tab" on each tab button with aria-selected, aria-controls,
 *     and roving tabindex (selected tab is 0, others are -1)
 *   - role="tabpanel" on each panel with aria-labelledby
 *   - Arrow keys move focus AND select the adjacent tab (automatic
 *     activation — appropriate for cheap tab switches that just toggle
 *     visibility)
 *   - Home/End jump to first/last tab
 *   - Wraps at the ends (ArrowRight from last → first; ArrowLeft from
 *     first → last)
 *
 * Phase C0 lands this framework with the Note mode plugged in. Reply,
 * Photo, Listen, and Article render placeholder cards naming which
 * Phase C session lands the real implementation. All five panels are
 * rendered eagerly with `hidden` toggling visibility — this preserves
 * mode state (e.g. text typed into Note's textarea) across tab
 * switches.
 *
 * Phase F may surface additional tabs gated on companion-plugin
 * presence (e.g. Listen group only when Post Kinds is active). The
 * ModeId union and the modes array would extend then.
 */

/**
 * Waits between composer-config retries after a failure that isn't an auth
 * rejection. The schedule starts over when the browser comes back online or
 * the user taps Try again.
 */
export const CONFIG_RETRY_DELAYS_MS = [2_000, 5_000, 15_000, 30_000, 60_000];

type ModeId = 'note' | 'reply' | 'photo' | 'listen' | 'life' | 'recipe' | 'about';

interface ModeDefinition {
	id: ModeId;
	label: string;
	render: () => JSX.Element;
}

export interface ComposerTabsProps {
	token: StoredToken;
	tokenStore: TokenStoreEnvironment;
	micropubEnv?: MicropubEnvironment;
	composerConfigEnv?: ComposerConfigEnvironment;
	queueEnv?: OfflineQueueEnvironment;
	appearanceEnv?: AppearanceEnvironment;
	/** Device storage the modes keep drafts in; defaults to localStorage. */
	draftStorage?: DraftStorage | null;
}

/**
 * Tracks whether the tab strip has more tabs off either edge, for the
 * visible overflow cue on narrow screens, and keeps the selected tab in
 * view.
 */
function useTabOverflow(active: string): {
	ref: { current: HTMLDivElement | null };
	start: boolean;
	end: boolean;
} {
	const ref = useRef<HTMLDivElement | null>(null);
	const [edges, setEdges] = useState({ start: false, end: false });
	useEffect(() => {
		const el = ref.current;
		if (!el) return;
		const measure = (): void => {
			const start = el.scrollLeft > 1;
			const end = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
			setEdges((prev) => (prev.start === start && prev.end === end ? prev : { start, end }));
		};
		measure();
		el.addEventListener('scroll', measure, { passive: true });
		window.addEventListener('resize', measure);
		return (): void => {
			el.removeEventListener('scroll', measure);
			window.removeEventListener('resize', measure);
		};
	}, []);
	// Scroll only the strip, and only after the first render: calling
	// scrollIntoView() on load moved the browser's sequential-focus
	// starting point past the header, so Tab skipped it.
	const first_render = useRef(true);
	useEffect(() => {
		if (first_render.current) {
			first_render.current = false;
			return;
		}
		const strip = ref.current;
		const selected = strip?.querySelector<HTMLElement>('[role="tab"][aria-selected="true"]');
		if (!strip || !selected) return;
		const left = selected.offsetLeft - strip.offsetLeft;
		if (left < strip.scrollLeft || left + selected.offsetWidth > strip.scrollLeft + strip.clientWidth) {
			strip.scrollTo?.({ left: Math.max(0, left - 24) });
		}
	}, [active]);
	return { ref, ...edges };
}

export function ComposerTabs({
	token,
	tokenStore,
	micropubEnv,
	composerConfigEnv,
	queueEnv,
	appearanceEnv,
	draftStorage,
}: ComposerTabsProps) {
	const appearance = useAppearance({
		accessToken: token.accessToken,
		me: token.me,
		...(appearanceEnv ? { env: appearanceEnv } : {}),
	});
	const [settings_open, setSettingsOpen] = useState(false);
	// Making the composer inert blurs the settings button before the
	// drawer can record it, so focus goes back explicitly on close.
	const settings_button = useRef<HTMLButtonElement | null>(null);
	const close_settings = (): void => {
		setSettingsOpen(false);
		requestAnimationFrame(() => settings_button.current?.focus());
	};
	// Initial tab respects an in-flight share-target intake when present.
	// peek_share_target() doesn't clear sessionStorage — the targeted mode
	// drains it via consume_share_target() during its own mount.
	const initial_share = peek_share_target();
	const [active, setActive] = useState<ModeId>(initial_share?.tab ?? 'note');
	const [composer_config, setComposerConfig] = useState<ComposerConfig | null>(null);
	const [config_error, setConfigError] = useState<'unauthorized' | 'forbidden' | 'fetch_failed' | null>(null);
	// Whether unsent text survives the reload that "Sign in again" triggers.
	const [can_keep_drafts] = useState(() =>
		draftStorage === undefined ? drafts_available() : drafts_available(draftStorage),
	);
	const tab_refs = useRef<Partial<Record<ModeId, HTMLButtonElement | null>>>({});
	// Bumped to fetch the composer config again.
	const [config_attempt, setConfigAttempt] = useState(0);
	const config_retry_step = useRef(0);
	// True until a fetch succeeds or the site rejects the token; read by the
	// `online` listener, which is attached once.
	const config_needed = useRef(true);

	const retry_config_now = (): void => {
		config_retry_step.current = 0;
		setConfigAttempt((n) => n + 1);
	};

	// Fetch the composer config on mount, again on a bounded backoff after
	// a network or server failure, and again when the browser comes back
	// online. On failure we surface a banner above the tab strip so the user
	// can see WHY their More options + companion-gated fields aren't loading.
	// An auth rejection (expired bearer token) is not retried — the banner
	// offers re-auth instead.
	useEffect(() => {
		let cancelled = false;
		let retry_timer: ReturnType<typeof setTimeout> | null = null;
		fetch_composer_config(token.accessToken, composerConfigEnv)
			.then((cfg) => {
				if (cancelled) return;
				config_needed.current = false;
				config_retry_step.current = 0;
				setComposerConfig(cfg);
				setConfigError(null);
			})
			.catch((err: { code?: string }) => {
				if (cancelled) return;
				if (err && (err.code === 'unauthorized' || err.code === 'forbidden')) {
					config_needed.current = false;
					setConfigError(err.code);
				} else {
					config_needed.current = true;
					setConfigError('fetch_failed');
					const delay = CONFIG_RETRY_DELAYS_MS[config_retry_step.current];
					if (delay !== undefined) {
						config_retry_step.current += 1;
						retry_timer = setTimeout(() => setConfigAttempt((n) => n + 1), delay);
					}
				}
				if (typeof console !== 'undefined') {
					console.warn('Outpost: composer-config fetch failed', err);
				}
			});
		return (): void => {
			cancelled = true;
			if (retry_timer !== null) clearTimeout(retry_timer);
		};
	}, [token.accessToken, composerConfigEnv, config_attempt]);

	useEffect(() => {
		const on_online = (): void => {
			if (config_needed.current) retry_config_now();
		};
		window.addEventListener('online', on_online);
		return (): void => window.removeEventListener('online', on_online);
	}, []);

	// Page title tracks the active mode so the browser tab / tab-switcher UI
	// and screen-reader users navigating by document title get a meaningful
	// label instead of a static "Outpost" for the whole session.
	useEffect(() => {
		const current_mode = modes.find((mode) => mode.id === active);
		if (current_mode) {
			document.title = `${current_mode.label} – Outpost`;
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `modes` is a fresh array every render, but its id/label pairs are static; only `active` should retrigger this.
	}, [active]);

	const modes: ModeDefinition[] = [
		{
			id: 'note',
			label: 'Post',
			// Spread micropubEnv conditionally — exactOptionalPropertyTypes
			// rejects passing `undefined` to an optional prop.
			render: () => (
				<NoteMode
					token={token}
					tokenStore={tokenStore}
					{...(micropubEnv ? { micropubEnv } : {})}
					{...(composer_config ? { composerConfig: composer_config } : {})}
				/>
			),
		},
		{
			id: 'reply',
			label: 'Reply',
			render: () => (
				<ReplyMode
					token={token}
					{...(micropubEnv ? { micropubEnv } : {})}
					{...(composer_config ? { composerConfig: composer_config } : {})}
				/>
			),
		},
		{
			id: 'photo',
			label: 'Photo',
			render: () => (
				<PhotoMode
					token={token}
					{...(micropubEnv ? { micropubEnv } : {})}
					{...(composer_config ? { composerConfig: composer_config } : {})}
				/>
			),
		},
		{
			id: 'listen',
			label: 'Doing',
			render: () => (
				<ListenMode
					token={token}
					{...(micropubEnv ? { micropubEnv } : {})}
					{...(composer_config ? { composerConfig: composer_config } : {})}
				/>
			),
		},
		{
			id: 'life',
			label: 'Life',
			render: () => (
				<LifeMode
					token={token}
					{...(micropubEnv ? { micropubEnv } : {})}
					{...(composer_config ? { composerConfig: composer_config } : {})}
				/>
			),
		},
		{
			id: 'recipe',
			label: 'Recipe',
			render: () => (
				<RecipeMode
					token={token}
					{...(micropubEnv ? { micropubEnv } : {})}
					{...(composer_config ? { composerConfig: composer_config } : {})}
				/>
			),
		},
		{
			id: 'about',
			label: 'About',
			render: () => (
				<AboutTab
					siteUrl={typeof window !== 'undefined' ? window.location.origin : ''}
					me={token.me}
					scope={token.scope}
					connection={
						composer_config
							? 'connected'
							: config_error === 'unauthorized'
								? 'signed-out'
								: config_error === 'forbidden'
									? 'forbidden'
									: config_error === 'fetch_failed'
										? 'unreachable'
										: 'checking'
					}
					companions={composer_config?.companions ?? null}
					onRetry={retry_config_now}
					onReauth={async (): Promise<void> => {
						await clear_token(tokenStore);
						window.location.reload();
					}}
				/>
			),
		},
	];

	const handle_keydown = (event: KeyboardEvent, current_index: number): void => {
		let next_index: number | null = null;
		switch (event.key) {
			case 'ArrowRight':
				next_index = (current_index + 1) % modes.length;
				break;
			case 'ArrowLeft':
				next_index = (current_index - 1 + modes.length) % modes.length;
				break;
			case 'Home':
				next_index = 0;
				break;
			case 'End':
				next_index = modes.length - 1;
				break;
			default:
				return;
		}
		event.preventDefault();
		const next_mode = modes[next_index];
		if (!next_mode) return;
		setActive(next_mode.id);
		// Focus the newly-selected tab so screen readers announce it and
		// keyboard users see the visible focus indicator move.
		tab_refs.current[next_mode.id]?.focus();
	};

	const overflow = useTabOverflow(active);

	const config_message =
		config_error === 'unauthorized'
			? {
					title: 'Your sign-in expired.',
					body: can_keep_drafts
						? 'Posting needs a fresh sign-in. Signing in again reloads Outpost; your unsent text stays on this device and comes back afterward. Nothing posts until you tap a Post button.'
						: "Posting needs a fresh sign-in. Signing in again reloads Outpost, and this device can't keep drafts, so copy anything you have written first.",
				}
			: config_error === 'forbidden'
				? {
						title: "Your site didn't allow loading companion options.",
						body: 'This sign-in may lack a permission your site requires. The composer still posts; categories, tags, and other More options may be missing. About › Diagnostics shows what this sign-in can do.',
					}
				: {
						title: "Couldn't reach your site.",
						body: 'Outpost tries again on its own and as soon as your connection is back. Posting still works; More options appear once your site answers.',
					};

	return (
		<>
			<div class="outpost-composer" inert={settings_open}>
				<AppHeader
					siteHost={typeof window !== 'undefined' ? window.location.host : ''}
					mode={appearance.state.mode}
					onMode={appearance.setMode}
					onOpenSettings={(): void => setSettingsOpen(true)}
					settingsOpen={settings_open}
					settingsButtonRef={settings_button}
				/>
				<InstallPrompt />
				{config_error && (
					<aside class="outpost-config-error" role="status">
						<div class="outpost-config-error__body">
							<strong>{config_message.title}</strong>
							<p>{config_message.body}</p>
						</div>
						{config_error === 'unauthorized' ? (
							<button
								class="outpost-button outpost-button--secondary"
								type="button"
								onClick={async (): Promise<void> => {
									await clear_token(tokenStore);
									window.location.reload();
								}}
							>
								Sign in again
							</button>
						) : config_error === 'fetch_failed' ? (
							<button
								class="outpost-button outpost-button--secondary"
								type="button"
								onClick={retry_config_now}
							>
								Try again
							</button>
						) : null}
					</aside>
				)}
				<div class="outpost-composer__header">
					<QueueBadge
						{...(micropubEnv ? { micropubEnv } : {})}
						{...(queueEnv ? { queueEnv } : {})}
					/>
				</div>
				<h1 class="outpost-visually-hidden">Outpost composer</h1>
				<div
					class="outpost-tablist-wrap"
					data-overflow-start={overflow.start ? 'true' : 'false'}
					data-overflow-end={overflow.end ? 'true' : 'false'}
				>
					<div
						ref={overflow.ref}
						role="tablist"
						aria-label="Composer modes"
						class="outpost-tablist"
					>
						{modes.map((mode, index) => (
							<button
								key={mode.id}
								ref={(el): void => {
									tab_refs.current[mode.id] = el;
								}}
								id={`outpost-tab-${mode.id}`}
								role="tab"
								type="button"
								class="outpost-tab"
								aria-selected={active === mode.id}
								aria-controls={`outpost-panel-${mode.id}`}
								tabIndex={active === mode.id ? 0 : -1}
								onClick={(): void => setActive(mode.id)}
								onKeyDown={(event: KeyboardEvent): void => handle_keydown(event, index)}
							>
								{mode.label}
							</button>
						))}
					</div>
					<span class="outpost-tablist-cue outpost-tablist-cue--start" aria-hidden="true">‹</span>
					<span class="outpost-tablist-cue outpost-tablist-cue--end" aria-hidden="true">›</span>
				</div>
				{modes.map((mode) => (
					<div
						key={mode.id}
						id={`outpost-panel-${mode.id}`}
						role="tabpanel"
						aria-labelledby={`outpost-tab-${mode.id}`}
						hidden={active !== mode.id}
					>
						{mode.render()}
					</div>
				))}
				<Scenery />
			</div>
			<SettingsSheet
				open={settings_open}
				onClose={close_settings}
				mode={appearance.state.mode}
				source={appearance.state.source}
				status={appearance.status}
				onMode={appearance.setMode}
				onSource={appearance.setSource}
				onRetry={appearance.retry}
			/>
		</>
	);
}
