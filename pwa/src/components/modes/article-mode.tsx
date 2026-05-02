/**
 * Article mode — placeholder for Phase C4.
 *
 * Will handle long-form posts: an h-entry with both `name` (title) and
 * `content` (body). Unlike Note, Article supports the title field. The
 * content field uses a richer editor than Note's textarea — likely a
 * minimal markdown surface, since the Block Editor is unwieldy on
 * mobile and the IndieWeb-typical Article author already knows
 * markdown.
 *
 * Phase C4 lands Article. The More pull-out (Phase C5) adds Post
 * Formats / Yoast SEO / Syndication Links integration on top, available
 * across all modes but most-relevant for Article.
 */

export function ArticleMode() {
	return (
		<section class="outpost-card" aria-labelledby="outpost-article-mode-title">
			<h2 id="outpost-article-mode-title" class="outpost-card__title">
				Article
			</h2>
			<p class="outpost-card__lede">
				Article posting (title + body) lands in Phase C4. Uses h-entry with `name` and
				`content` properties.
			</p>
		</section>
	);
}
