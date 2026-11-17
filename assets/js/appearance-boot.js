/*
 * Outpost appearance boot.
 *
 * Runs synchronously right after <body> opens, before the composer
 * paints, and applies this device's last-known appearance:
 *
 *   - `outpost-mode-{day|night|system}` (the Light / Dark / System choice)
 *   - `outpost-source-{outpost|site}` (Outpost Default / Use Site Theme)
 *   - the cached token CSS for Use Site Theme or user overrides
 *
 * The PWA (pwa/src/lib/appearance.ts) writes the cache and keeps it in
 * step with the server. The cache holds preferences and colors only,
 * never a credential; the sign-in token stays in encrypted IndexedDB.
 * Storage can be unavailable (private browsing, blocked site data); the
 * server-rendered classes then stand.
 *
 * A classic script, not a module: module scripts are deferred, so they
 * run after first paint. The CSP allows no inline script.
 */
( function () {
	'use strict';
	var KEY = 'outpost.appearance.v1';
	var MODES = { day: 1, night: 1, system: 1 };
	var SOURCES = { outpost: 1, site: 1 };
	var state = null;
	try {
		state = JSON.parse( window.localStorage.getItem( KEY ) || 'null' );
	} catch ( e ) {
		state = null;
	}
	if ( ! state || typeof state !== 'object' ) {
		return;
	}
	var body = document.body;
	if ( ! body ) {
		return;
	}
	if ( MODES[ state.mode ] === 1 ) {
		body.className = body.className.replace( /\boutpost-mode-[a-z]+\b/g, '' ).trim() + ' outpost-mode-' + state.mode;
	}
	if ( SOURCES[ state.source ] === 1 ) {
		body.className = body.className.replace( /\boutpost-source-[a-z]+\b/g, '' ).trim() + ' outpost-source-' + state.source;
	}
	if ( typeof state.css === 'string' && state.css !== '' ) {
		var style = document.getElementById( 'outpost-appearance-tokens' );
		if ( ! style ) {
			style = document.createElement( 'style' );
			style.id = 'outpost-appearance-tokens';
			document.head.appendChild( style );
		}
		style.textContent = state.css;
	}
}() );
