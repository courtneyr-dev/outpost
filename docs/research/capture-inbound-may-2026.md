
# Outpost Doc 2 — Inbound capture, keyed to Courtney's source platforms

> Companion to Doc 1. **Inbound axis:** *share-sheet from another app → Outpost.* When you tap Share in Spotify/Snipd/YouTube/etc. and pick Outpost, the URL hits `/post/share-target` and Outpost should auto-route to the right composer mode with metadata pre-filled.
>
> Architecture mirrors `Companion_*` for outbound: a parallel `Source_*` family for inbound. Same runtime detection pattern, same capability surface, inverted direction.

---

## 1. The inbound pipeline

```
[App on phone] → Share Sheet → Outpost PWA (or iOS Shortcut bridge)
                                ↓
                        /post/share-target
                                ↓
                   Source_Detector → host pattern match
                                ↓
                      ┌────────┴────────┐
              unambiguous              ambiguous
                  ↓                       ↓
         auto-route to mode      mode-picker UI
         + fire B2 preview      (smart default)
                  ↓                       ↓
              extraction recipe (per-source)
                                ↓
                  Pre-fill composer with extracted h-entry properties
                                ↓
                       User reviews → Submits via Micropub
```

**B2 preview endpoint** (`/wp-json/outpost/v1/preview`) already does the SSRF-defended fetch + content-type allowlist + script stripping — that's the metadata-extraction engine. The `Source_*` adapters tell B2 *what to extract* per host (oEmbed endpoint? OG tags? RSS? mf2? a specific public API?).

**Web Share Target reality:**
- iOS Safari: **never landed** Web Share Target. WebKit bug 194593 still open. iOS inbound needs an iOS Shortcut bridge that POSTs to `/post/share-target` — Outpost ships the Shortcut as a downloadable iCloud link.
- Android Chrome: full Web Share Target Level 2 support including files. Direct.

---

## 2. `Source_*` adapter shape

```php
// Conceptual shape, not final API
[
  'id'                => 'spotify',
  'host_patterns'     => [ 'open.spotify.com', 'spotify.link' ],
  'mode'              => 'listen',           // single mode (unambiguous)
  // OR for ambiguous:
  // 'mode_options'   => [ 'reply', 'like', 'repost', 'bookmark' ],
  // 'mode_default'   => 'reply',
  'ambiguity'         => 'unambiguous',      // | 'ambiguous'
  'extractor'         => 'oembed',           // 'oembed' | 'og_tags' | 'mf2' | 'rss' | 'api' | 'composite'
  'oembed_endpoint'   => 'https://open.spotify.com/oembed?url={url}',
  'auth_required'     => false,
  'mapping'           => [
    'title'           => 'p-name',
    'thumbnail_url'   => 'u-photo',
    'provider_name'   => 'p-publication',
    // u-listen-of points back to the source URL — always set from the share
  ],
  'h_entry_property'  => 'u-listen-of',      // the IndieWeb verb-of property
  'tags_default'      => [ 'listen' ],
]
```

The composer reads `mode` (or `mode_options` + `mode_default`) and routes accordingly. Extraction runs through B2 with the source's recipe, then maps the output into composer field names per `mapping`.

---

## 3. Per-platform inbound research — Courtney's list

### 3.1 Spotify (`open.spotify.com/user/courtneyengle`)
- **Share-sheet URL form:** `https://open.spotify.com/track/{id}`, `/album/{id}`, `/episode/{id}`, `/show/{id}`, `/playlist/{id}`. Short links: `https://spotify.link/...`.
- **Public metadata path:** ✅ **oEmbed endpoint** at `https://open.spotify.com/oembed?url={url}`. Returns JSON with `title`, `thumbnail_url`, `provider_name`, `html` (iframe embed). No auth, no API key, no rate limit advertised.
- **Extracted fields → h-entry:**
  - `oembed.title` → `p-name`
  - `oembed.thumbnail_url` → `u-photo`
  - share-target URL → `u-listen-of` (always)
  - Spotify Web API would give artist + album separately if you want them, but requires OAuth + Premium (per Feb 2026 changelog) — **don't use it for inbound; oEmbed is enough**
