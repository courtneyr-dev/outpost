import type { Meta, StoryObj } from '@storybook/preact-vite';
import { within } from 'storybook/test';
import { AuthCallback } from './auth-callback';
import { ME, authFlowEnv, memoryStorage, pendingFetch, source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/AuthCallback',
	component: AuthCallback,
	parameters: source(
		'pwa/src/components/auth-callback.tsx',
		'Mounts on the IndieAuth redirect back to Outpost. It reads `code` and `state` from the URL, checks the saved state, exchanges the code for a token and stores it, then replaces the location with the composer so Back cannot replay the code. On error it shows the message and a Start over link. The success state is not shown because it navigates away.',
	),
	args: {
		clientId: `${ME}post/`,
		redirectUri: `${ME}post/auth-callback`,
		composerUrl: `${ME}post/`,
	},
} satisfies Meta<typeof AuthCallback>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Pending: Story = {
	args: {
		env: authFlowEnv(
			pendingFetch,
			memoryStorage({
				'outpost.auth.state': 'storybook-state',
				'outpost.auth.verifier': 'storybook-verifier',
				'outpost.auth.me': ME,
				'outpost.auth.token_endpoint': `${ME}token`,
			}),
		),
	},
	beforeEach: () => {
		const original = window.location.href;
		const url = new URL(original);
		url.searchParams.set('code', 'storybook-code');
		url.searchParams.set('state', 'storybook-state');
		window.history.replaceState(window.history.state, '', url);
		return (): void => window.history.replaceState(window.history.state, '', original);
	},
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('heading', { name: 'Signing you in…' });
	},
};

export const MissingCode: Story = {
	args: { env: authFlowEnv(pendingFetch) },
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('heading', { name: "Sign-in didn't complete" });
	},
};
