// Link check over the built site. Internal links always; external links
// too unless SKIP_EXTERNAL=1 (CI skips externals to stay deterministic).
import { LinkChecker } from 'linkinator';
import { startPreview } from './preview-server.mjs';

const { base, stop } = await startPreview(4326);
const skipExternal = process.env.SKIP_EXTERNAL === '1';
const includeStorybook = process.env.CHECK_STORYBOOK === '1';

try {
	const checker = new LinkChecker();
	const result = await checker.check({
		// No docs page links to the Storybook, so seed its manager page and
		// preview iframe; the crawl then follows their scripts and styles.
		path: includeStorybook ? [base, `${base}storybook/`, `${base}storybook/iframe.html`] : base,
		recurse: true,
		...(skipExternal && {
			linksToSkip: async (link) => !link.startsWith('http://localhost'),
		}),
	});
	const broken = result.links.filter((l) => l.state === 'BROKEN');
	const storybook = result.links.filter((l) => l.url.startsWith(`${base}storybook/`));
	const storybookSummary = includeStorybook
		? ` (${storybook.length} under ${new URL(base).pathname}storybook/)`
		: '';
	console.log(`Checked ${result.links.length} links${storybookSummary}; ${broken.length} broken.`);
	for (const l of broken) console.error(`BROKEN ${l.status} ${l.url} (on ${l.parent})`);
	stop();
	process.exit(broken.length ? 1 : 0);
} catch (e) {
	console.error(e);
	stop();
	process.exit(1);
}
