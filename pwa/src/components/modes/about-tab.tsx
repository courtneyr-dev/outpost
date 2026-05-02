/**
 * About tab — informational content about Outpost.
 *
 * Phase C5e (UI polish, same arc as the C5 More-pull-out work). The
 * About tab is the only non-composing tab in the strip — it doesn't
 * accept input, doesn't post anything, and doesn't depend on
 * companion-plugin presence. It exists so users (especially first-time
 * IndieWeb-curious ones) can answer the questions they'll ask:
 *
 *   - "What does this plugin actually post to?"
 *   - "What's POSSE and why should I care?"
 *   - "Which IndieWeb specs is this built on?"
 *   - "What's XFN? What are Post Formats?"
 *
 * Static content — no state, no fetches, no JS reactivity beyond Preact
 * rendering. Renders once when the tab is opened.
 *
 * Links open in a new tab so users don't lose the composer they were
 * mid-typing in. `rel="noopener noreferrer"` per the existing pattern.
 */

interface ExternalLinkProps {
	href: string;
	children: preact.ComponentChildren;
}

function ExternalLink({ href, children }: ExternalLinkProps): preact.JSX.Element {
	return (
		<a href={href} target="_blank" rel="noopener noreferrer">
			{children}
		</a>
	);
}

export function AboutTab(): preact.JSX.Element {
	return (
		<section class="outpost-card outpost-about" aria-labelledby="outpost-about-title">
			<h2 id="outpost-about-title" class="outpost-card__title">
				About Outpost
			</h2>
			<p class="outpost-card__lede">
				A mobile-first PWA composer for posting to your own WordPress site, built
				around IndieWeb specs and POSSE workflow.
			</p>

			<h3>What Outpost posts</h3>
			<p>
				Outpost only writes <strong>posts</strong> — the standard WordPress{' '}
				<code>post</code> post type. It does <strong>not</strong> write:
			</p>
			<ul>
				<li>Pages</li>
				<li>Custom post types (unless the Micropub plugin is configured server-side to map there)</li>
				<li>Media attachments outside of the photo upload flow</li>
				<li>Comments — even Reply mode posts an h-entry on your own site, not a comment on someone else&apos;s</li>
			</ul>
			<p>
				Custom post type capacity: Outpost speaks Micropub; David Shanske&apos;s
				Micropub plugin decides which post type the request lands on. By default
				that&apos;s <code>post</code>. Routing to a CPT requires{' '}
				<ExternalLink href="https://github.com/dshanske/wordpress-micropub#filters">
					a server-side filter on the Micropub plugin
				</ExternalLink>
				. Outpost doesn&apos;t configure that — it just talks to whatever the
				server accepts.
			</p>

			<h3>POSSE — Publish (on your) Own Site, Syndicate Elsewhere</h3>
			<p>
				The acronym was{' '}
				<ExternalLink href="https://indieweb.org/POSSE">
					coined by Tantek Çelik
				</ExternalLink>{' '}
				as a mantra for the IndieWeb. The idea is simple but the consequences are
				large:
			</p>
			<ul>
				<li>
					<strong>Your site is the canonical source.</strong> Every post lives at
					a permanent URL on a domain you control.
				</li>
				<li>
					<strong>Other platforms are mirrors, not the home.</strong> A copy
					goes to Mastodon, Twitter (X), Bluesky, LinkedIn, or wherever your
					audience is — but the original lives at your URL.
				</li>
				<li>
					<strong>If a platform disappears, your work doesn&apos;t.</strong> Twitter
					rate-limits, Tumblr deletes adult content, Cohost shuts down, Threads
					quietly throttles links — none of those events erase posts you POSSE&apos;d.
				</li>
				<li>
					<strong>Conversations come back to you via webmentions.</strong>{' '}
					Replies / likes / reposts on syndicated copies get backfed to your
					site so the conversation lives on your domain too.
				</li>
			</ul>
			<p>
				Outpost makes POSSE workflow one tap:
			</p>
			<ul>
				<li>
					<strong>Syndicate-to chips</strong> in the More options panel —
					populated from your Micropub endpoint&apos;s configured destinations
					(<code>?q=syndicate-to</code>).
				</li>
				<li>
					<strong>Bridgy auto-suggest</strong> (Phase E2): when you reply to a
					Mastodon or Twitter URL, the matching{' '}
					<ExternalLink href="https://brid.gy/">Bridgy</ExternalLink> publish
					target gets pre-checked.
				</li>
				<li>
					<strong>Per-post syndication picks</strong>: every configured
					destination is on by default per post; tap to opt out individually.
				</li>
				<li>
					<strong>ActivityPub native</strong> (when the{' '}
					<ExternalLink href="https://wordpress.org/plugins/activitypub/">
						ActivityPub plugin
					</ExternalLink>{' '}
					is active): your posts federate to the fediverse without needing
					Bridgy in between.
				</li>
			</ul>

			<h3>IndieWeb specs Outpost speaks</h3>
			<p>
				Every post Outpost sends is built on these specs. Helpful primers if
				you want to understand what a post actually looks like on the wire:
			</p>
			<dl class="outpost-spec-list">
				<dt>
					<ExternalLink href="https://micropub.spec.indieweb.org/">
						Micropub
					</ExternalLink>
				</dt>
				<dd>
					The protocol Outpost uses to post. POST a form-encoded body with{' '}
					<code>h=entry</code> and properties (content, name, photo,
					in-reply-to…) to your Micropub endpoint, get a Location header
					pointing at the new post URL.
				</dd>
				<dt>
					<ExternalLink href="https://indieauth.spec.indieweb.org/">
						IndieAuth
					</ExternalLink>
				</dt>
				<dd>
					Browser-side auth flow. Outpost discovers your authorization
					endpoint, redirects you for consent, exchanges the code for a bearer
					token, stores it encrypted in IndexedDB.
				</dd>
				<dt>
					<ExternalLink href="https://microformats.org/wiki/h-entry">
						h-entry
					</ExternalLink>
				</dt>
				<dd>
					The shape of a post in the IndieWeb. Properties like{' '}
					<code>content</code>, <code>name</code> (title), <code>photo</code>,{' '}
					<code>in-reply-to</code>, <code>like-of</code>, <code>category</code>.
					Every variant Outpost ships is just a different combination of
					h-entry properties.
				</dd>
				<dt>
					<ExternalLink href="https://microformats.org/wiki/h-card">
						h-card
					</ExternalLink>
				</dt>
				<dd>
					Person/organization marker on a page. Outpost reads h-cards from
					Reply targets to surface the page author in the citation context.
				</dd>
				<dt>
					<ExternalLink href="https://microformats.org/wiki/h-cite">
						h-cite
					</ExternalLink>
				</dt>
				<dd>
					Citation marker. The Quote variant&apos;s source citation maps here in
					richer Micropub shapes (Phase F).
				</dd>
				<dt>
					<ExternalLink href="https://microformats.org/wiki/microformats2">
						Microformats2
					</ExternalLink>
				</dt>
				<dd>
					The umbrella spec all the h-* shapes live under.
				</dd>
				<dt>
					<ExternalLink href="https://indieweb.org/Webmention">
						Webmention
					</ExternalLink>
				</dt>
				<dd>
					How replies on syndicated copies make their way back to your site.
					Outpost doesn&apos;t implement webmention itself — your WordPress
					site does, via the{' '}
					<ExternalLink href="https://wordpress.org/plugins/webmention/">
						Webmention plugin
					</ExternalLink>
					.
				</dd>
			</dl>

			<h3>XFN — XHTML Friends Network</h3>
			<p>
				The relationship picker on Reply / Doing target URLs is XFN. It lets
				you mark how you know the person on the other end of a link:{' '}
				<code>friend</code>, <code>met</code>, <code>colleague</code>,{' '}
				<code>contact</code>, <code>spouse</code>, and so on.
			</p>
			<dl class="outpost-spec-list">
				<dt>
					<ExternalLink href="https://gmpg.org/xfn/">XFN home page</ExternalLink>
				</dt>
				<dd>The original Global Multimedia Protocols Group hub.</dd>
				<dt>
					<ExternalLink href="https://gmpg.org/xfn/11">
						XFN 1.1 specification
					</ExternalLink>
				</dt>
				<dd>
					The complete relationship vocabulary Outpost surfaces in the picker.
				</dd>
				<dt>
					<ExternalLink href="https://wordpress.org/plugins/link-extension-for-xfn/">
						Link Extension for XFN plugin
					</ExternalLink>
				</dt>
				<dd>
					The companion plugin Outpost gates the picker on. Without it, your
					theme isn&apos;t reading the rels you set, so the picker stays hidden
					to avoid suggesting it does something it doesn&apos;t.
				</dd>
			</dl>

			<h3>Post Formats</h3>
			<p>
				WordPress&apos;s native classification of posts as Status / Aside /
				Image / Gallery / Video / Audio / Quote / Link / Standard. Outpost
				auto-applies the matching format from h-entry signals so themes can
				render each kind appropriately:
			</p>
			<ul>
				<li><code>like-of</code> / <code>repost-of</code> / <code>bookmark-of</code> → Link</li>
				<li>Single <code>photo</code> → Image; multiple → Gallery</li>
				<li><code>listen-of</code> → Audio</li>
				<li><code>watch-of</code> → Video</li>
				<li><code>in-reply-to</code> → Status</li>
				<li>Note ≤ 280 chars → Status; longer → Standard</li>
				<li>Article (h-entry with title) → Standard</li>
				<li>Quote variant → Quote</li>
			</ul>
			<dl class="outpost-spec-list">
				<dt>
					<ExternalLink href="https://wordpress.org/documentation/article/post-formats/">
						Post Formats — WordPress documentation
					</ExternalLink>
				</dt>
				<dd>End-user explanation of what each format means.</dd>
				<dt>
					<ExternalLink href="https://developer.wordpress.org/themes/functionality/post-formats/">
						Post Formats — theme developer reference
					</ExternalLink>
				</dt>
				<dd>How themes opt in via <code>add_theme_support()</code>.</dd>
				<dt>
					<ExternalLink href="https://wordpress.org/plugins/post-formats-for-block-themes/">
						Post Formats for Block Themes plugin
					</ExternalLink>
				</dt>
				<dd>
					Required for block themes (which don&apos;t opt into post formats by
					default). Outpost gates the format selector and the auto-inference
					bridge on this plugin being active.
				</dd>
			</dl>

			<h3>Companion plugins</h3>
			<p>
				Outpost works on its own but adapts when these plugins are present:
			</p>
			<dl class="outpost-spec-list">
				<dt>
					<ExternalLink href="https://wordpress.org/plugins/indieauth/">
						IndieAuth
					</ExternalLink>{' '}
					&middot;{' '}
					<ExternalLink href="https://wordpress.org/plugins/micropub/">
						Micropub
					</ExternalLink>
				</dt>
				<dd>
					<strong>Required.</strong> The protocol layer. Without these you
					can&apos;t sign in or post.
				</dd>
				<dt>
					<ExternalLink href="https://wordpress.org/plugins/indieweb-post-kinds/">
						Post Kinds for IndieWeb
					</ExternalLink>
				</dt>
				<dd>
					Surfaces the Listen / Watch / Read / Play / Checkin variants on the
					Doing tab and the Like / Repost / Bookmark / RSVP / Follow variants
					on the Reply tab as proper post-kind taxonomy on your site.
				</dd>
				<dt>
					<ExternalLink href="https://wordpress.org/plugins/syndication-links/">
						Syndication Links
					</ExternalLink>
				</dt>
				<dd>
					Stores the URLs of syndicated copies so they can be displayed on
					your post and consumed by webmention back-feeds.
				</dd>
				<dt>
					<ExternalLink href="https://wordpress.org/plugins/wordpress-seo/">
						Yoast SEO
					</ExternalLink>
				</dt>
				<dd>
					Outpost surfaces the focus keyphrase field in More options when this
					is active. Keyphrase saves to Yoast&apos;s postmeta.
				</dd>
				<dt>
					<ExternalLink href="https://wordpress.org/plugins/activitypub/">
						ActivityPub
					</ExternalLink>{' '}
					&middot;{' '}
					<ExternalLink href="https://brid.gy/">Bridgy</ExternalLink>
				</dt>
				<dd>
					Federate your posts to the fediverse and bridge to silo platforms
					like Mastodon, Twitter, Bluesky.
				</dd>
				<dt>
					<ExternalLink href="https://equalizedigital.com/accessibility-checker/">
						Accessibility Checker
					</ExternalLink>
				</dt>
				<dd>
					When active, Outpost surfaces a &ldquo;View accessibility
					report&rdquo; link in the success message after each post. The scan
					itself runs server-side automatically on save.
				</dd>
			</dl>

			<h3>Outpost itself</h3>
			<dl class="outpost-spec-list">
				<dt>
					<ExternalLink href="https://github.com/courtneyr-dev/outpost">
						Source on GitHub
					</ExternalLink>
				</dt>
				<dd>GPLv2-or-later. Issues, pull requests, and reading welcome.</dd>
				<dt>
					<ExternalLink href="https://indieweb.org/POSSE">
						More on POSSE
					</ExternalLink>{' '}
					&middot;{' '}
					<ExternalLink href="https://indieweb.org/">indieweb.org</ExternalLink>
				</dt>
				<dd>
					The wider community Outpost is built for and built with.
				</dd>
			</dl>
		</section>
	);
}
