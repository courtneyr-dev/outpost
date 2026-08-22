/**
 * Webpack config for the block editor bundle (`npm run build:wp`).
 *
 * Only one thing differs from @wordpress/scripts' default: what survives
 * the pre-emit clean. wp-scripts empties `build/` before every compile,
 * keeping only `fonts/` and `images/` — which takes `build/pwa/` with it,
 * the Vite-built PWA composer bundle that ships in the release zip and is
 * tracked in git. Running `build:wp` on its own therefore deleted the
 * composer's assets; `build:all` only masked it because Vite re-emitted
 * them immediately afterward.
 *
 * Adding `pwa/` to the keep pattern lets the two builds share `build/`
 * without erasing each other, in either order.
 *
 * ESM rather than CommonJS because package.json sets `"type": "module"`.
 *
 * @file
 */

import defaultConfig from '@wordpress/scripts/config/webpack.config.js';

/** Widen wp-scripts' clean allowlist so the Vite output survives. */
const keepPwa = ( config ) => ( {
	...config,
	output: {
		...config.output,
		clean: {
			keep: /^(fonts|images|pwa)\//,
		},
	},
} );

// wp-scripts exports an array when its experimental modules flag is set,
// a single config otherwise. Handle both so a future flag flip doesn't
// silently restore the deletion.
export default Array.isArray( defaultConfig )
	? defaultConfig.map( keepPwa )
	: keepPwa( defaultConfig );
