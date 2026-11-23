import type { Meta, StoryObj } from '@storybook/preact-vite';
import { DraftStatus } from './draft-status';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/DraftStatus',
	component: DraftStatus,
	parameters: source(
		'pwa/src/components/draft-status.tsx',
		'One line under a mode\'s form that says what happened to the draft. It is not a live region, because "Draft saved" after every pause in typing would interrupt a screen reader mid-sentence. The paragraph stays mounted and only its text changes, so it can be read on demand.',
	),
	args: { state: 'saved' },
} satisfies Meta<typeof DraftStatus>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Restored: Story = { args: { state: 'restored' } };

export const Saved: Story = {};

export const Unavailable: Story = { args: { state: 'unavailable' } };
