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
 * A story also fails when its render or play function throws, when the page
 * raises an uncaught error, or when it logs a console error: axe on a
 * half-played story audits the wrong state. The runner keeps listening for
 * SETTLE_MS after Storybook reports the story finished (rendered and played)
 * and counts every error from page load to that deadline. Errors raised later
 * than SETTLE_MS after the story finishes are not observed.
 *
 * It also runs axe on the manager page (index.html, the sidebar and toolbar
 * around the preview iframe) in both color schemes.
 *
 * Run `npm run build-storybook` first, then `npm run test:storybook-a11y`.
 * To audit another build, pass its folder and the URL path it is published
 * under: `node bin/test-storybook-a11y.mjs docs/dist/storybook --base /outpost/storybook/`
 * serves that folder at /outpost/storybook/, as GitHub Pages does.
 */

import { createServer } from 'node:http';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { chromium } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import { WCAG_TAGS } from '../.storybook/a11y-rules.js';

const args = parseArgs( { allowPositionals: true, options: { base: { type: 'string', default: '/' } } } );
const ROOT = args.positionals[ 0 ]
	? resolve( args.positionals[ 0 ] )
	: fileURLToPath( new URL( '../storybook-static', import.meta.url ) );
const BASE = `/${ args.values.base.replace( /^\/+|\/+$/g, '' ) }/`.replace( /^\/\/$/, '/' );
const SCHEMES = [ 'light', 'dark' ];
const VIEWPORTS = {
	phone: { width: 390, height: 844 },
	desktop: { width: 1280, height: 800 },
};
const CONCURRENCY = 4;
const SETTLE_MS = 2000;
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

function serve() {
	const server = createServer( async ( req, res ) => {
		const pathname = decodeURIComponent( new URL( req.url, 'http://localhost' ).pathname );
		if ( ! pathname.startsWith( BASE ) ) {
			res.writeHead( 404 ).end();
			return;
		}
		const path = normalize( `/${ pathname.slice( BASE.length ) }` );
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
			resolve( { server, origin: `http://127.0.0.1:${ server.address().port }` } )
		)
	);
}

const STORY_ERROR_EVENTS = [
	'playFunctionThrewException',
	'storyThrewException',
	'storyErrored',
	'unhandledErrorsWhilePlaying',
];

// Storybook assigns its channel to this global once; subscribing in the setter
// catches render and play errors that the preview recovers from on screen.
function watchStoryErrors( events ) {
	const errors = ( globalThis.__outpostStoryErrors = [] );
	let channel;
	Object.defineProperty( globalThis, '__STORYBOOK_ADDONS_CHANNEL__', {
		configurable: true,
		get: () => channel,
		set: ( next ) => {
			channel = next;
			for ( const event of events ) {
				next.on( event, ( payload ) => {
					const first = [ payload ].flat()[ 0 ];
					errors.push( `${ event }: ${ String( first?.message ?? first ).split( '\n' )[ 0 ] }` );
				} );
			}
		},
	} );
}

// One browser context per run: stories in parallel pages would otherwise share
// localStorage and IndexedDB, and one story's cleanup would erase another's seed.
async function audit( browser, origin, url, run ) {
	const context = await browser.newContext( { viewport: VIEWPORTS[ run.viewport ] } );
	const page = await context.newPage();
	const leaks = [];
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( `pageerror: ${ error.message.split( '\n' )[ 0 ] }` ) );
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' && ! message.text().startsWith( 'Failed to load resource' ) ) {
			errors.push( `console.error: ${ message.text().split( '\n' )[ 0 ] }` );
		}
	} );
	await page.addInitScript( watchStoryErrors, STORY_ERROR_EVENTS );
	await page.route( '**/*', ( route ) => {
		const target = route.request().url();
		const local = target.startsWith( `${ origin }/` ) && ! new URL( target ).pathname.startsWith( '/wp-json' );
		if ( local || target.startsWith( 'data:' ) || target.startsWith( 'blob:' ) ) {
			return route.continue();
		}
		leaks.push( target );
		return route.abort();
	} );
	try {
		await page.emulateMedia( { colorScheme: run.scheme } );
		await page.goto( `${ url }iframe.html?id=${ run.story }&viewMode=story&globals=scheme:${ run.scheme }` );
		await page.waitForFunction(
			() => globalThis.__STORYBOOK_PREVIEW__?.currentRender?.phase === 'finished',
			null,
			{ timeout: 20000 }
		);
		const settled = Date.now() + SETTLE_MS;
		const storyErrors = async () =>
			[
				...errors,
				...( await page.evaluate( () => globalThis.__outpostStoryErrors ) ),
				...( ( await page.$( 'body.sb-show-errordisplay' ) ) ? [ 'the preview shows its error display' ] : [] ),
			].map( ( help ) => ( { id: 'story-error', target: run.story, help } ) );
		const early = await storyErrors();
		if ( early.length ) {
			return early;
		}
		if ( run.story === 'components-settingssheet--open' && run.viewport === 'phone' ) {
			await page.locator( '#outpost-settings-reset-help' ).scrollIntoViewIfNeeded();
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
		await page.waitForTimeout( Math.max( 0, settled - Date.now() ) );
		return [
			...( await storyErrors() ),
			...violations.flatMap( ( v ) =>
				v.nodes.map( ( node ) => ( { id: v.id, target: node.target.join( ' ' ), help: v.help } ) )
			),
			...unmeasured,
			...leaks.map( ( target ) => ( { id: 'unmocked-request', target, help: 'request left the static Storybook' } ) ),
		];
	} finally {
		await context.close();
	}
}

