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
import { OutpostSidebar } from './sidebar/outpost-sidebar.js';
import { fingerpost } from './icons/fingerpost.js';
import { registerPendingSyndicationNotice } from './editor-notices/pending-syndication-notice.js';

registerPlugin( 'outpost-sidebar', {
	render: OutpostSidebar,
	icon: fingerpost,
} );

// Pending-syndication reminder. `admin_notices` output is buried in the
// block editor's hidden no-JS container, so the reminder comes through
// core/notices instead.
registerPendingSyndicationNotice();
