import { defineConfig, devices } from '@playwright/test';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = fileURLToPath(new URL('.', import.meta.url));

/**
 * Requirement-derived browser checks for the composer at /post/, run against
 * the disposable Studio site (see web-testing.config.json) through the
 * web-testing toolkit (`wt`) or directly:
 *
 *   sh tests/web-testing/reset.sh
 *   npx -y -p node@24 npx playwright test -c tests/web-testing/playwright.config.ts --grep @journey
 *
 * Every assertion names the document it comes from. A failing assertion is a
 * product finding to record with its reproduction, not a test to loosen.
 *
 * `wt` sets WT_RUN_DIR (results go under it) and WT_BASE_URL. Ordinary runs
 * never write screenshot baselines. Update them with:
 *   npx -y -p node@24 npx playwright test -c tests/web-testing/playwright.config.ts --grep @visual --update-snapshots
 * Review every changed image against the requirements before committing it.
 */
const baseURL = process.env.WT_BASE_URL ?? 'http://localhost:8883';
const runDir = process.env.WT_RUN_DIR ?? join(HERE, 'output', 'local');
const out = join(runDir, 'playwright');

// Fixed rendering inputs for screenshots and axe.
const stable = { locale: 'en-US', timezoneId: 'UTC', reducedMotion: 'reduce' as const };
const desktop = { ...devices['Desktop Chrome'], ...stable, viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 };
const mobile = { ...devices['Pixel 7'], ...stable, deviceScaleFactor: 1 };

export default defineConfig({
	testDir: 'specs',
	outputDir: join(out, 'test-results'),
	snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{arg}{ext}',
	globalSetup: './global-setup.ts',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60_000,
	forbidOnly: true,
	updateSnapshots: 'none',
	expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.002, threshold: 0.1, animations: 'disabled', caret: 'hide', scale: 'css' } },
	reporter: [
		['list'],
		['json', { outputFile: join(out, 'results.json') }],
	],
	use: { baseURL, trace: 'retain-on-failure', screenshot: 'only-on-failure', video: 'off' },
	projects: [
		{ name: 'desktop', grep: /@journey|@a11y|@visual/, use: desktop },
		{ name: 'pixel7', grep: /@journey|@a11y|@visual|@mobile/, use: mobile },
		// MSW owns the network here; a service worker would answer first, so it's blocked.
		{ name: 'network', grep: /@network/, use: { ...desktop, serviceWorkers: 'block' } },
		// The real service worker: never MSW, never blocked.
		{ name: 'pwa', grep: /@pwa/, use: { ...desktop, serviceWorkers: 'allow' } },
	],
});
