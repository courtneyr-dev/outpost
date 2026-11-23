import type { Meta, StoryObj } from '@storybook/preact-vite';
import { CharCounter } from './char-counter';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/CharCounter',
	component: CharCounter,
	parameters: source(
		'pwa/src/components/char-counter.tsx',
		'Counts code points, not bytes, below the textarea it describes. The limit is a soft warning: over the limit the counter turns into "−N over" and posting still works. The polite live region speaks only at 50 or fewer characters remaining, so screen readers do not hear every keystroke. Note and Reply pass 280 (Twitter via Bridgy), 500 (Mastodon) or 300 (Bluesky).',
	),
	args: { value: 'Posting from the trailhead.' },
} satisfies Meta<typeof CharCounter>;

export default meta;
type Story = StoryObj<typeof meta>;

export const NoLimit: Story = {};

export const UnderLimit: Story = { args: { limit: 280 } };

export const NearLimit: Story = { args: { value: 'x'.repeat(265), limit: 280 } };

export const OverLimit: Story = { args: { value: 'x'.repeat(312), limit: 280 } };

export const EmojiCountsAsOne: Story = { args: { value: '🏔️🌲🌲🏕️', limit: 300 } };
