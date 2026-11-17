import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = fileURLToPath(new URL('.', import.meta.url));
import { adminCookies, contrast, expect, footerHome, homeHeading, modes, noteBody, noteError, openPost, pickStyle, postsContaining, signIn, test, userMeta } from './support';

/**
 * User journeys on the real site (projects `desktop` and `pixel7`).
 *
 * Requirement sources, cited per test:
 *   HOME   docs/decisions/session-pwa-ui-home.md
 *   BRIEF  docs/product/pwa-ui-2026-11-07/implementation-brief.md (sections 3, 5, 6, 7, 8)
 *   GS     docs/src/content/docs/getting-started.md
 *   SET    docs/src/content/docs/settings.md
 *   TASKS  docs/src/content/docs/common-tasks.md
 *   A11Y   docs/src/content/docs/accessibility.md
 *   PRIV   docs/src/content/docs/privacy-and-data.md
 *   LOG    CHANGELOG.md [Unreleased]
 */

const ROOT = resolve(HERE, '../../..');
const tokenFile = readFileSync(resolve(ROOT, 'styles/outpost-tokens.css'), 'utf8');

/** A token's value in one block of styles/outpost-tokens.css (the Outpost Default design). */
function token(block: string, name: string): string {
	const start = tokenFile.indexOf(block);
	const body = tokenFile.slice(start, tokenFile.indexOf('}', start));
	const m = body.match(new RegExp(`--outpost-${name}:\\s*([^;]+);`));
	if (!m) throw new Error(`no --outpost-${name} in ${block}`);
	return m[1].trim().toLowerCase();
}
const LIGHT_BG = token('.outpost-mode-day,', 'surface-bg');
const DARK_BG = token('.outpost-mode-night {', 'surface-bg');

const CARDS = [
	{ card: 'Post', title: 'Post' },
	{ card: 'Reply', title: 'Reply' },
	{ card: 'Photo', title: 'Photo' },
	{ card: 'Doing', title: 'Doing' },
	{ card: 'Life', title: 'Life' },
	{ card: 'Recipe', title: 'Recipe' },
] as const;

const marker = (label: string): string => `wt-${label}-${Date.now().toString(36)}`;

