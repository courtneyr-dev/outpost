import type { Meta, StoryObj } from '@storybook/preact-vite';
import { Scenery } from './scenery';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/Scenery',
	component: Scenery,
	parameters: source(
		'pwa/src/components/scenery.tsx',
		'Decorative mountain-and-pine silhouette for the foot of the composer, drawn from flat polygons with no remote asset. It conveys nothing, so it is hidden from assistive tech and never animated. Colors come from `--outpost-scenery-far` and `--outpost-scenery-near`.',
	),
} satisfies Meta<typeof Scenery>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};