// The manager page, with every sidebar group expanded so axe sees each item.
async function auditManager( browser, url ) {
	const fresh = [];
	for ( const scheme of SCHEMES ) {
		const context = await browser.newContext( { viewport: VIEWPORTS.desktop } );
		const page = await context.newPage();
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( `pageerror: ${ error.message.split( '\n' )[ 0 ] }` ) );
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				errors.push( `console.error: ${ message.text().split( '\n' )[ 0 ] }` );
			}
		} );
		try {
			await page.emulateMedia( { colorScheme: scheme } );
			await page.goto( url );
			await page.waitForSelector( '#storybook-explorer-menu' );
			for ( const button of await page.locator( '[data-action="expand-all"]' ).all() ) {
				await button.click();
			}
			await page.waitForTimeout( SETTLE_MS );
			const { violations } = await new AxeBuilder( { page } ).exclude( 'iframe' ).withTags( WCAG_TAGS ).analyze();
			for ( const help of errors ) {
				fresh.push( `${ scheme.padEnd( 5 ) } story-error: manager page  ${ help }` );
			}
			for ( const v of violations ) {
				for ( const node of v.nodes ) {
					const target = node.target.join( ' ' );
					fresh.push( `${ scheme.padEnd( 5 ) } ${ v.id }: ${ target }  ${ v.help }` );
				}
			}
		} finally {
			await context.close();
		}
	}
	console.log( `${ fresh.length ? 'FAIL' : 'pass' }  desktop both   manager page (index.html)` );
	for ( const line of fresh ) {
		console.log( `        ${ line }` );
	}
	return fresh.length;
}

if ( ! existsSync( join( ROOT, 'index.json' ) ) ) {
	console.error( `${ join( ROOT, 'index.json' ) } is missing. Run \`npm run build-storybook\` first.` );
	process.exit( 1 );
}

const index = JSON.parse( await readFile( join( ROOT, 'index.json' ), 'utf8' ) );
const stories = Object.values( index.entries )
	.filter( ( e ) => e.type === 'story' )
	.map( ( e ) => e.id );
const runs = Object.keys( VIEWPORTS ).flatMap( ( viewport ) =>
	SCHEMES.flatMap( ( scheme ) => stories.map( ( story ) => ( { story, scheme, viewport } ) ) )
);

const { server, origin } = await serve();
const url = `${ origin }${ BASE }`;
const browser = await chromium.launch( { channel: 'chrome' } );
let failures = 0;
let next = 0;

async function worker() {
	while ( next < runs.length ) {
		const run = runs[ next++ ];
		let found;
		try {
			found = await audit( browser, origin, url, run );
		} catch ( error ) {
			found = [ { id: 'audit-error', target: run.story, help: error.message.split( '\n' )[ 0 ] } ];
		}
		const fresh = found.map( ( finding ) => `${ finding.id }: ${ finding.target }  ${ finding.help }` );
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
	failures += await auditManager( browser, url );
} finally {
	await browser.close();
	server.close();
}

console.log(
	`\n${ stories.length } stories x ${ SCHEMES.length } schemes x ${ Object.keys( VIEWPORTS ).length } viewports plus the manager page x ${ SCHEMES.length } schemes, served at ${ BASE }, ${ failures } failures (axe tags: ${ WCAG_TAGS.join( ', ' ) })`
);

if ( failures > 0 ) {
	process.exitCode = 1;
}
