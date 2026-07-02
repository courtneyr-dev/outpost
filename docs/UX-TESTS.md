# UX tests

Manual and agent-runnable UX test suite for the Outpost composer. Written against v0.1.104. Complements [SMOKE-TESTS.md](SMOKE-TESTS.md) (device-level PWA plumbing) — this suite covers the user-facing composer experience.

## How to run

- **Target staging first:** `https://qkf.b0d.myftpupload.com/post/`. Only run against live (`https://courtneyr.dev/post/`) after a full staging pass.
- **Cache-buster:** append `?_cb=<timestamp>` on first load so GoDaddy's edge cache doesn't serve a stale response.
- **Test content prefix:** every post created by a test starts its content with `UX-TEST` so cleanup is a single search.
- **Cleanup:** after the run, trash every post whose content starts with `UX-TEST` (wp-admin → Posts → search "UX-TEST" → bulk trash).

Each test carries a runner tag:

- `[auto]` — runnable by an agent in desktop Chrome (Claude in Chrome, see [UX-TESTS-CHROME-RUNNER.md](UX-TESTS-CHROME-RUNNER.md)).
- `[manual]` — needs a real phone, a device permission (mic, camera, location), or a network-state change desktop Chrome can't fake. Run these yourself per the SMOKE-TESTS.md device matrix.

