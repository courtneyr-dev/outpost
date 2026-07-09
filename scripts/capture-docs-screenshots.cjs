#!/usr/bin/env node
/**
 * Capture documentation screenshots for Outpost.
 *
 * Boots a disposable WordPress via WordPress Playground CLI (no Docker needed),
 * mounts this plugin, installs the IndieAuth and Micropub dependencies from
 * WordPress.org (see scripts/playground-blueprint.json), and captures the
 * screens listed in docs/screenshots.md into docs/assets/screenshots/.
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
 *                    launching Playground (must be logged-in-accessible or a
 *                    Playground --login server). No credentials are stored here.
 *   PLAYGROUND_PORT  Port for the disposable Playground server (default 9400).
 */

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(REPO_ROOT, 'docs', 'assets', 'screenshots');
const BLUEPRINT = path.join(__dirname, 'playground-blueprint.json');
const PORT = process.env.PLAYGROUND_PORT || '9400';
const EXTERNAL_URL = process.env.WP_BASE_URL || '';
const BASE = EXTERNAL_URL || `http://127.0.0.1:${PORT}`;

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
	const child = spawn(
		'npx',
		[
			'--yes',
			'@wp-playground/cli@latest',
			'server',
			'--auto-mount',
			REPO_ROOT,
			'--blueprint',
			BLUEPRINT,
			'--login',
			'--port',
			PORT,
		],
		{ stdio: ['ignore', 'pipe', 'pipe'] }
	);
	child.stdout.on('data', (d) => process.stdout.write(`[playground] ${d}`));
	child.stderr.on('data', (d) => process.stderr.write(`[playground] ${d}`));
	child.on('exit', (code) => {
		if (code && code !== 0 && !shuttingDown) {
			console.error(`Playground exited unexpectedly with code ${code}.`);
			process.exit(1);
		}
	});
	return child;
}

let shuttingDown = false;

(async () => {
	fs.mkdirSync(OUT_DIR, { recursive: true });
	const chromium = resolveChromium();

	let playground = null;
	if (!EXTERNAL_URL) {
		playground = launchPlayground();
	}

	try {
		await waitForServer(BASE + '/', 300000);
		console.log(`WordPress is up at ${BASE}`);

		const browser = await chromium.launch();
		const ctx = await browser.newContext({
			viewport: { width: 1280, height: 800 },
			deviceScaleFactor: 2,
		});
		const page = await ctx.newPage();

		// Prime the logged-in admin session (Playground --login authenticates the first visit).
		await page.goto(BASE + '/wp-admin/', { waitUntil: 'networkidle' });
		if (!/wp-admin/.test(page.url()) || /wp-login/.test(page.url())) {
			throw new Error(
				`Could not reach a logged-in wp-admin at ${BASE}. If you passed WP_BASE_URL, make sure the session does not require interactive login.`
			);
		}

		const shoot = async (file) => {
			const target = path.join(OUT_DIR, file);
			await page.screenshot({ path: target });
			console.log(`captured ${path.relative(REPO_ROOT, target)}`);
		};

		// 1. Outpost admin page (bookmarklets + composer defaults).
		await page.goto(BASE + '/wp-admin/admin.php?page=outpost', { waitUntil: 'networkidle' });
		await shoot('admin-outpost-bookmarklets.png');

		// 2. Outpost settings (tabbed; default tab is API Keys).
		await page.goto(BASE + '/wp-admin/admin.php?page=outpost-settings', { waitUntil: 'networkidle' });
		await shoot('admin-settings-api-keys.png');

		// 3. Appearance settings.
		await page.goto(BASE + '/wp-admin/admin.php?page=outpost-appearance', { waitUntil: 'networkidle' });
		await shoot('admin-appearance-settings.png');

		// 4. OAuth connections.
		await page.goto(BASE + '/wp-admin/admin.php?page=outpost-oauth', { waitUntil: 'networkidle' });
		await shoot('admin-oauth-connections.png');

		// 5. iOS Shortcut settings (Settings → Outpost iOS Shortcut).
		await page.goto(BASE + '/wp-admin/options-general.php?page=outpost-ios-shortcut', {
			waitUntil: 'networkidle',
		});
		await shoot('admin-ios-shortcut.png');

		// 6. The composer shell at /post/ (mobile viewport).
		const mobile = await browser.newContext({
			viewport: { width: 390, height: 844 },
			deviceScaleFactor: 2,
			isMobile: true,
			hasTouch: true,
		});
		const mpage = await mobile.newPage();
		await mpage.goto(BASE + '/wp-admin/', { waitUntil: 'domcontentloaded' });
		await mpage.goto(BASE + '/post/', { waitUntil: 'networkidle' });
		await mpage.waitForTimeout(4000);
		await mpage.screenshot({ path: path.join(OUT_DIR, 'frontend-composer-signin.png') });
		console.log('captured docs/assets/screenshots/frontend-composer-signin.png');
		await mobile.close();

		await browser.close();
		console.log(`Done. Screenshots are in ${path.relative(REPO_ROOT, OUT_DIR)}/`);
	} finally {
		if (playground) {
			shuttingDown = true;
			playground.kill('SIGTERM');
		}
	}
})().catch((e) => {
	console.error(e.message || e);
	process.exit(1);
});
