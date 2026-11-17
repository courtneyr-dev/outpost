import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, homeHeading, noteBody, openPost, postsContaining, SITE, signIn, test } from './support';

/**
 * The real service worker and manifest (project `pwa`; no MSW, workers allowed).
 *
 * Requirements:
 *   SW     the contract in Outpost_PWA_Shell::render_service_worker()'s doc comment
 *          (includes/class-pwa-shell.php): precache the shell on install and take
 *          over (skipWaiting, clients.claim); the cache name carries OUTPOST_VERSION
 *          and activation deletes every other outpost-* cache; shell network-first
 *          with cache fallback; bundled assets cache-first; /wp-json/outpost/v1/
 *          never cached.
 *   SCOPE  CLAUDE.md Standards: "Service worker scope is /post/ only".
 *   TASKS  docs/src/content/docs/common-tasks.md "Post while offline".
 *   TS     docs/src/content/docs/troubleshooting.md: Android share target needs the
 *          fixed-size PNG manifest icons; "As a PWA it also updates its service
 *          worker on reload."
 *   README readme.txt: "Installs to your home screen ... queues posts made offline".
 *
 * Not tested because nothing documents it: offline editing of published posts,
 * background sync, push.
 */

const ROOT = resolve(fileURLToPath(new URL('.', import.meta.url)), '../../..');
const VERSION = readFileSync(resolve(ROOT, 'outpost.php'), 'utf8').match(/define\( 'OUTPOST_VERSION', '([^']+)' \)/)?.[1] ?? '';
const PLUGIN = '/wp-content/plugins/outpost-mobile-publishing/';

/**
 * Wait until /post/sw serves `version` on several reads in a row. PHP's opcache
 * can serve the previous outpost.php for a moment after the file changes, and a
 * stale read would reinstall the old worker over the new one.
 */
async function swServes(page: import('@playwright/test').Page, version: string): Promise<void> {
	let streak = 0;
	const until = Date.now() + 20_000;
	while (streak < 4) {
		if (Date.now() > until) throw new Error(`/post/sw never settled on ${version}`);
		const text = await page.evaluate(async () => (await fetch('/post/sw', { cache: 'no-store' })).text());
		streak = text.includes(`CACHE_VERSION = '${version}'`) ? streak + 1 : 0;
		await page.waitForTimeout(500);
	}
}

async function controlled(page: import('@playwright/test').Page): Promise<void> {
	await page.evaluate(() => navigator.serviceWorker.ready.then(() => undefined));
	if (!(await page.evaluate(() => navigator.serviceWorker.controller !== null))) await page.reload();
	await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
}

