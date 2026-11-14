import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

/**
 * T10-S4-04 (F-21): the service worker's install-time precache of the
 * `/post/` shell. The shell is sent `Cache-Control: no-store` because a
 * cookie-identified response carries that person's appearance choice.
 * The Cache API ignores `no-store`, so whatever the install fetch receives
 * is what an offline launch later serves, after sign-out too.
 *
 * The offline shell must exist (the composer opens offline) and must be the
 * credential-less shell, with no per-person appearance in it.
 */

interface CookieSession {
	name: string;
	value: string;
}

const PHP = `
$login = 'outpost_e2e_cookie';
$user  = get_user_by( 'login', $login );
$uid   = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.test', 'role' => 'editor' ) );
update_user_meta( $uid, 'outpost_appearance_source', 'site' );
echo wp_json_encode( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $uid, time() + HOUR_IN_SECONDS, 'logged_in' ) ) );
`;

function cookie_session(): CookieSession {
	const out = execFileSync('npx', ['wp-env', 'run', 'cli', '--', 'wp', 'eval', PHP], {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'inherit'],
	});
	return JSON.parse(out.slice(out.indexOf('{'), out.lastIndexOf('}') + 1)) as CookieSession;
}

test('the offline shell is the credential-less shell, not the installing person’s', async ({ page, context, baseURL }) => {
	const { name, value } = cookie_session();
	await context.addCookies([{ name, value, url: baseURL ?? 'http://localhost:8888' }]);

	const live = await page.goto('/post/');
	expect(live?.headers()['cache-control'] ?? '').toMatch(/no-store/);
	expect(await page.locator('body').getAttribute('class')).toContain('outpost-source-site');

	await page.evaluate(async () => {
		await navigator.serviceWorker.register('/post/sw', { scope: '/post/' });
		await navigator.serviceWorker.ready;
	});
	const cached = await page.evaluate(async () => {
		const hit = await caches.match('/post/');
		return hit ? await hit.text() : null;
	});

	expect(cached, 'The shell is precached so the composer opens offline.').not.toBeNull();
	expect(cached ?? '', 'The precached shell carries no per-person appearance.').not.toContain('outpost-source-site');

	await context.clearCookies();
	await page.reload();
	await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
	await context.setOffline(true);
	await page.goto('/post/');
	expect(await page.locator('#outpost-root').count(), 'Offline launch still serves the shell.').toBe(1);
	expect(await page.locator('body').getAttribute('class')).not.toContain('outpost-source-site');
	await context.setOffline(false);
});
