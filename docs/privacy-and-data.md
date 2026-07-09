# Privacy and data

What Outpost stores on your site and your device, which external services it talks to, and what it adds to your public pages. Everything here comes from reading the plugin source at version 0.1.114 (plugin header); items we couldn't verify are called out.

## Data stored in your WordPress database

### Options (site-wide)

- `outpost_settings` — the Composer defaults (default variant, Bridgy auto-suggest, format inference).
- `outpost_settings_<tab>` — one option per settings tab (for example the API Keys tab); sensitive values are stored encrypted.
- `outpost_encryption_key` — a generated encryption key, stored only when the `OUTPOST_ENCRYPTION_KEY` constant isn't defined in `wp-config.php` (the constant is the recommended setup).
- `outpost_rewrite_version` — internal bookkeeping for the `/post` rewrite rules.
- `outpost_bridgy_silos_enabled` — which Bridgy destinations are on.
- `outpost_creds_*` — encrypted credentials for connected services.
- Assorted transients for rate limiting and caching (`outpost_config_rl_*`, `outpost_geocode_*`, `outpost_composite_*`, preview caches).

### Post meta

Attached to individual posts as they're published or syndicated:

- Syndication records: `outpost_syndication_links` (also exposed through the REST API to users who can edit posts), `_outpost_syndication_urls`, POSSE target/failure logs (`_outpost_posse_*`), `outpost_manual_share_log`, `outpost_bridgy_publish_log`.
- Post context: `_outpost_xfn` (relationship data), `_outpost_place_name` (checkin place names).
- Wellness/activity snapshots pulled from connected services: `_outpost_oura_*`, `_outpost_whoop_*`, `_outpost_polar_*`, `_outpost_rwg_*`, `_outpost_ravelry_*`. If you post from those integrations, health and activity data becomes post content/meta on your site — treat it accordingly.
- Telegraph author overrides.

### User meta (per user)

- `outpost_ios_shortcut_token` — the iOS Shortcut bridge token.
- `outpost_appearance_mode` — day/night preference.
- `outpost_creds_*` — per-user OAuth and API credentials, encrypted via libsodium. Exception: the developer notes record that Telegraph tokens are currently stored unencrypted in user meta (flagged for maintainer attention below).

### On uninstall

Deleting the plugin from the Plugins screen runs a cleanup that currently removes only `outpost_rewrite_version`. Other options, post meta, and user meta listed above are not removed by the current uninstall script — the script marks them as future cleanup. If you want a full purge after uninstalling, you'd remove those rows manually.

## External services contacted

Outpost is a syndication tool, so outbound connections are the product. By category, with the named hosts found in the source:

- **Your own site** — all posting goes through your site's Micropub endpoint; sign-in through your IndieAuth endpoint.
- **Syndication bridges** — brid.gy, fed.brid.gy, bsky.brid.gy (Bridgy Publish for Mastodon, Bluesky, and other silos).
- **Publishing/newsletter platforms** (when configured with API keys) — Notion (api.notion.com), Telegraph (api.telegra.ph), Beehiiv, Buttondown, Kit/ConvertKit, write.as.
- **Life-tracking services** (when connected via OAuth) — Oura (api.ouraring.com), WHOOP (api.prod.whoop.com), Polar (polaraccesslink.com, polarremote.com), Ride With GPS, Ravelry. Connecting these pulls your health/activity data into WordPress.
- **Media metadata lookups** — via Post Kinds' lookup APIs (TMDB, Open Library, MusicBrainz, BoardGameGeek/RAWG, Foursquare/Nominatim), proxied through your own site's REST API.
- **URL-paste metadata fetches** — when you paste a link, the server may fetch that page's metadata; recognized hosts include Spotify, YouTube, Apple Music/Podcasts, Bandcamp, SoundCloud, Vimeo, TikTok, Pinterest, Reddit, Substack, Medium, Mastodon, Bluesky, Goodreads, Last.fm, Readwise, Amazon, and others (about 30 hosts listed in the source).

Nothing is contacted for services you haven't configured, connected, or pasted links to. There is no analytics or telemetry host in the list.

## Data stored on your device (browser)

The composer PWA uses client-side storage:

- **IndexedDB** — the offline draft queue (queued posts include your access token so they can replay), and your IndieAuth token, which is encrypted at rest with a non-extractable AES-GCM key. The plugin's readme describes the draft queue as "encrypted IndexedDB"; we verified encryption for the token store but not for queued drafts, so that stronger claim is attributed to the readme.
- **localStorage and sessionStorage** — composer preferences and in-progress UI state.
- **Cookies** — none written by the composer code we checked.

## What Outpost adds to your public pages

- **`u-syndication` links** — after syndication, Outpost appends "also posted on" anchor links (with the `u-syndication` microformats2 class) to the post content, so readers and IndieWeb tools can find the copies.
- **The PWA shell** at `/post` — a standalone HTML page (plus manifest and service worker scoped to `/post/` only).

Outpost registers no blocks, shortcodes, widgets, custom post types, or taxonomies. Posts it creates are standard WordPress posts; the Auto Post-Format inference setting can set a post's format, and stored post meta is listed above.

## What we could and couldn't verify

Verified in source: the options/meta inventories above, the external host list, the token-store encryption, the libsodium credential encryption, and the uninstall behavior.

Attributed to the plugin's readme (not independently verified): offline drafts encrypted in IndexedDB; EXIF data stripped from photo uploads.

Flagged for the maintainer (see [documentation plan](documentation-plan.md)): the unencrypted Telegraph tokens, the draft-encryption and EXIF claims, and the limited uninstall cleanup.

---

[Documentation home](index.md) · Previous: [FAQ](faq.md) · Next: [Accessibility](accessibility.md)
