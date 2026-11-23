import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { PreferencesPanel } from './preferences-panel';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/PreferencesPanel',
	component: PreferencesPanel,
	parameters: source(
		'pwa/src/components/preferences-panel.tsx',
		'Default start screen, Light / Dark / System, and Outpost Default or Use Site Theme. It renders in the Preferences sheet and in About › Preferences at the same time, so every id and radio name carries `idPrefix`. The status line stays mounted and only its text changes. Try saving again shows when a save fell back to this device, except after an expired or forbidden sign-in, and Reset is disabled at the defaults.',
	),
	args: {
		idPrefix: 'story-prefs',
		mode: 'system',
		source: 'outpost',
		start: 'home',
		status: { kind: 'idle' },
		onMode: fn(),
		onSource: fn(),
		onStart: fn(),
		onReset: fn(),
		onRetry: fn(),
	},
} satisfies Meta<typeof PreferencesPanel>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Idle: Story = {};

export const Saving: Story = { args: { mode: 'night', status: { kind: 'saving' } } };

export const Saved: Story = { args: { mode: 'night', status: { kind: 'saved' } } };

export const DeviceOnlyOffline: Story = {
	args: { mode: 'night', status: { kind: 'device-only', reason: 'offline', stored: true } },
};

export const DeviceOnlyUnauthorized: Story = {
	args: { mode: 'night', status: { kind: 'device-only', reason: 'unauthorized', stored: false } },
};

export const Customized: Story = { args: { mode: 'day', source: 'site', start: 'note' } };
