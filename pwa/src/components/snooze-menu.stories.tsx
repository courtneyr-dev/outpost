import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { SnoozeMenu } from './snooze-menu';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/SnoozeMenu',
	component: SnoozeMenu,
	parameters: source(
		'pwa/src/components/snooze-menu.tsx',
		'Picker for how long to snooze one manual-share reminder, or every reminder from the rollup. The options are 1 day, 3 days, 1 week and Forever, which the server records as abandoned. It is presentational: `on_pick` receives the duration the caller sends to the dismiss-reminder or snooze-all endpoint, and Cancel shows only when `on_cancel` is passed.',
	),
	args: { on_pick: fn() },
} satisfies Meta<typeof SnoozeMenu>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

export const WithCancel: Story = { args: { on_cancel: fn() } };

export const Disabled: Story = { args: { on_cancel: fn(), disabled: true } };
