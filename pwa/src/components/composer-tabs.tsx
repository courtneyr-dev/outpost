import { useEffect, useRef, useState } from 'preact/hooks';
import type { JSX } from 'preact';
import type { StoredToken, TokenStoreEnvironment } from '../lib/token-store';
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
import { AboutTab } from './modes/about-tab';
import { QueueBanner } from './queue-banner';
import { InstallPrompt } from './install-prompt';
import type { OfflineQueueEnvironment } from '../lib/offline-queue';
import { peek_share_target } from '../lib/share-target';

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

type ModeId = 'note' | 'reply' | 'photo' | 'listen' | 'about';

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
}

export function ComposerTabs({
	token,
	tokenStore,
	micropubEnv,
	composerConfigEnv,
	queueEnv,
}: ComposerTabsProps) {
	// Initial tab respects an in-flight share-target intake when present.
	// peek_share_target() doesn't clear sessionStorage — the targeted mode
	// drains it via consume_share_target() during its own mount.
	const initial_share = peek_share_target();
	const [active, setActive] = useState<ModeId>(initial_share?.tab ?? 'note');
	const [composer_config, setComposerConfig] = useState<ComposerConfig | null>(null);
	const tab_refs = useRef<Partial<Record<ModeId, HTMLButtonElement | null>>>({});

	// Fetch the composer config once on mount. Failure is non-fatal —
	// modes still render their main forms; only the More pull-out is
	// gated on this. Modes receive null until the fetch resolves.
	useEffect(() => {
		let cancelled = false;
		fetch_composer_config(token.accessToken, composerConfigEnv)
			.then((cfg) => {
				if (!cancelled) setComposerConfig(cfg);
			})
			.catch(() => {
				// Silent failure — log to console for triage but don't surface.
				if (!cancelled && typeof console !== 'undefined') {
					console.warn('Outpost: composer-config fetch failed; More pull-out hidden');
				}
			});
		return (): void => {
			cancelled = true;
		};
	}, [token.accessToken, composerConfigEnv]);

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
			id: 'about',
			label: 'About',
			render: () => <AboutTab />,
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

	return (
		<div class="outpost-composer">
			<InstallPrompt />
			<QueueBanner
				{...(micropubEnv ? { micropubEnv } : {})}
				{...(queueEnv ? { queueEnv } : {})}
			/>
			<div role="tablist" aria-label="Composer modes" class="outpost-tablist">
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
			{modes.map((mode) => (
				<div
					key={mode.id}
					id={`outpost-panel-${mode.id}`}
					role="tabpanel"
					aria-labelledby={`outpost-tab-${mode.id}`}
					hidden={active !== mode.id}
					tabIndex={0}
				>
					{mode.render()}
				</div>
			))}
		</div>
	);
}