Record results as: **PASS**, **FAIL** (with what you saw), or **BLOCKED** (couldn't attempt, with why).

---

## A. Shell and login

**A1. Composer shell loads** `[auto]`
Open `/post/?_cb=<timestamp>` while signed out of Outpost (but signed in to WordPress).
Expected: page titled "Outpost" renders a card titled "Sign in to Outpost" with a "Your site" input pre-filled with the site URL and a readable "Sign in" button. No blank page, no raw PHP errors.

**A2. IndieAuth round-trip** `[auto]`
Tap "Sign in" → the IndieAuth authorization page renders, identifying the client as the `/post/` URL → tap "Allow".
Expected: redirect to `/post/auth/callback?code=...`, brief "Signing you in…" state, then the composer mounts at `/post/` with the tab strip visible. No `AuthFlowError` codes (`state_mismatch`, `missing_state`, `exchange_failed`, `no_code`) on screen.

**A3. Session persists across reload** `[auto]`
After A2, reload `/post/`.
Expected: composer mounts directly — no login screen. (Token lives in encrypted IndexedDB.)

**A4. Back button doesn't replay the login** `[auto]`
After A2, press the browser Back button.
Expected: you do NOT land back on `/post/auth/callback` with a consumed code error. (The callback uses `location.replace`.)

**A5. Sign out** `[auto]` — run LAST, after all other tests
On the Post tab, activate "Sign out".
Expected: page reloads to the login screen. Reloading `/post/` again still shows login (token actually cleared).

---

## B. Tab framework and keyboard access

**B1. All seven tabs render** `[auto]`
Expected tabs in order: Post, Reply, Photo, Doing, Life, Recipe, About. The strip has `role="tablist"` with label "Composer modes"; exactly one tab has `aria-selected="true"` (Post, by default).

**B2. Arrow-key navigation with wrap-around** `[auto]`
Focus the active tab. Press ArrowRight repeatedly.
Expected: focus AND selection move together (automatic activation), each panel swaps in, and after the last tab (About) focus wraps to the first (Post). ArrowLeft from Post wraps to About. Home jumps to Post, End jumps to About.

**B3. Panel state survives tab switches** `[auto]`
Type `UX-TEST state check` into the Post tab textarea. Switch to Reply, then back to Post.
Expected: the text is still there. (Panels render eagerly; `hidden` toggles visibility.)

**B4. Focus visible on every interactive element** `[auto]`
Tab through the composer with the keyboard.
Expected: every button, tab, input, and radio shows a visible focus indicator. No focus traps, no unreachable controls.

---

## C. Post tab (note)

**C1. Post a plain note** `[auto]`
Type `UX-TEST note <timestamp>` and submit.
Expected: button shows a transient state ("Finding endpoint…" / "Posting…"), then a success message "Posted to:" with a URL. Opening that URL shows the note published on the site.

**C2. Empty note blocked** `[auto]`
Clear the textarea and try to submit.
Expected: submission is blocked (disabled button or validation) — no empty post is created.

**C3. Character counter reacts** `[auto]`
Type into the textarea.
Expected: the character counter near the textarea updates as you type.

**C4. Voice input button present** `[manual]` (mic permission)
Tap the microphone button and grant permission.
Expected: recording state is visibly indicated (pulse animation); dictated text lands in the textarea. Under "reduce motion" OS setting the pulse slows but stays visible.

**C5. More panel opens and holds values** `[auto]`
Open the More pull-out on the Post tab.
Expected: it expands without errors and shows the configured options (syndication targets when configured, format options). Set a value, close, reopen — the value persists. If the composer-config endpoint is unreachable, a banner explains why More options are missing but posting still works.

---

## D. Reply tab (9 variants)

**D1. Variant picker renders all nine** `[auto]`
Expected radios in order: Reply, Like, Repost, Bookmark, RSVP, Follow, Wishlist, Tag, Issue — in a fieldset with a legend, arrow-key navigable as a radio group. Heading and submit-button label update to match the selected variant (e.g. "Post like").

**D2. Reply requires URL and content** `[auto]`
Select Reply. Fill only the URL.
Expected: submit stays blocked until content is present too.

**D3. Like requires URL only** `[auto]`
Select Like, paste `https://qkf.b0d.myftpupload.com/` (or any post URL on the target site), leave content empty.
Expected: submit enabled. Submitting creates the like post; success message shows the new URL. Prefix any content field you do fill with `UX-TEST`.

**D4. Preview step** `[auto]`
Select Reply, paste a public post URL, activate "Show preview".
Expected: a citation preview (page title, final URL) renders. Preview failure (slow/unreachable target) shows an error but does NOT block submitting.

**D5. Preview rejects junk URLs** `[auto]`
Enter `notaurl` and try to preview/submit.
Expected: a clear validation error; nothing posts. Try `javascript:alert(1)` — also rejected.

**D6. Bookmark round-trip** `[auto]`
Select Bookmark, paste any public article URL, content `UX-TEST bookmark`, submit.
Expected: success with URL; the published post shows the bookmarked target.

---

## E. Photo tab

**E1. Photo upload requires alt text** `[auto]` (desktop file picker)
Choose an image file. Try to submit with the alt field empty.
Expected: submit blocked until alt text is entered OR the decorative toggle is set.

**E2. Photo posts end-to-end** `[auto]`
Add an image, alt text `UX-TEST photo alt`, caption `UX-TEST photo`, submit.
Expected: success with URL; the published post shows the image, and the image's alt attribute in the page source equals the alt text entered.

**E3. Camera capture** `[manual]` (phone camera)
On a phone, use the photo input.
Expected: camera/photo-library sheet opens; a captured photo uploads and posts.

---

## F. Doing tab (10 variants)

**F1. Variant picker renders all ten** `[auto]`
Expected radios: Listen, Watch, Read, Play, Game, Jam, Checkin, Eat, Drink, Exercise. Labels and inputs swap per variant.

**F2. Listen post with URL** `[auto]`
Select Listen, paste a Spotify track URL, submit (content `UX-TEST listen` if a content field is shown).
Expected: success; published post carries the listen-of target.

**F3. Snapshot variant with media** `[auto]` (desktop file picker)
Select Eat. Fill the primary field with `UX-TEST eat`, attach an image via the media picker, leave alt empty.
Expected: submit blocked until alt text is present (same alt discipline as Photo). With alt filled, post succeeds.

**F4. Checkin requires a location; posts with video URL** `[auto]`
Select Checkin. With only a note and a video URL filled, the submit button stays DISABLED — a checkin without a location is not submittable by design. Now type `geo:29.12,-103.24` into "Location URL or geo:lat,lon" (typed manually — no permission prompt) and a place name `UX-TEST checkin place`, keep the video URL, submit.
Expected: submit enables once the location field has a value; post succeeds and is verifiable in wp-admin.

**F4b. Success banner resets on variant switch** `[auto]`
After any successful Doing-tab post (e.g. F2), switch to a different variant without submitting.
Expected: the "Posted…" banner disappears. A lingering banner misreads as the new variant having posted.

**F5. Checkin location picker** `[manual]` (geolocation permission)
Select Checkin and use the location picker control (device GPS, not typed coordinates).
Expected: permission prompt; a location resolves and attaches to the post.

---

## G. Life tab

**G1. Mood and Weather variants** `[auto]`
Expected radios: Mood, Weather. Each shows a primary input plus optional content. Post a Mood entry with primary `UX-TEST mood` — succeeds with URL.

---

## H. Recipe tab

**H1. Recipe form renders and posts** `[auto]`
Fill the recipe fields with obviously-test values (name `UX-TEST recipe`), submit.
Expected: success with URL; published post renders the recipe structure.

---

## I. About tab

**I1. Bookmarklets render** `[auto]`
Expected: the About tab lists the bookmarklet(s) with drag-to-bookmarks-bar affordance and the iOS setup guide. Links resolve; no broken images; the source link points at the GitHub repo.

---

## J. Share targets and entry paths

**J1. Dispatch params pre-fill the composer** `[auto]`
Open `/post/?url=https%3A%2F%2Fexample.com%2F&mode=bookmark` (simulates the share-target 303 redirect).
Expected: the composer opens with the matching tab/variant selected and the URL pre-filled, and the query string is stripped from the address bar (refresh doesn't re-apply).

**J2. Android share sheet** `[manual]` (Android device)
Share a Spotify URL from the Android share sheet to the installed PWA.
Expected: composer opens on Doing → Listen with metadata pre-filled.

**J3. iOS Shortcut bridge** `[manual]` (iPhone with the Shortcut configured)
Share a URL through the Outpost Shortcut.
Expected: composer opens with the URL routed to the right mode.

---

## K. Offline behavior

**K1. Offline banner** `[manual]` (real network toggle)
Enable Airplane Mode on a phone, then open the (already-installed/cached) PWA.
Expected: the connection banner says you're offline. Restore network, reload — banner gone.

**K2. Offline queue and badge** `[manual]`
While offline, submit a note `UX-TEST offline queue`.
Expected: the post queues instead of failing; the queue badge shows a count. Back online, the queue flushes and the badge clears; the post appears on the site.

**K3. Data Saver notice** `[manual]` (Android Chrome with Data Saver on)
Expected: the Data Saver message renders (Chromium-only feature).

---

## L. Visual, responsive, and PWA plumbing

**L1. Mobile viewport layout** `[auto]` (Chrome device emulation ~390px)
Expected: no horizontal scroll on any tab; tab strip usable; touch targets not clipped; the bottom toolbar respects safe-area padding.

**L2. No raw/unstyled state** `[auto]`
Expected on every tab: buttons have readable text (not black-on-black), inputs have visible borders, nothing renders as unstyled HTML. (Paint comes from theme tokens; structure from the plugin.)

**L3. Manifest and service worker respond** `[auto]`
Fetch `/post/manifest.json` (expect `application/manifest+json`, scope and start_url of `/post/`) and `/post/sw` (expect `application/javascript`).

**L4. Install to home screen** `[manual]`
Android Chrome: Install app → opens standalone. iPhone Safari: Add to Home Screen → icon launches to `/post/`.

**L5. Console hygiene** `[auto]`
After a full pass across all tabs, the browser console shows no uncaught errors (warnings are recordable but not failures).

---

## Coverage summary

| Area | auto | manual |
|------|------|--------|
| Shell/login | 5 | 0 |
| Tabs/keyboard | 4 | 0 |
| Post | 4 | 1 |
| Reply | 6 | 0 |
| Photo | 2 | 1 |
| Doing | 4 | 1 |
| Life | 1 | 0 |
| Recipe | 1 | 0 |
| About | 1 | 0 |
| Entry paths | 1 | 2 |
| Offline | 0 | 3 |
| Visual/PWA | 4 | 1 |
| **Total** | **33** | **9** |

---

# Tier 2 — exhaustive variant pass

Tier 1 above is a breadth smoke test: every area once. Tier 2 drives **every postable variant end-to-end** and exercises **every distinct control** at least once. Run it before a release, after changes to the modes or the Micropub client, or whenever Tier 1 passes but you want real confidence. All `[auto]`.

**Rules (in addition to the Tier 1 "How to run" rules):**

- Staging only. Every post's text content starts with `UX-TEST`.
- **All syndication chips OFF on every post.** If a chip renders pre-selected, click it off before submitting. Tier 2 must never publish to an external network.
- Verify every "posted" claim in wp-admin (`edit.php`, newest first): a NEW post ID with a fresh timestamp. Do not trust the banner alone — that lesson is why F4b exists.
- After each section, trash that section's posts before moving on (keeps the newest-first check unambiguous).
- Post-run, the deeper property check (`mf2_*` post meta) is done from Claude Code with wp-cli: `wp @staging post meta list <ID> | grep -E "mf2_"` on a sample of trashed posts. The browser agent only needs to report post IDs.

Target URLs: use `https://qkf.b0d.myftpupload.com/` for on-site targets and `https://www.youtube.com/watch?v=dQw4w9WgXcQ` / `https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT` for media targets.

## T2.A — Reply tab: all nine variants post

For each: select the variant, fill the target URL (plus content where required, prefixed `UX-TEST`), toggle any syndication chips OFF, submit, expect a success banner, verify the new post in wp-admin, note the ID.

**T2.1 Reply** — "In reply to" = staging URL, content `UX-TEST t2 reply` (both required). Also click "Show preview" first and confirm a citation renders.
**T2.2 Like** — URL only.
**T2.3 Repost** — URL only.
**T2.4 Bookmark** — URL + note `UX-TEST t2 bookmark`.
**T2.5 RSVP** — "Event URL" = staging URL. Confirm the yes/no/maybe/interested picker shows with "yes" preselected; choose **maybe**; submit.
**T2.6 Follow** — "Person or feed URL" = staging URL.
**T2.7 Wishlist** — target URL + note `UX-TEST t2 wishlist`.
**T2.8 Tag** — target URL + note `UX-TEST t2 tag`.
**T2.9 Issue** — target URL + note `UX-TEST t2 issue`.

Expected mf2 properties (for the wp-cli follow-up): in-reply-to, like-of, repost-of, bookmark-of, rsvp, follow-of, wishlist-of, tag-of, issue-of.

## T2.B — Doing tab: all ten variants post, every control exercised

**T2.10 Listen + rating + artist + title** — Spotify track URL, Title `UX-TEST t2 listen`, Artist `Test Artist`, Rating `4`, comment `UX-TEST t2 listen`. Verify posted (Audio format expected).
**T2.11 Watch** — YouTube URL, Title `UX-TEST t2 watch`, Director field filled, Rating `5`.
**T2.12 Read + read-status** — any book URL (e.g. `https://qkf.b0d.myftpupload.com/`), Title `UX-TEST t2 read`, Author filled, set the read-status dropdown to **reading**, Rating `3`.
**T2.13 Play** — any URL, Title `UX-TEST t2 play`.
**T2.14 Game** — any URL, Title `UX-TEST t2 game` (same property as Play; distinct label).
**T2.15 Jam** — Spotify URL, Artist filled, comment `UX-TEST t2 jam`.
**T2.16 Checkin** — type `geo:29.12,-103.24`, Place name `UX-TEST t2 checkin`. (Submit must be DISABLED before the geo value is entered — re-assert F4's gating.)
**T2.17 Eat + media** — "What did you eat?" = `UX-TEST t2 eat`, attach an image with alt text, type a venue `geo:` value in the optional venue field.
**T2.18 Drink + video URL** — "What did you drink?" = `UX-TEST t2 drink`, paste a YouTube URL in "Video URL (optional)", no image.
**T2.19 Exercise** — "What activity?" = `UX-TEST t2 exercise`, note in the optional content field, venue left empty.

Expected mf2 properties: listen-of ×2 (Listen, Jam), watch-of, read-of (+read-status), play-of ×2, location, eat-of, drink-of, exercise.

## T2.C — Life tab: both variants

**T2.20 Mood + title** — Title `UX-TEST t2 mood title`, "How are you feeling?" = `focused`, context `UX-TEST t2 mood`.
**T2.21 Weather** — "Conditions" = `UX-TEST t2 72F sunny`.

## T2.D — Photo: decorative-alt path

**T2.22 Decorative toggle** — attach an image, leave alt empty, flip the decorative toggle instead. Submit must enable (decorative = deliberate empty alt), post succeeds, caption `UX-TEST t2 decorative`.

## T2.E — More panel: values actually land on the post

**T2.23 Note + More panel round trip** — On the Post tab, write `UX-TEST t2 more panel`. Open More; set Category `UX-Test-Cat` (new), Tag `ux-test-tag`, Slug `ux-test-t2-slug`, Post Format `Aside`. Submit. In wp-admin, open the new post's row/quick-edit and confirm ALL FOUR landed: the category exists and is assigned, the tag is assigned, the slug is `ux-test-t2-slug`, the format is Aside. This is the only Tier 2 test where wp-admin verification goes beyond "the post exists."
**T2.24 Syndication chips render-only** — On the Post tab, open the chip strip (if any chips are configured). Confirm chips render with pressed/unpressed states and that every chip is OFF before any Tier 2 submission. Do NOT submit with a chip on.

## T2.F — Error paths

**T2.25 Invalid target URL** — Reply tab, Wishlist, target `notaurl` → clear inline error, nothing posts.
**T2.26 Rating out of range** — Doing tab, Listen, rating `9` → expect the "Rating must be a number between 1 and 5." error; nothing posts.
**T2.27 Invalid video URL scheme** — Doing tab, Drink, video URL `ftp://example.com/x` → expect the video-URL validation error; nothing posts.

## T2.G — State hygiene

**T2.28 Life-tab banner reset** — Post T2.20's mood, then switch the Type radio to Weather without submitting: the success banner must disappear (same contract as F4b, Life mode).
**T2.29 Error banner reset** — Trigger T2.26's rating error, then switch variant to Watch: the error banner must disappear.
**T2.30 Console sweep** — After all sections: no uncaught console errors.

## Tier 2 cleanup and accounting

~21 posts created (T2.1–T2.23). Trash via the per-section sweeps + a final `UX-TEST` search; report created vs trashed counts (photo/media posts may not text-match — check the Media Library and newest-first list). Claude Code then runs the wp-cli property audit on the trashed IDs and permanently empties nothing — Trash emptying stays manual.
