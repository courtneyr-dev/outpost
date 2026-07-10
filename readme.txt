=== Outpost ===

Contributors:      courane01
Tags:              indieweb, micropub, posse, pwa, syndication
Tested up to:      7.0
Stable tag:        0.1.114
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.5
Requires PHP:      8.2

Post notes, replies, photos, and life-tracking entries to your WordPress site from your phone, with one-tap POSSE syndication.

== Description ==

Outpost is a mobile-first Progressive Web App (PWA) composer for WordPress, built for IndieWeb POSSE workflows (Publish on your Own Site, Syndicate Elsewhere). Open `/post` on your phone, write a note or reply, and the post publishes to your own site through the Micropub API. Syndication chips send the same post to Mastodon, Bluesky, the Fediverse, or wherever else you've configured — no Jetpack, no app store, no third-party account.

**Who it's for:** WordPress site owners who want fast mobile posting on their own domain — micro-bloggers, POSSE practitioners, and life-loggers tracking what they listen to, watch, and read.

**Composer modes:**

* **Note** — a plain text post. Voice-input ready.
* **Reply / Like / Repost / Bookmark / RSVP / Follow** — paste a URL (or use a bookmarklet from any page), add your response, post.
* **Listen / Watch / Read / Checkin / Play** — log a life-tracking entry. With the Post Kinds companion active, the "Look it up" search fills in title, creator, and cover art from MusicBrainz, TMDB, Open Library, Foursquare, or RAWG.
* **Photo** — upload from your camera roll, with a required alt text field.
* **Article** — hands off to the block editor for long-form work.

The composer is a real PWA: it installs to your iOS or Android home screen, queues drafts written offline, and accepts pages from the share sheet (Android's Web Share Target; on iOS via an Apple Shortcut).

POSSE-first: every syndication destination configured on your site is enabled by default for every new post. Tap a chip to skip it.

**What can go wrong:** Outpost needs the IndieAuth and Micropub plugins — without them, wp-admin shows a notice and `/post` shows a setup prompt instead of the composer. Some managed hosts strip the Authorization header that Micropub sign-in depends on (see FAQ).

Long-form documentation — installation, settings, common tasks, troubleshooting, privacy — lives in the [Outpost docs on GitHub](https://github.com/courtneyr-dev/outpost/tree/main/docs).

== Requirements ==

* WordPress 6.5 or newer, PHP 8.2 or newer.
* The [IndieAuth plugin](https://wordpress.org/plugins/indieauth/) (required — Micropub itself requires it).
* The [Micropub plugin](https://wordpress.org/plugins/micropub/) (required — the publishing endpoint).

Optional companions light up extra features when active:

* **Post Kinds for IndieWeb** — powers the "Look it up" media search and renders Listen/Watch/Read/Checkin/Play entries as proper post kinds. Without it, those entries publish as generic notes.
* **Post Formats for Block Themes** — post format selector and auto-detection.
* **Link Extension for XFN** — XFN relationship picker on reply targets.
* **Syndication Links** — destinations auto-populate the syndication chips.
* **Yoast SEO** — focus keyphrase and meta description fields.
* **ActivityPub** / **Bridgy** — surface as syndication chips automatically.

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

Not yet — Outpost is pre-release and this readme is prepared for submission. Until it's listed, install from GitHub per the Installation section.

= Where do the settings live? =

In wp-admin: the **Outpost** menu holds the bookmarklet generator, phone install steps, and composer defaults; **Outpost Settings** holds destination API keys; **Outpost → Appearance** controls day/night mode and color tokens; **Outpost → OAuth Connections** connects life-tracking services; and **Settings → Outpost iOS Shortcut** sets up share-sheet posting from an iPhone.

= Does it work on managed hosts? =

Mostly, with one known pitfall: some managed hosts strip the Authorization header that Micropub sign-in depends on. Outpost ships workarounds for the cases found so far (GoDaddy), but if sign-in loops or posting fails with an auth error, see the [troubleshooting guide](https://github.com/courtneyr-dev/outpost/blob/main/docs/troubleshooting.md).

= Does Outpost work offline? =

Yes, for composing. Drafts written offline queue in your browser's IndexedDB and submit when the connection returns. Sign-in tokens are encrypted (AES-GCM) in the browser.

= What sends data where? =

Posts go to your own site through Micropub. Syndication sends post content only to destinations you configure (for example the Bridgy service for Mastodon and Bluesky). Media search and URL previews fetch metadata from the relevant services. Life-tracking services (Oura, WHOOP, Polar, Ride With GPS, Ravelry, Notion) are contacted only after you connect them under OAuth Connections. Details in the [privacy and data doc](https://github.com/courtneyr-dev/outpost/blob/main/docs/privacy-and-data.md).

= Does Outpost require Jetpack? =

No. Outpost uses Micropub and IndieAuth for everything Jetpack normally provides on mobile.

= Can I change the /post URL? =

Not currently. The composer is served at the fixed `/post` path.

= How do the bookmarklets work? =

The Outpost admin page generates one bookmarklet per post kind, embedded with your site's URL. Drag one to your bookmark bar (or long-press on mobile); click it while viewing any page to open the composer with that page's URL pre-filled. Pattern adapted from IndieWeb Press This (Pfefferle, Shanske, Barrett).

= Does Outpost replace the block editor for long-form? =

No. The Article mode hands off to `/wp-admin/post-new.php`. Outpost is for fast, phone-sized posts; the block editor remains the right tool for long-form.

== Screenshots ==

1. The composer sign-in screen at /post on a phone — enter your site address to authorize the device via IndieAuth.
2. The Outpost admin page with per-variant bookmarklets, the composer link, and phone install steps.
3. OAuth Connections — connect life-tracking providers such as Notion, Polar Flow, Oura, Ride With GPS, Ravelry, and WHOOP.
4. Appearance settings — day/night mode and per-token color overrides, with automatic contrast adjustment.
5. The Outpost iOS Shortcut Bridge — site URL, per-user token, and connection status for share-sheet posting from iPhone.

== Credits ==

Outpost evolves from prior IndieWeb work for WordPress.

* IndieWeb Press This by Matthias Pfefferle, David Shanske, and Ryan Barrett — the bookmarklet pattern Outpost extends.
* Micropub plugin by David Shanske — the server endpoint Outpost depends on.
* IndieAuth plugin by Matthias Pfefferle and David Shanske — authentication.
* Bridgy by Ryan Barrett — round-trip syndication service.

The IndieWeb WordPress community built the foundation Outpost sits on top of.

== Changelog ==

= 0.1.0 =
* Initial scaffold (Session A0). Plugin bootstrap, requirements check, Micropub status admin notice. PWA shell, composer modes, and companion adapters land in subsequent sessions.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
