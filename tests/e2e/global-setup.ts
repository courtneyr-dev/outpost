import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));

/**
 * Create a disposable editor and an IndieAuth token for the composer, on the
 * wp-env development site. The token is written to tests/e2e/.auth/ (git
 * ignored) and lasts one hour.
 */
const PHP = `
$login = 'outpost_e2e_editor';
$user  = get_user_by( 'login', $login );
$uid   = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.test', 'role' => 'editor' ) );
$token = ( new IndieAuth\\Token\\User( '_indieauth_token_', $uid ) )->set(
	array( 'token_type' => 'Bearer', 'scope' => 'create update media', 'me' => get_author_posts_url( $uid ), 'client_id' => home_url( '/post/' ), 'issued_by' => rest_url( 'indieauth/1.0/token' ), 'issued_at' => time(), 'user' => $uid ),
	HOUR_IN_SECONDS
);
echo wp_json_encode( array( 'token' => $token, 'me' => get_author_posts_url( $uid ) ) );
`;

export default function globalSetup(): void {
	const out = execFileSync('npx', ['wp-env', 'run', 'cli', '--', 'wp', 'eval', PHP], {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'inherit'],
	});
	const json = out.slice(out.indexOf('{'), out.lastIndexOf('}') + 1);
	const dir = resolve(HERE, '.auth');
	mkdirSync(dir, { recursive: true });
	writeFileSync(resolve(dir, 'session.json'), json);
}