test.describe('composer journeys @journey', () => {
	test('Home opens by default with the six post types and an About row', async ({ page }) => {
		await signIn(page);
		// HOME "Default: home"; GS step 3 "Outpost opens on Home, with a card for each kind of post".
		await expect(homeHeading(page)).toBeVisible();
		await expect(page).toHaveTitle('Home – Outpost');
		const cards = page.getByRole('region', { name: 'What would you like to post?' }).getByRole('listitem').getByRole('button');
		// BRIEF 6: six cards, Post, Reply, Photo, Doing, Life, Recipe, in that order; each is
		// "named by all of its visible text, title first" (WCAG 2.5.3 Label in Name).
		await expect(cards).toHaveCount(CARDS.length);
		for (const [i, c] of CARDS.entries()) {
			const visible = (await cards.nth(i).innerText()).replace(/\s+/g, ' ').trim();
			expect(visible.startsWith(c.card + ' ')).toBe(true);
			await expect(cards.nth(i)).toHaveAccessibleName(visible);
		}
		await expect(page.getByRole('button', { name: /^About Outpost/ })).toBeVisible();
		// BRIEF 6: a connection line from runtime state, never hard-coded.
		const line = page.getByRole('region', { name: 'What would you like to post?' }).getByRole('paragraph').first();
		await expect(line).toContainText('Connected');
		await expect(line).toContainText('localhost:8883');
		// HOME: "Home has no footer link."
		await expect(footerHome(page)).toBeHidden();
	});

	test('each Home card opens its tab, and ← Home returns with focus on the Home heading', async ({ page }) => {
		await signIn(page);
		for (const { card, title } of [...CARDS, { card: 'About Outpost', title: 'About' }]) {
			await page.getByRole('button', { name: new RegExp(`^${card}\\b`) }).first().click();
			// A11Y "a page title that names the active tab, such as Reply – Outpost".
			await expect(page).toHaveTitle(`${title} – Outpost`);
			// HOME: a card "shows the tabs, and moves focus to the selected tab".
			const selected = modes(page).getByRole('button', { name: title, exact: true });
			await expect(selected).toHaveAttribute('aria-current', /.+/);
			await expect(selected).toBeFocused();
			// HOME / BRIEF 7: one "← Home" in a nav after the panels, in page flow, never fixed or sticky.
			const home = footerHome(page);
			await expect(home).toBeVisible();
			const position = await page.getByRole('navigation', { name: 'Outpost' }).evaluate((el) => getComputedStyle(el).position);
			expect(['fixed', 'sticky'], `footer nav position for ${title}`).not.toContain(position);
			await home.click();
			// HOME: "The ← Home link sets view to Home and moves focus to the Home heading".
			await expect(homeHeading(page)).toBeFocused();
			await expect(page).toHaveTitle('Home – Outpost');
		}
	});

	test('a note is saved as a draft, restored after reload, then published and confirmed on the server', async ({ page }) => {
		const s = await signIn(page);
		const text = marker('note');
		await openPost(page, 'Note');
		const body = noteBody(page);
		await body.fill(text);
		// GS step 3 / TASKS: "After you pause, 'Draft saved' appears under the form".
		await expect(page.getByText('Draft saved', { exact: true })).toBeVisible();
		await page.reload();
		// HOME: a card whose tab holds a saved draft adds "Unsent draft" to its name.
		await expect(page.getByRole('button', { name: /^Post\b.*Unsent draft/ })).toBeVisible();
		await page.getByRole('button', { name: /^Post\b/ }).first().click();
		// TASKS: "the text comes back with 'Draft restored from this device. Nothing posts until you post it.'"
		await expect(body).toHaveValue(text);
		await expect(page.getByText('Draft restored from this device. Nothing posts until you post it.')).toBeVisible();
		await pickStyle(page, 'Note');
		await page.getByRole('button', { name: 'Post note' }).click();
		// GS step 3: "a success message with a link to the new post on your site".
		const link = page.getByRole('link', { name: /View published post/ });
		await expect(link).toBeVisible({ timeout: 20_000 });
		// Server-confirmed: exactly one post, published, by this person, at the linked URL.
		await expect.poll(() => postsContaining(text).length).toBe(1);
		const [post] = postsContaining(text);
		expect(post.post_status).toBe('publish');
		expect(Number(post.post_author)).toBe(s.userId);
		expect(post.post_content).toContain(text);
		expect(await link.getAttribute('href')).toBe(post.permalink);
		// PRIV: "Drafts clear when you post".
		await page.reload();
		await expect(page.getByRole('button', { name: /^Post\b/ }).first()).not.toHaveAccessibleName(/Unsent draft/);
	});

	test('text typed just before a reload comes back after it (prior Bombadil finding b)', async ({ page }) => {
		await signIn(page);
		const text = marker('quick');
		await openPost(page, 'Note');
		await noteBody(page).fill(text);
		// GS First-run notes: "each tab keeps its unsent text on your device, so a reload
		// or a fresh sign-in doesn't lose it." TASKS adds that the save runs "about half a
		// second after you stop typing"; this reload comes sooner than that.
		await page.reload();
		await page.getByRole('button', { name: /^Post\b/ }).first().click();
		await expect(noteBody(page)).toHaveValue(text);
	});

	test('with More options saved open, Home still scrolls and only the mode on screen opens a drawer (prior Bombadil finding a)', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await signIn(page);
		await page.evaluate(() => localStorage.setItem('outpost.composer.more-open', '1'));
		await page.reload();
		await expect(homeHeading(page)).toBeVisible();
		// LOG [Unreleased] Fixed: "The drawer now opens only in the mode on screen"; before
		// it, "five hidden drawers stayed open and locked page scrolling: Home couldn't scroll".
		const openDrawers = page.locator('.outpost-drawer[data-open="true"]');
		await expect(openDrawers).toHaveCount(0);
		expect(await page.evaluate(() => document.body.style.overflow)).not.toBe('hidden');
		const room = await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
		if (room > 0) {
			await page.mouse.move(180, 400);
			await page.mouse.wheel(0, 300);
			await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
		}
		await page.getByRole('button', { name: /^Post\b/ }).first().click();
		expect(await openDrawers.count()).toBeLessThanOrEqual(1);
	});

	test('the default start screen is saved to the site and opens on the next launch on another device', async ({ page, browser, baseURL }) => {
		const s = await signIn(page);
		await page.getByRole('button', { name: 'Preferences' }).click();
		const sheet = page.getByRole('dialog', { name: 'Preferences' });
		await sheet.getByRole('combobox', { name: 'Default start screen' }).selectOption({ label: 'Reply' });
		// HOME / SET: stored as user meta outpost_start_screen with the tab id; "follows you to every device".
		await expect.poll(() => userMeta(s.userId ?? 0, 'outpost_start_screen'), { timeout: 10_000 }).toBe('reply');
		// BRIEF 3: "Changing the preference never navigates."
		await sheet.getByRole('button', { name: /Close/ }).first().click();
		await expect(homeHeading(page)).toBeVisible();
		// Same device, next launch.
		await page.reload();
		await expect(page).toHaveTitle('Reply – Outpost');
		// Another device: no device copy, so it asks the site (BRIEF 3: at most 1.5 s, then Home).
		const other = await browser.newContext({ baseURL });
		const second = await other.newPage();
		await signIn(second);
		await expect(second).toHaveTitle('Reply – Outpost', { timeout: 5_000 });
		await other.close();
	});

	test('Light, Dark, and System apply the Outpost Default schemes and save per person', async ({ page }) => {
		await page.emulateMedia({ colorScheme: 'light' });
		const s = await signIn(page);
		const body = page.locator('body');
		const bg = (): Promise<string> => page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--outpost-surface-bg').trim().toLowerCase());
		// SET: "New people start on Outpost Default and System."
		await expect(body).toHaveClass(/outpost-source-outpost/);
		await expect(body).toHaveClass(/outpost-mode-system/);
		const header = page.getByRole('group', { name: 'Appearance' }).first();
		// SET: "Light and Dark always show that scheme."
		await header.getByRole('radio', { name: 'Dark' }).check();
		await expect(body).toHaveClass(/outpost-mode-night/);
		await expect(body).toHaveAttribute('data-outpost-scheme', 'dark');
		expect(await bg()).toBe(DARK_BG);
		await expect.poll(() => userMeta(s.userId ?? 0, 'outpost_appearance_mode'), { timeout: 10_000 }).toBe('night');
		await header.getByRole('radio', { name: 'Light' }).check();
		await expect(body).toHaveAttribute('data-outpost-scheme', 'light');
		expect(await bg()).toBe(LIGHT_BG);
		await expect.poll(() => userMeta(s.userId ?? 0, 'outpost_appearance_mode'), { timeout: 10_000 }).toBe('day');
		// SET: "System follows your phone or computer setting and switches while Outpost is open."
		await header.getByRole('radio', { name: 'System' }).check();
		await expect(body).toHaveAttribute('data-outpost-scheme', 'light');
		await page.emulateMedia({ colorScheme: 'dark' });
		await expect(body).toHaveAttribute('data-outpost-scheme', 'dark');
		expect(await bg()).toBe(DARK_BG);
	});

	test('Use Site Theme is a separate choice, and Reset preferences returns to Home, System, and Outpost Default', async ({ page }) => {
		const s = await signIn(page);
		const body = page.locator('body');
		await page.getByRole('group', { name: 'Appearance' }).first().getByRole('radio', { name: 'Dark' }).check();
		await page.getByRole('button', { name: 'Preferences' }).click();
		const sheet = page.getByRole('dialog', { name: 'Preferences' });
		await sheet.getByRole('radio', { name: 'Use Site Theme' }).check();
		// HOME/SET: style source and appearance are "two independent choices".
		await expect(body).toHaveClass(/outpost-source-site/);
		await expect(body).toHaveClass(/outpost-mode-night/);
		await expect.poll(() => userMeta(s.userId ?? 0, 'outpost_appearance_source'), { timeout: 10_000 }).toBe('site');
		await sheet.getByRole('combobox', { name: 'Default start screen' }).selectOption({ label: 'Photo' });
		// SET: "Reset preferences returns to Home, System, and Outpost Default."
		await sheet.getByRole('button', { name: 'Reset preferences' }).click();
		await expect(body).toHaveClass(/outpost-source-outpost/);
		await expect(body).toHaveClass(/outpost-mode-system/);
		await expect(sheet.getByRole('combobox', { name: 'Default start screen' })).toHaveValue('home');
		await expect.poll(() => userMeta(s.userId ?? 0, 'outpost_appearance_source'), { timeout: 10_000 }).toBe('outpost');
		await expect.poll(() => userMeta(s.userId ?? 0, 'outpost_start_screen'), { timeout: 10_000 }).toBe('home');
	});

	test('About shows runtime connection facts, the version, and documentation links to real destinations', async ({ page }) => {
		const s = await signIn(page);
		await page.getByRole('button', { name: /^About Outpost/ }).click();
		const about = page.getByRole('region', { name: 'About Outpost' });
		// HOME / BRIEF 8: four sections, in order.
		await expect(about.getByRole('heading', { level: 3 })).toHaveText(['Connection', 'Preferences', 'Help and documentation', 'Project']);
		// BRIEF 8: the connected site's actual address, state, and signed-in me, from runtime data.
		await expect(about.getByRole('link', { name: /^localhost:8883\b/ })).toHaveAttribute('href', /^http:\/\/localhost:8883\/?$/);
		await expect(about.getByText(s.me, { exact: true })).toBeVisible();
		const status = about.locator('dt:text-is("Status") + dd');
		await expect(status).toContainText('Connected');
		await expect(status).not.toContainText(/not connected/i);
		// HOME: version and credits from readme.txt.
		const stable = readFileSync(resolve(ROOT, 'readme.txt'), 'utf8').match(/^Stable tag:\s*(\S+)/m)?.[1] ?? '';
		await expect(about.getByText(new RegExp(`\\bOutpost ${stable.replace(/\./g, '\\.')}\\b`)).filter({ visible: true })).toBeVisible();
		// BRIEF 8: "Link to real destinations".
		const links: Array<[RegExp, string]> = [
			[/^Getting started\b/, 'https://courtneyr-dev.github.io/outpost/getting-started/'],
			[/^Plugin documentation\b/, 'https://courtneyr-dev.github.io/outpost/'],
			[/^Sharing and shortcuts\b/, 'https://courtneyr-dev.github.io/outpost/common-tasks/#share-to-outpost-from-your-phone'],
			[/^Troubleshooting\b/, 'https://courtneyr-dev.github.io/outpost/troubleshooting/'],
			[/^Privacy and data\b/, 'https://courtneyr-dev.github.io/outpost/privacy-and-data/'],
			[/^GitHub repository\b/, 'https://github.com/courtneyr-dev/outpost'],
			[/^Report an issue\b/, 'https://github.com/courtneyr-dev/outpost/issues'],
		];
		for (const [name, href] of links) {
			const link = about.getByRole('link', { name });
			await expect(link, String(name)).toHaveAttribute('href', href);
			// A11Y: "links that open a new tab say so to screen readers".
			if ((await link.getAttribute('target')) === '_blank') await expect(link).toHaveAccessibleName(/opens in (a )?new tab/);
		}
	});

	test('the signpost is decorative beside a visible wordmark and meets 3:1 in light and dark', async ({ page }) => {
		await signIn(page);
		const mark = page.locator('.outpost-app-header__mark');
		for (const mode of ['Light', 'Dark'] as const) {
			await page.getByRole('group', { name: 'Appearance' }).first().getByRole('radio', { name: mode }).check();
			await expect(mark).toBeVisible();
			// BRIEF 5: "An adjacent Outpost wordmark can make the inline mark decorative with
			// aria-hidden; a standalone mark needs an accessible name."
			const hidden = await mark.getAttribute('aria-hidden');
			if (hidden === 'true') {
				await expect(page.locator('.outpost-app-header').getByText('Outpost', { exact: true })).toBeVisible();
			} else {
				await expect(mark).toHaveAccessibleName(/.+/);
			}
			// A11Y: "3:1 for borders, focus, and the logo".
			const [fg, bg] = await mark.evaluate((el) => {
				let node: Element | null = el.parentElement;
				let back = 'rgba(0, 0, 0, 0)';
				while (node && /rgba\(0, 0, 0, 0\)|transparent/.test(back)) {
					back = getComputedStyle(node).backgroundColor;
					node = node.parentElement;
				}
				return [getComputedStyle(el).color, back];
			});
			expect(contrast(fg, bg), `${mode}: signpost ${fg} on ${bg}`).toBeGreaterThanOrEqual(3);
		}
	});

	test('a subscriber cannot create a post, keeps the text, and cannot open the Appearance page', async ({ page, baseURL }) => {
		await signIn(page, 'subscriber');
		const text = marker('subscriber');
		await openPost(page, 'Note');
		const body = noteBody(page);
		await body.fill(text);
		await page.getByRole('button', { name: 'Post note' }).click();
		// Micropub creates only for users with edit_posts; BRIEF 7: a 403 is a capability
		// failure shown as one, "while retaining the draft".
		await expect(noteError(page)).not.toBeEmpty({ timeout: 20_000 });
		await expect(page.getByRole('link', { name: /View published post/ })).toHaveCount(0);
		await expect(body).toHaveValue(text);
		expect(postsContaining(text), 'server: no post for the subscriber').toEqual([]);
		// SET: the Appearance page is "Available to anyone who can edit posts".
		await adminCookies(page, 'subscriber', baseURL ?? '');
		const denied = await page.goto('/wp-admin/admin.php?page=outpost-appearance');
		expect(denied?.status()).toBeGreaterThanOrEqual(400);
		await page.context().clearCookies();
		await adminCookies(page, 'editor', baseURL ?? '');
		const allowed = await page.goto('/wp-admin/admin.php?page=outpost-appearance');
		expect(allowed?.status()).toBe(200);
		await expect(page.getByRole('heading', { level: 1 })).toContainText(/Appearance/);
	});

	test('an expired sign-in shows a banner with Sign in again over the screen that opens, and posts nothing', async ({ page }) => {
		await signIn(page, 'expired');
		// HOME precedence 1: "An expired sign-in is a banner on whichever screen opens, so it
		// never blocks or replaces one."
		await expect(page.getByText('Your sign-in expired.')).toBeVisible({ timeout: 15_000 });
		await expect(page.getByRole('button', { name: 'Sign in again' })).toBeVisible();
		await expect(homeHeading(page)).toBeVisible();
		const text = marker('expired');
		await openPost(page, 'Note');
		const body = noteBody(page);
		await body.fill(text);
		await page.getByRole('button', { name: 'Post note' }).click();
		await expect(noteError(page)).not.toBeEmpty({ timeout: 20_000 });
		// BRIEF 7: "Never auto-publish after sign-in"; the draft stays.
		await expect(body).toHaveValue(text);
		expect(postsContaining(text), 'server: nothing posted with the expired sign-in').toEqual([]);
	});
});
