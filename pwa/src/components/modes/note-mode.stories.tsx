import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn, userEvent, within } from 'storybook/test';
import { NoteMode } from './note-mode';
import { composerConfig, micropubOffline, micropubOnline, micropubRejects, source, token } from '../../../../.storybook/fixtures';

const chooseNote = async (canvasElement: HTMLElement): Promise<HTMLElement> => {
	const canvas = within(canvasElement);
	await userEvent.click(canvas.getByRole('radio', { name: 'Note' }));
	return canvas.findByRole('textbox', { name: "What's on your mind?" });
};

const writeAndPost = async (canvasElement: HTMLElement): Promise<void> => {
	const canvas = within(canvasElement);
	await userEvent.type(await chooseNote(canvasElement), 'First light at the trailhead.');
	await userEvent.click(canvas.getByRole('button', { name: 'Post note' }));
};

const meta = {
	title: 'Modes/NoteMode',
	component: NoteMode,
	parameters: source(
		'pwa/src/components/modes/note-mode.tsx',
		'The Post tab: Note, Status, Aside, Article and Quote share one form, and Article adds a required title. With no share, restored draft or site default, the form opens on Article. It discovers the Micropub endpoint on the first post and queues the post on this device when the site is unreachable. More options shows only once composer config has loaded. These stories post through an injected Micropub env that answers, goes offline, or refuses the post.',
	),
	args: { token, micropubEnv: micropubOnline, onSignInExpired: fn() },
} satisfies Meta<typeof NoteMode>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Empty: Story = {};

export const WithComposerConfig: Story = { args: { composerConfig: composerConfig() } };

export const Posted: Story = {
	play: async ({ canvasElement }) => {
		await writeAndPost(canvasElement);
		await within(canvasElement).findByRole('link', { name: /View published post/ });
	},
};

export const Queued: Story = {
	args: { micropubEnv: micropubOffline },
	play: async ({ canvasElement }) => {
		await writeAndPost(canvasElement);
		await within(canvasElement).findByText(/Saved for later/);
	},
};

export const Rejected: Story = {
	args: { micropubEnv: micropubRejects },
	play: async ({ canvasElement }) => {
		await writeAndPost(canvasElement);
		await within(canvasElement).findByText(/didn't accept this post/);
	},
};

export const LongContent: Story = {
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		const textbox = await chooseNote(canvasElement);
		await userEvent.click(textbox);
		await userEvent.paste('Long day on the ridge. '.repeat(40));
		await canvas.findByText('920');
	},
};
