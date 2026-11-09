import { Drawer } from './drawer';
import { PreferencesPanel, type PreferencesPanelProps } from './preferences-panel';

export { status_text } from './preferences-panel';

/**
 * Preferences sheet, opened from the header button on any screen. Holds
 * the same panel as About › Preferences, so a change in either place shows
 * in both.
 */
export interface SettingsSheetProps extends Omit<PreferencesPanelProps, 'idPrefix'> {
	open: boolean;
	onClose: () => void;
}

export function SettingsSheet({ open, onClose, ...panel }: SettingsSheetProps): preact.JSX.Element {
	return (
		<Drawer open={open} onClose={onClose} title="Preferences" idPrefix="outpost-settings">
			<PreferencesPanel idPrefix="outpost-settings" {...panel} />
		</Drawer>
	);
}
