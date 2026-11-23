import type { Meta, StoryObj } from '@storybook/preact-vite';
import { BookmarkletList } from './bookmarklet-list';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/BookmarkletList',
	component: BookmarkletList,
	parameters: source(
		'pwa/src/components/bookmarklet-list.tsx',
		'The four Reply-variant bookmarklets in the About tab, built client-side from `location.origin` so each one points at the site\'s own `/post/share-target` route. It mirrors `Outpost_Admin_Page::build_bookmarklet()` in PHP. Copy source is the fallback on every device, and the iPhone guide renders below the cards.',
	),
} satisfies Meta<typeof BookmarkletList>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
