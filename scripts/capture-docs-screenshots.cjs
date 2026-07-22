#!/usr/bin/env node
/**
 * Capture documentation screenshots for Outpost.
 *
 * Boots a disposable WordPress via WordPress Playground CLI (no Docker needed),
 * mounts this plugin, installs the IndieAuth and Micropub dependencies from
 * WordPress.org (see scripts/playground-blueprint.json), and captures the
 * screens listed in docs/src/content/docs/screenshots.md into
 * docs/src/assets/screenshots/ (the directory the docs site reads).
 *
 * The composer captures complete a REAL IndieAuth sign-in against the
 * disposable site: the Playground runs the IndieAuth plugin, so Playwright
 * submits the composer's sign-in form, approves the authorization screen as
 * the logged-in admin, and lands back in the composer with a bearer token.
 * After the approval the WordPress cookies are cleared so cookie auth can't
 * collide with bearer auth (see "Cookie + bearer credential collision" in
 * CLAUDE.md) — that mirrors the real phone-browser situation where the user
 * isn't logged into wp-admin.
 *
 * Prerequisites:
 *   - Node.js 18+
 *   - npm install (installs Playwright from devDependencies)
 *   - npx playwright install chromium (once, to download the browser)
 *   - Network access (the blueprint installs IndieAuth and Micropub from WordPress.org)
 *
 * Usage:
 *   node scripts/capture-docs-screenshots.cjs
 *
 * Environment variables:
 *   WP_BASE_URL      Capture against an already-running WordPress instead of
 *                    launching Playground. The script logs in via wp-login.php
 *                    using WP_ADMIN_USER / WP_ADMIN_PASS.
 *   WP_ADMIN_USER    Admin username (default "admin" — the Playground default).
 *   WP_ADMIN_PASS    Admin password (default "password" — the Playground default).
 *   PLAYGROUND_PORT  Port for the disposable Playground server (default 9400).
 *   PKIW_PLUGIN_DIR  Optional path to a local Post Kinds for IndieWeb checkout.
 *                    When set, it is mounted into the Playground and the
 *                    media-lookup capture (frontend-composer-media-lookup.png)
 *                    is attempted; when unset that capture is skipped.
 */

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(REPO_ROOT, 'docs', 'src', 'assets', 'screenshots');
const BLUEPRINT = path.join(__dirname, 'playground-blueprint.json');
const PORT = process.env.PLAYGROUND_PORT || '9400';
const EXTERNAL_URL = process.env.WP_BASE_URL || '';
const BASE = EXTERNAL_URL || `http://127.0.0.1:${PORT}`;
// Playground CLI's default WordPress credentials. Placeholder values for a
// disposable instance — never real secrets.
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'password';
const PKIW_DIR = process.env.PKIW_PLUGIN_DIR || '';

function resolveChromium() {
	try {
		return require('playwright').chromium;
	} catch (e) {
		try {
			return require('@playwright/test').chromium;
		} catch (e2) {
			console.error(
				'Playwright is not installed. Run `npm install` in the repo root, then `npx playwright install chromium`.'
			);
			process.exit(1);
		}
	}
}

async function waitForServer(url, timeoutMs) {
	const deadline = Date.now() + timeoutMs;
	while (Date.now() < deadline) {
		try {
			const res = await fetch(url, { redirect: 'manual' });
			if (res.status > 0 && res.status < 500) {
				return;
			}
		} catch (e) {
			// Not up yet.
		}
		await new Promise((r) => setTimeout(r, 2000));
	}
	throw new Error(`WordPress did not become reachable at ${url} within ${timeoutMs / 1000}s.`);
}

function launchPlayground() {
	console.log(`Starting WordPress Playground on port ${PORT} (downloads WordPress + dependency plugins on first run)...`);
	const args = [
		'--yes',
		'@wp-playground/cli@latest',
		'server',
		'--auto-mount',
		REPO_ROOT,
		'--blueprint',
		BLUEPRINT,
		'--port',
		PORT,
	];
	if (PKIW_DIR) {
		args.push('--mount', `${PKIW_DIR}:/wordpress/wp-content/plugins/post-kinds-for-indieweb`);
	}
	const child = spawn('npx', args, { stdio: ['ignore', 'pipe', 'pipe'] });
	// The HTTP server starts answering while the blueprint steps (plugin
	// installs, seeding) are still running, so "server reachable" is not
	// "site ready". The CLI prints "Ready!" once the blueprint has finished.
	let readyResolve;
	const ready = new Promise((resolve) => {
		readyResolve = resolve;
	});
	child.stdout.on('data', (d) => {
		process.stdout.write(`[playground] ${d}`);
		if (String(d).includes('Ready!')) {
			readyResolve();
		}
	});
	child.stderr.on('data', (d) => process.stderr.write(`[playground] ${d}`));
	child.on('exit', (code) => {
		if (code && code !== 0 && !shuttingDown) {
			console.error(`Playground exited unexpectedly with code ${code}.`);
			process.exit(1);
		}
	});
	return { child, ready };
}

