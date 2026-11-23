import type { Meta, StoryObj } from '@storybook/preact-vite';
import { PublishButton } from './publish-button';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/PublishButton',
	component: PublishButton,
	parameters: source(
		'pwa/src/components/publish-button.tsx',
		'The publish action at the end of every composing form: a full-width primary submit button with the variant\'s label, and above it a hint naming the required fields that are still empty. The hint stays mounted while empty, and the button points at it with `aria-describedby` only when it has text. It is not a live region, so it does not talk over typing. The hint hides while a submission runs.',
	),
	args: { idPrefix: 'story-publish', label: 'Post note', disabled: false, missing: [], busy: false },
	decorators: [
		(Story) => (
			<form onSubmit={(event): void => event.preventDefault()}>
				<Story />
			</form>
		),
	],
} satisfies Meta<typeof PublishButton>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Ready: Story = {};

export const MissingContent: Story = { args: { disabled: true, missing: ['Content'] } };

export const MissingSeveral: Story = { args: { label: 'Post recipe', disabled: true, missing: ['Title', 'Ingredients', 'Instructions'] } };

export const Busy: Story = { args: { label: 'Posting…', disabled: true, busy: true, missing: ['Content'] } };
