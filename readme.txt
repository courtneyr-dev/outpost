=== Outpost Mobile Publishing ===

Contributors:      courane01
Tags:              indieweb, micropub, posse, pwa, syndication
Tested up to:      7.1
Stable tag:        1.0.11
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.5
Requires PHP:      8.2
Requires Plugins:  indieauth, micropub

Post notes, replies, photos, and life-tracking entries to your WordPress site from your phone, with one-tap POSSE syndication.

== Description ==

Outpost is a mobile-first Progressive Web App (PWA) composer for WordPress, built for IndieWeb POSSE workflows (Publish on your Own Site, Syndicate Elsewhere). Open `/post` on your phone, write a note or reply, and the post publishes to your own site through the Micropub API. Syndication chips send the same post to Mastodon, Bluesky, the Fediverse, or wherever else you've configured — no Jetpack, no app store, no third-party account.

**Who it's for:** WordPress site owners who want fast mobile posting on their own domain — micro-bloggers, POSSE practitioners, and life-loggers tracking what they listen to, watch, and read.

**Composer modes** — every kind the Post Kinds companion registers is postable from the composer:

* **Post** — Note, Status, Aside, Quote, and Article (long-form with a title). Voice-input ready.
* **Reply / Like / Favorite / Repost / Bookmark / RSVP / Follow / Wishlist / Tag / Acquisition / Issue** — paste a URL (or use a bookmarklet from any page), add your response, post.
* **Doing: Listen / Watch / Read / Play / Game / Jam / Checkin / Eat / Drink / Exercise / Craft / Event / Review / Video / Audio** — log a life-tracking entry. With the Post Kinds companion active, the "Look it up" search fills in title, creator, and cover art from MusicBrainz, TMDB, Open Library, Foursquare, or RAWG. Any Doing entry except Video can carry a photo.
* **Life: Mood / Weather / Sleep / Trip / Itinerary / Question** — quick personal-state entries with an optional note.
* **Photo** — upload from your camera roll, with a required alt text field. The first photo on any post becomes its featured image.
* **Recipe** — title, ingredients, steps, and an optional photo, published as an h-recipe.

The composer is a real PWA: it installs to your iOS or Android home screen, queues drafts written offline, and accepts pages from the share sheet — Android's Web Share Target once it's installed as an app, and an Apple Shortcut on iOS. A shared link opens a Reply, shared text a Note, a shared photo the Photo tab.

POSSE-first: every syndication destination configured on your site is enabled by default for every new post. Tap a chip to skip it.

**What can go wrong:** Outpost needs the IndieAuth and Micropub plugins — without them, wp-admin shows a notice and `/post` shows a setup prompt instead of the composer. Some managed hosts strip the Authorization header that Micropub sign-in depends on (see FAQ).

