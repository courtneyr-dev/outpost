import { useEffect, useRef, useState } from 'preact/hooks';
import { Fragment, type JSX } from 'preact';
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
import {
	hold_share_for_review,
	release_held_share,
	discard_held_share,
	set_held_shares,
	type ShareTargetData,
} from '../lib/share-target';
import {
	read_share_inbox,
	keep_shared_photos,
	SHARE_INBOX_KEY,
	SHARE_INBOX_CANDIDATE_KEY,
	type ShareInboxEnvironment,
	type ShareInboxSlot,
	type ShareInboxState,
	type SharedPhotos,
} from '../lib/share-inbox';
import { ShareReview, type WaitingShare } from './share-review';
import { useAppearance } from '../lib/use-appearance';
import type { AppearanceEnvironment } from '../lib/appearance';
import { AppHeader } from './app-header';
import { SettingsSheet } from './settings-sheet';
import { Scenery } from './scenery';
import { DRAFT_SAVE_DELAY_MS, draft_modes, drafts_available, type DraftMode, type DraftStorage } from '../lib/drafts';

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
	/** Injectable IndexedDB for the photo share inbox (tests pass fake-indexeddb). */
	shareInboxEnv?: ShareInboxEnvironment;
}

const SHARE_REFUSED =
	"Another share arrived but wasn't kept, because two were already waiting. Choose below, then share it again.";
const SHARE_UNREADABLE =
	"Couldn't read the shared photos on this device, so this choice wasn't applied. Reload and try again.";
const SHARE_READING = 'Still reading the shared photos. Try again in a moment.';
const SHARE_NOT_APPLIED =
	"Your choice wasn't applied because this device couldn't update its saved shares. Nothing changed; try again.";

/**
 * Match waiting shares to the photo records in the share inbox.
 *
 * Photo shares are session entries whose bytes the service worker parked in
 * IndexedDB, filling `pending` first, so photo entries map to the stored
 * records in order. An entry with no record left (another tab used it) is
 * dropped. A record with no entry (its redirect never arrived, or a share
 * left from an earlier visit) becomes a photo entry when a slot is free and
 * is otherwise surplus, to be removed.
 */
function match_photo_slots(
	shares: ShareTargetData[],
	inbox: ShareInboxState,
): { shares: ShareTargetData[]; slots: Array<ShareInboxSlot | null>; surplus: boolean } {
	const records = ([SHARE_INBOX_KEY, SHARE_INBOX_CANDIDATE_KEY] as const).filter(
		(slot) => inbox[slot === SHARE_INBOX_KEY ? 'pending' : 'candidate'] !== null,
	);
	const out: ShareTargetData[] = [];
	const slots: Array<ShareInboxSlot | null> = [];
	let next = 0;
	for (const share of shares) {
		if (!share.sharedPhotos) {
			out.push(share);
			slots.push(null);
		} else if (next < records.length) {
			out.push(share);
			slots.push(records[next++] ?? null);
		}
	}
	let surplus = false;
	for (; next < records.length; next++) {
		if (out.length < 2) {
			out.push({ tab: 'photo', sharedPhotos: true });
			slots.push(records[next] ?? null);
		} else {
			surplus = true;
		}
	}
	return { shares: out, slots, surplus };
}

function photos_in(inbox: ShareInboxState | null, slot: ShareInboxSlot | null | undefined): SharedPhotos | null {
	if (!inbox || !slot) return null;
	return slot === SHARE_INBOX_KEY ? inbox.pending : inbox.candidate;
}

/**
 * Tracks whether the tab strip has more tabs off either edge, for the
 * visible overflow cue on narrow screens, and keeps the selected tab in
 * view.
 */
