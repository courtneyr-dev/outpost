=== Outpost Mobile Publishing ===

Contributors:      courane01
Tags:              indieweb, micropub, posse, pwa, syndication
Tested up to:      7.1
Stable tag:        1.0.27
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.5
Requires PHP:      8.2
Requires Plugins:  indieauth, micropub

Post notes, replies, photos, and life-tracking entries to your WordPress site from your phone, with one-tap POSSE syndication.

== Description ==

Outpost is a mobile composer for WordPress, installed as a Progressive Web App at `/post`. Write a note, reply, photo, recipe, or life-tracking entry on your phone; it publishes to your own site through the Micropub API, and syndication chips send it on to the destinations your site has configured.

* Modes: Post (note to article), Reply and its kinds, Doing (listen, watch, read, and more), Life, Photo (alt text required), and Recipe.
* Installs to your home screen, keeps unsent text on the device, and queues posts made offline for the account that wrote them.
* Accepts shares from Android's share sheet and an Apple Shortcut; shared content waits until you choose Use or Discard.

Documentation: [courtneyr-dev.github.io/outpost](https://courtneyr-dev.github.io/outpost/).

= Abilities =

With the Abilities API, Outpost registers three read-only abilities: `outpost/get-syndicate-targets` and `outpost/get-composer-config` (`edit_posts`) and `outpost/get-share-status` (`edit_post`).

== Installation ==

1. Install and activate the IndieAuth and Micropub plugins (WordPress asks for them).
2. Install and activate Outpost.
3. Open `yoursite.com/post` on your phone, sign in with your site address, and add it to your home screen.

Optional companions: Post Kinds for IndieWeb (more post kinds and media lookups), Syndication Links, Post Formats for Block Themes, Link Extension for XFN, and Yoast SEO.

== Frequently Asked Questions ==

= Sign-in fails on my host =

Some managed hosts strip the Authorization header that Micropub sign-in needs. The documentation lists the fixes.

= Does it work when WordPress is in a subdirectory? =

Not yet. The composer lives at `/post/` and calls `/wp-json/` from the root of your domain, so Outpost needs WordPress at that root and any permalink setting except Plain.

= Does it work offline? =

Yes. A post made offline waits on the device and sends when you reconnect, signed in as the same account.

== External services ==

Outpost publishes to your own site. It contacts a service below only for the feature named, and only when you use it. Recognizing a pasted link's host happens on your server and contacts nothing.

Syndication (only for chips you leave on; the post's URL or content is sent):

* Telegraph: off unless the `outpost_telegraph_enabled` option is set; then each published post (never a password-protected one) goes to api.telegra.ph, which makes a public copy, and the first use stores a Telegraph account token on your site. [Terms](https://telegram.org/tos), [Privacy](https://telegram.org/privacy)
* Beehiiv (api.beehiiv.com): [Terms](https://www.beehiiv.com/tou), [Privacy](https://www.beehiiv.com/privacy)
* Buttondown (api.buttondown.email): [Terms](https://buttondown.com/legal/terms), [Privacy](https://buttondown.com/legal/privacy)
* Kit (api.convertkit.com; the broadcast's app.kit.com URL is saved on the post, never requested): [Terms](https://kit.com/terms), [Privacy](https://kit.com/privacy)
* write.as: [Guidelines](https://write.as/guidelines), [Privacy](https://write.as/privacy)

Bridgy chips (brid.gy, bsky.brid.gy, fed.brid.gy) only add a syndication target to the post your site's Micropub endpoint receives; Outpost sends nothing to Bridgy. A webmention plugin on your site may notify Bridgy, as that plugin discloses.

Previews (only the URL you pasted is sent):

* The page you link to, and any feed or oEmbed endpoint it advertises.
* oEmbed for Vimeo ([Terms](https://vimeo.com/terms), [Privacy](https://vimeo.com/privacy)), YouTube ([Terms](https://www.youtube.com/t/terms), [Privacy](https://policies.google.com/privacy)), Spotify ([Terms](https://www.spotify.com/legal/end-user-agreement/), [Privacy](https://www.spotify.com/legal/privacy-policy/)), and SoundCloud ([Terms](https://soundcloud.com/terms-of-use), [Privacy](https://soundcloud.com/pages/privacy)).
* Media lookups, through the Post Kinds companion: your search text goes to TMDB ([Terms](https://www.themoviedb.org/api-terms-of-use), [Privacy](https://www.themoviedb.org/privacy-policy)), Open Library ([Terms and privacy](https://archive.org/about/terms.php)), MusicBrainz and Cover Art Archive ([Terms](https://musicbrainz.org/doc/About/Data_License), [Privacy](https://metabrainz.org/privacy)), RAWG ([Terms](https://rawg.io/terms), [Privacy](https://rawg.io/privacy_policy)), BoardGameGeek ([Terms](https://boardgamegeek.com/terms), [Privacy](https://boardgamegeek.com/privacy)), or Foursquare ([Terms](https://foursquare.com/legal/terms), [Privacy](https://foursquare.com/legal/privacy)). Your browser loads cover images from those services' image hosts, which see your IP address.
* Nominatim (nominatim.openstreetmap.org): your place search. [Usage policy](https://operations.osmfoundation.org/policies/nominatim/), [Privacy](https://wiki.osmfoundation.org/wiki/Privacy_Policy)

Connected accounts (only after you connect one; an encrypted token is stored on your site and sent to that service when you connect, disconnect, fetch recent activity in the block editor, or preview a Notion page you cite):

* Notion (api.notion.com): [Terms and privacy](https://www.notion.com/terms)
* Oura (cloud.ouraring.com, api.ouraring.com): [Terms](https://ouraring.com/terms-and-conditions), [Privacy](https://ouraring.com/privacy-policy)
* WHOOP (api.prod.whoop.com): [API terms](https://developer.whoop.com/api-terms-of-use/), [Privacy](https://www.whoop.com/privacy/)
* Polar (flow.polar.com, polarremote.com, www.polaraccesslink.com): [Terms](https://www.polar.com/en/legal/terms-of-use), [Privacy](https://www.polar.com/en/legal/privacy-notice)
* Ride With GPS (ridewithgps.com): [Terms](https://ridewithgps.com/terms), [Privacy](https://ridewithgps.com/privacy)
* Ravelry (www.ravelry.com, api.ravelry.com): [Terms](https://www.ravelry.com/about/terms), [Privacy](https://www.ravelry.com/about/privacy)

Manual share (your site sends nothing; tapping a chip opens the service in your browser or app with the post's link and text, and for Pinterest its image URL):

* Facebook ([Terms](https://www.facebook.com/terms.php), [Privacy](https://www.facebook.com/privacy/policy/)), X ([Terms](https://x.com/en/tos), [Privacy](https://x.com/en/privacy)), Threads ([Terms](https://help.instagram.com/769983657850450), [Privacy](https://help.instagram.com/515230437301944)), Pinterest ([Terms](https://policy.pinterest.com/en/terms-of-service), [Privacy](https://policy.pinterest.com/en/privacy-policy)), Reddit ([Terms](https://redditinc.com/policies/user-agreement), [Privacy](https://www.reddit.com/policies/privacy-policy)), LinkedIn ([Terms](https://www.linkedin.com/legal/user-agreement), [Privacy](https://www.linkedin.com/legal/privacy-policy)), Instagram ([Terms](https://help.instagram.com/581066165581870), [Privacy](https://privacycenter.instagram.com/policy)), TikTok ([Terms](https://www.tiktok.com/legal/page/row/terms-of-service/en), [Privacy](https://www.tiktok.com/legal/page/row/privacy-policy/en)), Flickr ([Terms](https://www.flickr.com/help/terms), [Privacy](https://www.flickr.com/help/privacy))
* The iOS Shortcut installer links to www.icloud.com/shortcuts/ and opens only when you tap it. [Terms](https://www.apple.com/legal/internet-services/icloud/), [Privacy](https://www.apple.com/legal/privacy/)

Sign-in happens between your browser and your own site's IndieAuth endpoint; nothing goes to a third party.

== Source Code ==

Source, tests, and build tooling: [github.com/courtneyr-dev/outpost](https://github.com/courtneyr-dev/outpost). The composer (`build/pwa/`) is built from `pwa/src/` (TypeScript and Preact); the block-editor sidebar (`build/index.js`, `build/index.css`, `build/index-rtl.css`) from `src/`. Rebuild with Node 20.10 or newer: `npm install`, then `npm run build:all` (runs `npm run build:wp` and `npm run build`). Preact (MIT License, Copyright (c) 2015-present Jason Miller) is included; its license is in `THIRD-PARTY-LICENSES.txt`.

== Screenshots ==

1. Signing in to the composer at /post on a phone.
2. The Outpost admin page with bookmarklets and install steps.
3. OAuth Connections for life-tracking accounts.
4. Appearance settings.
5. The iOS Shortcut Bridge settings.
6. Note mode with syndication chips.
7. The offline queue.

== Credits ==

Builds on IndieWeb Press This (Matthias Pfefferle, David Shanske, Ryan Barrett), the Micropub and IndieAuth plugins (David Shanske, Matthias Pfefferle), Bridgy (Ryan Barrett), and Preact (Jason Miller and contributors).

== Changelog ==

= 1.0.27 =
* Security: the iOS Shortcut uses an application password limited to its endpoint and deleted on deactivation; upgrading deletes old Shortcut tokens.
* Security, from the WordPress.org review: shared content waits for Use or Discard, cookie REST requests need a valid nonce, connected-account data stays first-party, offline posts stay with their account, previews refuse internal addresses, logging is debug-only, and saved API keys never render.
* Removed: /post/shortcut and the sample fetch provider.
* Added: three read-only abilities.
* Changed: composer modes are two rows of buttons.
* Fixed: More options without Outpost's stylesheet, publish error placement, a 50 MB photo cap, and WordPress's fallback category.

Earlier versions: see CHANGELOG.md in the source repository.

== Upgrade Notice ==

= 1.0.27 =
If you use the iOS Shortcut, create its new application password under Settings → Outpost iOS Shortcut. Mode tabs are now two rows of buttons.
