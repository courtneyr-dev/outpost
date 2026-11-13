import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright end-to-end tests for the composer PWA.
 *
 * Runs against the wp-env development site (`npm run wp-env:start`), which
 * serves the real PHP shell, the service worker, and the committed
 * `build/pwa/` bundle from this working tree. Run `npm run build` first when
 * the PWA source changed. `tests/e2e/global-setup.ts` creates a disposable
 * editor and an IndieAuth token for it through wp-env; specs sign the PWA in
 * by storing that token the way the app does. Nothing here contacts a
 * service outside the local site: specs stub any request that would.
 */
export default defineConfig({
	testDir: 'tests/e2e',
	globalSetup: './tests/e2e/global-setup.ts',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [['list']],
	use: {
		baseURL: process.env.OUTPOST_E2E_BASE_URL ?? 'http://localhost:8888',
		trace: 'retain-on-failure',
	},
	projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
