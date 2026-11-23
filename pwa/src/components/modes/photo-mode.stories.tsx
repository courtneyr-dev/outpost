import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { PhotoMode } from './photo-mode';
import { fakeIndexedDB, micropubOnline, source, token } from '../../../../.storybook/fixtures';

const meta = {
	title: 'Modes/PhotoMode',
	component: PhotoMode,
	parameters: source(
		'pwa/src/components/modes/photo-mode.tsx',
		'The Photo tab: a single photo or a gallery, where every photo needs alt text or the Decorative box. On mount it drains photos the service worker parked in the share inbox; this story passes an empty fake-indexeddb inbox.',
	),
	args: { token, micropubEnv: micropubOnline, onSignInExpired: fn(), shareInboxEnv: { indexedDB: fakeIndexedDB() } },
} satisfies Meta<typeof PhotoMode>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Empty: Story = {};
