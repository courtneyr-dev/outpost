// Single source of per-plugin parameters for the docs site.
// Everything else (astro.config.mjs, Head.astro, structured data) reads from here.
export default {
	name: 'Outpost',
	slug: 'outpost',
	site: 'https://courtneyr-dev.github.io',
	base: '/outpost',
	description:
		'User documentation for Outpost, a mobile-first PWA composer for WordPress: installation, setup, everyday posting tasks, and troubleshooting.',
	github: 'https://github.com/courtneyr-dev/outpost',
	// Not published in the WordPress.org plugin directory. Set to the listing URL after publication.
	wporg: null,
	version: '1.0.11',
	requiresWP: '6.5',
	requiresPHP: '8.2',
	author: 'Courtney Robertson',
	authorUrl: 'https://courtneyr.dev/',
};
