import { IDBFactory } from 'fake-indexeddb';
import type { StoredToken, TokenStoreEnvironment } from '../pwa/src/lib/token-store';
import type { MicropubEnvironment } from '../pwa/src/lib/micropub';
import { enqueue, type OfflineQueueEnvironment } from '../pwa/src/lib/offline-queue';
import type { ComposerConfig, CompanionId, CompanionStatus } from '../pwa/src/lib/composer-config';

// Fixtures mirror the unit tests: every network call goes through an injected
// `env.fetch`, and IndexedDB is a fresh fake-indexeddb factory per story, so
// stories never reach a live site or the browser's real databases.

export const ME = 'https://example.test/';
export const SITE_HOST = 'example.test';

export const token: StoredToken = {
	accessToken: 'storybook-token',
	tokenType: 'Bearer',
	scope: 'create media',
	me: ME,
	storedAt: 0,
};

export const fakeIndexedDB = (): IDBFactory => new IDBFactory();

export const tokenStore = (): TokenStoreEnvironment => ({
	indexedDB: fakeIndexedDB(),
	crypto: globalThis.crypto,
});

export const noop = (): void => undefined;
export const noopAsync = async (): Promise<void> => undefined;

export const json = (body: unknown, status = 200): Response =>
	new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

/** A request that never settles: holds a component in its loading state. */
export const pendingFetch: typeof fetch = () => new Promise<Response>(() => undefined);

export const offlineFetch: typeof fetch = async () => {
	throw new TypeError('Failed to fetch');
};

const MICROPUB_ENDPOINT = 'https://example.test/micropub';

/** Discovery finds the endpoint; the create POST answers 201 with a Location. */
export const micropubOnline: MicropubEnvironment = {
	fetch: async (_input, init) => {
		if (init?.method === 'POST') {
			return new Response('', { status: 201, headers: { Location: 'https://example.test/2026/11/22/storybook-note' } });
		}
		return new Response('<html></html>', {
			status: 200,
			headers: { Link: `<${MICROPUB_ENDPOINT}>; rel="micropub"` },
		});
	},
};

export const micropubOffline: MicropubEnvironment = { fetch: offlineFetch };

/** Discovery succeeds; the site refuses the post. */
export const micropubRejects: MicropubEnvironment = {
	fetch: async (_input, init) => {
		if (init?.method === 'POST') {
			return json({ error: 'invalid_request', error_description: 'The site rejected this post.' }, 400);
		}
		return new Response('<html></html>', {
			status: 200,
			headers: { Link: `<${MICROPUB_ENDPOINT}>; rel="micropub"` },
		});
	},
};

const ALL_ABSENT: Record<CompanionId, CompanionStatus> = {
	'post-kinds': 'absent',
	'post-formats': 'absent',
	xfn: 'absent',
	'syndication-links': 'absent',
	yoast: 'absent',
	activitypub: 'absent',
	'accessibility-checker': 'absent',
	'rss-chat-routing': 'absent',
};

export const composerConfig = (
	companions: Partial<Record<CompanionId, CompanionStatus>> = {},
	overrides: Partial<ComposerConfig> = {},
): ComposerConfig => ({
	companions: { ...ALL_ABSENT, ...companions },
	postFormats: null,
	xfnRels: [],
	existingCategories: [],
	existingTags: [],
	bridgyHostMap: {},
	siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'note' },
	...overrides,
});

/** Docs description that names the component's source file first. */
export const source = (path: string, usage: string): { docs: { description: { component: string } } } => ({
	docs: { description: { component: `Source: \`${path}\`\n\n${usage}` } },
});

/** An inline SVG image, so cover and photo previews never request a URL. */
export const placeholderImage = (label: string, width = 92, height = 138): string =>
	'data:image/svg+xml,' +
	encodeURIComponent(
		`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="100%" height="100%" fill="#6b6299"/><text x="50%" y="50%" fill="#ffffff" font-family="sans-serif" font-size="14" text-anchor="middle">${label}</text></svg>`,
	);

/** A fresh fake queue holding one queued note per entry in `contents`. */
export const seededQueue = async (contents: string[], me = ME): Promise<OfflineQueueEnvironment> => {
	const queueEnv = { indexedDB: fakeIndexedDB() };
	for (const content of contents) {
		await enqueue({ source: 'note', properties: { content }, micropubEndpoint: 'https://example.test/micropub', me }, queueEnv);
	}
	return queueEnv;
};
