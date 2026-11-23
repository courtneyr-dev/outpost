import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn, userEvent, within } from 'storybook/test';
import { PendingSyndicationsPanel } from './pending-syndications-panel';
import type { PendingPost } from '../lib/manual-share/capture-api';
import { offlineFetch, source } from '../../../.storybook/fixtures';

const PENDING: PendingPost[] = [
	{
		post_id: 42,
		post_title: 'Summit at sunrise',
		permalink: 'https://example.test/2026/11/20/summit/',
		entries: [
			{ id: 'a1', platform_id: 'instagram-feed', fired_at: '2026-11-20T14:00:00Z', strategy: 'manual', outcome: 'fired', completed_at: null, silo_url: null },
			{ id: 'a2', platform_id: 'threads', fired_at: '2026-11-20T14:01:00Z', strategy: 'manual', outcome: 'fired', completed_at: null, silo_url: null },
		],
	},
	{
		post_id: 43,
		post_title: 'Lakeside lunch',
		permalink: 'https://example.test/2026/11/21/lunch/',
		entries: [
			{ id: 'b1', platform_id: 'linkedin', fired_at: '2026-11-21T18:30:00Z', strategy: 'manual', outcome: 'fired', completed_at: null, silo_url: null },
		],
	},
];

const meta = {
	title: 'Components/PendingSyndicationsPanel',
	component: PendingSyndicationsPanel,
	parameters: source(
		'pwa/src/components/pending-syndications-panel.tsx',
		'Lists each manual-share entry still waiting for its silo URL, with the platform, how long ago it fired, and Add URL, which opens the capture form inline. Skip shows only when `on_dismiss_entry` is passed. With no pending entries the panel renders nothing.',
	),
	args: { pending: PENDING, api_env: { fetch: offlineFetch }, on_entry_recorded: fn(), on_dismiss_entry: fn() },
} satisfies Meta<typeof PendingSyndicationsPanel>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Rows: Story = {};

export const CaptureFormOpen: Story = {
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		const [first] = canvas.getAllByRole('button', { name: 'Add URL' });
		if (first) await userEvent.click(first);
		await canvas.findByText('Paste the URL of your Instagram post');
	},
};
