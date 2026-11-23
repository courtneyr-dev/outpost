#!/usr/bin/env node
/**
 * Run axe against every story in the built Storybook, in both color schemes
 * and at both viewports, and fail on any violation that is not an exact
 * entry in KNOWN.
 *
 * Drives the installed Google Chrome through Playwright's `chrome` channel,
 * so it never downloads a browser. Any request that leaves the local static
 * server, or reaches a /wp-json path on it, fails the run: stories must mock
 * every call through the component's injected `env.fetch`.
 *
 * Run `npm run build-storybook` first, then `npm run test:storybook-a11y`.
 */

import { createServer } from 'node:http';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import { WCAG_TAGS } from '../.storybook/a11y-rules.js';

const ROOT = fileURLToPath( new URL( '../storybook-static', import.meta.url ) );
const SCHEMES = [ 'light', 'dark' ];
const VIEWPORTS = {
	phone: { width: 390, height: 844 },
	desktop: { width: 1280, height: 800 },
};
const CONCURRENCY = 4;
const TYPES = {
	'.html': 'text/html',
	'.js': 'text/javascript',
	'.mjs': 'text/javascript',
	'.css': 'text/css',
	'.json': 'application/json',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.woff2': 'font/woff2',
};

// Exact known violations: story id, scheme, viewport, axe rule and the
// node's full target selector. A known entry that stops occurring fails the
// run, so the list can only shrink.
const KNOWN = [];

const isKnown = ( run, finding ) =>
	KNOWN.find(
		( k ) =>
			k.story === run.story &&
			k.scheme === run.scheme &&
			k.viewport === run.viewport &&
			k.rule === finding.id &&
			k.target === finding.target
	);

function serve() {
	const server = createServer( async ( req, res ) => {
		const path = normalize( decodeURIComponent( new URL( req.url, 'http://localhost' ).pathname ) );
		const file = join( ROOT, path.endsWith( '/' ) ? `${ path }index.html` : path );
		try {
			const body = await readFile( file );
			res.writeHead( 200, { 'content-type': TYPES[ extname( file ) ] ?? 'application/octet-stream' } );
			res.end( body );
		} catch {
			res.writeHead( 404 ).end();
		}
	} );
	return new Promise( ( resolve ) =>
		server.listen( 0, '127.0.0.1', () =>
			resolve( { server, url: `http://127.0.0.1:${ server.address().port }` } )
		)
	);
}

async function audit( context, url, run ) {
	const page = await context.newPage();
	const leaks = [];
	await page.route( '**/*', ( route ) => {
		const target = route.request().url();
		const local = target.startsWith( `${ url }/` ) && ! new URL( target ).pathname.startsWith( '/wp-json' );
		if ( local || target.startsWith( 'data:' ) || target.startsWith( 'blob:' ) ) {
			return route.continue();
		}
		leaks.push( target );
		return route.abort();
	} );
	try {
		await page.emulateMedia( { colorScheme: run.scheme } );
		await page.goto( `${ url }/iframe.html?id=${ run.story }&viewMode=story&globals=scheme:${ run.scheme }` );
		await page.waitForFunction(
			() => globalThis.__STORYBOOK_PREVIEW__?.currentRender?.phase === 'finished',
			null,
			{ timeout: 20000 }
		);
		if ( await page.$( 'body.sb-show-errordisplay' ) ) {
			return [ { id: 'story-error', target: run.story, help: 'the story threw while rendering or playing' } ];
		}
		const { violations, incomplete } = await new AxeBuilder( { page } )
			.include( '#storybook-root' )
			.withTags( WCAG_TAGS )
			.analyze();
		const unmeasured = ( incomplete.find( ( i ) => i.id === 'color-contrast' )?.nodes ?? [] )
			.filter( ( node ) => ! node.any.some( ( check ) => check.data?.messageKey === 'nonBmp' ) )
			.map( ( node ) => ( {
				id: 'color-contrast-unmeasured',
				target: node.target.join( ' ' ),
				help: node.any[ 0 ]?.message,
			} ) );
		return [
			...violations.flatMap( ( v ) =>
				v.nodes.map( ( node ) => ( { id: v.id, target: node.target.join( ' ' ), help: v.help } ) )
			),
			...unmeasured,
			...leaks.map( ( target ) => ( { id: 'unmocked-request', target, help: 'request left the static Storybook' } ) ),
		];
	} finally {
		await page.close();
	}
}

if ( ! existsSync( join( ROOT, 'index.json' ) ) ) {
	console.error( 'storybook-static/index.json is missing. Run `npm run build-storybook` first.' );
	process.exit( 1 );
}

const index = JSON.parse( await readFile( join( ROOT, 'index.json' ), 'utf8' ) );
const stories = Object.values( index.entries )
	.filter( ( e ) => e.type === 'story' )
	.map( ( e ) => e.id );
const runs = Object.keys( VIEWPORTS ).flatMap( ( viewport ) =>
	SCHEMES.flatMap( ( scheme ) => stories.map( ( story ) => ( { story, scheme, viewport } ) ) )
);

const { server, url } = await serve();
const browser = await chromium.launch( { channel: 'chrome' } );
const contexts = Object.fromEntries(
	await Promise.all(
		Object.entries( VIEWPORTS ).map( async ( [ name, viewport ] ) => [
			name,
			await browser.newContext( { viewport } ),
		] )
	)
);

let failures = 0;
const seenKnown = new Set();
let next = 0;

async function worker() {
	while ( next < runs.length ) {
		const run = runs[ next++ ];
		let found;
		try {
			found = await audit( contexts[ run.viewport ], url, run );
		} catch ( error ) {
			found = [ { id: 'audit-error', target: run.story, help: error.message.split( '\n' )[ 0 ] } ];
		}
		const fresh = [];
		for ( const finding of found ) {
			const match = isKnown( run, finding );
			if ( match ) {
				seenKnown.add( match );
			} else {
				fresh.push( `${ finding.id }: ${ finding.target }  ${ finding.help }` );
			}
		}
		failures += fresh.length;
		console.log(
			`${ fresh.length ? 'FAIL' : 'pass' }  ${ run.viewport.padEnd( 7 ) } ${ run.scheme.padEnd( 5 ) }  ${ run.story }`
		);
		for ( const line of fresh ) {
			console.log( `        ${ line }` );
		}
	}
}

try {
	await Promise.all( Array.from( { length: CONCURRENCY }, worker ) );
} finally {
	await browser.close();
	server.close();
}

for ( const k of KNOWN ) {
	if ( seenKnown.has( k ) ) {
		console.log( `known ${ k.viewport } ${ k.scheme } ${ k.story } ${ k.rule } ${ k.target }  ${ k.note }` );
	} else {
		failures += 1;
		console.log( `FAIL  known violation no longer occurs, remove it from KNOWN: ${ k.viewport } ${ k.scheme } ${ k.story } ${ k.target }` );
	}
}

console.log(
	`\n${ stories.length } stories x ${ SCHEMES.length } schemes x ${ Object.keys( VIEWPORTS ).length } viewports, ${ failures } failures, ${ seenKnown.size } known (axe tags: ${ WCAG_TAGS.join( ', ' ) })`
);

if ( failures > 0 ) {
	process.exitCode = 1;
}
