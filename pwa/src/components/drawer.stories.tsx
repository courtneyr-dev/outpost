import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { Drawer } from './drawer';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/Drawer',
	component: Drawer,
	parameters: {
		...source(
			'pwa/src/components/drawer.tsx',
			'Bottom sheet used for More options, Preferences and the queue inspector. Tapping the scrim or pressing Esc closes it, focus moves inside on open and returns to the trigger on close, Tab stays inside, and body scroll locks while it is open. It always stays in the DOM: `open` toggles a data attribute for the slide, with native `hidden` as the fallback. Pass `idPrefix` when two drawers share a title.',
		),
		docs: { story: { inline: false, iframeHeight: 640 } },
	},
	args: { open: true, onClose: fn(), title: 'More options', idPrefix: 'story', children: null },
} satisfies Meta<typeof Drawer>;

export default meta;
type Story = StoryObj<typeof meta>;

export const OpenShort: Story = {
	args: {
		children: (
			<p class="outpost-help">Categories, tags and syndication targets live here when the site supports them.</p>
		),
	},
};

export const OpenLong: Story = {
	args: {
		children: (
			<div>
				{Array.from({ length: 14 }, (_, index) => (
					<p key={index} class="outpost-help">
						Paragraph {index + 1}. Long drawer content scrolls inside the sheet while the page behind it stays put.
					</p>
				))}
			</div>
		),
	},
};
