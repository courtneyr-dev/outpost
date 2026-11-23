import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { ManualShareFallbackModal } from './manual-share-fallback-modal';
import { placeholderImage, source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/ManualShareFallbackModal',
	component: ManualShareFallbackModal,
	parameters: {
		...source(
			'pwa/src/components/manual-share-fallback-modal.tsx',
			'The iOS fallback when every share strategy in the chain is exhausted: the caption is on the clipboard and the person opens the platform by hand. Save image shows only with `first_image_url`, and Open shows only with `app_homepage_url`. It is presentational, and every action reports through `on_dismiss`.',
		),
		docs: { story: { inline: false, iframeHeight: 640 } },
	},
	args: {
		platform_label: 'Instagram',
		platform_id: 'instagram-feed',
		caption: 'First light at the trailhead.',
		clipboard_text: 'First light at the trailhead.',
		first_image_url: placeholderImage('Photo', 320, 240),
		app_homepage_url: 'https://www.instagram.com',
		on_dismiss: fn(),
	},
} satisfies Meta<typeof ManualShareFallbackModal>;

export default meta;
type Story = StoryObj<typeof meta>;

export const ImageAndApp: Story = {};

export const TextOnly: Story = { args: { first_image_url: null, app_homepage_url: null } };