function useTabOverflow(active: string, visible: boolean): {
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
		// A strip hidden behind Home measures zero wide; measure again when it shows.
	}, [visible]);
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
	}, [active, visible]);
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
	shareInboxEnv,
}: ComposerTabsProps) {
	// Shares are held for review before any mode mounts (this initializer runs
	// before the children render), so no mode can pick one up on its own.
	const [held] = useState(() => hold_share_for_review());
	const [shares, setShares] = useState<ShareTargetData[]>(held.shares);
	// Which share-inbox record holds each photo share's bytes; null until read.
	const [photo_slots, setPhotoSlots] = useState<Array<ShareInboxSlot | null>>(() => held.shares.map(() => null));
	const [share_inbox, setShareInbox] = useState<ShareInboxState | null>(null);
	const [share_status, setShareStatus] = useState('');
	const [review_status, setReviewStatus] = useState(held.refused ? SHARE_REFUSED : '');
	// Set once a decision is applied, so the first inbox read can't bring
	// back a share the person already used or discarded.
	const decided = useRef(false);
	// Where focus goes when a conflict narrows to one share.
	const review_use_button = useRef<HTMLButtonElement | null>(null);
	// Bumped per mode to remount it, so it reads a share released to it.
	const [generation, setGeneration] = useState<Partial<Record<ModeId, number>>>({});
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
			share: held.shares[0] ?? null,
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

	const review_tab: ModeId | null = shares[0]?.tab ?? null;
	// While a share waits, the decision is the only thing on screen.
	const deciding = review_tab !== null;
	const waiting: WaitingShare[] = shares.map((share, i) => ({
		share,
		photos: photos_in(share_inbox, photo_slots[i]),
	}));

	// Read the share inbox once and reconcile it with the held shares.
	useEffect(() => {
		let cancelled = false;
		void (async (): Promise<void> => {
			const inbox = await read_share_inbox(shareInboxEnv);
			if (cancelled) return;
			if (decided.current) {
				setShareInbox(inbox);
				return;
			}
			if (inbox.status !== 'ok') {
				// Unknown is not empty: keep every held share and say so.
				setShareInbox(inbox);
				if (held.shares.some((share) => share.sharedPhotos)) setReviewStatus(SHARE_UNREADABLE);
				return;
			}
			const matched = match_photo_slots(held.shares, inbox);
			let final_inbox = inbox;
			let slots = matched.slots;
			if (matched.surplus) {
				// A third share's bytes: remove them, keeping the matched photo share.
				try {
					await keep_shared_photos(slots.find((slot) => slot !== null) ?? null, shareInboxEnv);
					slots = slots.map((slot) => (slot ? SHARE_INBOX_KEY : null));
					final_inbox = await read_share_inbox(shareInboxEnv);
				} catch (_err) {
					// Left in place; the next visit reconciles again.
				}
				if (cancelled || decided.current) return;
				setReviewStatus(SHARE_REFUSED);
			}
			set_held_shares(matched.shares);
			setShares(matched.shares);
			setPhotoSlots(slots);
			setShareInbox(final_inbox);
			if (matched.shares.length === 0 && held.refused) setShareStatus(SHARE_REFUSED);
			if (!held.shares.length && matched.shares.length) {
				// A photo share left waiting from an earlier visit opens its review.
				setActive('photo');
				setView('tabs');
			}
		})();
		return (): void => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- read once on mount; decisions update it.
	}, []);

	const reopen = (tab: ModeId, remount: boolean): void => {
		if (remount) setGeneration((g) => ({ ...g, [tab]: (g[tab] ?? 0) + 1 }));
		setActive(tab);
		setView('tabs');
		requestAnimationFrame(() => tab_refs.current[tab]?.focus());
	};

	/**
	 * Bring the share inbox in line with a decision before the session slots
	 * change: `survivor` is the index of the share that stays (null for none).
	 * Returns false, with the review left as it was and a retryable message,
	 * when the photo records couldn't be updated or read.
	 */
	const apply_to_inbox = async (survivor: number | null): Promise<boolean> => {
		if (!shares.some((share) => share.sharedPhotos)) {
			decided.current = true;
			return true;
		}
		const keeps_photo = survivor !== null && shares[survivor]?.sharedPhotos === true;
		const keep = keeps_photo && survivor !== null ? (photo_slots[survivor] ?? null) : null;
		if (keeps_photo && share_inbox === null) {
			setReviewStatus(SHARE_READING);
			return false;
		}
		if (keeps_photo && (share_inbox?.status !== 'ok' || keep === null)) {
			setReviewStatus(SHARE_UNREADABLE);
			return false;
		}
		try {
			await keep_shared_photos(keep, shareInboxEnv);
		} catch (_err) {
			setReviewStatus(SHARE_NOT_APPLIED);
			return false;
		}
		decided.current = true;
		return true;
	};

	const tab_label = (tab: ModeId): string => modes.find((mode) => mode.id === tab)?.label ?? tab;

	const finish_review = (tab: ModeId, remount: boolean, message: string): void => {
		setShares([]);
		setPhotoSlots([]);
		setReviewStatus('');
		setShareStatus(message);
		reopen(tab, remount);
	};

	const use_shared = async (): Promise<void> => {
		if (!review_tab || shares.length !== 1) return;
		if (!(await apply_to_inbox(0))) return;
		release_held_share();
		finish_review(review_tab, true, `Shared content added to the ${tab_label(review_tab)} form.`);
	};

	const discard_shared = async (): Promise<void> => {
		if (!review_tab) return;
		if (!(await apply_to_inbox(null))) return;
		discard_held_share();
		finish_review(review_tab, false, 'Shared content discarded.');
	};

	const keep_one = async (index: 0 | 1, message: string): Promise<void> => {
		const kept = shares[index];
		if (!kept || shares.length !== 2) return;
		if (!(await apply_to_inbox(index))) return;
		set_held_shares([kept]);
		setShares([kept]);
		setPhotoSlots([kept.sharedPhotos ? SHARE_INBOX_KEY : null]);
		if (kept.sharedPhotos) setShareInbox(await read_share_inbox(shareInboxEnv));
		setReviewStatus(message);
		// The conflict buttons are gone; the single-share review renders before
		// the next frame, so move focus into it rather than leave it on <body>.
		requestAnimationFrame(() => review_use_button.current?.focus());
	};

	const discard_both_shares = async (): Promise<void> => {
		if (!review_tab) return;
		if (!(await apply_to_inbox(null))) return;
		discard_held_share();
		finish_review(review_tab, false, 'Both shares discarded.');
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
					{...(shareInboxEnv ? { shareInboxEnv } : {})}
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

	const overflow = useTabOverflow(active, view === 'tabs');

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
					hideSettings={deciding}
				/>
				{/* Plain wrappers: these components set their own display, which a
				    bare `hidden` on them wouldn't override. */}
				<div hidden={deciding}>
					<InstallPrompt />
				</div>
				{config_error && !deciding && (
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
				<div hidden={deciding}>
					<div class="outpost-composer__header">
						<QueueBadge
							{...(micropubEnv ? { micropubEnv } : {})}
							{...(queueEnv ? { queueEnv } : {})}
						/>
					</div>
				</div>
				<h1 class="outpost-visually-hidden">Outpost composer</h1>
				<div class="outpost-start-status" role="status" aria-live="polite">
					{view === null ? <p class="outpost-status">Opening Outpost…</p> : null}
				</div>
				<div class="outpost-share-status" role="status" aria-live="polite">
					{share_status ? <p class="outpost-status">{share_status}</p> : null}
				</div>
				{deciding && (
					<ShareReview
						shares={waiting}
						status={review_status}
						useButtonRef={review_use_button}
						onUse={(): void => {
							void use_shared();
						}}
						onDiscard={(): void => {
							void discard_shared();
						}}
						onKeepEarlier={(): void => {
							void keep_one(0, 'Kept the earlier share. The new one was discarded.');
						}}
						onUseNew={(): void => {
							void keep_one(1, 'Using the new share. The earlier one was discarded.');
						}}
						onDiscardBoth={(): void => {
							void discard_both_shares();
						}}
					/>
				)}
				{view === 'home' && !deciding && (
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
					hidden={view !== 'tabs' || deciding}
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
						hidden={view !== 'tabs' || active !== mode.id || deciding}
					>
						<Fragment key={generation[mode.id] ?? 0}>{mode.render()}</Fragment>
					</div>
				))}
				<Scenery />
				{view === 'tabs' && !deciding && (
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
