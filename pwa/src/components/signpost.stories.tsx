import type { Meta, StoryObj } from '@storybook/preact-vite';
import { Signpost } from './signpost';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/Signpost',
	component: Signpost,
	parameters: source(
		'pwa/src/components/signpost.tsx',
		'The Outpost signpost mark in outline form, colored by `--outpost-logo` through `currentColor`. Next to a visible "Outpost" wordmark, leave `label` unset so the mark stays decorative and hidden from assistive tech. On its own, pass a `label`. The stroke grows at small sizes so it never renders under 1.5 CSS px.',
	),
	args: { size: 32 },
} satisfies Meta<typeof Signpost>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Decorative: Story = {};

export const Labelled: Story = { args: { label: 'Outpost' } };

export const Small: Story = { args: { size: 20 } };

export const Large: Story = { args: { size: 96, label: 'Outpost' } };