Long-form documentation — installation, settings, common tasks, troubleshooting, privacy — lives at [courtneyr-dev.github.io/outpost](https://courtneyr-dev.github.io/outpost/).

== Requirements ==

WordPress 6.5 or newer, PHP 8.2 or newer.

= Required plugins =

Outpost publishes through the IndieWeb's own standards rather than a proprietary service, so it needs the two plugins that provide them. Both are free on WordPress.org, and Outpost tells you which one is missing:

* [IndieAuth](https://wordpress.org/plugins/indieauth/) — signs you in to the composer with your own domain. Install this one first: Micropub requires it too.
* [Micropub](https://wordpress.org/plugins/micropub/) — the endpoint that receives what the composer publishes.

= Recommended =

Not required, but Outpost is better with them, and it detects them the moment you activate one:

* [Post Kinds for IndieWeb in Block Themes](https://wordpress.org/plugins/post-kinds-for-indieweb-in-block-themes/) — powers the "Look it up" media search and classifies every composer entry as its proper post kind, so a listen, a check-in or a recipe arrives as that kind rather than a generic note. It also reads standard.site records behind the pages you cite in replies, likes, and bookmarks — no account needed — and, with ATmosphere, decides which kinds publish as standard.site documents on the AT Protocol.
* [Webmention](https://wordpress.org/plugins/webmention/) — lets the replies, likes and reposts you send be received by the sites you send them to, and shows theirs on your posts. It also carries the request that asks Bridgy to publish a post and brings back the replies Bridgy and rss.chat send home; Outpost itself sends and receives no webmentions.
* [Syndication Links](https://wordpress.org/plugins/syndication-links/) — your configured destinations become the composer's syndication chips.

= Works with =

Active-only enhancements. Nothing here changes what Outpost can publish; each adds a field or a chip when present:

* [Post Formats for Block Themes](https://wordpress.org/plugins/post-formats-for-block-themes/) — post format selector, plus automatic format inference from what you posted.
* [Link Extension for XFN](https://wordpress.org/plugins/link-extension-for-xfn/) — XFN relationship picker on reply targets.
* [ActivityPub](https://wordpress.org/plugins/activitypub/) — the Fediverse appears as a syndication chip.
* [Bridgy](https://brid.gy/) — its publish endpoints appear as syndication chips.
* [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) — focus keyphrase and meta description fields in the composer's More panel.
* [Accessibility Checker](https://wordpress.org/plugins/accessibility-checker/) — flags accessibility problems on what you post.
* [RSS Chat Routing](https://github.com/courtneyr-dev/rss-chat-routing) — rss.chat is a chat network built on RSS: your site publishes a post to it, people reply there, and the replies come back to your post as comments. This companion chooses which posts go (by default post format, default Post Kind, or per post) and, when active, adds a per-post "Send to rss.chat" choice — site default, include, or exclude — to the composer's More panel, so you can opt a post in or out from the app.
* [ATmosphere](https://wordpress.org/plugins/atmosphere/) — publishes each post to the AT Protocol: a Bluesky post or thread linking back to your site, plus the full article stored on your AT Protocol account as a standard.site document. Bluesky replies, likes, and reposts come back as comments. Outpost adds no chip for it — posts from the composer cross-post like any other published post; opt a post out from the block editor sidebar, and leave the "Bluesky via Bridgy" chip off when ATmosphere is connected so the same post doesn't reach Bluesky twice. With Post Kinds active, Post Kinds decides which kinds publish by default and derives readable titles for untitled ones.

== Installation ==

1. Install and activate the IndieAuth plugin from the WordPress.org plugin directory.
2. Install and activate the Micropub plugin from the WordPress.org plugin directory.
3. Install and activate Outpost. While Outpost is not yet listed on WordPress.org, download the ZIP from [GitHub releases](https://github.com/courtneyr-dev/outpost/releases) and install it via Plugins → Add New Plugin → Upload Plugin.
4. In wp-admin, open the **Outpost** menu: it links to the composer, generates bookmarklets, and walks through installing the app on your phone.
5. Open `/post` on your phone, sign in with your site address (IndieAuth), and tap "Add to Home Screen" to install the PWA.

If you skip step 1, the Micropub plugin refuses to register its endpoints; Outpost surfaces this as an admin notice with an install link.

== Frequently Asked Questions ==

= Do I need other plugins? =

Yes, two: IndieAuth (sign-in) and Micropub (publishing endpoint), both free on WordPress.org. Everything else is optional — companions such as Post Kinds, Syndication Links, and ActivityPub are detected at runtime and add features when active.

= Is Outpost on WordPress.org yet? =

Not yet — until it's listed, install from GitHub per the Installation section.

= Where do the settings live? =

In wp-admin: the **Outpost** menu holds the bookmarklet generator, phone install steps, and composer defaults; **Outpost → Settings** holds destination API keys; **Outpost → Appearance** controls day/night mode and color tokens; **Outpost → OAuth Connections** connects life-tracking services; and **Settings → Outpost iOS Shortcut** sets up share-sheet posting from an iPhone.

= Does it work on managed hosts? =

Mostly, with one known pitfall: some managed hosts strip the Authorization header that Micropub sign-in depends on. Outpost ships workarounds for the cases found so far (GoDaddy), but if sign-in loops or posting fails with an auth error, see the [troubleshooting guide](https://courtneyr-dev.github.io/outpost/troubleshooting/).

= Does Outpost work offline? =

Yes, for composing. Drafts written offline queue in your browser's IndexedDB and submit when the connection returns. Sign-in tokens are encrypted (AES-GCM) in the browser.

= What sends data where? =

Posts go to your own site through Micropub. Every other connection is listed, service by service, in the External services section below, and in more detail in the [privacy and data doc](https://courtneyr-dev.github.io/outpost/privacy-and-data/).

= Does Outpost require Jetpack? =

No. Outpost uses Micropub and IndieAuth for everything Jetpack normally provides on mobile.

= Can I change the /post URL? =

Not currently. The composer is served at the fixed `/post` path.

= How do the bookmarklets work? =

The Outpost admin page generates one bookmarklet per post kind, embedded with your site's URL. Drag one to your bookmark bar (or long-press on mobile); click it while viewing any page to open the composer with that page's URL pre-filled. Pattern adapted from IndieWeb Press This (Pfefferle, Shanske, Barrett).

= How do I post from my phone's share sheet? =

On Android (and desktop Chrome or Edge), install Outpost as an app and it appears in the share sheet on its own; a shared link opens a Reply, shared text a Note, a title plus text an Article, and a shared photo opens the Photo tab with the picture attached.

iOS Safari doesn't support share targets, so on iPhone or iPad add a Shortcut. The guided one from Settings → Outpost iOS Shortcut posts through a scoped token without opening the composer. To build one that opens the composer so you can review first:

1. Open the Shortcuts app and tap + for a new Shortcut.
2. In the first action, tap Nowhere and choose Share Sheet; set the types to URLs and Text.
3. Add the Open URLs action (not Share, which only reopens the share sheet). Set its URL to https://your-site.example/post/share-target?url= and insert Shortcut Input at the end.
4. Rename it Post to Outpost.
5. Share any page from Safari and pick Post to Outpost — the composer opens on Reply with that link.
6. To keep it near the top of the share sheet, scroll to the bottom of the sheet, tap Edit Actions…, tap the green + next to Post to Outpost to add it to Favorites, drag it to the top, and tap Done.

Photos get a second Shortcut, because the share sheet only offers a Shortcut that accepts what you're sharing: the same steps, but keep Images as the type and set Open URLs to https://your-site.example/post/?mode=photo with nothing after it. Name it Photo to Outpost. Sharing a photo then opens the composer on the Photo tab; pick the picture there and add alt text.

The composer's About tab has the same steps with your site's address filled in, and the full walkthrough is at https://courtneyr-dev.github.io/outpost/common-tasks/.

= Does Outpost replace the block editor for long-form? =

No. The Article variant publishes a titled post through Micropub like every other mode, but Outpost is built for fast, phone-sized posts — the block editor remains the right tool for serious long-form work, and anything posted from Outpost can be reopened there.

= Does Outpost create pages or custom post types? =

No. Outpost writes standard posts only — not pages, custom post types, or comments (a Reply is a post on your own site that links to the page you're replying to). Routing Micropub posts to a custom post type is a filter on the Micropub plugin, not an Outpost setting.

= Does Outpost support standard.site and the AT Protocol? =

Yes, through companions. With Post Kinds for IndieWeb in Block Themes, the pages you cite in replies, likes, and bookmarks are checked for a standard.site record and the author's own title, description, and tags are shown — no account needed. Add the ATmosphere plugin and, once it's connected and cross-posting is on, your posts publish as standard.site documents on the AT Protocol and appear on Bluesky, with Post Kinds deciding which kinds publish by default (public content and logs) and which stay opt-in (likes, check-ins, moods, and other private signals).

== External services ==

Outpost publishes to your own WordPress site through the Micropub plugin. It contacts the services below only for the specific feature named; nothing is sent until you use that feature. Each entry lists what is sent, when, and the service's terms and privacy policy.

= Syndication (per post, only for chips you leave enabled) =

* **Bridgy / Bridgy Fed** — when a Bridgy destination chip is enabled on a post, your site sends a webmention containing that post's URL to the Bridgy endpoint you configured: **brid.gy** (Flickr, GitHub, Reddit), **bsky.brid.gy** (Bluesky), or **fed.brid.gy** (the fediverse). Bridgy then reads the public post from your site and republishes it to the connected network. [About, terms, and privacy](https://brid.gy/about).
* **Telegraph (a Telegram service)** — when the Telegraph chip is enabled, the post's content is sent to api.telegra.ph to create the syndicated copy. The first use creates a Telegraph account token, which is stored on your site. [Terms](https://telegram.org/tos), [Privacy](https://telegram.org/privacy).
* **Beehiiv** — newsletter destination; when configured with an API key and enabled on a post, the post's content is sent to api.beehiiv.com. [Terms](https://www.beehiiv.com/tou), [Privacy](https://www.beehiiv.com/privacy).
* **Buttondown** — newsletter destination; sends the post's content to api.buttondown.email when enabled. [Terms](https://buttondown.com/legal/terms), [Privacy](https://buttondown.com/legal/privacy).
* **Kit (formerly ConvertKit)** — newsletter destination; sends the post's content to api.convertkit.com when enabled. [Terms](https://kit.com/terms), [Privacy](https://kit.com/privacy).
* **write.as** — blog destination; sends the post's content to the write.as API when enabled. [Platform guidelines](https://write.as/guidelines), [Privacy](https://write.as/privacy).

= Reply context and link previews =

* **The page you link to** — when you paste a URL into a reply, like, repost, bookmark, or similar mode, your site fetches that page (and, where available, its feed or oEmbed endpoint) once to build the preview (title, image, summary). Only the URL you pasted and endpoints it advertises are requested, from whatever site it points to.
* **Named oEmbed providers** — for four hosts, Outpost skips discovery and requests a known oEmbed endpoint directly, sending only the URL you pasted. This happens once per pasted link, and only for that host:
    * **Vimeo** — vimeo.com/api/oembed.json. [Terms](https://vimeo.com/terms), [Privacy](https://vimeo.com/privacy).
    * **YouTube** — www.youtube.com/oembed. [Terms](https://www.youtube.com/t/terms), [Privacy](https://policies.google.com/privacy).
    * **Spotify** — open.spotify.com/oembed. [Terms](https://www.spotify.com/legal/end-user-agreement/), [Privacy](https://www.spotify.com/legal/privacy-policy/).
    * **SoundCloud** — soundcloud.com/oembed. [Terms](https://soundcloud.com/terms-of-use), [Privacy](https://soundcloud.com/pages/privacy).
* **Media lookups** — the "Look it up" search contacts no service directly from Outpost. It hands the query to the Post Kinds for IndieWeb in Block Themes companion plugin when that plugin is active, and the companion's own listing documents its lookup services.

= Geocoding =

* **Nominatim (OpenStreetMap Foundation)** — the check-in location search sends your search text to nominatim.openstreetmap.org to find matching places, only when you search for a location. [Usage policy](https://operations.osmfoundation.org/policies/nominatim/), [Privacy](https://wiki.osmfoundation.org/wiki/Privacy_Policy).

= Connected life-tracking accounts (only after you connect them under Outpost → OAuth Connections) =

Connecting an account sends you to that service's sign-in page to authorize Outpost, then stores an encrypted token on your site. Afterwards, Outpost contacts the service's API only when you connect or disconnect it, or when you fetch your recent activity to prefill a post — sending the stored token and, for a page citation, the item URL you are viewing. The hosts contacted for each service:

* **Notion** — api.notion.com (authorize, token exchange, and reading a page you cite). [Terms and privacy](https://www.notion.com/terms).
* **Oura** — cloud.ouraring.com (authorize) and api.ouraring.com (token, verify, recent activity). [Terms](https://ouraring.com/terms-and-conditions), [Privacy](https://ouraring.com/privacy-policy).
* **WHOOP** — api.prod.whoop.com (authorize, token, verify, revoke, recent activity). [Terms](https://www.whoop.com/us/en/termsofuse/), [Privacy](https://www.whoop.com/us/en/privacy/).
* **Polar (Flow / AccessLink)** — flow.polar.com (authorize), polarremote.com (token, revoke), and www.polaraccesslink.com (register, verify, recent activity). [Terms](https://www.polar.com/en/legal/terms-of-use), [Privacy](https://www.polar.com/en/legal/privacy-notice).
* **Ride With GPS** — ridewithgps.com (authorize, token, verify, and reading a trip or route you cite). [Terms](https://ridewithgps.com/terms), [Privacy](https://ridewithgps.com/privacy).
* **Ravelry** — www.ravelry.com (authorize, token) and api.ravelry.com (verify, and reading a pattern or project you cite). [Terms](https://www.ravelry.com/about/terms), [Privacy](https://www.ravelry.com/about/privacy).

= Manual share (opens the service in your browser, only when you tap its chip) =

These destinations have no posting API Outpost can use, so sharing to them is a handoff rather than a connection: tapping the chip opens the service's own share page (or its app, on a phone) with your post's link and text already filled in, and you finish the post there. **Your site never contacts these services** — no request is made from your server, and nothing is sent unless you tap the chip. What travels is what the share URL carries: your post's URL, its text, and for Pinterest the image URL.

* **Facebook** — www.facebook.com/sharer.php. [Terms](https://www.facebook.com/terms.php), [Privacy](https://www.facebook.com/privacy/policy/).
* **X** — twitter.com/intent/tweet. [Terms](https://x.com/en/tos), [Privacy](https://x.com/en/privacy).
* **Threads** — www.threads.net/intent/post. [Terms](https://help.instagram.com/581066165581870), [Privacy](https://privacycenter.instagram.com/policy).
* **Pinterest** — www.pinterest.com/pin/create/button/. [Terms](https://policy.pinterest.com/en/terms-of-service), [Privacy](https://policy.pinterest.com/en/privacy-policy).
* **Reddit** — www.reddit.com/submit. [Terms](https://redditinc.com/policies/user-agreement), [Privacy](https://www.reddit.com/policies/privacy-policy).
* **LinkedIn** — the LinkedIn app, or www.linkedin.com to finish by hand. [Terms](https://www.linkedin.com/legal/user-agreement), [Privacy](https://www.linkedin.com/legal/privacy-policy).
* **Instagram** and **Instagram Stories** — the Instagram app on your phone; your photo is handed to it through the share sheet. [Terms](https://help.instagram.com/581066165581870), [Privacy](https://privacycenter.instagram.com/policy).
* **TikTok** — the TikTok app on your phone. [Terms](https://www.tiktok.com/legal/page/row/terms-of-service/en), [Privacy](https://www.tiktok.com/legal/page/row/privacy-policy/en).
* **Flickr** — the Flickr app, or flickr.com to finish by hand. [Terms](https://www.flickr.com/help/terms), [Privacy](https://www.flickr.com/help/privacy).

= Inbound only (no data sent) =

* **IndieAuth sign-in** — authentication happens between your browser and your own site's IndieAuth endpoint (from the required IndieAuth plugin). Outpost sends nothing to third parties to sign you in.

== Source Code ==

The composer interface ships as compiled JavaScript in `build/pwa/`. Its human-readable source is the `pwa/src/` directory (TypeScript + Preact) in the public development repository: [github.com/courtneyr-dev/outpost](https://github.com/courtneyr-dev/outpost). The repository also carries the full PHP source, the test suites, and the build tooling.

To rebuild the compiled assets from source: `npm install`, then `npm run build` (Node 20.10 or newer). Vite writes the production bundle to `build/pwa/`, and the shipped bundle is the unmodified output of that build for this version.

== Screenshots ==

1. The composer sign-in screen at /post on a phone — enter your site address to authorize the device via IndieAuth.
2. The Outpost admin page with per-variant bookmarklets, the composer link, and phone install steps.
3. OAuth Connections — connect life-tracking providers such as Notion, Polar Flow, Oura, Ride With GPS, Ravelry, and WHOOP.
4. Appearance settings — day/night mode and per-token color overrides, with automatic contrast adjustment.
5. The Outpost iOS Shortcut Bridge — site URL, per-user token, and connection status for share-sheet posting from iPhone.
6. Note mode on a phone, signed in — write a note and pick syndication targets before posting.
7. The composer offline — posts made without a connection wait in the queue until you reconnect.

== Credits ==

Outpost evolves from prior IndieWeb work for WordPress.

* IndieWeb Press This by Matthias Pfefferle, David Shanske, and Ryan Barrett — the bookmarklet pattern Outpost extends.
* Micropub plugin by David Shanske — the server endpoint Outpost depends on.
* IndieAuth plugin by Matthias Pfefferle and David Shanske — authentication.
* Bridgy by Ryan Barrett — round-trip syndication service.

The IndieWeb WordPress community built the foundation Outpost sits on top of.

== Changelog ==

= 1.0.11 =
* Added: Default categories and Default tags in Composer defaults. The composer's More options open with them pre-selected, and a post that names no category gets them instead of WordPress's own default category. Leave them empty to keep the old behavior.

= 1.0.10 =
* Fixed: sharing a photo from the phone's share sheet lands on the Photo tab. The Web Share Target now accepts image files (Android and desktop Chrome/Edge): the picture stays on the device and the composer opens with it attached. The iOS directions add a second Shortcut, Photo to Outpost, that opens the Photo tab.

= 1.0.9 =
* Fixed: liking or replying to an X or Mastodon URL could fail with "Unknown mp-syndicate-to targets" because the suggested Bridgy chip sent a hard-coded brid.gy uid the site's Micropub endpoint never advertised. The chip now resolves to one of the endpoint's own syndication targets and stays hidden when there is none.
* Changed: the share-sheet directions add a step for moving Post to Outpost near the top of the iOS share sheet through Edit Actions.

= 1.0.8 =
* Changed: the About tab's share-sheet directions name the two iOS Shortcut traps (tap Nowhere → Share Sheet; add Open URLs, not Share), add a test step, and link to the full walkthrough; the About tab now links to the documentation site.

= 1.0.7 =
* Fixed: the wp-admin sidebar showed two identical "Outpost" menus; the settings screen now sits under the single Outpost menu as "Settings" (its URL is unchanged).

= 1.0.6 =
* New: the About tab now explains how to add Outpost to your device's share sheet — the automatic Web Share Target on Android (once installed as an app) and a Shortcut-based setup for iPhone/iPad, where iOS Safari doesn't support share targets.

= 1.0.5 =
* Fixed: on managed-WP hosts that strip the Authorization header, the composer's More-options surface (Yoast keyphrase, categories, tags, XFN) failed to load with a "sign-in may have expired" notice even after re-authenticating. The composer-config request now carries its token in the request body and is authenticated by that token, so it works on those hosts. The token no longer travels in the URL.

= 1.0.4 =
* New: attach a photo to any Doing kind except video and to Recipe posts; the first photo becomes the featured image.
* Security: REST route scoping keys on the route WordPress resolves, closing a nonce-protection gap on the composer-config path; preview fetches refuse link-local, CGNAT, and IPv6-internal targets and re-check every redirect; fetched preview HTML is sanitized with a wp_kses allowlist; Outpost's own routes render only from a matched rewrite rule; the Notion page cache is scoped per user; Micropub bridges write only to attachments the author can edit.
* Fixed: photo alt text is no longer lost on Micropub posts; the shipped app bundle matches the source; uninstall removes all Outpost data and nothing else.
* Changed: the External services list matches the source.

= 1.0.3 =
* Security hardening: enforce the 2048-character URL length cap on the preview and share/shortcut URL validators, matching the manual-share path and the plugin's documented input contract.

= 1.0.2 =
* Security: hardened iOS Shortcut token scope enforcement. The token, which is scoped to the share endpoint, could be accepted on other REST routes because scope was decided from the raw request URI rather than the route WordPress resolves. Now keyed on the resolved route. Found in internal security review.

= 1.0.1 =
* Security: the composer-config REST endpoint now requires the `edit_posts` capability. Logged-in users without it (Subscribers) could previously read composer configuration and companion-plugin status. Flagged by the WordPress.org plugin review team.
* Fix: the pending-syndication reminder now raises a block-editor notice; it previously rendered only inside the editor's hidden no-JS container, so block-editor users never saw it.

= 1.0.0 =
* First stable release, prepared for the WordPress.org plugin directory.
* PWA composer at `/post` with Note, Reply, Like, Repost, Bookmark, RSVP, Follow, Listen, Watch, Read, Checkin, Play, Photo, and Article modes.
* One-tap POSSE syndication chips, including Bridgy, Telegraph, and newsletter destinations (Beehiiv, Buttondown, Kit, write.as).
* Offline draft queue, home-screen install, share-sheet posting (Android Web Share Target; iOS via Shortcut).
* OAuth connections for life-tracking services (Notion, Oura, WHOOP, Polar, Ride With GPS, Ravelry) with libsodium-encrypted credential storage.
* Bookmarklet generator, appearance token editor with live preview, and companion-plugin detection.

= 0.1.0 =
* Initial scaffold. Plugin bootstrap, requirements check, Micropub status admin notice.

== Upgrade Notice ==

= 1.0.0 =
First stable release.
