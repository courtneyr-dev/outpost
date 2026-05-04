
# Outpost Doc 1 — Outbound POSSE, keyed to Courtney's destinations

> Companion to the May 2026 image-POSSE strategy artifact. This document narrows scope to **the platforms in Courtney's actual link list**, adds the platforms not in the prior research (GitHub, Facebook, YouTube, Twitch, Pinterest, TikTok), elevates **manual cross-post to a first-class architectural surface**, and adds **Web Stories for WordPress** as a vertical-video source for Reels/TikTok/Shorts cross-posting.
>
> Outbound axis: *post on blog → fan out to silos.* Includes Note, Photo (single + gallery), Article, Web Story video, and the IndieWeb life-tracking kinds (Listen/Watch/Read/Play/Checkin) which fan out as text + URL.
>
> Inbound axis lives in Doc 2.

---

## 1. Carry-forward from prior research (re-verified May 2026)

The seven platforms in your link list that the prior artifact already covered:

| Platform | Outbound feasibility | Recommended Outpost path |
|---|---|---|
| **Mastodon** (`@courtneyr@m.courtneyr.co`) | ✅ Per-instance OAuth, free | Adapt to **Share on Mastodon** (janboddez); ALSO covered by ActivityPub plugin |
| **Bluesky** (`courtneyr.dev`) | ✅ App password / OAuth (DPoP), free | ActivityPub plugin + Bridgy Fed (federation path), OR direct AT Proto when app password configured |
| **X / Twitter** (`courtneyr_dev`) | ❌ Pay-per-use default since Feb 2026; `media.write` requires paid credits | Manual fallback only |
| **LinkedIn** (`courtneyr-dev`) | ❌ Verified app + Community Management API gate | Manual fallback only |
| **Instagram** (`courtneyr_dev`) | ❌ Personal API gone Dec 2024; Business needs Meta App Review + FB Page link | Manual fallback only |
| **Threads** (`courtneyr_dev`) | ❌ direct API; ⚠️ via fediverse opt-in | If Threads → Fediverse toggle on, ActivityPub plugin handles it for free |
| **Flickr** (`courane001`) | ⚠️ OAuth 1.0a embedding is borderline §5; **Bridgy Publish** is the clean path | Adapt via Bridgy Publish (zero keys held by plugin) |

No breaking changes since the prior artifact for these seven.

---

## 2. New outbound research — platforms not in the prior artifact

### 2.1 GitHub (`github.com/courtneyr-dev`)
- **API:** REST + GraphQL, free, OAuth Apps + GitHub Apps. POST surfaces: gists, issue comments, issue creation, PR comments, releases, repo stars (semantic "like").
- **Bridgy:** ✅ One of five Bridgy Publish silos (Mastodon, Bluesky, Flickr, GitHub, Reddit). Issue comments via reply-to-URL, issue creation via post-to-repo-URL, stars via like-of-repo-URL. Backfeed sends webmentions for GitHub comments + reactions.
- **Image gotchas:** Markdown `![alt](url)` references; Bridgy doesn't upload binaries, just references your hosted image. Fine for IndieWeb photo + body posts; weak for pure photo dumps.
- **§5:** ✅ via Bridgy Publish proxy. No GitHub credentials in the plugin.
- **Adapter:** No new adapter — `Companion_BridgyPublish` covers it. `Bridgy_Detector` already handles host-pattern matching.

### 2.2 Facebook (`facebook.com/streamlining`)
- **API:** **Personal-profile posting is fully gone since 2018** (Cambridge Analytica response, never restored). Graph API v25 (Feb 2026) confirms only Pages and Groups have posting endpoints. Pages require admin OAuth + Meta App Review for `pages_manage_posts`.
- **Bridgy:** ❌ Facebook support retired. Browser-extension scraping discontinued.
- **§5:** ❌ Not feasible for any account type a typical Outpost user holds.
- **Adapter:** Manual-fallback chip via `Companion_ManualShare`.

