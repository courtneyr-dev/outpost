import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { SyndicationDetailView, type DetailEntry } from './syndication-detail-view';
import { noopAsync, offlineFetch, source } from '../../../.storybook/fixtures';

const ENTRIES: DetailEntry[] = [
	{
		id: 'a1',
		platform_id: 'instagram-feed',
		fired_at: '2026-11-20T14:00:00Z',
		strategy: 'clipboard',
		outcome: 'fired',
		completed_at: '2026-11-20T14:05:00Z',
		silo_url: 'https://www.instagram.com/p/storybook/',
		reminder_dismissed_until: null,
	},
	{
		id: 'a2',
		platform_id: 'threads',
		fired_at: '2026-11-20T14:01:00Z',
		strategy: 'manual',
		outcome: 'fired',
		completed_at: null,
		silo_url: null,
		reminder_dismissed_until: null,
	},
	{
		id: 'a3',
		platform_id: 'linkedin',
		fired_at: '2026-11-20T14:02:00Z',
		strategy: 'manual',
		outcome: 'fired',
		completed_at: null,
		silo_url: null,
		reminder_dismissed_until: '2200-01-01T00:00:00+00:00',
	},
];

const meta = {
	title: 'Components/SyndicationDetailView',
	component: SyndicationDetailView,
	parameters: source(
		'pwa/src/components/syndication-detail-view.tsx',
		'Per-post view of the manual-share audit log. Complete entries link to the silo post, pending entries offer Add URL and Snooze, and abandoned entries offer Add URL anyway and Forget reminder. An entry counts as abandoned when its reminder is dismissed more than 10 years out, which is how the server marks Forever.',
	),
	args: {
		post_id: 42,
		post_title: 'Summit at sunrise',
		posted_at: '2026-11-20T13:58:00Z',
		entries: ENTRIES,
		api_env: { fetch: offlineFetch },
		on_snooze: noopAsync,
		on_un_abandon: noopAsync,
		on_entry_recorded: fn(),
	},
} satisfies Meta<typeof SyndicationDetailView>;

export default meta;
type Story = StoryObj<typeof meta>;

export const CompletePendingAbandoned: Story = {};
