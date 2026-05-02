import { useRef, useState } from 'preact/hooks';
import type { JSX } from 'preact';
import type { StoredToken, TokenStoreEnvironment } from '../lib/token-store';
import type { MicropubEnvironment } from '../lib/micropub';
import { NoteMode } from './modes/note-mode';
import { ReplyMode } from './modes/reply-mode';
import { PhotoMode } from './modes/photo-mode';
import { ListenMode } from './modes/listen-mode';
import { ArticleMode } from './modes/article-mode';

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

type ModeId = 'note' | 'reply' | 'photo' | 'listen' | 'article';

interface ModeDefinition {
	id: ModeId;
	label: string;
	render: () => JSX.Element;
}

export interface ComposerTabsProps {
	token: StoredToken;
	tokenStore: TokenStoreEnvironment;
	micropubEnv?: MicropubEnvironment;
}

export function ComposerTabs({ token, tokenStore, micropubEnv }: ComposerTabsProps) {
	const [active, setActive] = useState<ModeId>('note');
	const tab_refs = useRef<Partial<Record<ModeId, HTMLButtonElement | null>>>({});

	const modes: ModeDefinition[] = [
		{
			id: 'note',
			label: 'Note',
			// Spread micropubEnv conditionally — exactOptionalPropertyTypes
			// rejects passing `undefined` to an optional prop.
			render: () => (
				<NoteMode
					token={token}
					tokenStore={tokenStore}
					{...(micropubEnv ? { micropubEnv } : {})}
				/>
			),
		},
		{ id: 'reply', label: 'Reply', render: () => <ReplyMode /> },
		{ id: 'photo', label: 'Photo', render: () => <PhotoMode /> },
		{ id: 'listen', label: 'Listen', render: () => <ListenMode /> },
		{ id: 'article', label: 'Article', render: () => <ArticleMode /> },
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