let shuttingDown = false;

/** Log into wp-admin through the regular login form. */
async function wpLogin(page) {
	await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	if (/wp-admin/.test(page.url()) && !/wp-login/.test(page.url())) {
		return; // Already logged in.
	}
	await page.fill('#user_login', ADMIN_USER);
	await page.fill('#user_pass', ADMIN_PASS);
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
	if (/wp-login/.test(page.url())) {
		throw new Error('wp-admin login failed — check WP_ADMIN_USER / WP_ADMIN_PASS.');
	}
}

/**
 * Complete the IndieAuth sign-in from the composer's login screen. The page
 * must be on /post/ showing the sign-in card, with a logged-in wp-admin
 * session in the same context (the authorization screen requires it).
 */
async function indieAuthSignIn(page, context) {
	await page.locator('button[type=submit]:has-text("Sign in")').click();
	await page.waitForURL(/action=indieauth/, { timeout: 30000 });
	await page.waitForLoadState('domcontentloaded');
	// The authorization screen pre-checks the requested scopes; Approve.
	await page.locator('input[name="wp-submit"], button[name="wp-submit"]').first().click();
	await page.waitForURL(/\/post\/?$/, { timeout: 30000 });
	// Drop the wp-admin cookies so the bearer token authenticates alone —
	// a lingering logged-in cookie makes WordPress demand a REST nonce the
	// bearer request doesn't have (cookie + bearer collision).
	await context.clearCookies();
	await page.reload({ waitUntil: 'networkidle' });
	await page.waitForTimeout(2000);
}

const panel = (id) => `#outpost-panel-${id}`;