### 2.3 YouTube (`youtube.com/channel/UC1tidWhCHiaARk3_YHJwZLw`)
- **API:** Data API v3 `videos.insert` is alive, free, OAuth 2.0. Default quota 10,000 units/day; **single upload costs 1,600 units → ~6 uploads/day per project**. Quota is per Google Cloud project, not per user. Quota increases require manual application (1–6 weeks).
- **Bridgy:** ❌
- **§5:** ⚠️ Borderline. BYO Google Cloud project per user is feasible but the friction is brutal (Console → enable API → OAuth consent screen verification → quota application). **Manual fallback for v1.** A `Companion_YouTubeBYO` is a later option for advanced users who paste their own credentials.
- **Adapter:** Manual fallback v1; BYO adapter deferred.

### 2.4 Twitch (`twitch.tv/courtneyr_dev`)
- **API:** Helix API is alive, free, OAuth 2.0. POST surfaces: `POST /helix/clips` (creates clips, requires `clips:edit`), `PUT /helix/channels` (title/category). **No "post text/image to channel feed" endpoint** — Twitch isn't a microblog.
- **§5:** Twitch isn't a POSSE *destination* in any meaningful sense. Helix matters for **inbound** (extracting metadata about a stream Courtney is watching) — see Doc 2.
- **Adapter:** No outbound adapter. Inbound-only platform.

### 2.5 Pinterest (`pinterest.com/courtneyr_dev`)
- **API:** v5 alive, OAuth 2.0, free. `POST /v5/pins` creates pins. Scopes: `pins:write`, `boards:read`, `boards:write`. **All new apps start in trial mode** (pins visible only to app owner) until manual Standard Access review (10+ days, currently). Refresh tokens 60-day, refreshable indefinitely.
- **Bridgy:** ❌
- **Image gotchas:** Pin requires title (≤100), description (≤500), board ID, media (public URL or upload). EXIF preserved on Pinterest — unusual.
- **§5:** ⚠️ BYO Pinterest app is theoretically feasible but the trial-mode + Standard Access review per user is heavy friction.
- **Adapter:** Manual fallback v1; `Companion_PinterestBYO` deferred.

### 2.6 TikTok (`tiktok.com/@courtneyr_dev`)
- **API:** Content Posting API v2 alive. **Photo posts ARE supported** (`/v2/post/publish/content/init/` with `media_type: PHOTO`, up to 35 photos via URL pull). Videos via `/v2/post/publish/video/init/`. OAuth 2.0 with PKCE. Rate limit 6 init/min, 30 status/min per user token. Token TTL 24 hr / 365-day refresh. **Audit gate:** unaudited apps post to private only; audit takes 5–10 business days + demo video + privacy policy + UX compliance. Caps ~15 posts/day/creator.
- **§5:** ⚠️ Borderline-to-impossible. Audit + UX guidelines (creator nickname display, per-post creator info query, privacy-level dropdown, no default checkboxes) make turnkey embedding impractical. Per-user OAuth needs each user to pass audit.
- **Adapter:** Manual fallback only.

### 2.7 Tumblr (not in your list)
Skip unless you join. API v2 supports OAuth 1.0a / 2.0 + PKCE, free, NPF format, 250 posts/day/blog. BYO-app feasible if added.

---

## 3. Web Stories for WordPress → Reels / Shorts / TikTok

You publish vertical Web Stories via the Google Web Stories plugin and want the underlying video cross-posted to Instagram Reels, Facebook Reels, TikTok, YouTube Shorts. Verified plugin internals from `wp.stories.google` and the WP.org listing:

**Plugin facts that matter for Outpost:**
- Custom post type `web-story` (separate from `post`)
- Video Optimization on by default; uploaded video auto-converts to MP4
- **Auto-resize to max 720×1280 (9:16 vertical)** — same aspect ratio as Reels/Shorts/TikTok
- Source MP4s live in the standard WP media library as attachments
- Stories are AMP HTML pages on the front-end, not a single video file
- Plugin exposes a "Add to New Post" flow for creating a regular blog post that embeds the story

**The architectural insight:** the *story* is an AMP page; the *video assets inside the story* are normal WP media-library MP4s. Outpost can extract those video attachments via the WP REST API and cross-post the underlying MP4 — not the AMP page.

