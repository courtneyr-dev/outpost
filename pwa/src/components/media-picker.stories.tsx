import type { Meta, StoryObj } from '@storybook/preact-vite';
import { useState } from 'preact/hooks';
import { MediaPicker, type MediaEntry } from './media-picker';
import { placeholderImage, source } from '../../../.storybook/fixtures';

const entry = (id: string, label: string, patch: Partial<MediaEntry> = {}): MediaEntry => ({
	id,
	file: new File([label], `${label.toLowerCase()}.jpg`, { type: 'image/jpeg' }),
	preview_url: placeholderImage(label, 120, 90),
	alt: '',
	decorative: false,
	...patch,
});

const meta = {
	title: 'Components/MediaPicker',
	component: MediaPicker,
	parameters: source(
		'pwa/src/components/media-picker.tsx',
		'Shared picker for Doing, Life and Recipe variants that attach images: file input, thumbnail, alt text, a Decorative toggle, reorder and remove. Every entry needs alt text or the Decorative box, and `all_entries_have_alt` gives parent modes that rule. It is controlled: the parent holds the entries and runs the upload, so these stories wrap it in local state. Move buttons show only with two or more entries.',
	),
	args: { idPrefix: 'story-media', entries: [], onChange: () => undefined },
	render: function Controlled(args) {
		const [entries, setEntries] = useState<MediaEntry[]>(args.entries);
		return <MediaPicker {...args} entries={entries} onChange={setEntries} />;
	},
} satisfies Meta<typeof MediaPicker>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Empty: Story = {};

export const WithEntries: Story = {
	args: {
		entries: [
			entry('ridge', 'Ridge', { alt: 'Snow on the ridge above the treeline at sunrise.' }),
			entry('lake', 'Lake'),
		],
	},
};

export const DecorativeChecked: Story = {
	args: { entries: [entry('texture', 'Texture', { decorative: true })] },
};

export const Disabled: Story = {
	args: {
		disabled: true,
		entries: [entry('ridge', 'Ridge', { alt: 'Snow on the ridge.' }), entry('lake', 'Lake', { alt: 'Still lake.' })],
	},
};
