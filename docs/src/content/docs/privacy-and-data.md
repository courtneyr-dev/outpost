---
title: Privacy and data
description: "What Outpost 1.0.24 stores on your site and device, which external services it contacts, what it adds to your public pages, and what uninstalling leaves behind."
---

What Outpost stores on your site and your device, which external services it talks to, what it adds to your public pages, and what uninstalling removes. This page describes version 1.0.24 and comes from reading the plugin source for that version.

## Data stored in your WordPress database

### Options (site-wide)

- `outpost_settings`: the Composer defaults (default variant, default categories and tags, Bridgy auto-suggest, format inference).
- `outpost_settings_<tab>`: one option per Settings tab, for example `outpost_settings_api_keys`. Sensitive values are stored encrypted.
- `outpost_encryption_key`: a generated encryption key, stored only when the `OUTPOST_ENCRYPTION_KEY` constant isn't defined in `wp-config.php`. Defining the constant is the recommended setup; see [Settings → API Keys tab](/outpost/settings/#api-keys-tab).
- `outpost_rewrite_version`: bookkeeping for the `/post` rewrite rules.
- `outpost_appearance_schema`: records whether the site predates per-person style sources, so people who used Outpost before 1.0.24 keep Use Site Theme.
- `outpost_bridgy_silos_enabled`: which Bridgy destinations are on.
- `outpost_telegraph_enabled` and the `outpost_telegraph_*` author settings, when you use Telegraph.
- `outpost_creds_*`: encrypted credentials for connected services.
- Transients for rate limiting and caching, all named `outpost_*` (for example `outpost_config_rl_*`, `outpost_geocode_*`, and `outpost_composite_*`).

### Post meta

Attached to individual posts as they're published or syndicated:

- Syndication records: `outpost_syndication_links` (also readable through the REST API by users who can edit the post), `_outpost_syndication_urls`, the POSSE target, failure, and retry records (`_outpost_posse_*`), `outpost_manual_share_log`, and `outpost_bridgy_publish_log`.
- Post context: `_outpost_xfn` (relationship choices) and `_outpost_place_name` (check-in place names).
- Telegraph page links and author overrides (`outpost_telegraph_*`) and the per-post `_outpost_skip_telegraph` switch.
- Activity details from connected services: when you add an item from the block editor's **Add from connected platforms** picker, the picker puts its details into the post, such as workout duration, distance, and sleep score, and also sends them as `_outpost_oura_*`, `_outpost_whoop_*`, or `_outpost_polar_*` post meta. Treat a post that uses them as publishing that health and activity data.

### User meta (per person)

- `outpost_appearance_source`: your style source, Outpost Default or Use Site Theme.
- `outpost_appearance_mode`: your Light, Dark, or System choice.
- `outpost_appearance_overrides`: any token overrides you set on the Appearance page.
- `outpost_ios_shortcut_token` and `outpost_ios_shortcut_first_seen`: the iOS Shortcut bridge token and when it was first used.
- `outpost_creds_*`: your OAuth and API credentials, encrypted with libsodium (`sodium_crypto_secretbox`). Telegraph tokens moved into this encrypted store in version 1.0.0; an older plaintext copy is migrated into it and deleted the first time the adapter runs.
- `outpost_dismissed_encryption_key_notice_*`: which version of the encryption-key notice you dismissed.

## What uninstalling removes

Deleting Outpost from the Plugins screen runs its uninstaller on a single site and on every site of a multisite network. In 1.0.24 it removes:

- Every option above except `outpost_appearance_schema`.
- Every user-meta key above except `outpost_appearance_source`.
- The syndication, POSSE, manual-share, Bridgy, Telegraph, XFN, and check-in post meta.
- All `outpost_*` transients and the scheduled syndication retries.

It leaves these behind:

