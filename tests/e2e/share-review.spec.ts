import { expect, test, type Page } from '@playwright/test';
import { share_photo, sign_in } from './helpers';

/**
 * T10-S3-05 v1.1–v1.2: shared content waits for "Use shared content", and
 * while it waits the decision is the only thing on screen.
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
	// The form can't be reached until the person decides (v1.2).
	await expect(page.locator('#outpost-photo-name')).toBeHidden();
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

/** Where Tab can reach from inside the review: one entry per stop, in order. */
async function tab_stops(page: Page, count: number): Promise<string[]> {
	const stops: string[] = [];
	for (let i = 0; i < count; i++) {
		await page.keyboard.press('Tab');
		stops.push(
			await page.evaluate(() => {
				const el = document.activeElement;
				if (!el || el === document.body) return 'body';
				if (el.closest('[data-outpost-share-review]')) return 'review';
				if (el.getAttribute('name') === 'outpost-header-mode') return 'appearance';
				return el.outerHTML.slice(0, 80);
			}),
		);
	}
	return stops;
}

test('while a share waits, only the decision can be reached; afterwards focus lands on its mode button', async ({ page }) => {
	await page.goto('/post/?mode=note&text=Shared%20words');
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toBeVisible();

	// Since #322 the modes are a group of buttons, not a tablist.
	const modes = page.getByRole('group', { name: 'Composer modes' });
	await expect(modes).toBeHidden();
	await expect(page.locator('#outpost-panel-note')).toBeHidden();
	await expect(page.getByRole('button', { name: 'Preferences' })).toHaveCount(0);
	await expect(page.getByRole('button', { name: 'Home' })).toHaveCount(0);
	await expect(page.getByRole('button', { name: /^Post/ })).toHaveCount(0);

	const stops = await tab_stops(page, 12);
	expect(stops.filter((s) => !['review', 'appearance', 'body'].includes(s))).toEqual([]);

	await review.getByRole('button', { name: 'Use shared content' }).click();
	await expect(review).toBeHidden();
	await expect(modes).toBeVisible();
	const post = modes.getByRole('button', { name: 'Post', exact: true });
	await expect(post).toBeFocused();
	await expect(post).toHaveAttribute('aria-current', 'true');
	await expect(page.getByRole('button', { name: 'Preferences' })).toBeVisible();
	await expect(page.locator('#outpost-note-content')).toHaveValue('Shared words');
});

test('a recipe share fills the name and notes, and shows the link it does not import', async ({ page }) => {
	await page.goto('/post/?mode=recipe&title=Shared%20pie&text=Grandma%27s%20notes&url=https%3A%2F%2Fexample.test%2Fpie');
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toContainText('https://example.test/pie');
	await expect(review).toContainText('Recipe form');
	await review.getByRole('button', { name: 'Use shared content' }).click();
	await expect(page.locator('#outpost-recipe-name')).toHaveValue('Shared pie');
	await expect(page.locator('#outpost-recipe-content')).toHaveValue("Grandma's notes");
	expect(await page.evaluate(() => sessionStorage.getItem('outpost.share_target'))).toBeNull();
});

test('a second text share waits beside the first, both survive a reload, and a third is refused', async ({ page }) => {
	await page.goto('/post/?mode=note&text=First%20shared%20words');
	await expect(page.getByRole('region', { name: 'Shared content' })).toBeVisible();
	await page.goto('/post/?mode=bookmark&url=https%3A%2F%2Fexample.test%2Fsecond');

	const conflict = page.getByRole('region', { name: 'Two shares are waiting' });
	await expect(conflict).toContainText('First shared words');
	await expect(conflict).toContainText('https://example.test/second');

	await page.reload();
	await expect(conflict).toContainText('First shared words');
	await expect(conflict).toContainText('https://example.test/second');

	await page.goto('/post/?mode=note&text=Third%20shared%20words');
	await expect(conflict).not.toContainText('Third shared words');
	await expect(conflict.getByRole('status')).toContainText("wasn't kept");

	await conflict.getByRole('button', { name: 'Use new share' }).click();
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toContainText('https://example.test/second');
	await review.getByRole('button', { name: 'Use shared content' }).click();
	await expect(page.locator('#outpost-reply-target')).toHaveValue('https://example.test/second');
});

