#!/usr/bin/env node
/**
 * Guard: every directory the plugin loads at runtime must ship in the zip.
 *
 * `wp-scripts plugin-zip` builds the archive from package.json's `files`
 * array — it does not read `.distignore`. A path missing from `files` is
 * therefore absent from the distributed plugin while working perfectly in
 * a git checkout, so nothing local catches it.
 *
 * That already happened once: `styles/` dropped out of `files` and the
 * composer's token stylesheet 404'd in the packaged plugin.
 *
 * This compares the first path segment of every `OUTPOST_PLUGIN_URL .` /
 * `OUTPOST_PLUGIN_DIR .` reference in PHP against `files`, and fails if
 * any is unshipped.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, extname } from 'node:path';

const root = new URL( '..', import.meta.url ).pathname;
const scanDirs = [ 'includes', 'admin' ];
const scanFiles = [ 'outpost.php', 'uninstall.php' ];

function phpFilesIn( dir ) {
	const out = [];
	const walk = ( d ) => {
		let entries;
		try {
			entries = readdirSync( d );
		} catch {
			return;
		}
		for ( const entry of entries ) {
			const full = join( d, entry );
			if ( statSync( full ).isDirectory() ) {
				walk( full );
			} else if ( extname( full ) === '.php' ) {
				out.push( full );
			}
		}
	};
	walk( join( root, dir ) );
	return out;
}

const targets = [
	...scanDirs.flatMap( phpFilesIn ),
	...scanFiles.map( ( f ) => join( root, f ) ),
];

// OUTPOST_PLUGIN_URL . 'styles/outpost-tokens.css' → "styles"
const reference = /OUTPOST_PLUGIN_(?:URL|DIR)\s*\.\s*'([^'/]+)/g;
const required = new Set();
for ( const file of targets ) {
	const source = readFileSync( file, 'utf8' );
	for ( const match of source.matchAll( reference ) ) {
		required.add( match[ 1 ].replace( /\/$/, '' ) );
	}
}

const shipped = new Set(
	JSON.parse( readFileSync( join( root, 'package.json' ), 'utf8' ) ).files ?? []
);

const missing = [ ...required ].filter( ( path ) => ! shipped.has( path ) ).sort();

if ( missing.length ) {
	console.error(
		'Runtime paths referenced by PHP but missing from package.json "files":\n' +
			missing.map( ( m ) => `  - ${ m }` ).join( '\n' ) +
			'\nThese work in a checkout and 404 in the packaged plugin. Add them to "files".'
	);
	process.exit( 1 );
}

console.log(
	`Distribution paths OK — ${ [ ...required ].sort().join( ', ' ) } all present in package.json "files".`
);
