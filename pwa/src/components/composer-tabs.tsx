import { useEffect, useRef, useState } from 'preact/hooks';
import type { JSX } from 'preact';
import { clear_token, type StoredToken, type TokenStoreEnvironment } from '../lib/token-store';
import { sign_out } from '../lib/sign-out';
import { START_SCREEN_WAIT_MS, initial_screen } from '../lib/start-screen';
import type { StartScreen } from '../lib/appearance';
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
import { AboutTab, type ConnectionState } from './modes/about-tab';
import { HomeScreen } from './home-screen';
import { QueueBadge } from './queue-badge';
import { InstallPrompt } from './install-prompt';
import type { OfflineQueueEnvironment } from '../lib/offline-queue';
import { peek_share_target } from '../lib/share-target';
import { useAppearance } from '../lib/use-appearance';
import type { AppearanceEnvironment } from '../lib/appearance';
import { AppHeader } from './app-header';
import { SettingsSheet } from './settings-sheet';
import { Scenery } from './scenery';
import { DRAFT_SAVE_DELAY_MS, draft_modes, drafts_available, type DraftMode, type DraftStorage } from '../lib/drafts';

/**
 * Composer mode button group. Every mode is a native button in the normal
 * Tab order; aria-current identifies the selected mode. All panels render
 * eagerly and toggle with `hidden`, preserving form state across switches.
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
	// Which screen opens: see lib/start-screen.ts for the precedence. A
	// share-target intake wins; peek_share_target() doesn't clear
	// sessionStorage, the targeted mode drains it during its own mount.
	// `view` is null while the saved start screen is still unknown.
	const [first_screen] = useState<StartScreen | null>(() =>
		initial_screen({
			share: peek_share_target(),
			start: appearance.state.start,
			ready: appearance.ready,
			timedOut: false,
		}),
	);
	const [view, setView] = useState<'home' | 'tabs' | null>(
		first_screen === null ? null : first_screen === 'home' ? 'home' : 'tabs',
	);
	const [active, setActive] = useState<ModeId>(
		first_screen !== null && first_screen !== 'home' ? first_screen : 'note',
	);
	const [start_wait_over, setStartWaitOver] = useState(false);
	const home_heading = useRef<HTMLHeadingElement | null>(null);
	const read_home_drafts = (): Set<DraftMode> =>
		new Set(draftStorage === undefined ? draft_modes(token.me) : draft_modes(token.me, draftStorage));
	const [home_drafts, setHomeDrafts] = useState<Set<DraftMode>>(read_home_drafts);

	// First launch on a device: wait briefly for the site's answer, then
	// settle once. Later preference changes never move the person.
	useEffect(() => {
		if (view !== null) return;
		const next = initial_screen({
			share: null,
			start: appearance.state.start,
			ready: appearance.ready,
			timedOut: start_wait_over,
		});
		if (next === null) {
			const timer = setTimeout(() => setStartWaitOver(true), START_SCREEN_WAIT_MS);
			return (): void => clearTimeout(timer);
		}
		if (next === 'home') {
			setView('home');
		} else {
			setActive(next);
			setView('tabs');
		}
		return undefined;
	}, [view, appearance.ready, appearance.state.start, start_wait_over]);

	// Home's "Unsent draft" labels: read now, and again once a save that
	// was still debouncing has landed.
	useEffect(() => {
		if (view !== 'home') return;
		setHomeDrafts(read_home_drafts());
		const timer = setTimeout(() => setHomeDrafts(read_home_drafts()), DRAFT_SAVE_DELAY_MS + 100);
		return (): void => clearTimeout(timer);
		// eslint-disable-next-line react-hooks/exhaustive-deps -- re-read only when Home shows.
	}, [view]);

	const open_tab = (id: ModeId): void => {
		setActive(id);
		setView('tabs');
		requestAnimationFrame(() => tab_refs.current[id]?.focus());
	};
	const go_home = (): void => {
		setView('home');
		requestAnimationFrame(() => home_heading.current?.focus());
	};
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
		if (view === null) {
			document.title = 'Outpost';
			return;
		}
		if (view === 'home') {
			document.title = 'Home – Outpost';
			return;
		}
		const current_mode = modes.find((mode) => mode.id === active);
		if (current_mode) {
			document.title = `${current_mode.label} – Outpost`;
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `modes` is a fresh array every render, but its id/label pairs are static; only `active` and `view` should retrigger this.
	}, [active, view]);

	const connection: ConnectionState = composer_config
		? 'connected'
		: config_error === 'unauthorized'
			? 'signed-out'
			: config_error === 'forbidden'
				? 'forbidden'
				: config_error === 'fetch_failed'
					? 'unreachable'
					: 'checking';

	const preferences = {
		mode: appearance.state.mode,
		source: appearance.state.source,
		start: appearance.state.start ?? 'home',
		status: appearance.status,
		onMode: appearance.setMode,
		onSource: appearance.setSource,
		onStart: appearance.setStart,
		onReset: appearance.reset,
		onRetry: appearance.retry,
	};

	const modes: ModeDefinition[] = [
		{
			id: 'note',
			label: 'Post',
			// Spread micropubEnv conditionally — exactOptionalPropertyTypes
			// rejects passing `undefined` to an optional prop.
			render: () => (
				<NoteMode
					token={token}
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
					connection={connection}
					companions={composer_config?.companions ?? null}
					onRetry={retry_config_now}
					onReauth={async (): Promise<void> => {
						await clear_token(tokenStore);
						window.location.reload();
					}}
					onSignOut={(): void => {
						void sign_out(tokenStore);
					}}
					preferences={preferences}
				/>
			),
		},
	];

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
				<div class="outpost-start-status" role="status" aria-live="polite">
					{view === null ? <p class="outpost-status">Opening Outpost…</p> : null}
				</div>
				{view === 'home' && (
					<HomeScreen
						siteHost={typeof window !== 'undefined' ? window.location.host : ''}
						connection={connection}
						drafts={home_drafts}
						onOpen={open_tab}
						onAbout={(): void => open_tab('about')}
						headingRef={home_heading}
					/>
				)}
				<div
					hidden={view !== 'tabs'}
					class="outpost-tablist-wrap"
				>
					<div role="group" aria-label="Composer modes" class="outpost-tablist">
						{modes.map((mode) => (
							<button
								key={mode.id}
								ref={(el): void => {
									tab_refs.current[mode.id] = el;
								}}
								id={`outpost-tab-${mode.id}`}
								type="button"
								class="outpost-tab"
								aria-controls={`outpost-panel-${mode.id}`}
								aria-current={active === mode.id ? 'true' : undefined}
								onClick={(): void => setActive(mode.id)}
							>
								{mode.label}
							</button>
						))}
					</div>
				</div>
				{modes.map((mode) => (
					<div
						key={mode.id}
						id={`outpost-panel-${mode.id}`}
						class="outpost-mode-panel"
						hidden={view !== 'tabs' || active !== mode.id}
					>
						{mode.render()}
					</div>
				))}
				<Scenery />
				{view === 'tabs' && (
					<nav class="outpost-footer-nav" aria-label="Outpost">
						<button type="button" class="outpost-footer-nav__home" onClick={go_home}>
							<span aria-hidden="true">←</span> Home
						</button>
					</nav>
				)}
			</div>
			<SettingsSheet
				open={settings_open}
				onClose={close_settings}
				{...preferences}
			/>
		</>
	);
}