- **`outpost_appearance_source` user meta and the `outpost_appearance_schema` option**, both added in 1.0.24. They hold only a style choice and a version marker. To remove them after uninstalling, run `wp user meta delete <user-id> outpost_appearance_source` for each person and `wp option delete outpost_appearance_schema`.
- **Any `_outpost_oura_*`, `_outpost_whoop_*`, or `_outpost_polar_*` activity meta** on posts you created from connected services. It stays with those posts, like the posts themselves.
- Your posts, media, alt text, featured images, categories, and other plugins' data.

The [Uninstall](/outpost/uninstall/) page walks through it.

## External services contacted

Outpost is a syndication tool, so outbound connections are part of what it does. Nothing is contacted for a service you haven't configured, connected, pasted a link to, or tapped a chip for. Outpost sends no analytics or telemetry.

- **Your own site:** all posting goes through your site's Micropub endpoint, and sign-in through your IndieAuth endpoint.
- **Bridgy:** brid.gy, fed.brid.gy, and bsky.brid.gy, through your site's Webmention plugin, when a Bridgy chip is on.
- **Publishing platforms**, when configured: Telegraph (api.telegra.ph), Beehiiv, Buttondown, Kit, and write.as.
- **Connected accounts**, when you connect them under OAuth Connections: Notion (api.notion.com), Oura (api.ouraring.com), WHOOP (api.prod.whoop.com), Polar Flow (polaraccesslink.com and polarremote.com), Ride With GPS (ridewithgps.com), and Ravelry (ravelry.com). Connecting Oura, WHOOP, or Polar Flow lets you pull health and activity data into WordPress.
- **Check-in location search:** nominatim.openstreetmap.org, only when you search for a place.
- **Pasted links:** when you paste a link, your site fetches that page's metadata. Spotify, YouTube, SoundCloud, Vimeo, and others are asked through their public oEmbed endpoints.
- **Media lookups:** the "Look it up" search contacts no service from Outpost. It hands the query to the Post Kinds for IndieWeb in Block Themes plugin, which runs the lookup through your own site.
- **Manual-share handoffs** (Facebook, X, Threads, Pinterest, Reddit, LinkedIn, Instagram, TikTok, Flickr): your site never contacts these. Tapping the chip opens the service's share page or app on your device with your post's link and text.

The plugin's [readme.txt](https://github.com/courtneyr-dev/outpost/blob/main/readme.txt) lists every host with its terms and privacy links.

## Data stored on your device (browser)

The composer app keeps this in your browser, on each device you use:

- **Your sign-in token**, in IndexedDB, encrypted with a non-extractable AES-GCM key. Browser developer tools can't read it, though a script injected into the composer page could use it.
- **The offline queue**, in IndexedDB, **not encrypted**. It holds the full content of posts waiting to send. Since 1.0.22 a queued post holds no copy of your token; it sends with your current sign-in, and only to the site it was queued for. Signing out leaves the queue in place.
- **Unsent drafts**, in localStorage, **not encrypted**: titles, body text, reply targets, form fields, and photo alt text and captions, kept per signed-in site. Drafts clear when you post, when the post moves to the offline queue, and when you sign out. Photos themselves aren't stored.
- **Preferences**, in localStorage and sessionStorage: your appearance choice and in-progress screen state.
- **Cookies:** the composer writes none.

Anyone who can open the composer on an unlocked device can read its drafts and queued posts. Signing out clears drafts but not the queue; dismiss queued posts you don't want kept.

**Photos:** before upload, the composer redraws each photo, which removes its EXIF data, including the GPS location phone cameras record. It also scales large photos down and saves them as JPEG.

## What Outpost adds to your public pages

- **Syndication links:** for each copy of a post on another site, Outpost adds a `u-syndication` link inside `<div class="outpost-syndication-links" hidden>` at the end of the post content. Readers don't see it; Bridgy and other IndieWeb tools read it. The `outpost_render_syndication_links` filter turns it off.
- **The composer app at `/post`:** a standalone HTML page, plus a manifest and a service worker scoped to `/post/` only.

Outpost registers no blocks, shortcodes, widgets, custom post types, or taxonomies. Posts it creates are standard WordPress posts. With Auto Post-Format inference on, it can set a post's format.
