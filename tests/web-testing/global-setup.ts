import { execFileSync } from 'node:child_process';
import { statSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = fileURLToPath(new URL('.', import.meta.url));

/**
 * Sessions (tokens and cookies) last an hour. `reset.sh` mints them after
 * each reset; a direct Playwright run mints fresh ones when the file is
 * missing or older than 30 minutes. Minting never resets the site.
 */
export default function globalSetup(): void {
	const file = resolve(HERE, '.auth/sessions.json');
	let age = Infinity;
	try {
		age = Date.now() - statSync(file).mtimeMs;
	} catch {
		// Not minted yet.
	}
	if (age > 30 * 60_000) {
		execFileSync('sh', [resolve(HERE, 'reset.sh'), '--mint'], { stdio: ['ignore', 'ignore', 'inherit'] });
	}
}
