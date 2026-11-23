import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn, userEvent, within } from 'storybook/test';
import { ReminderRollup } from './reminder-rollup';
import type { PendingPost } from '../lib/manual-share/capture-api';
import { noopAsync, source } from '../../../.storybook/fixtures';

const pendingEntry = (id: string, platform_id: string, fired_at: string): PendingPost['entries'][number] => ({
	id,
	platform_id,
	fired_at,
	strategy: 'manual',
	outcome: 'fired',
	completed_at: null,
	silo_url: null,
});

const PENDING: PendingPost[] = [
	{
		post_id: 43,
		post_title: 'Lakeside lunch',
		permalink: 'https://example.test/2026/11/21/lunch/',
		entries: [pendingEntry('b1', 'linkedin', '2026-11-21T18:30:00Z')],
	},
	{
		post_id: 42,
		post_title: 'Summit at sunrise',
		permalink: 'https://example.test/2026/11/20/summit/',
		entries: [pendingEntry('a1', 'instagram-feed', '2026-11-20T14:00:00Z'), pendingEntry('a2', 'threads', '2026-11-20T14:01:00Z')],
	},
];

const meta = {
	title: 'Components/ReminderRollup',
	component: ReminderRollup,
	parameters: source(
		'pwa/src/components/reminder-rollup.tsx',
		'Rolls up every post with pending manual syndications, oldest first, with View to open the post\'s detail view. Snooze all opens the snooze menu; the server rate-limits it, and the button is disabled with a hint when `can_snooze_all` is false. With a count of zero it shows "No pending syndications."',
	),
	args: { pending: PENDING, count: 2, can_snooze_all: true, on_open_post: fn(), on_snooze_all: noopAsync },
} satisfies Meta<typeof ReminderRollup>;

export default meta;
type Story = StoryObj<typeof meta>;

export const List: Story = {};

export const SnoozeMenuOpen: Story = {
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		await userEvent.click(canvas.getByRole('button', { name: 'Snooze all' }));
		await canvas.findByRole('button', { name: 'Cancel' });
	},
};

export const SnoozeDisabled: Story = { args: { can_snooze_all: false } };

export const Empty: Story = { args: { pending: [], count: 0 } };
