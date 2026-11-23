import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn, userEvent, within } from 'storybook/test';
import { ReplyMode } from './reply-mode';
import { micropubOnline, source, token } from '../../../../.storybook/fixtures';

const meta = {
	title: 'Modes/ReplyMode',
	component: ReplyMode,
	parameters: source(
		'pwa/src/components/modes/reply-mode.tsx',
		'The Reply tab: reply, like, favorite, repost, bookmark, RSVP, follow, wishlist, tag, acquisition and issue, each posting the target URL under its own Micropub property. RSVP adds a yes, no, maybe or interested choice. Link previews are not shown here because `lib/preview.ts` calls the global `fetch` directly.',
	),
	args: { token, micropubEnv: micropubOnline, onSignInExpired: fn() },
} satisfies Meta<typeof ReplyMode>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Empty: Story = {};

export const Bookmark: Story = {
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		await userEvent.click(canvas.getByRole('radio', { name: 'Bookmark' }));
		await canvas.findByRole('button', { name: 'Post bookmark' });
	},
};

export const Rsvp: Story = {
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		await userEvent.click(canvas.getByRole('radio', { name: 'RSVP' }));
		await canvas.findByRole('radio', { name: 'Interested' });
	},
};
