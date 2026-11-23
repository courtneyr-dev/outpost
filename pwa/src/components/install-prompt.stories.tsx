import type { Meta, StoryObj } from '@storybook/preact-vite';
import { expect, waitFor, within } from 'storybook/test';
import { InstallPrompt } from './install-prompt';
import { source } from '../../../.storybook/fixtures';

const meta = {
	title: 'Components/InstallPrompt',
	component: InstallPrompt,
	parameters: source(
		'pwa/src/components/install-prompt.tsx',
		'Add to Home Screen banner above the composer, shown once the person has made one successful post and is not already running the installed app. Chromium browsers hold the `beforeinstallprompt` event and offer Install; iOS Safari has no programmatic install, so it shows instructions with Got it. Dismissing is permanent for the device. These stories set `outpost.has_posted`; Android dispatches a stand-in `beforeinstallprompt` event and iOS Safari overrides `navigator.userAgent` for the story only.',
	),
} satisfies Meta<typeof InstallPrompt>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Android: Story = {
	beforeEach: () => {
		localStorage.setItem('outpost.has_posted', '1');
	},
	play: async ({ canvasElement }) => {
		const canvas = within(canvasElement);
		// The listener attaches in an effect after first paint, so keep firing until it has caught one.
		await waitFor(() => {
			const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
				prompt: async (): Promise<void> => undefined,
				userChoice: Promise.resolve({ outcome: 'dismissed' as const }),
			});
			window.dispatchEvent(event);
			expect(canvas.queryByRole('button', { name: 'Install' })).not.toBeNull();
		}, { timeout: 5000 });
		await canvas.findByRole('button', { name: 'Install' });
	},
};

const IPHONE_SAFARI =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

export const IosSafari: Story = {
	beforeEach: () => {
		localStorage.setItem('outpost.has_posted', '1');
		Object.defineProperty(navigator, 'userAgent', { configurable: true, get: () => IPHONE_SAFARI });
		return (): void => {
			delete (navigator as unknown as Record<string, unknown>)['userAgent'];
		};
	},
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('button', { name: 'Got it' });
	},
};
