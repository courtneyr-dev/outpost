import type { Meta, StoryObj } from '@storybook/preact-vite';
import { within } from 'storybook/test';
import { ConnectionBanner } from './connection-banner';
import { source } from '../../../.storybook/fixtures';

const overrideNavigator = (key: 'onLine' | 'connection', value: unknown): (() => void) => {
	Object.defineProperty(navigator, key, { configurable: true, get: () => value });
	return (): void => {
		delete (navigator as unknown as Record<string, unknown>)[key];
	};
};

const meta = {
	title: 'Components/ConnectionBanner',
	component: ConnectionBanner,
	parameters: source(
		'pwa/src/components/connection-banner.tsx',
		'Says "you\'re offline right now" from the browser\'s `online` and `offline` events, and shows a Data Saver line from the Network Information API, which only Chromium ships. Both messages are `role="status"` regions that stay mounted with empty text, because iOS VoiceOver misses a live region that appears with text in one step. Online with Data Saver off renders no visible text, so it has no story. These stories override `navigator.onLine` and `navigator.connection` for the story only.',
	),
} satisfies Meta<typeof ConnectionBanner>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Offline: Story = {
	beforeEach: () => overrideNavigator('onLine', false),
	play: async ({ canvasElement }) => {
		window.dispatchEvent(new Event('offline'));
		await within(canvasElement).findByText(/You're offline/);
	},
};

export const DataSaver: Story = {
	beforeEach: () => overrideNavigator('connection', { saveData: true }),
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByText(/Data Saver is on/);
	},
};