(async () => {
	fs.mkdirSync(OUT_DIR, { recursive: true });
	const chromium = resolveChromium();

	let playground = null;
	let playgroundReady = Promise.resolve();
	if (!EXTERNAL_URL) {
		const launched = launchPlayground();
		playground = launched.child;
		playgroundReady = launched.ready;
	}

	try {
		await Promise.race([
			playgroundReady,
			new Promise((_, reject) =>
				setTimeout(() => reject(new Error('Playground did not report Ready! within 300s.')), 300000).unref()
			),
		]);
		await waitForServer(BASE + '/', 300000);
		console.log(`WordPress is up at ${BASE}`);

		const browser = await chromium.launch();
		const ctx = await browser.newContext({
			viewport: { width: 1280, height: 800 },
			deviceScaleFactor: 2,
		});
		const page = await ctx.newPage();
		// Playground's WASM PHP can be slow on cold workers; give every
		// navigation and locator wait generous headroom.
		page.setDefaultNavigationTimeout(90000);
		page.setDefaultTimeout(45000);
		await wpLogin(page);

		const shoot = async (p, file) => {
			const target = path.join(OUT_DIR, file);
			await p.screenshot({ path: target });
			console.log(`captured ${path.relative(REPO_ROOT, target)}`);
		};
		const goto = async (url) => {
			await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
			await page.waitForTimeout(500);
		};

		// 1. Outpost admin page (bookmarklets + phone install steps).
		await goto(BASE + '/wp-admin/admin.php?page=outpost');
		await shoot(page, 'admin-outpost-bookmarklets.png');

		// 2. Composer defaults — the Settings section further down the same page.
		await page.locator('h2:has-text("Settings")').first().scrollIntoViewIfNeeded();
		await page.waitForTimeout(300);
		await shoot(page, 'admin-settings-composer-defaults.png');

		// 3. Outpost settings (tabbed; default tab is API Keys).
		await goto(BASE + '/wp-admin/admin.php?page=outpost-settings');
		await shoot(page, 'admin-settings-api-keys.png');

		// 4. Appearance settings.
		await goto(BASE + '/wp-admin/admin.php?page=outpost-appearance');
		await shoot(page, 'admin-appearance-settings.png');

		// 5. OAuth connections.
		await goto(BASE + '/wp-admin/admin.php?page=outpost-oauth');
		await shoot(page, 'admin-oauth-connections.png');

		// 6. iOS Shortcut settings (Settings → Outpost iOS Shortcut).
		await goto(BASE + '/wp-admin/options-general.php?page=outpost-ios-shortcut');
		await shoot(page, 'admin-ios-shortcut.png');

		// 7. Posts list with the Syndication column. The blueprint seeds posts
		// with complete / partial / pending audit-log states so the badges show
		// real data.
		await goto(BASE + '/wp-admin/edit.php');
		await shoot(page, 'admin-syndication-column.png');

		// 8. Dependency notice: deactivate Micropub, load a fresh admin screen
		// (so the one-off "Plugin deactivated." confirmation isn't in frame),
		// capture the notice, reactivate.
		// The row selectors match on the action link's href (plugin file path)
		// rather than data-slug — the slug attribute is populated from the
		// wordpress.org update check, which the disposable Playground skips.
		const micropubAction = (action) =>
			page.locator(`a[href*="action=${action}"][href*="micropub%2Fmicropub.php"]`).first();
		await goto(BASE + '/wp-admin/plugins.php');
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			micropubAction('deactivate').click(),
		]);
		await goto(BASE + '/wp-admin/plugins.php');
		await page.locator('.notice-warning:has-text("Outpost needs the Micropub plugin")').waitFor({ timeout: 20000 });
		await shoot(page, 'admin-dependency-notice.png');
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			micropubAction('activate').click(),
		]);
		await goto(BASE + '/wp-admin/plugins.php');
		if (!(await micropubAction('deactivate').count())) {
			throw new Error('Micropub failed to reactivate after the dependency-notice capture.');
		}

		// ---- Composer captures (phone-sized viewport). ----
		const mobile = await browser.newContext({
			viewport: { width: 390, height: 844 },
			deviceScaleFactor: 2,
			isMobile: true,
			hasTouch: true,
		});
		const mpage = await mobile.newPage();
		mpage.setDefaultNavigationTimeout(90000);
		mpage.setDefaultTimeout(45000);

		// 9. The sign-in screen at /post/ (no token stored yet).
		await mpage.goto(BASE + '/post/', { waitUntil: 'networkidle' });
		await mpage.waitForSelector('#outpost-me-input', { timeout: 20000 });
		await mpage.waitForTimeout(1000);
		await shoot(mpage, 'frontend-composer-signin.png');

		// Real IndieAuth sign-in: needs a wp-admin session for the
		// authorization screen, dropped again right after approval.
		await wpLogin(mpage);
		await mpage.goto(BASE + '/post/', { waitUntil: 'networkidle' });
		await indieAuthSignIn(mpage, mobile);

		// 10. Note mode. Endpoint discovery (which the syndication chips need)
		// happens on the first successful post, so post once, then compose the
		// pictured note and open More options at the Send to chip list.
		await mpage.locator(panel('note') + ' input[value="note"]').check();
		await mpage.fill('#outpost-note-content', 'Hello from the Outpost composer.');
		await mpage.locator(panel('note') + ' button[type=submit]').click();
		await mpage.waitForSelector(panel('note') + ' .outpost-status:not([hidden])', { timeout: 30000 });
		await mpage.fill(
			'#outpost-note-content',
			'Coffee on the porch, watching the rain roll in. Perfect morning.'
		);
		await mpage.locator(panel('note') + ' button:has-text("More options")').click();
		await mpage.waitForSelector('.outpost-syndication-picker', { timeout: 20000 });
		await mpage.locator('.outpost-syndication-picker').scrollIntoViewIfNeeded();
		await mpage.waitForTimeout(500);
		await shoot(mpage, 'frontend-composer-note-mode.png');
		await mpage.locator('.outpost-drawer[data-open="true"] button[aria-label="Close"]').click();
		await mpage.waitForTimeout(500);

		// 11. Reply mode with a context preview of a post on this same site.
		await mpage.locator('#outpost-tab-reply').click();
		await mpage.fill('#outpost-reply-target', await readingClubUrl(mpage));
		await mpage.locator(panel('reply') + ' button:has-text("Show preview")').click();
		await mpage.waitForSelector(panel('reply') + ' .outpost-citation', { timeout: 20000 });
		await mpage.waitForTimeout(500);
		await mpage.fill(panel('reply') + ' textarea', 'Count me in — starting the first book this weekend.');
		await mpage.evaluate(() => window.scrollTo(0, 0));
		await mpage.waitForTimeout(300);
		await shoot(mpage, 'frontend-composer-reply-mode.png');

		// 12. Photo mode with an attached image and the required alt field focused.
		await mpage.locator('#outpost-tab-photo').click();
		await mpage.setInputFiles(
			panel('photo') + ' input[type=file]',
			path.join(REPO_ROOT, '.wordpress-org', 'icon-256x256.png')
		);
		await mpage.waitForSelector(panel('photo') + ' textarea', { timeout: 10000 });
		const altField = mpage.locator(panel('photo') + ' textarea').first();
		await altField.fill('The Outpost logo: a white signal tower on a teal background.');
		await altField.focus();
		await mpage.waitForTimeout(300);
		await shoot(mpage, 'frontend-composer-photo-mode.png');

		// 13. Offline queue: go offline, queue a note, nudge the queue badge to
		// re-count (it refreshes on the browser's online event — the flush
		// attempt fails while offline and the entry stays queued), then restore
		// the offline state so the connection banner is showing.
		await mobile.setOffline(true);
		await mpage.waitForTimeout(1000);
		await mpage.locator('#outpost-tab-note').click();
		await mpage.fill('#outpost-note-content', 'Notes from the trail — no signal out here, will sync later.');
		await mpage.locator(panel('note') + ' button[type=submit]').click();
		await mpage.waitForSelector(panel('note') + ' .outpost-status:not([hidden])', { timeout: 30000 });
		await mpage.evaluate(() => window.dispatchEvent(new Event('online')));
		await mpage.waitForTimeout(1200);
		await mpage.evaluate(() => window.dispatchEvent(new Event('offline')));
		await mpage.waitForTimeout(800);
		// The banner and the queue badge live at the top of the composer.
		await mpage.evaluate(() => window.scrollTo(0, 0));
		await mpage.waitForTimeout(300);
		await shoot(mpage, 'frontend-offline-queue.png');
		await mobile.setOffline(false);

		// 14. Optional: media lookup (Doing tab "Look it up"). Only attempted
		// when a local Post Kinds for IndieWeb checkout is mounted via
		// PKIW_PLUGIN_DIR — the lookup proxies through that plugin. Activated
		// last so every other capture shows a plain install without it.
		if (PKIW_DIR) {
			await goto(BASE + '/wp-admin/plugins.php');
			const activate = page
				.locator('a[href*="action=activate"][href*="post-kinds-for-indieweb"]')
				.first();
			if (await activate.count()) {
				await Promise.all([
					page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
					activate.click(),
				]);
			}
			await mpage.reload({ waitUntil: 'networkidle' });
			await mpage.waitForTimeout(2000);
			await captureMediaLookup(mpage);
		}

		await mobile.close();
		await browser.close();
		console.log(`Done. Screenshots are in ${path.relative(REPO_ROOT, OUT_DIR)}/`);
	} finally {
		if (playground) {
			shuttingDown = true;
			playground.kill('SIGTERM');
		}
	}

	async function readingClubUrl(p) {
		// The blueprint seeds "Announcing the summer reading club"; resolve its
		// permalink through the public REST index so the reply target (and the
		// server-side preview it drives) points at a real post on this site.
		return p.evaluate(async (fallback) => {
			const res = await fetch('/wp-json/wp/v2/posts?search=summer%20reading%20club&per_page=1', {
				credentials: 'omit',
			});
			const posts = await res.json();
			return Array.isArray(posts) && posts[0] && posts[0].link ? posts[0].link : fallback + '/?p=1';
		}, BASE);
	}

	async function captureMediaLookup(mpage) {
		// The Doing tab surfaces the lookup search when Post Kinds is active.
		await mpage.locator('#outpost-tab-listen').click();
		const query = mpage.locator(panel('listen') + ' .outpost-media-lookup__input');
		if (!(await query.count())) {
			console.log('skipping frontend-composer-media-lookup.png — no lookup affordance (is Post Kinds active?)');
			return;
		}
		await query.first().fill('Rhapsody in Blue');
		await mpage.locator(panel('listen') + ' .outpost-media-lookup__search').first().click();
		try {
			await mpage.waitForSelector(panel('listen') + ' .outpost-media-lookup__results li', { timeout: 30000 });
			await mpage.locator(panel('listen') + ' .outpost-media-lookup__results').scrollIntoViewIfNeeded();
			await mpage.waitForTimeout(500);
			await shoot(mpage, 'frontend-composer-media-lookup.png');
		} catch (e) {
			console.log('skipping frontend-composer-media-lookup.png — lookup returned no results (provider unreachable?)');
		}
	}
})().catch((e) => {
	console.error(e.message || e);
	process.exit(1);
});
