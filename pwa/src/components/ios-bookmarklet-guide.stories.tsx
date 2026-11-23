import type { Meta, StoryObj } from '@storybook/preact-vite';
import { IosBookmarkletGuide } from './ios-bookmarklet-guide';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/IosBookmarkletGuide',
	component: IosBookmarkletGuide,
	parameters: source(
		'pwa/src/components/ios-bookmarklet-guide.tsx',
		'Step-by-step bookmarklet install for iPhone Safari, shown below the bookmarklet cards because iOS Safari does not offer Add Bookmark for `javascript:` URLs. The path is to bookmark a regular page, then paste the bookmarklet into the bookmark\'s address. Each step has a checkbox for progress, held in component state only. The steps stay visible rather than behind a disclosure.',
	),
} satisfies Meta<typeof IosBookmarkletGuide>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
