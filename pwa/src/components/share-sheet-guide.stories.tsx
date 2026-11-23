import type { Meta, StoryObj } from '@storybook/preact-vite';
import { ShareSheetGuide } from './share-sheet-guide';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/ShareSheetGuide',
	component: ShareSheetGuide,
	parameters: source(
		'pwa/src/components/share-sheet-guide.tsx',
		'Static About-tab helper for sending a link, quote or photo into Outpost from the system share sheet. Android and desktop Chromium register Outpost as a Web Share Target once installed, while iOS needs a Shortcut that opens the same `/post/share-target` route. URLs come from `location.origin`, so in Storybook they point at the Storybook host. Links open in a new tab through `ExternalLink`.',
	),
} satisfies Meta<typeof ShareSheetGuide>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
