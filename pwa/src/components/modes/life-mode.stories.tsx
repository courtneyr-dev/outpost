import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { LifeMode } from './life-mode';
import { composerConfig, json, micropubOnline, source, token } from '../../../../.storybook/fixtures';

const meta = {
	title: 'Modes/LifeMode',
	component: LifeMode,
	parameters: source(
		'pwa/src/components/modes/life-mode.tsx',
		'The Life tab: mood, weather, sleep, trip, itinerary and question. When Post Kinds is active, Mood suggests the site\'s mood vocabulary, fetched through the `moodsEnv` stub here and cached on the device.',
	),
	args: { token, micropubEnv: micropubOnline, onSignInExpired: fn(), composerConfig: composerConfig() },
} satisfies Meta<typeof LifeMode>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Empty: Story = {};

export const PostKindsActive: Story = {
	args: {
		composerConfig: composerConfig({ 'post-kinds': 'active' }),
		moodsEnv: {
			fetch: async () =>
				json({
					spelling: 'american',
					locale: 'en_US',
					version: 'storybook-1',
					moods: [
						{ key: 'happy', label: 'Happy', variants: ['glad'] },
						{ key: 'tired', label: 'Tired', variants: ['sleepy'] },
					],
				}),
		},
	},
};