- **Mode:** **Listen** (unambiguous — Spotify track URL is always a listen)
- **Routing:** auto-route, `mode = 'listen'`
- **Source_Spotify** is the cleanest first-shipper for the inbound pipeline. Zero friction, zero auth.

### 3.2 Snipd (`share.snipd.com/user/courtneyr_dev`)
- **Share-sheet URL form:** `https://share.snipd.com/snip/{id}`, `https://share.snipd.com/episode/{id}`, profile URLs.
- **Public metadata path:** ✅ Standard OG tags (`og:title`, `og:description`, `og:image`, `og:url`). Snipd's public share pages are designed for unfurling. No oEmbed.
- **Extracted fields → h-entry:**
  - `og:title` → `p-name` (snip title or episode title)
  - `og:description` → `p-summary` (Snipd's auto-generated summary)
  - `og:image` → `u-photo` (episode cover art)
  - share-target URL → `u-listen-of`
- **Mode:** **Listen** (unambiguous)
- **Routing:** auto-route, `mode = 'listen'`
- B2 already extracts OG tags (or extends easily); the Snipd source just declares the OG-extraction recipe.

### 3.3 YouTube (`youtube.com/channel/UC1tidWhCHiaARk3_YHJwZLw`)
- **Share-sheet URL form:** `https://www.youtube.com/watch?v={id}`, short link `https://youtu.be/{id}`, Shorts `https://www.youtube.com/shorts/{id}`, channel URLs.
- **Public metadata path:** ✅ **oEmbed** at `https://www.youtube.com/oembed?url={url}&format=json`. Returns `title`, `author_name`, `author_url`, `thumbnail_url`, `html` (iframe). No auth, no API key. Description requires Data API + key (skip — oEmbed is enough for the composer).
- **Extracted fields → h-entry:**
  - `oembed.title` → `p-name`
  - `oembed.author_name` → contributor (display only)
  - `oembed.thumbnail_url` → `u-photo`
  - share-target URL → `u-watch-of`
- **Mode:** **Watch** (unambiguous — even music videos make sense as Watch given Outpost has no separate Music kind)
- **Routing:** auto-route, `mode = 'watch'`

### 3.4 Twitch (`twitch.tv/courtneyr_dev`)
- **Share-sheet URL form:** `https://www.twitch.tv/{channel}` (live stream), `https://www.twitch.tv/videos/{id}` (VOD), `https://clips.twitch.tv/{slug}` (clip).
- **Public metadata path:** Mixed. Twitch has OG tags on channel/VOD/clip pages, and Helix API gives richer data via `GET /helix/streams`, `/helix/videos`, `/helix/clips`. **Helix requires an app access token** (client ID + client secret + client_credentials grant — not a per-user OAuth flow; just app-level). That's an embedded-credential question for a free WP.org plugin.
- **§5 angle:** App access tokens via client_credentials are anonymous — they don't represent a user. Embedding a *shared* client ID + secret in a public plugin is risky (rotation, shared rate limit). **Two clean paths:**
  1. **OG tags only** — no auth, no embedded secret, sufficient for `p-name` + `u-photo` + `p-summary`. Recommended default.
  2. **BYO Helix credentials** — user pastes their own client ID + secret in Outpost settings for richer metadata (game name, viewer count). Optional.
- **Extracted fields → h-entry (OG path):**
  - `og:title` → `p-name`
  - `og:description` → `p-summary`
  - `og:image` → `u-photo`
  - share-target URL → `u-watch-of`
- **Mode:** **Watch** (unambiguous)
- **Routing:** auto-route, `mode = 'watch'`

### 3.5 Goodreads (`goodreads.com/user/show/2768384-courtney-robertson`)
- **Share-sheet URL form:** Book page `https://www.goodreads.com/book/show/{id}-{slug}`, review `https://www.goodreads.com/review/show/{id}`, user shelf URLs.
- **Public metadata path:** **REST API was killed Dec 2020.** Two surviving paths: (1) ✅ **OG tags** on book pages — `og:title`, `og:image` (cover), `og:description` (book blurb); (2) ✅ **RSS feed per shelf** at `https://www.goodreads.com/review/list_rss/{user_id}?shelf={shelf}` — caps at 100 most-recent items, includes `<book_image_url>`, `<author_name>`, `<isbn>`, `<user_rating>`, `<user_read_at>`. RSS is for *bulk pull* (not Outpost's inbound case); OG is for the share-target flow.
- **Extracted fields → h-entry (OG path for single share):**
  - `og:title` → `p-name`
  - `og:image` → `u-photo` (cover)
  - `og:description` → `p-summary` (blurb)
  - share-target URL → `u-read-of`
- **Mode:** **Read** (unambiguous)
- **Routing:** auto-route, `mode = 'read'`
- The RSS feed is interesting for a *separate* feature (passive sync from Goodreads to a "read" shelf in Outpost) — out of scope for the share-target inbound flow but worth noting for later.

### 3.6 BoardGameGeek (`boardgamegeek.com/user/courtneyr_dev`)
- **Share-sheet URL form:** Game page `https://boardgamegeek.com/boardgame/{id}/{slug}`, user collection URLs, plays page.
- **Public metadata path:** ✅ Both OG tags AND a public **XML API v2**. OG gives `og:title` + `og:image` (box art). XML API v2 gives full game data without auth: `https://boardgamegeek.com/xmlapi2/thing?id={id}&stats=1` returns name, year, players, time, mechanics, ratings. Plays endpoint requires per-user auth (cookie-based, undocumented).
- **§5 angle:** XML API is anonymous and free — no embedded secret needed. Clean.
- **Extracted fields → h-entry:**
  - `xml.thing.name` → `p-name`
  - `xml.thing.image` → `u-photo`
  - `xml.thing.description` → `p-summary` (long; truncate)
  - `xml.thing.statistics.average` → freeform note ("BGG avg: 7.4")
  - share-target URL → `u-play-of`
- **Mode:** **Play** (unambiguous if URL is `/boardgame/{id}/...`)
- **Routing:** auto-route, `mode = 'play'`. If URL is a forum/list/general BGG URL → ambiguous (fall to mode-picker, default Bookmark).

### 3.7 Last.fm (`Last.fm`)
- **Share-sheet URL form:** Track `https://www.last.fm/music/{artist}/_/{track}`, artist `https://www.last.fm/music/{artist}`, album, scrobble.
- **Public metadata path:** ✅ Both OG tags AND an API. `user.getRecentTracks` is anonymous (no auth) but **requires an API key** that the plugin would have to embed (§5 risk). OG tags are auth-free.
- **§5 angle:** OG tags only.
- **Extracted fields → h-entry:**
  - `og:title` → `p-name`
  - `og:image` → `u-photo`
  - share-target URL → `u-listen-of`
- **Mode:** **Listen** (unambiguous)
- **Routing:** auto-route, `mode = 'listen'`

### 3.8 Readwise (`readwise.io/@courtneyrdev`)
- **Share-sheet URL form:** Highlight share URLs, profile URLs. Reader documents at `https://read.readwise.io/read/{id}`.
- **Public metadata path:** ✅ Readwise has a documented **API at `readwise.io/api/v2/`** with token-per-user auth (BYO token). For *public share* URLs, OG tags are present. Token-auth is per-user (not embedded secret) — clean §5.
- **Two flows:**
  1. **Anonymous OG** for share-target (preferred for v1)
  2. **Authenticated highlight pull** as a separate background sync feature (BYO token in settings)
- **Extracted fields → h-entry (OG path):**
  - `og:title` → `p-name`
  - `og:description` → `e-content` (the highlight text itself)
  - share-target URL → `u-bookmark-of` (Readwise share isn't quite "Read" — it's a quote)
- **Mode:** **Bookmark** with quote treatment, OR a new sub-mode "Quote" (Outpost doesn't have a Quote mode in CLAUDE.md — Bookmark is the closest fit).
- **Routing:** auto-route, `mode = 'bookmark'`

### 3.9 Amazon Wishlist (`amazon.com/hz/wishlist/ls/1T5OKLMTA0MZQ`)
- **Share-sheet URL form:** Product detail page `https://www.amazon.com/dp/{ASIN}`, wishlist URLs, search URLs. Affiliate-tag URLs from share are common.
- **Public metadata path:** ⚠️ Amazon aggressively blocks scraping. OG tags exist on product pages but content-type / cookies / region all vary; Amazon Product Advertising API (PA-API 5.0) requires Amazon Associates approval + signed requests — not viable for a free WP.org plugin.
- **§5 angle:** OG tag scraping is the only realistic path; expect failures on some product pages.
- **Extracted fields → h-entry (best-effort):**
  - `og:title` → `p-name` (product title)
  - `og:image` → `u-photo`
  - share-target URL → `u-bookmark-of` (or `u-want-of` if Outpost adds that as a sub-kind)
- **Mode:** **Bookmark** (could be "Want" if you add that h-kind later)
- **Routing:** auto-route, `mode = 'bookmark'`. Strip affiliate tags before saving (privacy) unless user has set their own affiliate-tag preference.

### 3.10 Pinterest (`pinterest.com/courtneyr_dev`)
- **Share-sheet URL form:** Pin URLs `https://www.pinterest.com/pin/{id}/`, board URLs, profile.
- **Public metadata path:** ✅ OG tags on pin pages; richer data via Pinterest API v5 (already covered in Doc 1, requires user OAuth + trial-mode review).
- **Extracted fields → h-entry (OG path):**
  - `og:title` → `p-name`
  - `og:description` → `p-summary`
  - `og:image` → `u-photo`
  - share-target URL → `u-bookmark-of`
- **Mode:** **Bookmark** (unambiguous for pin URLs)
- **Routing:** auto-route, `mode = 'bookmark'`

### 3.11 Mastodon (`m.courtneyr.co/@courtneyr` and any other instance)
- **Share-sheet URL form:** Status URLs `https://{instance}/@{user}/{status_id}`, profile URLs.
- **Public metadata path:** ✅ **mf2 + ActivityStreams JSON.** Mastodon status pages have h-entry microformats and serve `application/activity+json` on Accept-header negotiation. For Outpost, mf2 parsing is the natural fit (Outpost already does mf2 server-side via B2). OG tags also present.
- **§5 angle:** Anonymous fetch, mf2 parse. Clean.
- **Extracted fields → h-entry:**
  - mf2 `p-name` / `e-content` → composer body
  - mf2 `p-author` → display only (composer doesn't write the author of the *target*)
  - share-target URL → `u-in-reply-to` / `u-like-of` / `u-repost-of` / `u-bookmark-of` (depends on mode picked)
- **Mode:** **Reply** mode group (already in Outpost) — picker shows Reply/Like/Repost/Bookmark, default Reply
- **Routing:** ambiguous → mode-picker with Reply default. Same UX you've already shipped in Phase C1b.

### 3.12 Bluesky (`bsky.app/profile/courtneyr.dev`)
- **Share-sheet URL form:** Post `https://bsky.app/profile/{handle}/post/{rkey}`, profile.
- **Public metadata path:** ✅ Two paths. (1) ✅ Public XRPC `app.bsky.feed.getPostThread` — anonymous read of any public post via PDS (no auth); (2) OG tags + Bluesky's own embed unfurl.
- **Extracted fields → h-entry:**
  - `post.record.text` → composer body context
  - share-target URL → `u-in-reply-to` etc.
- **Mode:** Reply group, ambiguous, default Reply.
- **Routing:** mode-picker.

### 3.13 X / Twitter (`x.com/courtneyr_dev`)
- **Share-sheet URL form:** `https://x.com/{user}/status/{id}` or legacy `twitter.com`. The X share button often appends affiliate-style tracking parameters.
- **Public metadata path:** ⚠️ OG tags are present but increasingly degraded since the API shutdown — many tweet pages return generic "X" titles to logged-out fetchers. Some unfurl better via `nitter` mirrors (out of scope for an official plugin).
- **§5 angle:** Best-effort OG; expect some failures.
- **Extracted fields → h-entry:**
  - `og:title` → fallback (often generic)
  - share-target URL → `u-in-reply-to` / etc.
- **Mode:** Reply group, ambiguous, default Reply.
- **Routing:** mode-picker.

### 3.14 Threads (`threads.net/@courtneyr_dev`)
- **Share-sheet URL form:** `https://www.threads.net/@{user}/post/{id}`.
- **Public metadata path:** ✅ OG tags; if user has fediverse-toggle ON, also reachable via ActivityPub.
- **Extracted fields:** `og:title` + `og:description`.
- **Mode:** Reply group, ambiguous, default Reply.

### 3.15 LinkedIn (`linkedin.com/in/courtneyr-dev`)
- **Share-sheet URL form:** Post `https://www.linkedin.com/feed/update/urn:li:activity:{id}/`, article, profile.
- **Public metadata path:** ⚠️ OG tags present but LinkedIn is aggressive about logged-out fetcher detection.
- **Mode:** Reply group, ambiguous, default Bookmark (most LinkedIn URL-shares are saves rather than replies).

### 3.16 Instagram (`instagram.com/courtneyr_dev`)
- **Share-sheet URL form:** Post `https://www.instagram.com/p/{shortcode}/`, reel `/reel/{shortcode}/`, profile.
- **Public metadata path:** ⚠️ OG tags are gated for logged-out users — Instagram serves a login wall to most server-side fetchers since 2022. Best-effort only.
- **§5 angle:** OG attempt, fallback to "URL-only bookmark with manual title."
- **Mode:** Reply group, ambiguous, default Bookmark.

### 3.17 Facebook (`facebook.com/streamlining`)
- **Share-sheet URL form:** Post URL, profile, page.
- **Public metadata path:** ⚠️ OG tags increasingly gated. Best-effort.
- **Mode:** Reply group, ambiguous, default Bookmark.

### 3.18 Flickr (`flickr.com/photos/courane001`)
- **Share-sheet URL form:** Photo `https://www.flickr.com/photos/{user}/{photo_id}/`, set URLs.
- **Public metadata path:** ✅ Strong OG tags AND public Flickr API (auth-free for read methods like `flickr.photos.getInfo` — but requires API key).
- **Extracted fields → h-entry (OG path):**
  - `og:title` → `p-name`
  - `og:description` → `p-summary`
  - `og:image` → `u-photo` (full-size variant)
  - share-target URL → `u-in-reply-to` (if commenting), `u-like-of` (favoriting), or `u-bookmark-of`
- **Mode:** Reply group, ambiguous, default Reply.

### 3.19 GitHub (`github.com/courtneyr-dev`)
- **Share-sheet URL form:** Repo `https://github.com/{user}/{repo}`, issue, PR, gist, file URL.
- **Public metadata path:** ✅ OG tags + REST API (anonymous, generous rate limits without auth). REST API is per-resource and rich.
- **Mode mapping per URL pattern:**
  - `/{user}/{repo}` (no path) → Bookmark default; ambiguous (could be like/star)
  - `/{user}/{repo}/issues/{n}` → Reply default; ambiguous
  - `/{user}/{repo}/pull/{n}` → Reply default; ambiguous
  - `/{user}/{repo}/releases/...` → Bookmark default; ambiguous (could be repost)
  - gist URLs → Bookmark default
- **Routing:** ambiguous → mode-picker with per-pattern smart default.

### 3.20 TikTok (`tiktok.com/@courtneyr_dev`)
- **Share-sheet URL form:** Video `https://www.tiktok.com/@{user}/video/{id}`, profile, short link `https://vm.tiktok.com/...`.
- **Public metadata path:** ✅ OG tags fairly stable; oEmbed at `https://www.tiktok.com/oembed?url={url}` returns title, author, thumbnail.
- **Extracted fields:** oEmbed → `p-name`, `u-photo`, share URL → `u-watch-of`.
- **Mode:** **Watch** (unambiguous for video URLs)
- **Routing:** auto-route, `mode = 'watch'`.

### 3.21 OpenProfile.dev — identity only, no inbound
Reljson-based identity hub; no posts to capture.

### 3.22 WordPress.org profile (`profiles.wordpress.org/courane01`) — identity only, no inbound

---

## 4. Summary routing table

| Source | Mode | Ambiguity | Extractor | Auth |
|---|---|---|---|---|
| open.spotify.com, spotify.link | Listen | unambiguous | oEmbed | none |
| share.snipd.com | Listen | unambiguous | OG | none |
| youtube.com, youtu.be | Watch | unambiguous | oEmbed | none |
| twitch.tv | Watch | unambiguous | OG | none (Helix BYO optional) |
| goodreads.com/book | Read | unambiguous | OG | none |
| boardgamegeek.com/boardgame | Play | unambiguous | XML API v2 | none |
| last.fm/music | Listen | unambiguous | OG | none |
| readwise.io / read.readwise.io | Bookmark | unambiguous | OG | none (BYO token optional) |
| amazon.com/dp | Bookmark | unambiguous (best-effort) | OG | none |
| pinterest.com/pin | Bookmark | unambiguous | OG | none |
| tiktok.com/video | Watch | unambiguous | oEmbed | none |
| Mastodon (any instance) | Reply group | **ambiguous** | mf2 | none |
| bsky.app | Reply group | **ambiguous** | XRPC public | none |
| x.com, twitter.com | Reply group | **ambiguous** | OG (best-effort) | none |
| threads.net | Reply group | **ambiguous** | OG | none |
| linkedin.com | Reply group | **ambiguous, default Bookmark** | OG | none |
| instagram.com | Reply group | **ambiguous, default Bookmark** | OG (best-effort) | none |
| facebook.com | Reply group | **ambiguous, default Bookmark** | OG (best-effort) | none |
| flickr.com/photos | Reply group | **ambiguous** | OG | none |
| github.com | Reply group / Bookmark | **ambiguous, pattern-dependent** | OG + REST | none |
| Anything else | unknown | mode-picker, default Bookmark | OG | none |

---

## 5. Mastodon-instance detection — special case

Mastodon URLs can come from any instance, not a fixed host. `Source_Mastodon` needs runtime instance detection:

1. On share-sheet URL receive, fetch with `Accept: application/activity+json`
2. If response is `Content-Type: application/activity+json` (or `application/ld+json`), it's an ActivityPub object — Mastodon-style routing applies
3. Otherwise fall through to generic `Source_Unknown` (mode-picker, OG extraction)

This is the same instance-discovery pattern Bridgy Fed uses. Generalizes to Pleroma, Akkoma, Misskey, Friendica — anything ActivityPub-native. Single source adapter (`Source_ActivityPub`) covers all of them.

---

## 6. The iOS Shortcut bridge (because Web Share Target doesn't work on iOS)

iOS Safari never landed Web Share Target. The bridge:

1. Outpost ships an iOS Shortcut (downloadable from a public iCloud shortcut link, also generated dynamically per user with their site URL embedded)
2. Shortcut accepts URL share-sheet input
3. Shortcut runs `Get URLs from Input` → POSTs to `https://your-site.example/post/share-target?url={url}` with the user's session token (or opens the URL in Safari which opens the PWA)
4. PWA receives, runs `Source_Detector`, routes

Trigger this on Outpost's Settings → "Connect iOS Shortcut" page. Generate the shortcut definition server-side based on the user's site URL. Expose a deep-link install URL that opens the iOS Shortcuts app to import.

Android needs no bridge — Web Share Target Level 2 works directly when the PWA is installed.

---

## 7. `Source_*` shipping queue (parallel to outbound)

1. **`Source_Spotify`** — first-shipper. oEmbed, no auth, unambiguous Listen routing, exercises the whole inbound pipeline end-to-end.
2. **`Source_YouTube`** — second. Same pattern as Spotify (oEmbed), unambiguous Watch.
3. **`Source_OG_Generic`** — base extractor that handles Snipd, Goodreads, Last.fm, Readwise, Amazon, Pinterest, Twitch, TikTok (where oEmbed isn't used), most "URL with OG tags" sources. Per-source config tells it the mode mapping.
4. **`Source_ActivityPub`** — covers Mastodon, Pleroma, Akkoma, Misskey, Friendica, Hubzilla, generic AP via `Accept: application/activity+json` content-negotiation. Reply mode group with picker.
5. **`Source_Bluesky`** — XRPC public read for `bsky.app` URLs.
6. **`Source_BGG`** — XML API v2 anonymous read for boardgame URLs.
7. **`Source_GitHub`** — REST API anonymous read for issue/PR/repo/release routing logic; pattern-based mode default.
8. **`Source_WebStories_Backfeed`** — handle inbound shares of Web Story URLs (someone shared YOUR Web Story to you?). Edge case; defer.
9. **iOS Shortcut bridge** — distribution surface, not an adapter. Document and ship as part of Phase E.

---

## 8. h-entry property write matrix

What gets written to the new post when the user submits, by mode:

| Mode | Required | Optional | Source URL goes into |
|---|---|---|---|
| Listen | `u-listen-of`, `p-name` | `u-photo`, `p-summary`, `p-author` of track/podcast | `u-listen-of` |
| Watch | `u-watch-of`, `p-name` | `u-photo`, `p-summary`, `p-author` | `u-watch-of` |
| Read | `u-read-of`, `p-name` | `u-photo`, `p-summary`, `p-author` | `u-read-of` |
| Play | `u-play-of`, `p-name` | `u-photo`, `p-summary` | `u-play-of` |
| Checkin | `p-location`, `p-name` | `u-photo`, `p-summary` | (geo URL or place URL) |
| Reply | `u-in-reply-to`, `e-content` (required for reply per C1b) | `p-summary` of target | `u-in-reply-to` |
| Like | `u-like-of` | (no body required per C1b) | `u-like-of` |
| Repost | `u-repost-of` | optional body | `u-repost-of` |
| Bookmark | `u-bookmark-of` | optional body | `u-bookmark-of` |

Aligns with your existing C1b VARIANTS table — Reply/Like/Repost/Bookmark already share form shape; Listen/Watch/Read/Play are the Phase C2 group and follow the same pattern with different verb-of properties.

---

## 9. Caveats specific to Doc 2

- iOS Web Share Target is the structural pain point. Without an iOS Shortcut bridge, iPhone users can't share *into* Outpost from other apps. The bridge works but adds onboarding friction (one-time install).
- Instagram, Facebook, X, LinkedIn aggressively gate OG tags for logged-out fetchers. Inbound metadata extraction will sometimes return generic titles. Outpost should fall through to "URL bookmark with manual title" when extraction is degraded.
- BoardGameGeek's plays endpoint requires session-cookie auth (undocumented). Out of scope for share-target; possible future BYO feature.
- Last.fm and Readwise both have rich APIs but require API keys / per-user tokens. Stick to OG for the share-target flow; both have BYO-token paths for separate background-sync features.
- Amazon's anti-scraping aggressiveness means OG extraction will fail unpredictably. The fallback to "URL bookmark with manual title" is more important here than anywhere else.
- The Mastodon instance-detection pattern (`Accept: application/activity+json` content negotiation) handles any AP-native server, but custom sub-paths or non-standard implementations may fail. Fall through to generic OG.
- Spotify Web API can't be used for inbound metadata extraction in 2026 because it now requires Premium for *any* access (Feb 2026 changelog). oEmbed is the only auth-free path.
- The `Source_*` adapters share a lot of code (B2 fetch + content-type allowlist + script strip is identical across sources). Factor a shared `Source_Base` abstract class with subclass override points for `extract()` and `route()` only.
- Goodreads' RSS feed feature is a separate use case — passive sync of recent reads to Outpost — and shouldn't be conflated with the share-target inbound flow. Note it for Phase G or later.
- Each `Source_*` should declare a `version` so future Outpost releases can track which sources have been added/changed without breaking the adapter contract.

---

## 10. Bidirectional symmetry summary

You now have parallel adapter families:

| | Outbound | Inbound |
|---|---|---|
| Detection | `Companion_*` runtime detection | `Source_*` host-pattern match |
| Capability surface | `capabilities()` (accepts_images, max_count, alt_passthrough, etc.) | `capabilities()` (mode, ambiguity, extractor, auth, mapping) |
| First-shipper | `Companion_ActivityPub` | `Source_Spotify` |
| Manual-fallback | `Companion_ManualShare` (per-platform intent matrix) | `Source_Unknown` (mode-picker, default Bookmark) |
| §5 posture | No embedded secrets | No embedded secrets |
| Phase | F (companion adapters) | F (source adapters) — same phase, parallel work |

Both pipelines hit the same Micropub layer at the end. The composer and Micropub server don't care which pipeline fed them — they just see h-entry properties and write the post.
