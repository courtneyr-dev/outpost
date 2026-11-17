import { defineNetworkFixture, type NetworkFixture } from '@msw/playwright';
import { delay, http, HttpResponse, passthrough } from 'msw';
import { expect, homeHeading, noteBody, noteError, openPost, signIn, test as base } from './support';

/**
 * Mocked network outcomes for the composer's Micropub and REST calls
 * (project `network`, service workers blocked so MSW answers first).
 * Mocked tests show what the UI does with a response; they never prove the
 * real backend works. The journeys check covers the real site.
 *
 * Requirements:
 *   BRIEF 7  docs/product/pwa-ui-2026-11-07/implementation-brief.md section 7:
 *            "Differentiate loading, empty, offline, network timeout, 401/expired
 *            sign-in, 403/capability/nonce failures, and server failures. ... A
 *            compact status message offers the right retry or sign-in action while
 *            retaining the draft. ... Keep diagnostics out of ordinary composer copy."
 *   GS       docs/src/content/docs/getting-started.md step 3 (success message with a link)
 *   TASKS    docs/src/content/docs/common-tasks.md "Post while offline" (network
 *            failure queues on the device)
 *
 * Every request needs a handler. The shell, the plugin's static files, the
 * `me` page used for endpoint discovery, Outpost's REST routes, and Micropub
 * queries pass through to the local site on purpose (listed below); a request
 * with no handler fails the test.
 */

const MICROPUB = '*/wp-json/micropub/1.0/endpoint';
const CONFIG = '*/wp-json/outpost/v1/composer-config*';

const passthroughs = [
	http.get(/\/post\/?(\?.*)?$/, () => passthrough()),
	http.get('*/post/manifest.json', () => passthrough()),
	http.get('*/wp-content/plugins/outpost-mobile-publishing/*', () => passthrough()),
	http.get('*/author/:login/', () => passthrough()),
	http.all('*/wp-json/outpost/v1/*', () => passthrough()),
	http.get(MICROPUB, () => passthrough()),
	http.get('*/favicon.ico', () => passthrough()),
];

const LOCATION = 'http://localhost:8883/2026/11/17/wt-mocked-post/';
const post = {
	success: http.post(MICROPUB, () => new HttpResponse(null, { status: 201, headers: { Location: LOCATION } })),
	unauthorized: http.post(MICROPUB, () => HttpResponse.json({ error: 'unauthorized', error_description: 'Unauthorized' }, { status: 401 })),
	forbidden: http.post(MICROPUB, () => HttpResponse.json({ error: 'insufficient_scope', error_description: 'insufficient to create posts' }, { status: 403 })),
	serverError: http.post(MICROPUB, () => HttpResponse.text('Internal Server Error', { status: 500 })),
	networkError: http.post(MICROPUB, () => HttpResponse.error()),
	timeout: http.post(MICROPUB, async () => {
		await delay('infinite');
		return new HttpResponse(null, { status: 201, headers: { Location: LOCATION } });
	}),
};

const test = base.extend<{ network: NetworkFixture; unhandled: string[] }>({
	unhandled: async ({}, use) => {
		const list: string[] = [];
		await use(list);
		expect(list, 'requests with no MSW handler (would have reached the live site)').toEqual([]);
	},
	network: [
		async ({ context, unhandled }, use) => {
			const network = defineNetworkFixture({
				context,
				handlers: [...passthroughs],
				onUnhandledFrame: ({ frame }) => {
					const request = (frame.data as { request?: Request } | undefined)?.request;
					unhandled.push(request ? `${request.method} ${request.url}` : `${frame.protocol} frame`);
					frame.errorWith(new Error('unhandled request'));
				},
			});
			await network.enable();
			await use(network);
			await network.disable();
		},
		{ auto: true },
	],
});

/** Sign in, open Post › Note, type, and press Post note. Returns the typed text. */
async function submitNote(page: import('@playwright/test').Page, label: string): Promise<string> {
	await signIn(page);
	await openPost(page, 'Note');
	const text = `wt-network-${label}`;
	await noteBody(page).fill(text);
	await page.getByRole('button', { name: 'Post note' }).click();
	return text;
}

const errorRegion = noteError;
const DIAGNOSTIC = /post_h_entry|post_failed|micropub endpoint returned|fetch threw/;

