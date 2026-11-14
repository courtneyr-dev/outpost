import { expect, test, type Page } from '@playwright/test';
import { session, sign_in } from './helpers';

/**
 * T10-S3-07: queued posts belong to the exact account that queued them.
 *
 * Runs in Chromium against the wp-env site with the committed bundle. Queue
 * rows are written straight into the `outpost-queue` IndexedDB, as an earlier
 * visit would have left them: one owned by the signed-in e2e editor, one by
 * another account on the same site, one legacy row with no owner. Micropub
 * requests go to a routed stub, so nothing is published.
 */

const STUB = 'http://localhost:8888/e2e-stub-micropub';
const OTHER_OWNER = 'http://localhost:8888/author/someone-else';

/** The canonical identity the app derives from a `me` URL (written out here, not imported). */
function canonical(me: string): string {
	const url = new URL(me);
	let path = url.pathname || '/';
	if (path.length > 1) path = path.replace(/\/+$/, '') || '/';
	return url.protocol + '//' + url.host + path + url.search;
}

interface Row {
	content: string;
	owner?: string;
}

async function seed(page: Page, rows: Row[]): Promise<void> {
	const { me } = session();
	await page.evaluate(
		async ({ rows, me, stub }) => {
			const db = await new Promise<IDBDatabase>((res, rej) => {
				const r = indexedDB.open('outpost-queue', 1);
				r.onupgradeneeded = (): void => {
					r.result.createObjectStore('queue', { autoIncrement: true });
				};
				r.onsuccess = (): void => res(r.result);
				r.onerror = (): void => rej(r.error);
			});
			await new Promise<void>((res, rej) => {
				const tx = db.transaction('queue', 'readwrite');
				const store = tx.objectStore('queue');
				store.clear();
				for (const row of rows) {
					store.add({
						source: 'note',
						properties: { content: row.content },
						micropubEndpoint: stub,
						me,
						...(row.owner ? { owner: row.owner } : {}),
						createdAt: 1,
						attempts: 0,
					});
				}
				tx.oncomplete = (): void => res();
				tx.onerror = (): void => rej(tx.error);
			});
			db.close();
		},
		{ rows, me, stub: STUB },
	);
}

async function queue_contents(page: Page): Promise<string[]> {
	return page.evaluate(
		() =>
			new Promise<string[]>((res) => {
				const r = indexedDB.open('outpost-queue', 1);
				r.onsuccess = (): void => {
					const req = r.result.transaction('queue', 'readonly').objectStore('queue').getAll();
					req.onsuccess = (): void => {
						r.result.close();
						res((req.result as Array<{ properties: { content: string } }>).map((row) => row.properties.content));
					};
				};
			}),
	);
}

async function has_token(page: Page): Promise<boolean> {
	return page.evaluate(
		() =>
			new Promise<boolean>((res) => {
				const r = indexedDB.open('outpost', 1);
				r.onsuccess = (): void => {
					const req = r.result.transaction('tokens', 'readonly').objectStore('tokens').get('micropub');
					req.onsuccess = (): void => {
						r.result.close();
						res(req.result !== undefined);
					};
				};
			}),
	);
}

async function open_about(page: Page): Promise<void> {
	const tab = page.getByRole('tab', { name: 'About' });
	if (await tab.isVisible()) {
		await tab.click();
	} else {
		await page.getByRole('button', { name: /about the connection, in About/ }).click();
	}
}

test.beforeEach(async ({ page }) => {
	await sign_in(page);
});

test('the signed-in account sees and sends only its own queued post; other work shows as a count', async ({ page }) => {
	const posted: Array<{ authorization: string | null; body: string }> = [];
	await page.route(STUB + '**', async (route) => {
		posted.push({ authorization: route.request().headers()['authorization'] ?? null, body: route.request().postData() ?? '' });
		await route.fulfill({ status: 201, headers: { Location: 'http://localhost:8888/?p=1' } });
	});
	await seed(page, [
		{ content: 'Own queued words', owner: canonical(session().me) },
		{ content: 'Other person private words', owner: OTHER_OWNER },
		{ content: 'Legacy private words' },
	]);
	await page.reload();

	await expect.poll(() => posted.length).toBe(1);
	expect(posted[0]?.body).toContain('Own+queued+words');
	expect(posted[0]?.authorization).toBe('Bearer ' + session().token);

	const note = page.locator('.outpost-queue-others');
	await expect(note).toHaveText(
		'2 queued posts on this device belong to another account or an older version of Outpost. They stay here and aren’t shown.',
	);
	await expect(page.locator('body')).not.toContainText('Other person private words');
	await expect(page.locator('body')).not.toContainText('Legacy private words');
	await page.waitForTimeout(1000);
	expect(posted).toHaveLength(1);
	await expect.poll(() => queue_contents(page)).toEqual(['Other person private words', 'Legacy private words']);
});

