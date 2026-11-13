import { expect, test, type Page } from '@playwright/test';
import { share_photo, sign_in } from './helpers';

/**
 * T10-S3-05 v1.1: shared content waits for "Use shared content".
 *
 * Runs in a real browser against the wp-env site: the PHP shell, the service
 * worker it emits, and the committed bundle. /preview is stubbed so no request
 * leaves the local site.
 */

const SPOTIFY = 'https://open.spotify.com/track/0000000000000000000000';

async function clear_shares(page: Page): Promise<void> {
	await page.evaluate(async () => {
		sessionStorage.clear();
		localStorage.removeItem('outpost:drafts');
		await new Promise<void>((res) => {
			const r = indexedDB.deleteDatabase('outpost-share-inbox');
			r.onsuccess = r.onerror = r.onblocked = (): void => res();
		});
	});
}

async function drafts(page: Page): Promise<string> {
	return page.evaluate(() => localStorage.getItem('outpost:drafts') ?? '');
}

test.beforeEach(async ({ page }) => {
	await sign_in(page);
	await clear_shares(page);
});

test('a shared link needs "Use shared content" before any preview request', async ({ page }) => {
	let preview_requests = 0;
	await page.route('**/wp-json/outpost/v1/preview**', async (route) => {
		preview_requests++;
		await route.fulfill({ json: { extracted: { 'p-name': 'Stub title' } } });
	});

	await page.goto(`/post/?mode=listen&source=spotify&url=${encodeURIComponent(SPOTIFY)}`);
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toBeVisible();
	await expect(review).toContainText(SPOTIFY);
	await expect(page.locator('#outpost-listen-target')).not.toHaveValue(SPOTIFY);
	await page.waitForTimeout(1000);
	expect(preview_requests).toBe(0);
	expect(await drafts(page)).not.toContain('spotify');

	await review.getByRole('button', { name: 'Use shared content' }).click();
	await expect(review).toBeHidden();
	await expect(page.locator('#outpost-listen-target')).toHaveValue(SPOTIFY);
	expect(preview_requests).toBe(0);

	await page.getByRole('button', { name: 'Fill in details from this link' }).click();
	await expect.poll(() => preview_requests).toBe(1);
});

test('a shared photo is not saved as a draft until it is accepted', async ({ page }) => {
	await share_photo(page, 'shared.png', 'Shared photo title');

	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toBeVisible();
	await expect(review).toContainText('shared.png');
	await page.locator('#outpost-photo-name').fill('typing is not accepting');
	await page.waitForTimeout(1200);
	expect(await drafts(page)).not.toContain('Shared photo title');
	await expect(review).toBeVisible();

	await review.getByRole('button', { name: 'Use shared content' }).click();
	await expect(page.locator('#outpost-photo-name')).toHaveValue('Shared photo title');
	await expect(page.locator('[id^="outpost-photo-alt-"]')).toHaveCount(1);
	await page.locator('[id^="outpost-photo-alt-"]').fill('A shared picture');
	await expect.poll(() => drafts(page)).toContain('Shared photo title');
});

test('a second photo share opens the conflict choices', async ({ page }) => {
	await share_photo(page, 'earlier.png', 'Earlier');
	await share_photo(page, 'newer.png', 'Newer');

	const conflict = page.getByRole('region', { name: 'Two shares are waiting' });
	await expect(conflict).toContainText('earlier.png');
	await expect(conflict).toContainText('newer.png');

	// A third share isn't kept, and the page says so.
	await share_photo(page, 'third.png', 'Third');
	await expect(page.getByRole('region', { name: 'Two shares are waiting' }).getByRole('status')).toContainText(
		"wasn't kept",
	);

	await page.getByRole('button', { name: 'Use new share' }).click();
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toContainText('newer.png');
	await expect(review).not.toContainText('earlier.png');

	await review.getByRole('button', { name: 'Discard shared content' }).click();
	await expect(review).toBeHidden();
	await expect(page.getByRole('status').filter({ hasText: 'Shared content discarded.' })).toBeVisible();
});

test('keep earlier share and discard both', async ({ page }) => {
	await share_photo(page, 'earlier.png', 'Earlier');
	await share_photo(page, 'newer.png', 'Newer');
	await page.getByRole('button', { name: 'Keep earlier share' }).click();
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toContainText('earlier.png');
	await expect(review).not.toContainText('newer.png');

	await share_photo(page, 'another.png', 'Another');
	await page.getByRole('button', { name: 'Discard both' }).click();
	await expect(page.getByRole('region', { name: /Shared content|Two shares are waiting/ })).toHaveCount(0);
});

test('the decision controls work from the keyboard and have accessible names', async ({ page }) => {
	await page.goto('/post/?mode=note&text=Shared%20words');
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toBeVisible();

	const use = review.getByRole('button', { name: 'Use shared content' });
	const discard = review.getByRole('button', { name: 'Discard shared content' });
	await expect(use).toBeVisible();
	await expect(discard).toBeVisible();

	await use.focus();
	await expect(use).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(discard).toBeFocused();
	await page.keyboard.press('Shift+Tab');
	await page.keyboard.press('Enter');

	await expect(review).toBeHidden();
	await expect(page.locator('#outpost-note-content')).toHaveValue('Shared words');
});
