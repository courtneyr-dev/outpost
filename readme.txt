=== Outpost ===

Contributors:      courane01
Tags:              indieweb, micropub, posse, pwa, syndication
Tested up to:      6.9
Stable tag:        0.1.60
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.5
Requires PHP:      8.2

Mobile-first PWA composer for IndieWeb POSSE workflows. Post from your outpost. Reach your people everywhere.

== Description ==

Outpost is a mobile-first Progressive Web App composer for WordPress, built for IndieWeb POSSE workflows (Publish on your Own Site, Syndicate Elsewhere). Open `/post` on your phone, write a note or reply, tap a syndication chip, and your post lands on your site and on Mastodon, Bluesky, the Fediverse, or wherever else you've configured — all without Jetpack, app stores, or third-party auth.

Outpost ships five composer modes, optimised for the post shapes IndieWeb people actually publish:

* **Note** — a plain text post, defaulting to the Aside post format. Voice-input ready.
* **Reply / Like / Repost / Bookmark / RSVP / Follow** — paste a URL (or use a bookmarklet from any page), add your response, post.
* **Listen / Watch / Read / Checkin / Play** — search MusicBrainz, TMDB, Open Library, Foursquare, or RAWG and post a life-tracking entry.
* **Photo** — upload from your camera roll, with required alt text. EXIF stripped on upload.
* **Article** — handoff to the Block Editor for long-form work.

The composer is a real PWA: it installs to your iOS or Android home screen, works offline (with a queued draft system), and integrates with the OS share sheet so you can reply to any URL from the share sheet.

POSSE-first: every syndication destination configured on your site is enabled by default for every new post. Tap to disable, not to enable.

== Requirements ==

* WordPress 6.5 or newer.
* PHP 8.2 or newer.
* The [IndieAuth plugin](https://wordpress.org/plugins/indieauth/) by Matthias Pfefferle and David Shanske (required dependency — Micropub itself requires this).
* The [Micropub plugin](https://wordpress.org/plugins/micropub/) by David Shanske (required dependency).
* Recommended: [Syndication Links](https://wordpress.org/plugins/syndication-links/) and [Post Kinds](https://wordpress.org/plugins/indieweb-post-kinds/) for full functionality.

== Companion Plugins ==

Outpost works standalone with just Micropub, and lights up additional features when these plugins are also active:

* **Post Kinds for IndieWeb** — adds Listen, Watch, Read, Checkin, Play, and Follow modes.
* **Post Formats for Block Themes** — surfaces a post format selector and auto-detects format from content.
* **Link Extension for XFN** — XFN relationship picker on reply targets.
* **Syndication Links** — destinations auto-populate the syndication chips.
* **Yoast SEO** — focus keyphrase and meta description fields.
* **ActivityPub** / **Bridgy** — surface as syndication chips automatically.

== Installation ==

1. Install and activate the IndieAuth plugin from the WordPress.org plugin directory.
2. Install and activate the Micropub plugin from the WordPress.org plugin directory.
3. Install Outpost from the WordPress.org plugin directory and activate.
4. Visit Settings → Outpost to configure syndication destinations and (optionally) the route slug.
5. Open `/post` on your phone. Tap "Add to Home Screen" in your browser to install the PWA.
6. From the settings page, drag the bookmarklet for each post kind to your bookmark bar.

If you skip step 1 the Micropub plugin will refuse to register its endpoints; Outpost surfaces this as a "[Install IndieAuth]" admin notice.

== Frequently Asked Questions ==

= Does Outpost require Jetpack? =

No. Outpost uses Micropub and IndieAuth for everything Jetpack normally provides on mobile.

= Does Outpost work without an internet connection? =

Yes. Drafts compose offline and are queued in encrypted IndexedDB; they submit when the connection returns.

= How does the bookmarklet work? =

The settings page generates one bookmarklet per post kind, embedded with your site's URL. Drag to your bookmark bar; click while viewing any page to open the Outpost composer with that page's URL pre-filled. Pattern adapted from IndieWeb Press This (Pfefferle, Shanske, Barrett).

= Can I customise the route? =

Yes. The default is `/post` but the slug is configurable in Settings → Outpost.

= Does Outpost replace the Block Editor for long-form? =

No. The Article mode hands off to `/wp-admin/post-new.php`. Outpost is for fast, mobile-friendly posts; the Block Editor remains the right tool for long-form.

== Changelog ==

= 0.1.0 =
* Initial scaffold (Session A0). Plugin bootstrap, requirements check, Micropub status admin notice. PWA shell, composer modes, and companion adapters land in subsequent sessions.

== Credits ==

Outpost evolves from prior IndieWeb work for WordPress.

* IndieWeb Press This by Matthias Pfefferle, David Shanske, and Ryan Barrett — the bookmarklet pattern for Reply/Like/Repost/RSVP/Follow from any page on the web. Outpost extends and modernizes this pattern to target the PWA composer endpoint.
* Micropub plugin by David Shanske — Outpost depends on this plugin as its server endpoint.
* IndieAuth plugin by Matthias Pfefferle and David Shanske — used for authentication.
* IndieBlocks by Jan Boddez — companion plugin for theme blocks.
* Bridgy by Ryan Barrett — round-trip syndication service.

The IndieWeb WordPress community built the foundation Outpost sits on top of.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
