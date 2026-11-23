import type { Meta, StoryObj } from '@storybook/preact-vite';
import { within } from 'storybook/test';
import { ComposerTabs } from './composer-tabs';
import { write_cache, type AppearanceEnvironment, type AppearanceMode, type StartScreen } from '../lib/appearance';
import { stash_share_target } from '../lib/share-target';
import type { ComposerConfigEnvironment } from '../lib/composer-config';
import {
	ME,
	composerConfig,
	fakeIndexedDB,
	json,
	memoryStorage,
	micropubOnline,
	offlineFetch,
	pendingFetch,
	source,
	token,
	tokenStore,
} from '../../../.storybook/fixtures';

type StoryGlobals = { globals: Record<string, unknown> };

const schemeMode = ({ globals }: StoryGlobals): AppearanceMode => (globals['scheme'] === 'dark' ? 'night' : 'day');

const seedAppearance =
	(start: StartScreen) =>
	(context: StoryGlobals): void => {
		write_cache({ mode: schemeMode(context), source: 'outpost', css: '', start, me: ME });
	};

const appearanceAnswering = (mode: AppearanceMode, start: StartScreen): AppearanceEnvironment => ({
	fetch: async () => json({ mode_preference: mode, style_source: 'outpost', css: '', start_screen: start }),
});

const configAnswering = (status = 200): ComposerConfigEnvironment => ({
	fetch: async () => (status === 200 ? json(composerConfig()) : json({ code: 'rest_forbidden' }, status)),
});

const meta = {
	title: 'App/ComposerTabs',
	component: ComposerTabs,
	parameters: {
		...source(
			'pwa/src/components/composer-tabs.tsx',
			'The whole composer: header, Home or the mode tabs, the offline queue badge, the install prompt and the Preferences sheet. Every panel renders at once and toggles with `hidden`, so switching tabs keeps form state. It fetches composer config on mount and retries on a backoff; a failure shows a banner above the tabs, and a 401 offers Sign in again. A waiting share is held for review before any mode mounts. These stories seed the device appearance copy to match the Scheme toolbar and answer every request through injected envs.',
		),
		docs: { story: { inline: false, iframeHeight: 800 } },
	},
	args: {
		token,
		tokenStore: tokenStore(),
		micropubEnv: micropubOnline,
		composerConfigEnv: configAnswering(),
		queueEnv: { indexedDB: fakeIndexedDB() },
		draftStorage: memoryStorage(),
		shareInboxEnv: { indexedDB: fakeIndexedDB() },
	},
	render: (args, context) => {
		const start = context.parameters['start'] as StartScreen | undefined;
		const appearanceEnv: AppearanceEnvironment = start
			? appearanceAnswering(schemeMode(context), start)
			: { fetch: pendingFetch };
		return <ComposerTabs {...args} appearanceEnv={appearanceEnv} />;
	},
} satisfies Meta<typeof ComposerTabs>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Home: Story = {
	parameters: { start: 'home' },
	beforeEach: seedAppearance('home'),
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('heading', { name: 'What would you like to post?' });
	},
};

export const StartOnPostTab: Story = {
	parameters: { start: 'note' },
	beforeEach: seedAppearance('note'),
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('button', { name: 'Post article' });
	},
};

export const ConfigUnreachable: Story = {
	args: { composerConfigEnv: { fetch: offlineFetch } },
	parameters: { start: 'home' },
	beforeEach: seedAppearance('home'),
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByText("Couldn't reach your site.");
	},
};

export const ConfigForbidden: Story = {
	args: { composerConfigEnv: configAnswering(403) },
	parameters: { start: 'home' },
	beforeEach: seedAppearance('home'),
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByText("Your site didn't allow loading companion options.");
	},
};

export const SignInExpired: Story = {
	args: { composerConfigEnv: configAnswering(401) },
	parameters: { start: 'home' },
	beforeEach: seedAppearance('home'),
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('button', { name: 'Sign in again' });
	},
};

export const ShareReviewWaiting: Story = {
	parameters: { start: 'home' },
	beforeEach: (context) => {
		seedAppearance('home')(context);
		stash_share_target({ tab: 'note', title: 'Trail conditions', content: 'Snow above 2,000 m, the lower loop is clear.' });
	},
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('button', { name: 'Use shared content' });
	},
};

export const Opening: Story = {
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByText('Opening Outpost…');
	},
};