test.describe('service worker and manifest @pwa', () => {
	test('the composer registers its worker at /post/ and precaches the documented shell', async ({ page }) => {
		await page.goto('/post/');
		await controlled(page);
		const state = await page.evaluate(async () => {
			const reg = await navigator.serviceWorker.getRegistration('/post/');
			const keys = await caches.keys();
			return { scope: reg?.scope, script: reg?.active?.scriptURL, keys };
		});
		// SCOPE
		expect(new URL(state.scope ?? '').pathname).toBe('/post/');
		expect(new URL(state.script ?? '').pathname).toBe('/post/sw');
		// SW: CACHE_NAME = 'outpost-' + OUTPOST_VERSION.
		expect(state.keys.filter((k) => k.startsWith('outpost-'))).toEqual([`outpost-${VERSION}`]);
		const precached = await page.evaluate(
			async ({ urls, name }) => {
				const cache = await caches.open(name);
				return Promise.all(urls.map(async (u) => [u, Boolean(await cache.match(u))] as const));
			},
			{
				name: `outpost-${VERSION}`,
				urls: ['/post/', '/post/manifest.json', `${PLUGIN}styles/outpost-tokens.css`, `${PLUGIN}assets/icons/outpost-icon.svg`, `${PLUGIN}assets/icons/outpost-icon-maskable.svg`],
			},
		);
		for (const [url, hit] of precached) expect(hit, `precached ${url}`).toBe(true);
	});

	test('after the first visit, the composer opens offline (bundle cached on first open)', async ({ page, context }) => {
		await signIn(page);
		await controlled(page);
		await expect(homeHeading(page)).toBeVisible();
		await context.setOffline(true);
		await page.reload();
		// SW doc comment: "Caches the PWA shell + bundled assets so the composer loads on a
		// cold device with no connection"; "The bundled JS/CSS get fetched lazily on first
		// composer open and cached by the fetch handler". FAQ: offline "Yes, for composing."
		await expect(homeHeading(page)).toBeVisible();
		await context.setOffline(false);
	});

	test('once a launch is worker-controlled, offline launches open the composer and REST is never cached', async ({ page, context }) => {
		await signIn(page);
		await controlled(page);
		// One launch the worker controls from the start, so its fetch handler sees the bundle.
		await page.reload();
		expect(await page.evaluate(() => (performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming).workerStart > 0)).toBe(true);
		await expect(homeHeading(page)).toBeVisible();
		await context.setOffline(true);
		await page.reload();
		// SW: shell network-first with cache fallback; bundled JS/CSS cache-first.
		await expect(homeHeading(page)).toBeVisible();
		await context.setOffline(false);
		const cached = await page.evaluate(async () => {
			const out: string[] = [];
			for (const key of await caches.keys()) for (const req of await (await caches.open(key)).keys()) out.push(req.url);
			return out;
		});
		// SW: "REST endpoints (/wp-json/outpost/v1/...) → bypass (never cache)".
		expect(cached.filter((u) => u.includes('/wp-json/'))).toEqual([]);
		expect(cached.some((u) => /\/build\/pwa\/assets\/index-[\w-]+\.js$/.test(u)), 'bundle cached').toBe(true);
	});

	test('a post made offline waits in the queue and publishes when the connection returns', async ({ page, context }) => {
		await signIn(page);
		await controlled(page);
		const text = `wt-pwa-offline-${Date.now().toString(36)}`;
		await openPost(page, 'Note');
		await context.setOffline(true);
		await noteBody(page).fill(text);
		await page.getByRole('button', { name: 'Post note' }).click();
		// TASKS: "if the network is down, the draft queues on your device".
		await expect(page.getByText("Saved for later. Outpost will post this when you're back online.")).toBeVisible();
		await expect(page.locator('.outpost-queue-badge')).toHaveAttribute('aria-label', /^1\b/);
		expect(postsContaining(text)).toEqual([]);
		await context.setOffline(false);
		// TASKS: "Outpost submits it automatically when the connection returns". Server-confirmed.
		await expect.poll(() => postsContaining(text).map((p) => p.post_status), { timeout: 30_000 }).toEqual(['publish']);
		await expect(page.locator('.outpost-queue-badge')).toHaveCount(0, { timeout: 15_000 });
	});

	test('a new plugin version installs a new worker that takes over and deletes the old cache', async ({ page }) => {
		await page.goto('/post/');
		await controlled(page);
		const file = join(SITE, 'wp-content/plugins/outpost-mobile-publishing/outpost.php');
		const original = readFileSync(file, 'utf8');
		const next = `${VERSION}-wt-update`;
		try {
			// Simulate an update on the disposable site; reset.sh reinstalls HEAD regardless.
			writeFileSync(file, original.replace(`define( 'OUTPOST_VERSION', '${VERSION}' );`, `define( 'OUTPOST_VERSION', '${next}' );`));
			await swServes(page, next);
			// TS: "As a PWA it also updates its service worker on reload."
			await page.reload();
			await page.waitForFunction(async (name) => (await caches.keys()).includes(name), `outpost-${next}`, { timeout: 20_000 });
			// SW: activation deletes every outpost-* cache that isn't the current version.
			await expect.poll(() => page.evaluate(async () => (await caches.keys()).filter((k) => k.startsWith('outpost-')))).toEqual([`outpost-${next}`]);
			await expect.poll(() => page.evaluate(async () => (await (await navigator.serviceWorker.getRegistration('/post/'))?.active?.scriptURL) ?? '')).toMatch(/\/post\/sw$/);
		} finally {
			writeFileSync(file, original);
		}
	});

	test('the manifest supports installing and the Android share sheet', async ({ request }) => {
		const res = await request.get('/post/manifest.json');
		expect(res.ok()).toBe(true);
		const m = (await res.json()) as {
			name?: string;
			start_url?: string;
			scope?: string;
			display?: string;
			icons?: Array<{ src: string; sizes?: string; type?: string }>;
			share_target?: { action?: string };
		};
		expect(m.name).toBeTruthy();
		// README: "Installs to your home screen"; GS step 4 opens straight to Outpost.
		expect(new URL(m.start_url ?? '', 'http://localhost:8883').pathname).toMatch(/^\/post\//);
		expect(new URL(m.scope ?? '', 'http://localhost:8883').pathname).toBe('/post/');
		expect(['standalone', 'fullscreen', 'minimal-ui']).toContain(m.display);
		// TS: Android registers a share target only with the fixed-size PNG icons.
		const png = (m.icons ?? []).filter((i) => i.type === 'image/png' || i.src.endsWith('.png'));
		for (const size of ['192x192', '512x512']) expect(png.some((i) => (i.sizes ?? '').split(' ').includes(size)), `PNG icon ${size}`).toBe(true);
		for (const icon of png) expect((await request.get(new URL(icon.src, 'http://localhost:8883/post/').href)).status(), icon.src).toBe(200);
		expect(new URL(m.share_target?.action ?? '', 'http://localhost:8883/post/').pathname).toMatch(/^\/post\/share-target\/?$/);
	});
});