test('a photo share after a text share is one decision; choosing it keeps its photo', async ({ page }) => {
	await page.goto('/post/?mode=note&text=First%20shared%20words');
	await expect(page.getByRole('region', { name: 'Shared content' })).toBeVisible();
	await share_photo(page, 'later.png', 'Later photo');

	await expect(page.locator('[data-outpost-share-review]')).toHaveCount(1);
	const conflict = page.getByRole('region', { name: 'Two shares are waiting' });
	await expect(conflict).toContainText('First shared words');
	await expect(conflict).toContainText('later.png');

	await conflict.getByRole('button', { name: 'Use new share' }).click();
	const review = page.getByRole('region', { name: 'Shared content' });
	await expect(review).toContainText('later.png');
	await expect(review).not.toContainText('First shared words');
	await review.getByRole('button', { name: 'Use shared content' }).click();
	await expect(page.locator('#outpost-photo-name')).toHaveValue('Later photo');
	await expect(page.locator('[id^="outpost-photo-alt-"]')).toHaveCount(1);
});

test.describe('focus after a conflict choice (v1.3)', () => {
	for (const action of ['Keep earlier share', 'Use new share']) {
		test(`"${action}" from the keyboard moves focus to Use shared content, inside the decision`, async ({ page }) => {
			await share_photo(page, 'earlier.png', 'Earlier');
			await share_photo(page, 'newer.png', 'Newer');
			const conflict = page.getByRole('region', { name: 'Two shares are waiting' });
			await expect(conflict).toBeVisible();

			await conflict.getByRole('button', { name: action }).focus();
			await page.keyboard.press('Enter');

			const review = page.getByRole('region', { name: 'Shared content' });
			await expect(review).toBeVisible();
			await expect(review.getByRole('button', { name: 'Use shared content' })).toBeFocused();
			const stops = await tab_stops(page, 6);
			expect(stops.filter((s) => !['review', 'appearance', 'body'].includes(s))).toEqual([]);
		});
	}

	test('a choice the device cannot save keeps the conflict and focus on the button', async ({ page }) => {
		// Page-side only: the composer's share-inbox writes throw; the service worker is untouched.
		await page.addInitScript(() => {
			const original = IDBDatabase.prototype.transaction;
			IDBDatabase.prototype.transaction = function (this: IDBDatabase, ...args: Parameters<IDBDatabase['transaction']>) {
				const flag = (window as unknown as { __outpostFailInboxWrites?: boolean }).__outpostFailInboxWrites;
				if (flag && this.name === 'outpost-share-inbox' && args[1] === 'readwrite') {
					throw new DOMException('blocked for the test', 'UnknownError');
				}
				return original.apply(this, args);
			} as IDBDatabase['transaction'];
		});
		await share_photo(page, 'earlier.png', 'Earlier');
		await share_photo(page, 'newer.png', 'Newer');
		const conflict = page.getByRole('region', { name: 'Two shares are waiting' });
		await expect(conflict).toBeVisible();
		await page.evaluate(() => {
			(window as unknown as { __outpostFailInboxWrites?: boolean }).__outpostFailInboxWrites = true;
		});

		const use_new = conflict.getByRole('button', { name: 'Use new share' });
		await use_new.focus();
		await page.keyboard.press('Enter');

		await expect(conflict.getByRole('status')).toContainText("wasn't applied");
		await expect(conflict).toContainText('earlier.png');
		await expect(conflict).toContainText('newer.png');
		await expect(use_new).toBeFocused();
	});
});
