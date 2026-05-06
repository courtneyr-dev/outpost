/**
 * Outpost block editor sidebar (G3.5c).
 *
 * Registers a single Gutenberg PluginSidebar named "outpost-sidebar" that
 * future Outpost surfaces (POSSE destination selector, fetch-recent picker,
 * headless-send config) hang panels off of.
 *
 * @file
 */

import { registerPlugin } from '@wordpress/plugins';
import { share } from '@wordpress/icons';
import { OutpostSidebar } from './sidebar/outpost-sidebar.js';

registerPlugin( 'outpost-sidebar', {
	render: OutpostSidebar,
	icon: share,
} );
