#!/usr/bin/env node
/**
 * Build the archive to upload to the WordPress.org plugin directory.
 *
 * `wp-scripts plugin-zip` gets most of the way there, but leaves three
 * things this script finishes:
 *
 *   1. The folder inside the archive is named after the npm package
 *      (`outpost`), not the plugin slug. WordPress.org expects the
 *      directory to match the slug, and the text domain with it.
 *   2. npm always adds `package.json` and `README.md` to a pack no
 *      matter what `files` says. Neither belongs in a shipped plugin —
 *      readme.txt is the directory's readme, and package.json only
 *      describes the build.
 *   3. The result should be named for the slug and version, so the
 *      upload form and the review thread agree on what was sent.
 *
 * Run it with `npm run package:wporg`.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, rmSync, mkdtempSync, renameSync, existsSync, copyFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const root = new URL( '..', import.meta.url ).pathname.replace( /\/$/, '' );

/** Read a header field out of the main plugin file. */
const header = ( field ) => {
	const source = readFileSync( join( root, 'outpost.php' ), 'utf8' ).slice( 0, 4096 );
	const match = source.match( new RegExp( `^\\s*\\*\\s*${ field }:\\s*(.+)$`, 'm' ) );
	if ( ! match ) {
		throw new Error( `Could not read "${ field }" from the plugin header.` );
	}
	return match[ 1 ].trim();
};

const slug = header( 'Text Domain' );
const version = header( 'Version' );
const stable = readFileSync( join( root, 'readme.txt' ), 'utf8' ).match(
	/^Stable tag:\s*(.+)$/m
);

// A zip whose version disagrees with the readme's stable tag is the kind
// of mismatch that costs a review round.
if ( stable && stable[ 1 ].trim() !== version ) {
	console.error(
		`Version mismatch: plugin header says ${ version }, readme.txt Stable tag says ${ stable[ 1 ].trim() }.`
	);
	process.exit( 1 );
}

const run = ( cmd, args, cwd = root ) =>
	execFileSync( cmd, args, { cwd, stdio: 'inherit' } );

console.log( `Packaging ${ slug } ${ version }…` );
run( 'npx', [ 'wp-scripts', 'plugin-zip' ] );

const built = join( root, 'outpost.zip' );
if ( ! existsSync( built ) ) {
	throw new Error( 'plugin-zip did not produce outpost.zip' );
}

const stage = mkdtempSync( join( tmpdir(), 'outpost-pkg-' ) );
run( 'unzip', [ '-q', built, '-d', stage ], stage );

// npm names the folder after the package; WordPress.org wants the slug.
const packed = join( stage, 'outpost' );
const target = join( stage, slug );
if ( packed !== target ) {
	renameSync( packed, target );
}

for ( const unwanted of [ 'package.json', 'README.md' ] ) {
	const path = join( target, unwanted );
	if ( existsSync( path ) ) {
		rmSync( path );
	}
}

const finalName = `${ slug }-${ version }.zip`;
run( 'zip', [ '-qr', finalName, slug ], stage );
copyFileSync( join( stage, finalName ), join( root, finalName ) );
rmSync( built );
rmSync( stage, { recursive: true, force: true } );

console.log( `\nReady to upload: ${ finalName }` );