**Two distinct cross-post targets per Web Story:**
1. **Story-as-link cross-post** — POSSE the AMP story URL itself to Mastodon/Bluesky/etc. as a text+link. Treat it like an Article; the ActivityPub plugin and Share on Mastodon already handle this.
2. **Video-asset cross-post** — pull the dominant video attachment(s) from the story and fan them out to Reels/Shorts/TikTok. **This is the new work.**

**`Companion_WebStories` adapter shape:**
- Detection: `is_plugin_active( 'web-stories/web-stories.php' )` + `post_type_exists( 'web-story' )`
- On `web-story` publish: scan story JSON for `video` element references → resolve attachment IDs → fetch MP4 file paths
- If the story is mostly one video, use that as the Reel/Short/TikTok source
- If the story is multiple videos, surface a "which video to syndicate" picker (ambiguous case → mode-picker UX, same pattern as Doc 2's inbound ambiguous routing)
- Capabilities surface: `{ source_type: 'video', max_duration: 60s for TikTok / 90s for Reels / 60s for Shorts under-60-trigger, aspect_ratio: '9:16', orientation: 'portrait', file_type: 'mp4', max_size_mb: 250 (TikTok) }`
- Then hand off to:
  - `Companion_YouTubeBYO` for Shorts (BYO Google Cloud project; defer)
  - `Companion_ManualShare` for Reels (FB + Instagram), TikTok — pre-stage MP4 to Photos/Gallery, fire share intent, paste caption from clipboard

**§5 verdict:** ✅ for detection + extraction. The plugin only reads from a sibling plugin's data — no API keys, no credentials. The actual cross-posting to Reels/TikTok routes through `Companion_ManualShare` which is also §5-clean.

**Manual-fallback intent matrix for vertical video** (additions to the image matrix in §6):

| Target | iOS | Android |
|---|---|---|
| Instagram Reels | Share Sheet → "Instagram" with `video/*`; opens with "Share to Reels" option | `ACTION_SEND` `video/*`, pkg `com.instagram.android`; user picks Reels in app |
| Facebook Reels | Share Sheet → "Facebook" with `video/*`; opens with Reels option | `ACTION_SEND` `video/*`, pkg `com.facebook.katana`; user picks Reels in app |
| TikTok | Share Sheet → "TikTok" with `video/*` | `ACTION_SEND` `video/*`, pkg `com.zhiliaoapp.musically` |
| YouTube Shorts | Share Sheet → "YouTube" with `video/*` (must be ≤60s with `#Shorts` in title for Shorts shelf) | `ACTION_SEND` `video/*`, pkg `com.google.android.youtube` |

Caption is clipboard-paste on all four — no Android intent honors `EXTRA_TEXT` for video on Instagram/Facebook/TikTok.

---

## 4. Manual cross-post as a first-class architectural surface

You explicitly want manual cross-post elevated. Here's the design.

### 4.1 `Companion_ManualShare` — base architecture

Single companion that registers a configurable list of manual-share targets. Each target is a small declarative config:

```php
// Conceptual shape, not final API
[
  'id'             => 'instagram-feed',
  'label'          => 'Instagram',
  'icon'           => 'instagram',
  'accepts'        => [ 'photo', 'gallery', 'video' ],
  'caption_via'    => 'clipboard',           // 'intent' | 'clipboard' | 'web_intent'
  'ios_strategy'   => 'navigator_share_files',  // or 'x_callback_url' | 'app_url_scheme' | 'web_intent'
  'ios_url'        => null,                  // if app_url_scheme: 'instagram://library?LocalIdentifier=...'
  'android_action' => 'android.intent.action.SEND',
  'android_pkg'    => 'com.instagram.android',
  'android_mime'   => 'image/*',
  'android_extras' => [ 'EXTRA_STREAM' => '@image_uri', 'EXTRA_TEXT' => '@caption' ],
  'web_intent_url' => null,
  'after_share'    => 'prompt_for_silo_url', // 'mark_done' | 'prompt_for_silo_url' | 'silent'
]
```

The composer reads `accepts` to decide which chips to show on which mode. The chip's tap handler runs:

1. **Pre-stage media** — write the image/video to iOS Photos (via `navigator.share` with `files`, fallback to download-to-Files-then-prompt-Save-to-Photos) or Android gallery (via `MediaStore` exposed through the Web Share API)
2. **Copy caption + alt text** to clipboard via `navigator.clipboard.writeText`
3. **Fire intent** — for iOS, attempt `navigator.share` with files first (works in Safari 16.4+ for installed PWAs), fall back to URL scheme, fall back to opening the app's web intent in a new tab; for Android, use `navigator.share` (Web Share Level 2 supports files), fall back to `intent://` URL
4. **Mark + prompt** — display a small "I posted it" affirmation; if `after_share = 'prompt_for_silo_url'`, show a single-field form asking for the URL of the posted item, which gets stored as `u-syndication` post-meta for later backfeed

### 4.2 The "manual syndication marker" — backfeed compatibility

The §5-impossible silos can't auto-emit silo URLs back to Outpost. But Bridgy backfeed only works if your post has `u-syndication` links pointing to the silo copies. So the manual flow needs to capture those URLs *after the fact*.

**Two-phase pattern:**
- Phase 1 (chip tap): pre-stage media + copy caption + fire intent. Mark `outpost_manual_share_pending = true` in post-meta with target ID + timestamp.
- Phase 2 (back in Outpost, anytime): the post now shows a small "Did you post to Instagram? Add the URL" prompt; user pastes the silo URL; Outpost writes a `u-syndication` link to the post. Backfeed via Bridgy now works for the platforms Bridgy backfeeds (Mastodon, Bluesky, Flickr, GitHub, Reddit). For Instagram/FB/TikTok/etc. there's no backfeed, but the syndication link is still useful for Outpost's own UI ("this post is syndicated at X").

This phase-2 prompt can be triggered from:
- The composer's "Recent Posts" sidebar (next visit)
- A subtle inline notice on the post permalink page (admin only)
- The IndieAuth-authenticated PWA's "syndication queue" panel

### 4.3 PWA constraints reality check

iOS Safari 16.4+ supports `navigator.share({ files: [...] })` for installed PWAs. The Web Share API target side (Outpost receives shares) is well-supported on Android Chrome but **never landed in iOS Safari** as of May 2026 (WebKit bug 194593, still open). This means:

- **iOS outbound** (Outpost shares to other apps): ✅ works via `navigator.share`
- **iOS inbound** (other apps share to Outpost): ❌ — iOS users need an iOS Shortcut as the share-sheet bridge (covered in Doc 2)
- **Android outbound + inbound:** ✅ both work natively

Because the Web Share API on iOS is one-way (outbound only), the manual cross-post surface is the *better* affordance on iOS — it's the only direction that works without an iOS Shortcut. Strengthens the case for shipping `Companion_ManualShare` first.

### 4.4 Per-platform quirks to bake into the config

| Platform | Caption pre-fill | Notable quirk |
|---|---|---|
| Instagram | Clipboard only | iOS scheme `instagram://library?AssetPath=` is undocumented but historically works; Stories is a separate intent on Android (`com.instagram.share.ADD_TO_STORY`) |
| Facebook | Clipboard only | Android `EXTRA_TEXT` IGNORED on Facebook target since 2014 |
| X / Twitter | ✅ Android `EXTRA_TEXT`; ⚠️ iOS web intent `https://twitter.com/intent/tweet?text=` works but doesn't attach images | Image attaches separately on iOS |
| LinkedIn | ✅ Android `EXTRA_TEXT`; ❌ iOS clipboard | No image-attach via web intent |
| Threads | ✅ iOS via `https://www.threads.net/intent/post?text=`; ❌ Android (drops text on app handoff) | Android pkg is `com.instagram.barcelona` (not `com.threads.android`) |
| TikTok | Clipboard only | Android pkg is `com.zhiliaoapp.musically` (not `com.tiktok.android`) |
| Pinterest | ✅ both via `https://pinterest.com/pin/create/button/?url=&media=&description=` | Web intent works on iOS and Android |
| Tumblr | ✅ both via `https://www.tumblr.com/new/photo?source=&caption=&tags=` | If you ever use Tumblr |
| Flickr | Clipboard only | Use Bridgy Publish where possible |
| Reddit | ✅ via `https://www.reddit.com/submit?url=&title=` | Use Bridgy Publish where possible |

### 4.5 Audit logging for the manual flow

A small thing that pays off later: every chip-tap should log to a per-post `outpost_manual_share_log` post-meta array with `{ target, fired_at, completed_at?, silo_url? }`. Lets you:
- Show a "this post was syndicated to: Instagram (5/4 14:22), Facebook (5/4 14:23) — silo URLs pending" status
- Detect partial completions ("you tapped Instagram but never came back with a URL")
- Power a future "remind me to syndicate this" surface

---

## 5. Bridgy Publish silo coverage — May 2026 confirmed

From `brid.gy/about` directly: **Bluesky, Mastodon, Flickr, GitHub, Reddit.** Twitter, Facebook, Instagram are gone. Backfeed covers the same five plus webmention support for Tumblr/WP.com/Blogger/Medium blog targets.

For your link list: Bridgy Publish covers **Mastodon, Bluesky, Flickr, GitHub** cleanly (4 of 22). Bridgy Fed extends to Bluesky and Threads-via-fediverse-toggle when paired with the WordPress ActivityPub plugin.

---

## 6. §5 feasibility, all 22 platforms + Web Stories

| # | Platform | Auto-feasible? | Path | Adapter |
|---|---|---|---|---|
| 1 | Mastodon | ✅ | Share on Mastodon / ActivityPub plugin / Bridgy | `Companion_ActivityPub` + `Companion_ShareOnMastodon` |
| 2 | Bluesky | ✅ | ActivityPub + Bridgy Fed; OR direct AT Proto | `Companion_ActivityPub` (federation) + later direct |
| 3 | Pixelfed (if joined) | ✅ | Share on Pixelfed / ActivityPub plugin | `Companion_ActivityPub` |
| 4 | Generic fediverse | ✅ | ActivityPub plugin | `Companion_ActivityPub` |
| 5 | Threads | ⚠️ via fediverse opt-in | ActivityPub plugin if toggled ON | `Companion_ActivityPub` (passive) + manual chip |
| 6 | Flickr | ✅ via Bridgy proxy | Bridgy Publish | `Companion_BridgyPublish` |
| 7 | GitHub | ✅ via Bridgy proxy | Bridgy Publish | `Companion_BridgyPublish` |
| 8 | Reddit (if joined) | ✅ via Bridgy proxy | Bridgy Publish | `Companion_BridgyPublish` |
| 9 | Tumblr (if joined) | ⚠️ BYO Tumblr app | OAuth 1.0a/2.0 self-serve | `Companion_TumblrBYO` (defer) |
| 10 | Pinterest | ⚠️ BYO Pinterest app | OAuth 2.0, trial-mode review | Manual + later `Companion_PinterestBYO` |
| 11 | YouTube | ⚠️ BYO Google Cloud project | Data API v3, 1,600 units/upload | Manual + later `Companion_YouTubeBYO` |
| 12 | TikTok | ⚠️ BYO + audit gate | Content Posting API v2 | Manual fallback only |
| 13 | X / Twitter | ❌ | Pay-per-use, no free tier | Manual fallback only |
| 14 | Instagram | ❌ | Personal API dead, Business needs Review | Manual fallback only |
| 15 | Facebook | ❌ | Personal API dead since 2018 | Manual fallback only |
| 16 | LinkedIn | ❌ | Verified app + Community API restricted | Manual fallback only |
| 17 | Twitch | n/a (inbound-only) | No "post" surface | n/a — Doc 2 |
| 18 | Snipd, Spotify, Last.fm, Goodreads, BGG, Readwise, Amazon, OpenProfile.dev, WordPress.org | n/a | Inbound-only / identity-only | Doc 2 |
| 19 | **Web Stories video assets** | ✅ for detection; routes to other adapters for delivery | Plugin detection + media-library extract → Reels/Shorts/TikTok via `Companion_ManualShare` | **`Companion_WebStories`** |

---

## 7. Adapter shipping queue (revised)

1. **`Companion_ActivityPub`** — highest leverage. ~7 silos covered (Mastodon, Pixelfed, Pleroma, Akkoma, Misskey, Friendica, Hubzilla) plus Bluesky (via Bridgy Fed) plus Threads (via fediverse toggle). Zero API keys.
2. **`Companion_ManualShare`** — newly elevated to first-class. Single adapter handles Instagram, Facebook, X, LinkedIn, Threads, TikTok, Pinterest, Reddit (manual mode), Tumblr (manual mode), VSCO, Flickr (manual mode). Two-phase pattern with `u-syndication` capture.
3. **`Companion_BridgyPublish`** — promote your existing `Bridgy_Detector` to a full companion. Explicit chips for Mastodon (account-distinct from federation), Bluesky (account-distinct), Flickr, GitHub, Reddit. Zero credentials.
4. **`Companion_ShareOnMastodon`** — explicit Mastodon chip when user wants a Mastodon account separate from their site's federated identity.
5. **`Companion_WebStories`** — detects Web Stories plugin, extracts video MP4s from `web-story` posts, routes to `Companion_ManualShare` for Reels/Shorts/TikTok delivery.
6. **`Companion_PostKinds` / `Companion_PostFormats` / `Companion_SyndicationLinks` / `Companion_XFN`** — IndieWeb metadata surface companions. Lower priority but high coverage of the IndieWeb-WP suite.
7. **`Companion_PinterestBYO` / `Companion_TumblrBYO` / `Companion_YouTubeBYO`** — BYO-credentials adapters for users willing to register their own app. Defer; document the path.

---

## 8. ActivityPub plugin remains the highest-leverage outbound adapter

Re-confirmed for May 2026. Pfefferle/Automattic v8.x ships:
- Federation to Mastodon, Pixelfed, Pleroma, Akkoma, Misskey, Friendica, Hubzilla
- Reader preview, Like/Boost buttons, moderation, post-format suggestions
- ClassicPress compatibility (v7.8.3+)
- HTML images become AP `attachment` arrays
- Bundled into WordPress.com via Settings → Discussion "Enter the fediverse"

Combined with Bridgy Fed → Bluesky reach. Combined with the user's Threads → Fediverse toggle → Threads reach.

**One adapter, your `@courtneyr@m.courtneyr.co`, your `@courtneyr.dev` Bluesky, your future fediverse-toggled Threads, plus any new fediverse network — all covered.**

---

## Caveats specific to Doc 1

- Web Share Target API never landed in iOS Safari (WebKit bug 194593, still open as of May 2026). iOS inbound shares from other apps need an iOS Shortcut bridge (covered in Doc 2). iOS *outbound* via `navigator.share` works fine in installed PWAs since 16.4.
- Facebook Android target ignores `EXTRA_TEXT` and has since 2014 — there's no fix. Caption goes to clipboard, period.
- TikTok's audit gate is the killer even for BYO. Each user passes audit independently. Skip TikTok outbound API entirely; manual only.
- Threads → Fediverse toggle isn't visible to EU users as of Apr 2026 (Meta regional rollout). EU users need manual fallback even if they want it.
- Web Stories' AMP-page format means the *story itself* isn't a single MP4; the video assets *inside* it are. The adapter pulls those, not the AMP page.
- The "manual syndication marker" pattern depends on the user remembering to come back and paste silo URLs. Soft completion; fine for most users, lossy for some. The audit log makes this visible.
- Bluesky's 1 MB / 1000px image cap and 60s video cap (when bridged via Bridgy Fed) still bite. Outpost should down-rez before federation if Bluesky is in the target chip list.
- Active install counts for Share on Mastodon and Share on Pixelfed are bucketed on WP.org and not directly visible in this research session.
- "Personal" Facebook posting will not return. Stop documenting any path for it; ship manual fallback only and move on.
