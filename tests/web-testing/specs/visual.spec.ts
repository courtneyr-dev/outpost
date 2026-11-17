import { expect, openPost, signIn, test } from './support';

/**
 * Screenshot comparisons of Light, Dark, and System (projects `desktop` and
 * `pixel7`). Baselines live in specs/__screenshots__/<project>/ and were
 * reviewed by eye against the requirements below before they were accepted.
 * Ordinary runs never write them (`updateSnapshots: 'none'`); see
 * playwright.config.ts for the update command.
 *
 * What a baseline must show (reviewed per image):
 *   - Outpost Default paint: "warm paper, violet ink, and orange actions, with a
 *     violet dark scheme" (docs/src/content/docs/settings.md, Appearance).
 *   - Home: connection line, "What would you like to post?", six cards, About row
 *     (implementation-brief.md section 6); scenery stays decoration.
 *   - Home cards: two columns on phones, three from 40rem (brief section 6).
 *   - Post (viewport capture, so it ends above the publish button): the seven-tab
 *     strip with Post selected, Article first among five style tiles, labeled
 *     Title (required) and Body fields (brief section 6; getting-started.md step 3).
 * Reviewed 2026-11-17: all ten images (desktop, pixel7) matched the above.
 */

type Scheme = 'light' | 'dark';

async function settle(page: import('@playwright/test').Page, ready: import('@playwright/test').Locator): Promise<void> {
	await expect(ready).toBeVisible();
	await page.evaluate(() => document.fonts.ready);
}

test.describe('appearance screenshots @visual', () => {
	for (const [mode, scheme] of [
		['Light', 'light'],
		['Dark', 'dark'],
		['System', 'dark'],
	] as Array<['Light' | 'Dark' | 'System', Scheme]>) {
		test(`Home in ${mode}${mode === 'System' ? ` (device ${scheme})` : ''}`, async ({ page }) => {
			await page.emulateMedia({ colorScheme: scheme === 'dark' && mode === 'System' ? 'dark' : 'light' });
			await signIn(page);
			await page.getByRole('group', { name: 'Appearance' }).first().getByRole('radio', { name: mode }).check();
			await expect(page.locator('body')).toHaveAttribute('data-outpost-scheme', scheme);
			// Home's connection line settles from checking to Connected before the capture.
			await settle(page, page.getByRole('region', { name: 'What would you like to post?' }).getByRole('paragraph').first().filter({ hasText: 'Connected' }));
			await expect(page).toHaveScreenshot(`home-${mode.toLowerCase()}.png`);
		});
	}

	for (const mode of ['Light', 'Dark'] as const) {
		test(`Post composer in ${mode}`, async ({ page }) => {
			await signIn(page);
			await page.getByRole('group', { name: 'Appearance' }).first().getByRole('radio', { name: mode }).check();
			await openPost(page, 'Article');
			await settle(page, page.locator('#outpost-panel-note').getByText(/^Still needed:/));
			await expect(page).toHaveScreenshot(`post-${mode.toLowerCase()}.png`);
		});
	}
});
