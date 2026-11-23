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

// Exact known violations. Each entry names a story, an axe rule, the node's
// full target selector, and every scheme and viewport it occurs in. A listed
// combination that stops occurring fails the run, so the list only shrinks.
const BOTH = [ 'light', 'dark' ];
const ALL = [ 'phone', 'desktop' ];
const PHONE = [ 'phone' ];
const known = ( rule, viewports, note, entries ) =>
	entries.map( ( [ story, target ] ) => ( { story, rule, target, schemes: BOTH, viewports, note } ) );

const SNOOZE_MENU = [ 'components-snoozemenu--default', 'components-snoozemenu--disabled', 'components-snoozemenu--with-cancel', 'components-reminderrollup--snooze-menu-open' ];
const SNOOZE_OPTIONS = [ 'P1D', 'P3D', 'P7D', 'forever' ].map( ( v ) => `button[data-snooze-value="${ v }"]` );
const SNOOZE_ARIA = 'SnoozeMenu puts role="menu" on a wrapper around ul > li, so its role="menuitem" buttons have no menu parent.';

const KNOWN = [
	...known( 'aria-required-children', ALL, SNOOZE_ARIA, SNOOZE_MENU.map( ( story ) => [ story, '.outpost-snooze-menu' ] ) ),
	...known( 'aria-required-parent', ALL, SNOOZE_ARIA, SNOOZE_MENU.flatMap( ( story ) => SNOOZE_OPTIONS.map( ( target ) => [ story, target ] ) ) ),
	...known( 'color-contrast-unmeasured', PHONE, 'The 90dvh settings drawer cuts the reset help text at its edge on a phone, so axe cannot read its background. Not a contrast failure.', [
		[ 'components-settingssheet--open', '#outpost-settings-reset-help' ],
	] ),
];

const comboKey = ( k, scheme, viewport ) => `${ k.story } ${ k.rule } ${ k.target } ${ scheme } ${ viewport }`;

const isKnown = ( run, finding ) =>
	KNOWN.find(
		( k ) =>
			k.story === run.story &&
			k.rule === finding.id &&
			k.target === finding.target &&
			k.schemes.includes( run.scheme ) &&
			k.viewports.includes( run.viewport )
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
async function audit( browser, url, run ) {
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
let failures = 0;
const seenKnown = new Set();
let next = 0;

async function worker() {
	while ( next < runs.length ) {
		const run = runs[ next++ ];
		let found;
		try {
			found = await audit( browser, url, run );
		} catch ( error ) {
			found = [ { id: 'audit-error', target: run.story, help: error.message.split( '\n' )[ 0 ] } ];
		}
		const fresh = [];
		for ( const finding of found ) {
			const match = isKnown( run, finding );
			if ( match ) {
				seenKnown.add( comboKey( match, run.scheme, run.viewport ) );
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

const missing = KNOWN.flatMap( ( k ) =>
	k.schemes.flatMap( ( scheme ) =>
		k.viewports.map( ( viewport ) => comboKey( k, scheme, viewport ) ).filter( ( key ) => ! seenKnown.has( key ) )
	)
);
for ( const key of missing ) {
	failures += 1;
	console.log( `FAIL  known violation no longer occurs, remove it from KNOWN: ${ key }` );
}
console.log( `\n${ KNOWN.length } KNOWN entries matched ${ seenKnown.size } times:` );
for ( const note of new Set( KNOWN.map( ( k ) => k.note ) ) ) {
	console.log( `  ${ KNOWN.filter( ( k ) => k.note === note ).length } x ${ note }` );
}

console.log(
	`\n${ stories.length } stories x ${ SCHEMES.length } schemes x ${ Object.keys( VIEWPORTS ).length } viewports, ${ failures } failures, ${ seenKnown.size } known (axe tags: ${ WCAG_TAGS.join( ', ' ) })`
);

if ( failures > 0 ) {
	process.exitCode = 1;
}
