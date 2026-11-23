import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { VoiceButton } from './voice-button';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/VoiceButton',
	component: VoiceButton,
	parameters: source(
		'pwa/src/components/voice-button.tsx',
		'Dictation button next to a textarea, using the Web Speech API. Tap to start and tap again to stop; the transcript is appended through `onTranscript` and the parent owns the field. It renders nothing where the API is missing, such as Firefox. These stories never start recording.',
	),
	args: { onTranscript: fn() },
} satisfies Meta<typeof VoiceButton>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Idle: Story = {};

export const Disabled: Story = { args: { disabled: true } };