test.describe('mocked Micropub outcomes @network', () => {
	test('success shows the posted message with a link to the post', async ({ page, network }) => {
		network.use(post.success);
		await submitNote(page, 'success');
		// GS step 3: "a success message with a link to the new post on your site".
		await expect(page.getByRole('link', { name: /View published post/ })).toHaveAttribute('href', LOCATION);
		await expect(noteBody(page)).toHaveValue('');
	});

	test('a network failure queues the post on the device', async ({ page, network }) => {
		network.use(post.networkError);
		await submitNote(page, 'offline');
		// TASKS "Post while offline": "the draft queues on your device and Outpost submits it
		// automatically when the connection returns. The queue shows pending entries".
		await expect(page.getByText("Saved for later. Outpost will post this when you're back online.")).toBeVisible();
		await expect(page.locator('.outpost-queue-badge')).toHaveAttribute('aria-label', /^1\b/);
	});

	for (const [name, scenario] of [
		['an expired sign-in (401)', 'unauthorized'],
		['a capability refusal (403)', 'forbidden'],
		['a server failure (500)', 'serverError'],
	] as const) {
		test(`${name} keeps the draft and shows a plain message without diagnostics`, async ({ page, network }) => {
			network.use(post[scenario]);
			const text = await submitNote(page, scenario);
			const error = errorRegion(page);
			await expect(error).not.toBeEmpty({ timeout: 15_000 });
			// BRIEF 7: "while retaining the draft".
			await expect(noteBody(page)).toHaveValue(text);
			await expect(page.getByRole('link', { name: /View published post/ })).toHaveCount(0);
			// BRIEF 7: "Keep diagnostics out of ordinary composer copy."
			await expect.soft(error).not.toHaveText(DIAGNOSTIC);
			if (scenario === 'unauthorized') {
				// BRIEF 7: the message "offers the right ... sign-in action".
				await expect.soft(page.getByRole('button', { name: /Sign in again/ })).toBeVisible();
			} else {
				// BRIEF 7: "... retry ..."; the post can be sent again.
				await expect(page.getByRole('button', { name: 'Post note' })).toBeEnabled();
			}
		});
	}

	test('401, 403, and 500 are told apart', async ({ page, network }) => {
		const seen: Record<string, string> = {};
		for (const scenario of ['unauthorized', 'forbidden', 'serverError'] as const) {
			network.use(post[scenario]);
			await submitNote(page, `differ-${scenario}`);
			const error = errorRegion(page);
			await expect(error).not.toBeEmpty({ timeout: 15_000 });
			// A raw diagnostic differs only in the echoed status and body, which isn't
			// telling the cases apart; count every diagnostic as one message.
			const message = (await error.textContent()) ?? '';
			seen[scenario] = DIAGNOSTIC.test(message) ? 'raw diagnostic' : message;
			network.resetHandlers();
			await page.evaluate(() => localStorage.clear());
		}
		// BRIEF 7: "Differentiate ... 401/expired sign-in, 403/capability/nonce failures, and server failures."
		expect(new Set(Object.values(seen)).size, JSON.stringify(seen, null, 1)).toBe(3);
	});

	test('a request that never answers keeps the draft and claims nothing', async ({ page, network }, testInfo) => {
		network.use(post.timeout);
		const text = await submitNote(page, 'timeout');
		await page.waitForTimeout(10_000);
		await expect(page.getByRole('link', { name: /View published post/ })).toHaveCount(0);
		await expect(noteBody(page)).toHaveValue(text);
		// BRIEF 7 also asks for a distinct "network timeout" state but sets no duration,
		// so this test records what shows after 10 s instead of asserting one.
		testInfo.annotations.push({
			type: 'observed-after-10s',
			description: JSON.stringify({
				button: await page.locator('#outpost-panel-note button[type="submit"]').first().textContent(),
				error: await errorRegion(page).textContent(),
			}),
		});
	});
});

test.describe('mocked composer-config outcomes @network', () => {
	test('a 401 on load shows the expired sign-in banner with Sign in again', async ({ page, network }) => {
		network.use(http.post(CONFIG, () => HttpResponse.json({ code: 'rest_forbidden' }, { status: 401 })), http.get(CONFIG, () => HttpResponse.json({ code: 'rest_forbidden' }, { status: 401 })));
		await signIn(page);
		// session-pwa-ui-home.md: "An expired sign-in is a banner on whichever screen opens".
		await expect(page.getByText('Your sign-in expired.')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Sign in again' })).toBeVisible();
		await expect(homeHeading(page)).toBeVisible();
	});

	test('a 500 on load offers Try again, and trying again recovers', async ({ page, network }) => {
		network.use(http.post(CONFIG, () => HttpResponse.text('down', { status: 500 })), http.get(CONFIG, () => HttpResponse.text('down', { status: 500 })));
		await signIn(page);
		const retry = page.getByRole('button', { name: 'Try again' });
		// BRIEF 7: "offers the right retry ... action".
		await expect(retry).toBeVisible();
		network.resetHandlers();
		await retry.click();
		await expect(retry).toBeHidden({ timeout: 15_000 });
	});
});
