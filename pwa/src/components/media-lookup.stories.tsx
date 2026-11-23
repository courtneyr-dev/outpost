import type { Meta, StoryObj } from '@storybook/preact-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { MediaLookup } from './media-lookup';
import type { MediaLookupEnvironment } from '../lib/media-lookup';
import { json, pendingFetch, placeholderImage, source, token } from '../../../.storybook/fixtures';

const lookupEnv = (body: unknown, status = 200): MediaLookupEnvironment => ({
	fetch: async () => json(body, status),
});

const ROWS = [
	{ title: 'The Trail Ahead', cover: placeholderImage('Trail'), creator: 'R. Alvarez', year: '2024', externalId: 'tmdb:1', url: 'https://example.test/movie/1' },
	{ title: 'Ridge Line', cover: '', creator: '', year: '2019', externalId: 'tmdb:2', url: 'https://example.test/movie/2' },
];

const search = async (canvasElement: HTMLElement, query = 'trail'): Promise<void> => {
	const canvas = within(canvasElement);
	await userEvent.type(canvas.getByRole('searchbox'), query);
	await userEvent.click(canvas.getByRole('button', { name: 'Look it up' }));
};

const meta = {
	title: 'Components/MediaLookup',
	component: MediaLookup,
	parameters: source(
		'pwa/src/components/media-lookup.tsx',
		'Search box inside the Doing tab. It asks the site\'s `/wp-json/outpost/v1/lookup` proxy (Post Kinds for IndieWeb\'s providers) for titles and fills the form from the result you tap. A search needs at least 2 characters. "Not configured" means the provider has no API key in Post Kinds → API Connections. The button is `type="button"` because the lookup lives inside the mode\'s form.',
	),
	args: {
		idPrefix: 'story-lookup',
		kind: 'watch',
		label: 'Look up a movie or show',
		accessToken: token.accessToken,
		onSelect: fn(),
		env: lookupEnv({ results: ROWS, kind: 'watch', notConfigured: false }),
	},
} satisfies Meta<typeof MediaLookup>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Idle: Story = {};

export const WatchTypeToggle: Story = { args: { showTypeToggle: true } };

export const Searching: Story = {
	args: { env: { fetch: pendingFetch } },
	play: async ({ canvasElement }) => {
		await search(canvasElement);
		await expect(await within(canvasElement).findByRole('button', { name: 'Looking…' })).toBeDisabled();
	},
};

export const Results: Story = {
	play: async ({ canvasElement }) => {
		await search(canvasElement);
		await within(canvasElement).findByText('The Trail Ahead');
	},
};

export const NoMatches: Story = {
	args: { env: lookupEnv({ results: [], kind: 'watch', notConfigured: false }) },
	play: async ({ canvasElement }) => {
		await search(canvasElement, 'zzzz');
		await within(canvasElement).findByText(/no matches/i);
	},
};

export const NotConfigured: Story = {
	args: { env: lookupEnv({ results: [], kind: 'watch', notConfigured: true }) },
	play: async ({ canvasElement }) => {
		await search(canvasElement);
		await within(canvasElement).findByText(/isn.t configured yet/);
	},
};

export const ServerError: Story = {
	args: { env: lookupEnv({}, 502) },
	play: async ({ canvasElement }) => {
		await search(canvasElement);
		await within(canvasElement).findByText(/returned 502/);
	},
};

export const Disabled: Story = { args: { disabled: true, initialQuery: 'Ridge Line' } };