test.describe('signing out with queued posts', () => {
	test.beforeEach(async ({ page }) => {
		// The site is "down": the owner's post stays queued.
		await page.route(STUB + '**', (route) => route.fulfill({ status: 503 }));
		await seed(page, [
			{ content: 'Own queued words', owner: canonical(session().me) },
			{ content: 'Other person private words', owner: OTHER_OWNER },
		]);
		await page.reload();
		await open_about(page);
		await page.getByRole('button', { name: 'Sign out of this device' }).click();
		await expect(page.getByText('You have 1 queued post on this device that hasn’t been sent.')).toBeFocused();
	});

	test('"Keep queued posts on this device" signs out and keeps every post', async ({ page }) => {
		await page.getByRole('button', { name: 'Keep queued posts on this device' }).click();

		await expect(page.getByRole('button', { name: /sign in/i }).first()).toBeVisible();
		expect(await has_token(page)).toBe(false);
		expect(await queue_contents(page)).toEqual(['Own queued words', 'Other person private words']);
	});

	test('"Remove my queued posts and sign out" removes only this account’s post', async ({ page }) => {
		await page.getByRole('button', { name: 'Remove my queued posts and sign out' }).click();

		await expect(page.getByRole('button', { name: /sign in/i }).first()).toBeVisible();
		expect(await has_token(page)).toBe(false);
		expect(await queue_contents(page)).toEqual(['Other person private words']);
	});
});

test.describe('sign-out failures in the real UI (T10-S3-07 v1.1)', () => {
	/**
	 * Page-side switches: when set, deleting the sign-in token, or any write to
	 * the queue database, throws. The service worker is untouched.
	 */
	async function install_failure_switches(page: Page): Promise<void> {
		await page.addInitScript(() => {
			type Flags = { __failTokenDelete?: boolean; __failQueueWrites?: boolean };
			const flags = window as unknown as Flags;
			const original_delete = IDBObjectStore.prototype.delete;
			IDBObjectStore.prototype.delete = function (this: IDBObjectStore, ...args: Parameters<IDBObjectStore['delete']>) {
				if (flags.__failTokenDelete && this.name === 'tokens') throw new DOMException('blocked for the test', 'UnknownError');
				return original_delete.apply(this, args);
			};
			const original_transaction = IDBDatabase.prototype.transaction;
			IDBDatabase.prototype.transaction = function (this: IDBDatabase, ...args: Parameters<IDBDatabase['transaction']>) {
				if (flags.__failQueueWrites && this.name === 'outpost-queue' && args[1] === 'readwrite') {
					throw new DOMException('blocked for the test', 'UnknownError');
				}
				return original_transaction.apply(this, args);
			} as IDBDatabase['transaction'];
		});
	}

	async function flip(page: Page, flag: '__failTokenDelete' | '__failQueueWrites', on: boolean): Promise<void> {
		await page.evaluate(
			([name, value]) => {
				(window as unknown as Record<string, boolean>)[name as string] = value as boolean;
			},
			[flag, on] as const,
		);
	}

	test.beforeEach(async ({ page }) => {
		await install_failure_switches(page);
		await page.route(STUB + '**', (route) => route.fulfill({ status: 503 }));
		await seed(page, [
			{ content: 'Own queued words', owner: canonical(session().me) },
			{ content: 'Other person private words', owner: OTHER_OWNER },
		]);
		await page.reload();
		await open_about(page);
	});

	test('when the sign-in can’t be removed, Remove deletes nothing and says the person is still signed in', async ({ page }) => {
		await page.getByRole('button', { name: 'Sign out of this device' }).click();
		await flip(page, '__failTokenDelete', true);
		await page.getByRole('button', { name: 'Remove my queued posts and sign out' }).click();

		await expect(page.locator('.outpost-status').filter({ hasText: 'still signed in' })).toHaveText(
			'Couldn’t remove the sign-in from this device, so you’re still signed in and nothing was removed. Try again.',
		);
		expect(await has_token(page)).toBe(true);
		expect(await queue_contents(page)).toEqual(['Own queued words', 'Other person private words']);
		await expect(page.getByRole('button', { name: 'Remove my queued posts and sign out' })).toBeVisible();
	});

	test('when the posts can’t be removed after signing out, says so, keeps them, and a retry removes only this account’s', async ({ page }) => {
		await page.getByRole('button', { name: 'Sign out of this device' }).click();
		await flip(page, '__failQueueWrites', true);
		await page.getByRole('button', { name: 'Remove my queued posts and sign out' }).click();

		await expect(page.locator('.outpost-status').filter({ hasText: 'signed out of this device' })).toHaveText(
			'You’re signed out of this device, but your queued posts couldn’t be removed and are still here. Try removing them again, or finish signing out and keep them.',
		);
		expect(await has_token(page)).toBe(false);
		await flip(page, '__failQueueWrites', false);
		expect(await queue_contents(page)).toEqual(['Own queued words', 'Other person private words']);

		await page.getByRole('button', { name: 'Try removing my queued posts again' }).click();
		await expect(page.getByRole('button', { name: /sign in/i }).first()).toBeVisible();
		expect(await queue_contents(page)).toEqual(['Other person private words']);
	});

	test('“Finish signing out” after that partial failure reloads and keeps the posts', async ({ page }) => {
		await page.getByRole('button', { name: 'Sign out of this device' }).click();
		await flip(page, '__failQueueWrites', true);
		await page.getByRole('button', { name: 'Remove my queued posts and sign out' }).click();
		await expect(page.getByRole('button', { name: 'Finish signing out' })).toBeVisible();

		await page.getByRole('button', { name: 'Finish signing out' }).click();
		await expect(page.getByRole('button', { name: /sign in/i }).first()).toBeVisible();
		expect(await has_token(page)).toBe(false);
		expect(await queue_contents(page)).toEqual(['Own queued words', 'Other person private words']);
	});
});
