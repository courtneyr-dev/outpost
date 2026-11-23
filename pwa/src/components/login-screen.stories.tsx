import type { Meta, StoryObj } from '@storybook/preact-vite';
import { userEvent, within } from 'storybook/test';
import { LoginScreen } from './login-screen';
import { ME, authFlowEnv, offlineFetch, pendingFetch, source } from '../../../.storybook/fixtures';

const signIn = async (canvasElement: HTMLElement): Promise<void> => {
	await userEvent.click(within(canvasElement).getByRole('button', { name: 'Sign in' }));
};

const meta = {
	title: 'Components/LoginScreen',
	component: LoginScreen,
	parameters: source(
		'pwa/src/components/login-screen.tsx',
		'One field for your site, defaulting to the origin Outpost runs on, and a Sign in button that starts IndieAuth in the same tab. Submitting discovers the site\'s IndieAuth endpoints, saves PKCE and state to sessionStorage, and navigates to the authorization URL. Discovery errors show inline so the person can correct the URL and retry. These stories inject an auth env whose requests never succeed, so the page never navigates.',
	),
	args: {
		clientId: `${ME}post/`,
		redirectUri: `${ME}post/auth-callback`,
		env: authFlowEnv(pendingFetch),
	},
} satisfies Meta<typeof LoginScreen>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Idle: Story = {};

export const DefaultMePrefilled: Story = { args: { defaultMe: 'https://notes.example.test/' } };

export const Pending: Story = {
	play: async ({ canvasElement }) => {
		await signIn(canvasElement);
		await within(canvasElement).findByRole('button', { name: 'Signing in…' });
	},
};

export const DiscoveryError: Story = {
	args: { env: authFlowEnv(offlineFetch) },
	play: async ({ canvasElement }) => {
		await signIn(canvasElement);
		await within(canvasElement).findByRole('alert');
	},
};
