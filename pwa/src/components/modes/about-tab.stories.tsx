import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { AboutTab } from './about-tab';
import { version } from '../../../../package.json';
import { ME, composerConfig, fakeIndexedDB, noopAsync, source, token } from '../../../../.storybook/fixtures';

const meta = {
	title: 'Modes/AboutTab',
	component: AboutTab,
	parameters: source(
		'pwa/src/components/modes/about-tab.tsx',
		'About: connection status, sign-out, copyable diagnostics, the same Preferences panel as the header sheet, help links, and the share sheet and bookmarklet guides. Every value shown comes from runtime data, and unknown values say so. Diagnostics never include the token, draft text or location. The version comes from `data-outpost-version` on `#outpost-root`, which a decorator supplies here.',
	),
	decorators: [
		(Story) => (
			<div id="outpost-root" data-outpost-version={version}>
				<Story />
			</div>
		),
	],
	args: {
		siteUrl: ME,
		me: token.me,
		scope: token.scope,
		connection: 'connected',
		companions: composerConfig({ 'post-kinds': 'active', 'syndication-links': 'active' }).companions,
		onRetry: fn(),
		onReauth: fn(),
		onClearSignIn: noopAsync,
		onFinishSignOut: fn(),
		queueOwner: ME,
		queueEnv: { indexedDB: fakeIndexedDB() },
		preferences: {
			mode: 'system',
			source: 'outpost',
			start: 'home',
			status: { kind: 'idle' },
			onMode: fn(),
			onSource: fn(),
			onStart: fn(),
			onReset: fn(),
			onRetry: fn(),
		},
	},
} satisfies Meta<typeof AboutTab>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Connected: Story = {};

export const Checking: Story = { args: { connection: 'checking', companions: null } };

export const SignedOut: Story = { args: { connection: 'signed-out' } };

export const Unreachable: Story = { args: { connection: 'unreachable', companions: null } };

export const CompanionsNotLoaded: Story = { args: { companions: null } };
