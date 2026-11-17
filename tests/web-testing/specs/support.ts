import { test as base, expect, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = fileURLToPath(new URL('.', import.meta.url));

/**
 * Shared helpers for the requirement-derived specs. The site is the
 * disposable Studio site that tests/web-testing/reset.sh restores; server
 * state is confirmed through WP-CLI, never through the UI alone.
 */

export const SITE = process.env.WT_OUTPOST_SITE ?? join(homedir(), 'Studio/outpost-bombadil');

export type Who = 'editor' | 'subscriber' | 'expired';

interface Cookie {
	name: string;
	value: string;
	path: string;
}

export interface Session {
	login: string;
	userId?: number;
	token: string;
	me: string;
	cookies?: Cookie[];
}

export function session(who: Who): Session {
	const all = JSON.parse(readFileSync(resolve(HERE, '../.auth/sessions.json'), 'utf8')) as Record<Who, Session>;
	return all[who];
}

/** Run WP-CLI on the site and return its trimmed stdout. */
export function wp(...args: string[]): string {
	return execFileSync('studio', ['wp', ...args], { cwd: SITE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
}

/** Run PHP on the site; the snippet must echo JSON. */
export function wpJson<T>(php: string): T {
	const out = wp('eval', php);
	return JSON.parse(out.slice(out.indexOf(out.match(/[[{]/)?.[0] ?? '{'))) as T;
}

/** Posts on the site whose content contains `marker`, any status. */
export function postsContaining(marker: string): Array<{ ID: number; post_status: string; post_author: string; post_content: string; permalink: string }> {
	const php = `$q = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'any', 's' => ${JSON.stringify(marker).replace(/\$/g, '\\$')}, 'posts_per_page' => 20 ) );
echo wp_json_encode( array_map( function ( $p ) { return array( 'ID' => $p->ID, 'post_status' => $p->post_status, 'post_author' => $p->post_author, 'post_content' => $p->post_content, 'permalink' => get_permalink( $p ) ); }, $q->posts ) );`;
	return wpJson(php);
}

export function userMeta(userId: number, key: string): string {
	return wpJson<{ v: string }>(`echo wp_json_encode( array( 'v' => (string) get_user_meta( ${Number(userId)}, ${JSON.stringify(key)}, true ) ) );`).v;
}

/**
 * Sign the composer in by storing the token the way pwa/src/lib/token-store.ts
 * does (AES-GCM, non-extractable key, IndexedDB `outpost`), then reload.
 */
export async function signIn(page: Page, who: Who = 'editor'): Promise<Session> {
	const s = session(who);
	await page.goto('/post/');
	await page.evaluate(
		async ({ token, me }) => {
			const db = await new Promise<IDBDatabase>((res, rej) => {
				const r = indexedDB.open('outpost', 1);
				r.onupgradeneeded = (): void => {
					if (!r.result.objectStoreNames.contains('crypto-keys')) r.result.createObjectStore('crypto-keys');
					if (!r.result.objectStoreNames.contains('tokens')) r.result.createObjectStore('tokens');
				};
				r.onsuccess = (): void => res(r.result);
				r.onerror = (): void => rej(r.error);
			});
			const put = (store: string, key: string, value: unknown): Promise<void> =>
				new Promise((res, rej) => {
					const tx = db.transaction(store, 'readwrite');
					tx.objectStore(store).put(value, key);
					tx.oncomplete = (): void => res();
					tx.onerror = (): void => rej(tx.error);
				});
			const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
			await put('crypto-keys', 'token-encryption-key', key);
			const iv = crypto.getRandomValues(new Uint8Array(12));
			const ciphertext = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(token)));
			await put('tokens', 'micropub', { iv, ciphertext, meta: { tokenType: 'Bearer', scope: 'create update media', me, storedAt: Date.now() } });
			db.close();
		},
		{ token: s.token, me: s.me },
	);
	await page.reload();
	await expect(page.getByRole('heading', { name: 'Outpost composer', level: 1 })).toBeAttached();
	return s;
}

/** wp-admin cookies for a user, for checks of wp-admin capability gates. */
export async function adminCookies(page: Page, who: Who, baseURL: string): Promise<void> {
	const s = session(who);
	await page.context().addCookies((s.cookies ?? []).map((c) => ({ name: c.name, value: c.value, url: new URL(c.path, baseURL).href })));
}

export const homeHeading = (page: Page) => page.getByRole('heading', { name: 'What would you like to post?', level: 2 });
export const modes = (page: Page) => page.getByRole('group', { name: 'Composer modes' });
export const footerHome = (page: Page) => page.getByRole('navigation', { name: 'Outpost' }).getByRole('button', { name: /Home/ });

/** The Post tab's body field; its label changes with the style ("Body", "What's on your mind?"). */
export const noteBody = (page: Page) => page.locator('#outpost-note-content');
/** The Post tab's alert region (each tab keeps its own, always mounted). */
export const noteError = (page: Page) => page.locator('#outpost-panel-note .outpost-error');
/** Pick a style on the Post tab; the panel's heading follows the style, so match the panel. */
export const pickStyle = (page: Page, style: string) => page.locator('#outpost-panel-note').getByRole('radio', { name: style, exact: true }).check();

/** Open the Post tab from Home and pick a style. */
export async function openPost(page: Page, style: 'Article' | 'Note' | 'Status' | 'Aside' | 'Quote' = 'Note'): Promise<void> {
	await homeHeading(page).waitFor();
	await page.getByRole('button', { name: /^Post\b/ }).first().click();
	await pickStyle(page, style);
}

export const test = base.extend<{ pageErrors: Error[]; restorePrefs: void }>({
	// An uncaught page error fails the test; a console message alone does not.
	pageErrors: [
		async ({ page }, use) => {
			const errors: Error[] = [];
			page.on('pageerror', (e) => errors.push(e));
			await use(errors);
			expect(errors.map((e) => e.message), 'uncaught page errors').toEqual([]);
		},
		{ auto: true },
	],
	// Preferences are per person on the server; put the editor's back so one
	// test's choice never decides where the next test opens.
	restorePrefs: [
		async ({}, use) => {
			await use();
			const id = session('editor').userId ?? 0;
			wp('eval', `foreach ( array( 'outpost_start_screen', 'outpost_appearance_mode', 'outpost_appearance_source' ) as $k ) { delete_user_meta( ${id}, $k ); }`);
		},
		{ auto: true },
	],
});
export { expect };

export const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * Scan the current state with axe; keep rule ids and targets per state.
 * An empty list means these rules found nothing here, not WCAG conformance
 * (docs/src/content/docs/accessibility.md says the same of Outpost).
 */
export async function scanState(page: Page, testInfo: TestInfo, state: string): Promise<void> {
	const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
	const record = {
		state,
		url: page.url(),
		project: testInfo.project.name,
		axeVersion: results.testEngine.version,
		violations: results.violations.map((v) => ({
			id: v.id,
			impact: v.impact,
			help: v.help,
			helpUrl: v.helpUrl,
			targets: v.nodes.map((n) => n.target.join(' ')),
		})),
		passes: results.passes.length,
		incomplete: results.incomplete.map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target.join(' ')) })),
	};
	const file = testInfo.outputPath(`axe-${state}.json`);
	writeFileSync(file, JSON.stringify(record, null, 2));
	await testInfo.attach(`axe-${state}`, { path: file, contentType: 'application/json' });
	const summary = record.violations.map((v) => `${v.id} (${v.impact}) at ${v.targets.join(', ')}`);
	expect.soft(summary, `axe violations in state "${state}"`).toEqual([]);
}

/** WCAG relative-luminance contrast between two CSS rgb()/rgba() colors. */
export function contrast(a: string, b: string): number {
	const lum = (c: string): number => {
		const [r, g, bl] = (c.match(/[\d.]+/g) ?? []).slice(0, 3).map((n) => {
			const v = Number(n) / 255;
			return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
		});
		return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
	};
	const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
	return (hi + 0.05) / (lo + 0.05);
}
