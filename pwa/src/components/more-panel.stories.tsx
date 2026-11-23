import type { Meta, StoryObj } from '@storybook/preact-vite';
import { within } from 'storybook/test';
import { MorePanel, useMorePanelValues } from './more-panel';
import type { MicropubEnvironment, SyndicationTarget } from '../lib/micropub';
import { composerConfig, json, offlineFetch, pendingFetch, source, token } from '../../../.storybook/fixtures';

const ENDPOINT = 'https://example.test/micropub';
const MASTODON_BRIDGY = 'https://brid.gy/publish/mastodon';

const answeringTargets = (targets: SyndicationTarget[]): MicropubEnvironment => ({
	fetch: async () => json({ 'syndicate-to': targets }),
});

const TARGETS: SyndicationTarget[] = [
	{ uid: 'https://example.test/syndicate/mastodon', name: 'Mastodon' },
	{ uid: 'https://example.test/syndicate/bluesky', name: 'Bluesky' },
];

const ALL_ACTIVE = composerConfig(
	{ 'post-formats': 'active', xfn: 'active', yoast: 'active', 'post-kinds': 'active', 'rss-chat-routing': 'active' },
	{
		postFormats: ['aside', 'status', 'quote', 'link'],
		xfnRels: ['friend', 'met', 'colleague'],
		existingCategories: [
			{ slug: 'hiking', name: 'Hiking' },
			{ slug: 'travel', name: 'Travel' },
		],
		existingTags: [
			{ slug: 'indieweb', name: 'IndieWeb' },
			{ slug: 'posse', name: 'POSSE' },
		],
	},
);

const meta = {
	title: 'Components/MorePanel',
	component: MorePanel,
	parameters: source(
		'pwa/src/components/more-panel.tsx',
		'The More options fields inside each mode\'s drawer. Categories, tags and slug always show; Post Format, Yoast keyphrase, XFN, Post surface and rss.chat routing show only when their companion plugin is active. Syndication targets load from the Micropub `?q=syndicate-to` query once the endpoint is known, and a Bridgy target for the reply URL\'s silo is suggested and pre-checked. The panel is controlled, so these stories hold values with `useMorePanelValues`.',
	),
	args: {
		token,
		composerConfig: composerConfig(),
		values: {
			categories: [],
			tags: [],
			slug: null,
			postFormat: null,
			yoastFocusKw: null,
			xfnRels: [],
			syndicateTo: [],
			promoteToMain: false,
			rssChatRouting: null,
		},
		onChange: () => undefined,
		micropubEndpoint: null,
		idPrefix: 'story-more',
	},
	render: function Controlled(args) {
		const [values, setValues] = useMorePanelValues(args.composerConfig);
		return <MorePanel {...args} values={values} onChange={setValues} />;
	},
} satisfies Meta<typeof MorePanel>;

export default meta;
type Story = StoryObj<typeof meta>;

export const NoCompanions: Story = {};

export const AllCompanionsActive: Story = {
	args: { composerConfig: ALL_ACTIVE, xfnTargetUrl: 'https://friend.example.test/2026/11/trail-report' },
};

export const SyndicationTargets: Story = {
	args: { micropubEndpoint: ENDPOINT, micropubEnv: answeringTargets(TARGETS) },
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('checkbox', { name: 'Bluesky' });
	},
};

export const SyndicationLoading: Story = {
	args: { micropubEndpoint: ENDPOINT, micropubEnv: { fetch: pendingFetch } },
};

export const SyndicationError: Story = {
	args: { micropubEndpoint: ENDPOINT, micropubEnv: { fetch: offlineFetch } },
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByText(/Couldn't load syndication targets/);
	},
};

export const BridgySuggestion: Story = {
	args: {
		composerConfig: composerConfig(
			{},
			{
				bridgyHostMap: { 'mastodon.social': { name: 'Mastodon', uid: MASTODON_BRIDGY } },
				siteSettings: { bridgyAutoSuggest: true, defaultPostVariant: 'note' },
			},
		),
		xfnTargetUrl: 'https://mastodon.social/@friend/1',
		micropubEndpoint: ENDPOINT,
		micropubEnv: answeringTargets([{ uid: MASTODON_BRIDGY, name: 'Mastodon via Bridgy' }, ...TARGETS]),
	},
	play: async ({ canvasElement }) => {
		await within(canvasElement).findByRole('group', { name: 'Suggested (from target URL)' });
	},
};
