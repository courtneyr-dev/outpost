import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { HomeScreen } from './home-screen';
import { SITE_HOST, source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/HomeScreen',
	component: HomeScreen,
	parameters: source(
		'pwa/src/components/home-screen.tsx',
		'Home is a launcher, not a form: six cards open the matching composer tab, with a connection status line above and an About row below. Each card is one native button named by its title, description and "Unsent draft" when that tab holds a draft. Nothing here holds composer state, so opening Home never drops text or attached photos.',
	),
	args: { siteHost: SITE_HOST, connection: 'connected', drafts: new Set(), onOpen: fn(), onAbout: fn() },
} satisfies Meta<typeof HomeScreen>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Connected: Story = {};

export const Checking: Story = { args: { connection: 'checking' } };

export const Unreachable: Story = { args: { connection: 'unreachable' } };

export const SignedOut: Story = { args: { connection: 'signed-out' } };

export const Forbidden: Story = { args: { connection: 'forbidden' } };

export const WithDrafts: Story = { args: { drafts: new Set(['note', 'recipe'] as const) } };

export const LongSiteHost: Story = { args: { siteHost: 'a-very-long-subdomain-name.example-blog-hosting-provider.test' } };
