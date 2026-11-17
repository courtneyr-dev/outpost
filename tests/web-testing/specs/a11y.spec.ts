import { expect, homeHeading, noteBody, noteError, openPost, scanState, signIn, test } from './support';

/**
 * axe at the composer's meaningful states (projects `desktop` and `pixel7`).
 *
 * Requirement: "The target is WCAG 2.2 Level AA" (docs/src/content/docs/accessibility.md)
 * and "Meet WCAG 2.2 AA in the rendered application" (implementation-brief.md
 * section 9). Each state's rule ids and targets are attached as axe-<state>.json.
 * axe finding nothing is not conformance; the same page says so.
 */

test.describe('accessibility scans @a11y', () => {
	test('Home, light and dark', async ({ page }, testInfo) => {
		await signIn(page);
		await expect(homeHeading(page)).toBeVisible();
		const header = page.getByRole('group', { name: 'Appearance' }).first();
		await header.getByRole('radio', { name: 'Light' }).check();
		await scanState(page, testInfo, 'home-light');
		await header.getByRole('radio', { name: 'Dark' }).check();
		await scanState(page, testInfo, 'home-dark');
	});

	test('Post composer with the missing-fields hint', async ({ page }, testInfo) => {
		await signIn(page);
		await openPost(page, 'Article');
		await expect(page.locator('#outpost-panel-note').getByText(/^Still needed:/)).toBeVisible();
		await scanState(page, testInfo, 'composer-post');
	});

	test('Preferences sheet (style source and theme controls)', async ({ page }, testInfo) => {
		await signIn(page);
		await page.getByRole('button', { name: 'Preferences' }).click();
		await expect(page.getByRole('dialog', { name: 'Preferences' })).toBeVisible();
		await scanState(page, testInfo, 'preferences-sheet');
	});

	test('About', async ({ page }, testInfo) => {
		await signIn(page);
		await page.getByRole('button', { name: /^About Outpost/ }).click();
		await expect(page.getByRole('region', { name: 'About Outpost' })).toBeVisible();
		await scanState(page, testInfo, 'about');
	});

	test('error states: expired sign-in banner and a rejected post', async ({ page }, testInfo) => {
		await signIn(page, 'expired');
		await expect(page.getByText('Your sign-in expired.')).toBeVisible({ timeout: 15_000 });
		await scanState(page, testInfo, 'error-expired-banner');
		await openPost(page, 'Note');
		await noteBody(page).fill('wt a11y error state');
		await page.getByRole('button', { name: 'Post note' }).click();
		await expect(noteError(page)).not.toBeEmpty({ timeout: 20_000 });
		await scanState(page, testInfo, 'error-post-rejected');
	});

	test('signed-out sign-in screen', async ({ page }, testInfo) => {
		await page.goto('/post/');
		await expect(page.locator('#outpost-login-title')).toBeVisible();
		await scanState(page, testInfo, 'sign-in');
	});
});
