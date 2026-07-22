=== Outpost ===

Contributors:      courane01
Tags:              indieweb, micropub, posse, pwa, syndication
Tested up to:      7.0
Stable tag:        1.0.0
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

Long-form documentation — installation, settings, common tasks, troubleshooting, privacy — lives at [courtneyr-dev.github.io/outpost](https://courtneyr-dev.github.io/outpost/).

== Requirements ==

* WordPress 6.5 or newer, PHP 8.2 or newer.
* The [IndieAuth plugin](https://wordpress.org/plugins/indieauth/) (required — Micropub itself requires it).
* The [Micropub plugin](https://wordpress.org/plugins/micropub/) (required — the publishing endpoint).

Optional companions light up extra features when active:

* **Post Kinds for IndieWeb in Block Themes** — powers the "Look it up" media search and renders Listen/Watch/Read/Checkin/Play entries as proper post kinds. Without it, those entries publish as generic notes.
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

Posts go to your own site through Micropub. Every other connection is listed, service by service, in the External services section below, and in more detail in the [privacy and data doc](https://github.com/courtneyr-dev/outpost/blob/main/docs/privacy-and-data.md).

= Does Outpost require Jetpack? =

No. Outpost uses Micropub and IndieAuth for everything Jetpack normally provides on mobile.

= Can I change the /post URL? =

Not currently. The composer is served at the fixed `/post` path.

= How do the bookmarklets work? =

The Outpost admin page generates one bookmarklet per post kind, embedded with your site's URL. Drag one to your bookmark bar (or long-press on mobile); click it while viewing any page to open the composer with that page's URL pre-filled. Pattern adapted from IndieWeb Press This (Pfefferle, Shanske, Barrett).

= Does Outpost replace the block editor for long-form? =

No. The Article mode hands off to `/wp-admin/post-new.php`. Outpost is for fast, phone-sized posts; the block editor remains the right tool for long-form.

== External services ==

Outpost publishes to your own WordPress site through the Micropub plugin. It contacts the services below only for the specific feature named; nothing is sent until you use that feature. Each entry lists what is sent, when, and the service's terms and privacy policy.

= Syndication (per post, only for chips you leave enabled) =

* **Bridgy / Bridgy Fed** — when a Bridgy destination chip is enabled on a post, your site sends a webmention containing that post's URL to the brid.gy or fed.brid.gy endpoint you configured. Bridgy then reads the public post from your site and republishes it to the connected network. [About, terms, and privacy](https://brid.gy/about).
* **Telegraph (a Telegram service)** — when the Telegraph chip is enabled, the post's content is sent to api.telegra.ph to create the syndicated copy. The first use creates a Telegraph account token, which is stored on your site. [Terms](https://telegram.org/tos), [Privacy](https://telegram.org/privacy).
* **Beehiiv** — newsletter destination; when configured with an API key and enabled on a post, the post's content is sent to api.beehiiv.com. [Terms](https://www.beehiiv.com/tou), [Privacy](https://www.beehiiv.com/privacy).
* **Buttondown** — newsletter destination; sends the post's content to api.buttondown.email when enabled. [Terms](https://buttondown.com/legal/terms), [Privacy](https://buttondown.com/legal/privacy).
* **Kit (formerly ConvertKit)** — newsletter destination; sends the post's content to api.convertkit.com when enabled. [Terms](https://kit.com/terms), [Privacy](https://kit.com/privacy).
* **write.as** — blog destination; sends the post's content to the write.as API when enabled. [Platform guidelines](https://write.as/guidelines), [Privacy](https://write.as/privacy).

= Reply context and link previews =

* **The page you link to** — when you paste a URL into a reply, like, repost, bookmark, or similar mode, your site fetches that page (and, where available, its feed or oEmbed endpoint) once to build the preview (title, image, summary). Only the URL you pasted and endpoints it advertises are requested, from whatever site it points to.
* **Apple iTunes Search API** — when the URL you paste is a music.apple.com link, the track or album metadata for the preview is fetched from itunes.apple.com with the item's ID. [Terms](https://www.apple.com/legal/internet-services/itunes/), [Privacy](https://www.apple.com/legal/privacy/).
* **Media lookups** — the "Look it up" search contacts no service directly from Outpost. It hands the query to the Post Kinds for IndieWeb in Block Themes companion plugin when that plugin is active, and the companion's own listing documents its lookup services.

= Geocoding =

* **Nominatim (OpenStreetMap Foundation)** — the check-in location search sends your search text to nominatim.openstreetmap.org to find matching places, only when you search for a location. [Usage policy](https://operations.osmfoundation.org/policies/nominatim/), [Privacy](https://wiki.osmfoundation.org/wiki/Privacy_Policy).

= Connected life-tracking accounts (only after you connect them under Outpost → OAuth Connections) =

Connecting an account stores an encrypted token on your site. Afterwards, Outpost contacts that service only to fetch your recent activity for prefilling posts and to maintain the connection (token refresh or revocation).

* **Notion** — [Terms and privacy](https://www.notion.com/terms).
* **Oura** — [Terms](https://ouraring.com/terms-and-conditions), [Privacy](https://ouraring.com/privacy-policy).
* **WHOOP** — [Terms](https://www.whoop.com/us/en/termsofuse/), [Privacy](https://www.whoop.com/us/en/privacy/).
* **Polar (Flow / AccessLink)** — [Terms](https://www.polar.com/en/legal/terms-of-use), [Privacy](https://www.polar.com/en/legal/privacy-notice).
* **Ride With GPS** — [Terms](https://ridewithgps.com/terms), [Privacy](https://ridewithgps.com/privacy).
* **Ravelry** — [Terms](https://www.ravelry.com/about/terms), [Privacy](https://www.ravelry.com/about/privacy).

= Inbound only (no data sent) =

* **IndieAuth sign-in** — authentication happens between your browser and your own site's IndieAuth endpoint (from the required IndieAuth plugin). Outpost sends nothing to third parties to sign you in.

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
