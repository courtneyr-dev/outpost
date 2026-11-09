import type { AppearanceMode } from '../lib/appearance';
import { Signpost } from './signpost';

/**
 * Compact identity and status row above the tab strip: the signpost and
 * wordmark, the connected site, a Light / Dark / System shortcut, and the
 * settings button. The shortcut and the settings sheet read and write the
 * same state, so they always agree.
 */
export interface AppHeaderProps {
	siteHost: string;
	mode: AppearanceMode;
	onMode: (mode: AppearanceMode) => void;
	onOpenSettings: () => void;
	settingsOpen: boolean;
	settingsButtonRef?: { current: HTMLButtonElement | null };
}

export const MODE_OPTIONS: ReadonlyArray<{ id: AppearanceMode; label: string }> = [
	{ id: 'day', label: 'Light' },
	{ id: 'night', label: 'Dark' },
	{ id: 'system', label: 'System' },
];

export function ModeIcon({ mode }: { mode: AppearanceMode }): preact.JSX.Element {
	const common = {
		viewBox: '0 0 24 24',
		width: 20,
		height: 20,
		fill: 'none',
		stroke: 'currentColor',
		'stroke-width': 2,
		'stroke-linecap': 'round' as const,
		'stroke-linejoin': 'round' as const,
		'aria-hidden': 'true' as const,
		focusable: 'false' as const,
	};
	if (mode === 'day') {
		return (
			<svg {...common}>
				<circle cx="12" cy="12" r="4" />
				<path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
			</svg>
		);
	}
	if (mode === 'night') {
		return (
			<svg {...common}>
				<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" />
			</svg>
		);
	}
	return (
		<svg {...common}>
			<rect x="3" y="4" width="18" height="12" rx="2" />
			<path d="M8 20h8M12 16v4" />
		</svg>
	);
}

export function AppHeader({
	siteHost,
	mode,
	onMode,
	onOpenSettings,
	settingsOpen,
	settingsButtonRef,
}: AppHeaderProps): preact.JSX.Element {
	return (
		<header class="outpost-app-header">
			<div class="outpost-app-header__identity">
				<Signpost size={34} class="outpost-app-header__mark" />
				<div class="outpost-app-header__titles">
					<p class="outpost-wordmark">Outpost</p>
					<p class="outpost-app-header__site">
						<span class="outpost-visually-hidden">Posting to </span>
						{siteHost}
					</p>
				</div>
			</div>
			<div class="outpost-app-header__controls">
				<fieldset class="outpost-mode-switch">
					<legend class="outpost-visually-hidden">Appearance</legend>
					{MODE_OPTIONS.map((option) => (
						<label key={option.id} class="outpost-mode-switch__option" title={option.label}>
							<input
								type="radio"
								name="outpost-header-mode"
								value={option.id}
								checked={mode === option.id}
								onChange={(): void => onMode(option.id)}
							/>
							<ModeIcon mode={option.id} />
							<span class="outpost-visually-hidden">{option.label}</span>
						</label>
					))}
				</fieldset>
				<button
					{...(settingsButtonRef ? { ref: settingsButtonRef } : {})}
					type="button"
					class="outpost-icon-button"
					aria-haspopup="dialog"
					aria-expanded={settingsOpen}
					onClick={onOpenSettings}
				>
					<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
						<circle cx="12" cy="12" r="3" />
						<path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" />
					</svg>
					<span class="outpost-visually-hidden">Preferences</span>
				</button>
			</div>
		</header>
	);
}
