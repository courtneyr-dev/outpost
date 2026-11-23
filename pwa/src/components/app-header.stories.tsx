import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { AppHeader } from './app-header';
import { SITE_HOST, source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/AppHeader',
	component: AppHeader,
	parameters: source(
		'pwa/src/components/app-header.tsx',
		'Identity and status row above the tab strip: the signpost and wordmark, the connected site, a Light / Dark / System shortcut, and the Preferences button. The shortcut and the Preferences sheet read and write the same state, so they always agree. `hideSettings` leaves out the Preferences button while a share waits for a decision.',
	),
	args: { siteHost: SITE_HOST, mode: 'system', onMode: fn(), onOpenSettings: fn(), settingsOpen: false },
} satisfies Meta<typeof AppHeader>;

export default meta;
type Story = StoryObj<typeof meta>;

export const System: Story = {};

export const Day: Story = { args: { mode: 'day' } };

export const Night: Story = { args: { mode: 'night' } };

export const SettingsOpen: Story = { args: { settingsOpen: true } };

export const HideSettings: Story = { args: { hideSettings: true } };

export const LongSiteHost: Story = { args: { siteHost: 'a-very-long-subdomain-name.example-blog-hosting-provider.test' } };
