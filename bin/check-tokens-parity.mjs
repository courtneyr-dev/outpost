#!/usr/bin/env node
/**
 * Token-parity check.
 *
 * `styles/outpost-tokens.css` (server-rendered, theme-overridable) and
 * `pwa/src/styles/tokens.css` (bundled mirror that resolves in Vite dev
 * mode) MUST stay in sync. Drift between them produces dev/prod
 * rendering differences that are hell to debug.
 *
 * This script reads both files, normalizes whitespace + comments, and
 * compares the resulting custom-property declarations as a sorted set.
 * Any divergence exits non-zero so CI can fail the build.
 *
 * Run: `node bin/check-tokens-parity.mjs`
 *      or `npm run check:tokens`
 */

import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');
const SERVER = resolve(root, 'styles/outpost-tokens.css');
const BUNDLED = resolve(root, 'pwa/src/styles/tokens.css');

/**
 * Pull the `--outpost-*: value;` declarations out of a CSS file. Strips
 * comments, normalizes whitespace, sorts so order doesn't matter.
 */
function extract_declarations(path) {
	const raw = readFileSync(path, 'utf8');
	// Strip /* ... */ block comments.
	const no_comments = raw.replace(/\/\*[\s\S]*?\*\//g, '');
	const decl_re = /--outpost-[a-z0-9-]+\s*:\s*[^;]+;/g;
	const matches = no_comments.match(decl_re) ?? [];
	return matches
		.map((d) => d.replace(/\s+/g, ' ').trim())
		.sort();
}

const server = extract_declarations(SERVER);
const bundled = extract_declarations(BUNDLED);

if (server.length !== bundled.length || server.some((decl, i) => decl !== bundled[i])) {
	console.error('Token parity FAILED.');
	console.error(`  ${SERVER}: ${server.length} declarations`);
	console.error(`  ${BUNDLED}: ${bundled.length} declarations`);
	const server_set = new Set(server);
	const bundled_set = new Set(bundled);
	const only_server = server.filter((d) => !bundled_set.has(d));
	const only_bundled = bundled.filter((d) => !server_set.has(d));
	if (only_server.length) {
		console.error('\nDeclarations only in server file:');
		only_server.forEach((d) => console.error('  + ' + d));
	}
	if (only_bundled.length) {
		console.error('\nDeclarations only in bundled file:');
		only_bundled.forEach((d) => console.error('  + ' + d));
	}
	process.exit(1);
}

console.log(`Token parity OK — ${String(server.length)} declarations match.`);
