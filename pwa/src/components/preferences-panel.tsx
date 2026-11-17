import type { AppearanceMode, StartScreen, StyleSource } from '../lib/appearance';
import type { AppearanceStatus } from '../lib/use-appearance';
import { MODE_OPTIONS, ModeIcon } from './app-header';
import { Signpost } from './signpost';

/**
 * Preferences: the screen a normal launch opens to, Light / Dark / System,
 * and Outpost Default / Use Site Theme. Rendered in the Preferences sheet
 * and in About › Preferences; both read and write the same state.
 *
 * Both copies are in the document at once (the About panel stays mounted
 * while hidden), so every id and radio-group name carries `idPrefix`.
 *
 * Changing the start screen saves it for the next launch. It never moves
 * the person off the screen they're on.
 *
 * The status line stays mounted and only its text changes (the composer's
 * live-region pattern; VoiceOver misses regions mounted on demand).
 */
export interface PreferencesPanelProps {
	idPrefix: string;
	mode: AppearanceMode;
	source: StyleSource;
	start: StartScreen;
	status: AppearanceStatus;
	onMode: (mode: AppearanceMode) => void;
	onSource: (source: StyleSource) => void;
	onStart: (start: StartScreen) => void;
	onReset: () => void;
	onRetry: () => void;
}

export const START_SCREEN_OPTIONS: ReadonlyArray<{ id: StartScreen; label: string }> = [
	{ id: 'home', label: 'Home' },
	{ id: 'note', label: 'Post' },
	{ id: 'reply', label: 'Reply' },
	{ id: 'photo', label: 'Photo' },
	{ id: 'listen', label: 'Doing' },
	{ id: 'life', label: 'Life' },
	{ id: 'recipe', label: 'Recipe' },
	{ id: 'about', label: 'About' },
];

const SOURCE_OPTIONS: ReadonlyArray<{ id: StyleSource; label: string; description: string }> = [
	{
		id: 'outpost',
		label: 'Outpost Default',
		description: 'The built-in Outpost look: warm paper, violet ink, and orange actions.',
	},
	{
		id: 'site',
		label: 'Use Site Theme',
		description: "Your WordPress theme's colors and fonts, adjusted where needed so text stays readable.",
	},
];

export function status_text(status: AppearanceStatus): string {
	switch (status.kind) {
		case 'saving':
			return 'Saving to your site…';
		case 'saved':
			return 'Saved to your site.';
		case 'device-only': {
			const where = status.stored ? 'Applied on this device' : 'Applied for now';
			const why =
				status.reason === 'offline'
					? "you're offline"
					: status.reason === 'timeout'
						? 'your site took too long to answer'
						: status.reason === 'unauthorized'
							? 'your sign-in expired'
							: status.reason === 'forbidden'
								? "this sign-in can't change settings"
								: "your site couldn't save it";
			return `${where}; not saved to your site because ${why}. Outpost tries again when it can.`;
		}
		default:
			return '';
	}
}

export function PreferencesPanel({
	idPrefix,
	mode,
	source,
	start,
	status,
	onMode,
	onSource,
	onStart,
	onReset,
	onRetry,
}: PreferencesPanelProps): preact.JSX.Element {
	const message = status_text(status);
	const is_default = start === 'home' && mode === 'system' && source === 'outpost';
	return (
		<div class="outpost-settings">
			<div class="outpost-form-row">
				<label class="outpost-choice-group__legend" for={`${idPrefix}-start`}>
					Default start screen
				</label>
				<p class="outpost-help" id={`${idPrefix}-start-help`}>
					What opens when you launch Outpost. Sharing to Outpost still opens the tab your content belongs in.
				</p>
				<select
					id={`${idPrefix}-start`}
					class="outpost-input"
					value={start}
					aria-describedby={`${idPrefix}-start-help`}
					onChange={(event): void => {
						const value = (event.currentTarget as HTMLSelectElement).value;
						const match = START_SCREEN_OPTIONS.find((option) => option.id === value);
						if (match) onStart(match.id);
					}}
				>
					{START_SCREEN_OPTIONS.map((option) => (
						<option key={option.id} value={option.id}>
							{option.label}
						</option>
					))}
				</select>
			</div>

			<fieldset class="outpost-choice-group">
				<legend class="outpost-choice-group__legend">Appearance</legend>
				<p class="outpost-help" id={`${idPrefix}-mode-help`}>
					Light and Dark stay put. System follows your device.
				</p>
				<div class="outpost-choice-group__options outpost-choice-group__options--row">
					{MODE_OPTIONS.map((option) => (
						<label key={option.id} class="outpost-choice outpost-choice--tile">
							<input
								type="radio"
								name={`${idPrefix}-mode`}
								value={option.id}
								checked={mode === option.id}
								aria-describedby={`${idPrefix}-mode-help`}
								onChange={(): void => onMode(option.id)}
							/>
							<ModeIcon mode={option.id} />
							<span class="outpost-choice__label">{option.label}</span>
						</label>
					))}
				</div>
			</fieldset>

			<fieldset class="outpost-choice-group">
				<legend class="outpost-choice-group__legend">Style source</legend>
				<p class="outpost-help">Choose where Outpost gets its colors and fonts.</p>
				<div class="outpost-choice-group__options">
					{SOURCE_OPTIONS.map((option) => (
						<label key={option.id} class="outpost-choice outpost-choice--card">
							<input
								type="radio"
								name={`${idPrefix}-source`}
								value={option.id}
								checked={source === option.id}
								aria-describedby={`${idPrefix}-source-${option.id}-desc`}
								onChange={(): void => onSource(option.id)}
							/>
							<span class="outpost-choice__body">
								<span class="outpost-choice__label">
									{option.id === 'outpost' && <Signpost size={20} class="outpost-choice__mark" />}
									{option.label}
								</span>
								<span class="outpost-choice__description" id={`${idPrefix}-source-${option.id}-desc`}>
									{option.description}
								</span>
							</span>
						</label>
					))}
				</div>
			</fieldset>

			<p class="outpost-settings__status" role="status" aria-live="polite">
				{message}
			</p>
			<div class="outpost-form-actions">
				{status.kind === 'device-only' && status.reason !== 'unauthorized' && status.reason !== 'forbidden' && (
					<button type="button" class="outpost-button outpost-button--secondary outpost-button--plain" onClick={onRetry}>
						Try saving again
					</button>
				)}
				<button
					type="button"
					class="outpost-button outpost-button--secondary"
					onClick={onReset}
					disabled={is_default}
					aria-describedby={`${idPrefix}-reset-help`}
				>
					Reset preferences
				</button>
			</div>
			<p class="outpost-help" id={`${idPrefix}-reset-help`}>
				Reset returns to Home, System, and Outpost Default. Drafts and your sign-in stay.
			</p>
		</div>
	);
}
