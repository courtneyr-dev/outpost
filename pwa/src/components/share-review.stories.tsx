import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { ShareReview, type WaitingShare } from './share-review';
import { source } from '../../../.storybook/fixtures';

const NOTE_SHARE: WaitingShare = {
	share: { tab: 'note', title: 'Trail conditions', content: 'Snow above 2,000 m, the lower loop is clear.' },
	photos: null,
};

const URL_SHARE: WaitingShare = {
	share: { tab: 'reply', replyVariant: 'bookmark', title: 'Leave no trace', url: 'https://example.test/leave-no-trace' },
	photos: null,
};

const PHOTO_SHARE: WaitingShare = {
	share: { tab: 'photo', sharedPhotos: true },
	photos: {
		files: [
			new File(['ridge'], 'ridge.jpg', { type: 'image/jpeg' }),
			new File(['lake'], 'lake.jpg', { type: 'image/jpeg' }),
		],
		title: '',
		text: 'Two from the summit.',
		received: 0,
	},
};

const meta = {
	title: 'Components/ShareReview',
	component: ShareReview,
	parameters: source(
		'pwa/src/components/share-review.tsx',
		'Holds a share from the system share sheet, a bookmarklet, a Shortcut or a share link until the person chooses Use shared content or Discard shared content. Nothing from it is saved as a draft, previewed, posted or queued before that choice. When two shares are waiting, the person picks which to keep first. Shared text renders as text, and nothing here fetches or follows a shared link.',
	),
	args: {
		shares: [NOTE_SHARE],
		status: '',
		onUse: fn(),
		onDiscard: fn(),
		onKeepEarlier: fn(),
		onUseNew: fn(),
		onDiscardBoth: fn(),
	},
} satisfies Meta<typeof ShareReview>;

export default meta;
type Story = StoryObj<typeof meta>;

export const NoteShare: Story = {};

export const UrlShare: Story = { args: { shares: [URL_SHARE] } };

export const PhotoShare: Story = { args: { shares: [PHOTO_SHARE] } };

export const TwoSharesConflict: Story = { args: { shares: [NOTE_SHARE, PHOTO_SHARE] } };

export const EmptyShare: Story = { args: { shares: [{ share: { tab: 'note' }, photos: null }] } };
