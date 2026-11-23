import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn, userEvent, within } from 'storybook/test';
import { SyndicationCaptureForm } from './syndication-capture-form';
import type { CaptureResponse } from '../lib/manual-share/capture-api';
import { offlineFetch, source } from '../../../.storybook/fixtures';

const submitAnswering = (response: CaptureResponse) => async (): Promise<CaptureResponse> => response;

const pasteAndSave = async (canvasElement: HTMLElement): Promise<void> => {
	const canvas = within(canvasElement);
	await userEvent.type(canvas.getByRole('textbox'), 'https://www.instagram.com/p/storybook/');
	await userEvent.click(canvas.getByRole('button', { name: 'Save' }));
};

const meta = {
	title: 'Components/SyndicationCaptureForm',
	component: SyndicationCaptureForm,
	parameters: source(
		'pwa/src/components/syndication-capture-form.tsx',
		'Inline prompt for the URL of one manually shared post. It moves from idle to submitting, then to recorded, a mismatch warning with Save anyway, or an error. All behavior comes through props: these stories pass `submit_fn` stubs instead of reaching the capture API.',
	),
	args: {
		post_id: 42,
		audit_log_id: 'a1',
		platform_id: 'instagram-feed',
		platform_label: 'Instagram',
		api_env: { fetch: offlineFetch },
		on_recorded: fn(),
		on_cancel: fn(),
	},
} satisfies Meta<typeof SyndicationCaptureForm>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Idle: Story = {};

export const MismatchWarning: Story = {
	args: {
		submit_fn: submitAnswering({
			status: 'mismatch_warning',
			platform_id: 'instagram-feed',
			silo_url: 'https://www.instagram.com/p/storybook/',
			message: 'That URL does not look like an Instagram post. Save it anyway?',
		}),
	},
	play: async ({ canvasElement }) => {
		await pasteAndSave(canvasElement);
		await within(canvasElement).findByRole('button', { name: 'Save anyway' });
	},
};

export const ServerError: Story = {
	args: {
		submit_fn: submitAnswering({ status: 'error', code: 'rest_forbidden', message: 'You can only add URLs to your own posts.' }),
	},
	play: async ({ canvasElement }) => {
		await pasteAndSave(canvasElement);
		await within(canvasElement).findByRole('alert');
	},
};
