import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn, userEvent, within } from 'storybook/test';
import { ListenMode } from './listen-mode';
import { json, micropubOnline, source, token } from '../../../../.storybook/fixtures';

const meta = {
	title: 'Modes/ListenMode',
	component: ListenMode,
	parameters: source(
		'pwa/src/components/modes/listen-mode.tsx',
		'The Doing tab: listen, watch, read, play, check in, eat, drink, exercise and more, each with its own fields. Watch, Read and Play can look titles up through the site\'s lookup proxy, and a shared link can fill details only when the person asks. These stories pass stub lookup and preview envs, so nothing leaves Storybook.',
	),
	args: {
		token,
		micropubEnv: micropubOnline,
		onSignInExpired: fn(),
		mediaLookupEnv: { fetch: async () => json({ results: [], kind: 'watch', notConfigured: false }) },
		previewEnv: { fetch: async () => json({ extracted: {} }) },
	},
} satisfies Meta<typeof ListenMode>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Empty: Story = {};

export const Watch: Story = {
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		await userEvent.click(canvas.getByRole('radio', { name: 'Watch' }));
		await canvas.findByRole('button', { name: 'Look it up' });
	},
};
