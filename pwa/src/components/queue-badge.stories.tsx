import type { Meta, StoryObj } from '@storybook/preact-vite';
import { userEvent, within } from 'storybook/test';
import { QueueBadge } from './queue-badge';
import type { OfflineQueueEnvironment } from '../lib/offline-queue';
import { ME, micropubOffline, seededQueue, source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/QueueBadge',
	component: QueueBadge,
	parameters: source(
		'pwa/src/components/queue-badge.tsx',
		'Header badge for posts saved on this device while the site was unreachable. It reads the `outpost-queue` IndexedDB store, replays posts when the browser comes back online, and renders nothing when the queue is empty. Tapping it opens a drawer to inspect, retry or remove each post. Posts queued by another account stay on the device and are only counted. These stories seed a fake-indexeddb queue and pass an offline Micropub env, so the posts stay queued.',
	),
	args: { owner: ME, micropubEnv: micropubOffline },
	render: (args, { loaded }) => <QueueBadge {...args} queueEnv={loaded['queueEnv'] as OfflineQueueEnvironment} />,
} satisfies Meta<typeof QueueBadge>;

export default meta;
type Story = StoryObj<typeof meta>;

export const OneQueued: Story = {
	loaders: [async () => ({ queueEnv: await seededQueue(['Posted from the ridge, no signal.']) })],
};

export const SeveralQueued: Story = {
	loaders: [
		async () => ({
			queueEnv: await seededQueue([
				'First light at the trailhead.',
				'Lunch by the lake.',
				'A much longer note written while the site was out of reach, to show how the inspector wraps a post that runs past a single line on a phone.',
			]),
		}),
	],
};

export const InspectorOpen: Story = {
	...SeveralQueued,
	parameters: { docs: { story: { inline: false, iframeHeight: 640 } } },
	play: async ({ canvasElement }) => {
		await userEvent.click(await within(canvasElement).findByRole('button', { name: /queued posts/ }));
	},
};

export const OtherAccountOnly: Story = {
	loaders: [async () => ({ queueEnv: await seededQueue(['Queued by a different account.'], 'https://other.test/') })],
};
