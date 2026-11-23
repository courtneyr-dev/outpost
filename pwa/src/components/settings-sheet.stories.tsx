import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { SettingsSheet } from './settings-sheet';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/SettingsSheet',
	component: SettingsSheet,
	parameters: {
		...source(
			'pwa/src/components/settings-sheet.tsx',
			'The Preferences sheet opened from the header button on any screen. It holds the same panel as About › Preferences, so a change in either place shows in both.',
		),
		docs: { story: { inline: false, iframeHeight: 640 } },
	},
	args: {
		open: true,
		onClose: fn(),
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
} satisfies Meta<typeof SettingsSheet>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Open: Story = {};
