import type { Meta, StoryObj } from '@storybook/preact-vite';
import { SyndicationStatusBadge } from './syndication-status-badge';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/SyndicationStatusBadge',
	component: SyndicationStatusBadge,
	parameters: source(
		'pwa/src/components/syndication-status-badge.tsx',
		'Composer-side status for a post\'s manual syndication, matching the server\'s five states. State shows as a Unicode glyph plus visible "X/Y" text, never color alone. The badge is `role="img"` with a full `aria-label`, and the glyph is hidden from assistive tech so it is not read twice.',
	),
	args: { status: 'complete', summary: { total: 2, complete: 2, pending: 0, abandoned: 0 } },
} satisfies Meta<typeof SyndicationStatusBadge>;

export default meta;
type Story = StoryObj<typeof meta>;

export const NoSyndication: Story = {
	args: { status: 'no_syndication', summary: { total: 0, complete: 0, pending: 0, abandoned: 0 } },
};

export const Complete: Story = {};

export const Partial: Story = {
	args: { status: 'partial', summary: { total: 3, complete: 1, pending: 2, abandoned: 0 } },
};

export const Pending: Story = {
	args: { status: 'pending', summary: { total: 2, complete: 0, pending: 2, abandoned: 0 } },
};

export const Abandoned: Story = {
	args: { status: 'abandoned', summary: { total: 1, complete: 0, pending: 0, abandoned: 1 } },
};
