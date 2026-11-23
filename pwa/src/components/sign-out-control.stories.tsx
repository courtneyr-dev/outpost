import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn, userEvent, within } from 'storybook/test';
import { SignOutControl } from './sign-out-control';
import type { OfflineQueueEnvironment } from '../lib/offline-queue';
import { ME, noopAsync, seededQueue, source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/SignOutControl',
	component: SignOutControl,
	parameters: source(
		'pwa/src/components/sign-out-control.tsx',
		'"Sign out of this device", with a choice when this account has posts in the offline queue: keep them for the next sign-in, or remove them. Removing deletes only this account\'s posts. The sign-in is always cleared first and queued posts are removed only after that succeeds, so retained work is never deleted while a credential that could publish it remains. With nothing queued, signing out happens at once.',
	),
	args: { owner: ME, clearSignIn: noopAsync, finishSignOut: fn() },
	render: (args, { loaded }) => {
		const queueEnv = loaded['queueEnv'] as OfflineQueueEnvironment | undefined;
		return <SignOutControl {...args} {...(queueEnv ? { queueEnv } : {})} />;
	},
} satisfies Meta<typeof SignOutControl>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Idle: Story = {
	loaders: [async () => ({ queueEnv: await seededQueue([]) })],
};

export const ChooseWithQueuedPosts: Story = {
	loaders: [async () => ({ queueEnv: await seededQueue(['Posted from the ridge, no signal.', 'Lunch by the lake.']) })],
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		await userEvent.click(canvas.getByRole('button', { name: 'Sign out of this device' }));
		await canvas.findByRole('button', { name: 'Keep queued posts on this device' });
	},
};
