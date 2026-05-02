# Smoke Tests

End-to-end verification plans for sessions that ship observable behavior to staging. Smoke tests run on real mobile devices because they exercise paths that unit tests can't reach: cookie persistence across redirects, IndexedDB writes, service worker registration, manifest parsing, PWA install flows.

## Device matrix

Three viable platforms. Stages 1–5 are device-agnostic; Stages 4 and 6 have platform-specific verification paths.

| Platform | Remote DevTools setup | True PWA install (standalone window) |
|----------|----------------------|--------------------------------------|
| **Android Chrome** | On the phone: Settings → About → tap Build number 7×, then System → Developer options → enable USB debugging. Connect by USB; on desktop Chrome visit `chrome://inspect/#devices` and click **inspect** next to the live tab. Full DevTools attaches — Application panel, IndexedDB, service workers, network. | Yes — Chrome offers an "Install" affordance (address-bar `+` icon, banner, or three-dot menu → "Install app"). Creates a launcher icon that opens the PWA in standalone mode. |
| **iPhone Safari** | On the phone: Settings → Safari → Advanced → Web Inspector ON. On the Mac: Safari → Settings → Advanced → "Show Develop menu in menu bar". Connect by USB; on the Mac use Develop → [device name] → [tab]. Full DevTools attaches. | Add-to-Home-Screen (Share → Add to Home Screen). Related but distinct platform path; observe complementarily, do not treat as a substitute for Android's true install. |
| **iOS Chrome** | Not available — Apple's iOS rules block remote inspection of third-party browsers. iOS Chrome can run the smoke test for stages 1/2/3/5 (user-visible behavior is fine), but no Stage 4 inspection, and Add-to-Home-Screen makes a bookmark rather than a real PWA. | Bookmark only. |

**Pick the platform you have at hand.** If you want full Stage 4 + Stage 6 coverage, use Android Chrome with USB DevTools. If you only have iPhone, use Safari (not Chrome) for Stage 4, and treat A2HS as a Stage-6-adjacent observation. If you only have iOS Chrome, run stages 1/2/3/5 and skip the rest.

## Cache-buster query string

Managed-WP edge caches (GoDaddy in our case, Varnish/nginx FastCGI elsewhere) can promote 302/301 responses to cached entries, so a fix that landed at deploy-time can appear absent on the next visit. Append `?_cb=<timestamp>` (or any unique query string) when verifying staging changes; the new key forces a real PHP request. Confirmed working when the response carries `x-gateway-skip-cache: 1`.

## B0b — IndieAuth login + token persistence

**Build under test:** the latest commit on `outpost main` whose `OUTPOST_VERSION` is at least `0.1.3` (the readable-button hotfix). Verify with `git log --oneline -1` in `~/projects/staging-courtneyr-dev/plugins/outpost/`.

**Pre-flight:**

1. Confirm staging is on the build under test (`wp-admin/plugins.php` shows Outpost at the expected version).
2. Open Query Monitor on the staging site so PHP errors are observable.
3. Choose your platform per the matrix above.

### Stage 1 — login screen renders

Open `https://qkf.b0d.myftpupload.com/post/?_cb=<timestamp>` on the phone.

**Expected:**

- Card titled "Sign in to Outpost".
- Lede: "Outpost posts to your site through Micropub. Sign in with your domain to authorize this device."
- "Your site" label above an input field pre-filled with `https://qkf.b0d.myftpupload.com/`.
- A "Sign in" button with **visible text** (not a black-on-black rectangle — the 0.1.3 hotfix uses the `revert` keyword for color/background fallbacks).
- No console errors.
- Query Monitor PHP errors panel: empty.

**Stop here if:** the page is blank, the bundle didn't load (DevTools Network tab → expect a 200 on `assets/index-*.js`), the button is unreadable, or QM shows PHP errors.

### Stage 2 — IndieAuth round-trip

1. Tap "Sign in".
2. **Expected:** the browser navigates to `https://qkf.b0d.myftpupload.com/wp-json/indieauth/1.0/auth?...`. The IndieAuth plugin's authorization page renders, identifying the client as `https://qkf.b0d.myftpupload.com/post/`.
3. Tap "Allow".
4. **Expected:** the browser redirects back to `/post/auth/callback?code=...&state=...`. The PWA briefly shows "Signing you in…", then "Signed in", then the URL changes to `/post/` and the ComposerPlaceholder mounts.

**Stop here if:** the authorization page doesn't appear, the redirect-back URL is wrong, or the PWA shows an `AuthFlowError` (look for `state_mismatch`, `missing_state`, `exchange_failed`, `no_code` in the surfaced message).

### Stage 3 — ComposerPlaceholder renders authenticated state

**Expected after the redirect lands:**

- Card titled "You're signed in".
- Lede mentions Phase C.
- Definition list:
  - Identity: `https://qkf.b0d.myftpupload.com/`
  - Scope: `create update media`
- A "Sign out" button.

**DevTools verification (Android Chrome OR iPhone Safari with desktop DevTools attached):**

- Application → Storage → IndexedDB → `outpost`:
  - `tokens` object store: one row keyed `"micropub"` with `iv` (12-byte Uint8Array), `ciphertext` (opaque bytes ~144 bytes for a typical bearer token — NOT the raw token), `meta` containing `tokenType: "Bearer"`, `scope: "create update media"` (or whatever the IndieAuth Allow page granted), `me`, `storedAt` (epoch ms).
  - `crypto-keys` object store: one row keyed `"token-encryption-key"` with a `CryptoKey` reference. DevTools shows `algorithm: AES-GCM`, `length: 256`, **`extractable: false`** (load-bearing — without this, `exportKey()` could dump raw bytes), `usages: ["decrypt", "encrypt"]`.
- Application → Storage → Session storage: empty (auth-flow's `finally{}` cleared `verifier`, `state`, `me`, `token_endpoint` regardless of exchange success).

**Stop here if:** the placeholder doesn't render, fields are blank, or the token isn't in IndexedDB.

### Stage 4 — service worker + manifest

**Android Chrome (USB DevTools) OR iPhone Safari (Develop menu):**

- Application → Service Workers: one registered SW with scope `https://qkf.b0d.myftpupload.com/post/`, scriptURL `https://qkf.b0d.myftpupload.com/post/sw` (note the missing `.js` — A2 staging fix #4: managed-WP nginx short-circuits `.js` requests before they reach WordPress). State should be **`activated`**.
- Manifest at `/post/manifest.json` (raw GET in browser): `{ "name": "Outpost", "scope": "/post/", "display": "standalone", "start_url": "/post/", "icons": [{ "src": "/wp-content/plugins/outpost/assets/icons/icon-192.png", ... }, { "src": "/wp-content/plugins/outpost/assets/icons/icon-512.png", ... }] }`.
- **Known gap:** the icon files referenced in the manifest don't ship until A3. Browsers tolerate missing icons (the manifest stays valid, install prompts may use a fallback) — this is tracked in `docs/A3-REQUIREMENTS.md`. Don't fail Stage 4 because of missing icons.

**iOS Chrome:** skip this stage.

### Stage 5 — sign out + repeat (with IV-freshness check)

1. Tap "Sign out".
2. Page reloads.
3. **Expected:** back to LoginScreen.
4. **DevTools (any platform with DevTools):**
   - `tokens` row: gone.
   - `crypto-keys` row: still present (the encryption key persists across sign-outs intentionally — re-login is one round-trip, not a key-regeneration round-trip too).
   - Note the IV bytes from Stage 3's token row before signing out (`iv: [157, 159, 220, ...]` or similar).
5. Sign in a second time. After Stage 2 + Stage 3 complete:
   - **Expected:** the new `tokens` row's `iv` is a **fresh random 12 bytes** — should NOT match Stage 3's IV. AES-GCM's contract requires unique IVs per key per encryption; `crypto.getRandomValues(new Uint8Array(12))` provides them. Verifying this once on staging confirms the `write_token` path is regenerating IVs (and not, for example, reusing a constant).
6. Session storage should still be empty after the second login.

### Stage 6 — install / Add-to-Home-Screen

**Android Chrome (true PWA install):**

1. Look for "Install" — address-bar `+` icon, install banner, or three-dot menu → "Install app" (the install variant, not the bookmark variant).
2. Tap install. Chrome creates a launcher icon.
3. Tap the launcher icon. The PWA opens in its own window with no Chrome chrome — that's `display: standalone` from the manifest.
4. **Expected:** LoginScreen renders again (the standalone window has its own IndexedDB scope, separate from the Chrome tab). Sign in once more to confirm the standalone path.

**iPhone Safari (Add-to-Home-Screen — separate observation):**

1. Tap Share → Add to Home Screen → Confirm.
2. Tap the home-screen icon. Opens in iOS standalone mode (no Safari chrome — `apple-mobile-web-app-capable: yes` in the shell head triggers this).
3. **Expected:** the auth flow completes inside the standalone window — no pop-out to regular Safari. iOS keeps standalone-mode navigations within the same window.
4. **Expected:** separate IndexedDB scope from the regular Safari tab. The standalone window starts logged-out even if the Safari tab is logged in — they're isolated browser storage contexts.
5. Treat the result as a complementary data point, not a substitute for Android's install verification — A2HS goes through a different platform code path. Apple-touch-icon and tuned status-bar styling land in A3; until then expect the placeholder favicon and `status-bar-style: default`.

**iOS Chrome:** skip — the install option creates a bookmark, not a real PWA.

## B1 — Micropub note round-trip

**Build under test:** `OUTPOST_VERSION` ≥ `0.1.4`. Verify with `git -C ~/projects/staging-courtneyr-dev/plugins/outpost log --oneline -1`.

**Pre-flight:**

1. Confirm staging shows Outpost 0.1.4 in `wp-admin/plugins.php`.
2. You should already be signed in from B0b (your IndieAuth token persists across deploys because the encryption key stays in IndexedDB and the ciphertext is forward-compatible). If not, run B0b Stages 1–3 first to get authenticated.
3. Open Query Monitor on staging.

### B1-Stage 1 — NoteForm renders for the authenticated user

Open `https://qkf.b0d.myftpupload.com/post/?_cb=<timestamp>` on the phone.

**Expected:**

- Card titled "Post a note" (B0b's "You're signed in" copy is replaced).
- Sub-line: "Signed in as `https://qkf.b0d.myftpupload.com/` · scope `create update media`".
- Label "What's on your mind?" above an empty multi-line textarea (5 rows tall).
- Two buttons in a row: "Post note" (primary, disabled until textarea has content) and "Sign out" (secondary).
- No console errors.
- Query Monitor: empty.

**Stop here if:** the page still shows B0b's "You're signed in" placeholder (browser cached the old shell — append a fresh `_cb=` and try again), or the textarea is missing.

### B1-Stage 2 — Post a note end-to-end

1. Type a short note in the textarea (e.g. "Outpost B1 smoke test 🛰️" — emojis are valid because the body is UTF-8 form-encoded).
2. Tap "Post note".
3. **Expected sequence (visible in the button label):**
   - Briefly: "Finding endpoint…" (NoteForm's first-post discovery).
   - Then: "Posting…".
   - Then: button returns to "Post note" (disabled because content cleared) and a status line appears: "Posted to: `https://qkf.b0d.myftpupload.com/2026/05/01/...`" with the URL as a clickable link.
4. Open the link in a new tab. The post should render on staging as a normal WordPress post with the body you typed. (Title may be "Untitled" or auto-generated from the first line of content — that's the Micropub plugin's default for h-entry without an explicit name. Phase C's note mode will refine this if Courtney wants.)

**DevTools verification (desktop Safari Develop menu):**

- Network tab: see `GET https://qkf.b0d.myftpupload.com/` (discovery, status 200) followed by `POST https://qkf.b0d.myftpupload.com/wp-json/micropub/1.0/endpoint` (the post, status 201) with `Authorization: Bearer ...` header redacted from the inspector or visible (Safari shows it).
- Response of the POST: `Location: https://qkf.b0d.myftpupload.com/<post-slug>`.
- Console: clean.

**Stop here if:** the button stays on "Finding endpoint…" indefinitely (discovery 4xx — check `~/` is reachable and has the `micropub` link rel), the POST returns 401/403 (token rejected — re-auth via Sign out + Sign in), or no Location header is in the response (server-side Micropub plugin issue, not Outpost).

### B1-Stage 3 — Endpoint caching (second post, same session)

1. Type a second short note in the textarea (which auto-cleared after Stage 2).
2. Tap "Post note".
3. **Expected:** button skips the "Finding endpoint…" state — goes straight to "Posting…". The endpoint is cached in `NoteForm`'s component state for the session.
4. **Network tab:** only one request this time (the POST). No discovery GET.
5. Verify the second post's URL is different from the first (each post gets its own slug).

**Stop here if:** discovery runs again on the second post. That means the cache isn't holding (component state reset unexpectedly).

### B1-Stage 4 — Error surfacing (optional)

If you want to verify the error UI without breaking your real account:

1. Sign out.
2. Sign in to a site that doesn't have Micropub configured, OR temporarily revoke the token from `wp-admin/users.php?page=indieauth-list-tokens` and try to post.
3. **Expected:** the form surfaces an inline error with the `MicropubError` code prefix (e.g. `post_failed: micropub endpoint returned 401 — ...`). Form stays mounted; you can sign out and sign back in to recover.

This is "nice to verify" not "must verify." Skipping it is fine for the B1 sign-off.

## C0 — Composer tab framework + Note mode plugged in

**Build under test:** `OUTPOST_VERSION` ≥ `0.1.9`. Verify with `git -C ~/projects/staging-courtneyr-dev/plugins/outpost log --oneline -1`.

**Pre-flight:** sign in via the B0b flow first if not already authenticated. The C0 tab framework only renders for authenticated users — pre-auth, the LoginScreen still renders.

### C0-Stage 1 — Tab strip renders

Open `https://qkf.b0d.myftpupload.com/post/?_cb=<timestamp>`.

**Expected:**

- A horizontal strip of 5 tabs at the top: **Note**, **Reply**, **Photo**, **Listen**, **Article**.
- The **Note** tab is selected (visually distinguished with a slightly heavier border, sitting on top of the panel boundary).
- Below the strip: the Note posting form (textarea + "Post note" + "Sign out" buttons), unchanged from B1.
- Other panels are hidden.

**Stop here if:** the tab strip doesn't render, or the wrong tab is initially selected.

### C0-Stage 2 — Click + tap to switch tabs

1. Tap the **Reply** tab.
2. **Expected:** Reply panel becomes visible — placeholder card titled "Reply" with text "Reply, Like, Repost, Bookmark, RSVP, and Follow modes land in Phase C1." The Note panel is hidden but its state is preserved (text typed into Note's textarea is still there when you switch back).
3. Tap **Photo**, **Listen**, **Article** in turn — each shows a placeholder card naming the Phase C session that lands the real mode.
4. Tap **Note** again — your previously-typed text is still in the textarea. Posting from here still works (B1's flow is untouched).

### C0-Stage 3 — Keyboard navigation (desktop)

If you have a keyboard available (Mac with the iPhone connected via Universal Control, or open the staging URL in desktop Safari):

1. Press Tab until focus lands on a tab in the strip (the focus ring is visible inside the tab — outline-offset is negative so it doesn't clip on the bottom border).
2. **Expected:** focus lands on the currently-selected tab (Note).
3. Press **ArrowRight** — focus + selection move to **Reply**. The previous tab loses `aria-selected`; the new tab gains it.
4. Press **ArrowRight** four more times — focus cycles through Photo → Listen → Article → wraps back to **Note**.
5. Press **ArrowLeft** — wraps to **Article** (last tab).
6. Press **Home** — jumps to **Note**.
7. Press **End** — jumps to **Article**.
8. Press **Tab** — focus moves OUT of the tablist into the active panel (Note's textarea).

This is the WAI-ARIA tabs pattern with automatic activation. iPhone VoiceOver announces tab role + selection state correctly when swiping through the tablist.

### C0-Stage 4 — VoiceOver (iPhone Safari)

If you have a moment with VoiceOver enabled:

1. Triple-click the side button (or however you toggle VoiceOver).
2. Swipe right through the tabs.
3. **Expected announcements:** "Note, tab, selected" → "Reply, tab" → "Photo, tab" → "Listen, tab" → "Article, tab".
4. Double-tap a tab to activate it. **Expected:** the panel's heading announces ("Reply, heading level 2"). The placeholder card content reads through normally.

Skip this if VoiceOver setup isn't convenient — A11Y-CHECKLIST Phase J formalizes the real-device screen reader testing matrix; for now, the unit tests cover the keyboard contract and the rendered ARIA attributes.

## B2 + C1 — Preview endpoint + Reply mode

**Build under test:** `OUTPOST_VERSION` ≥ `0.1.10`. Verify with `git -C ~/projects/staging-courtneyr-dev/plugins/outpost log --oneline -1`.

**Pre-flight:**

1. Confirm staging shows Outpost 0.1.10 in `wp-admin/plugins.php`.
2. You should already be signed in from B0b. If not, run B0b Stages 1–3 first.
3. The simple path is **C1-Stages 1–4 only** — those exercise the B2 endpoint indirectly through the Reply mode UI, which is enough to confirm everything works end-to-end. **B2-Stage 1 is an optional terminal-side smoke** that probes the endpoint contract directly (error paths, anonymous rejection, route hiding). Skip it if curl/terminal isn't comfortable.

### B2-Stage 1 — Preview endpoint sanity check (optional, terminal)

This stage probes the B2 REST endpoint with `curl` to confirm the SSRF defenses, content-type allowlist, and authentication gate behave correctly. Each curl is independent — run whichever ones you care about.

**Step 1 — Get your bearer token from the iPhone's IndexedDB.**

The token is stored encrypted in IndexedDB, so you can't read it directly. The easiest way to surface the plaintext is to **capture it from a real outgoing request** — when you post a Note, the PWA sends `Authorization: Bearer <token>` to the Micropub endpoint, and that header is plaintext in the network log:

1. Plug your iPhone into the Mac. On the iPhone, open `https://qkf.b0d.myftpupload.com/post/`. On the Mac, open Safari → **Develop** → [your iPhone] → [`https://qkf.b0d.myftpupload.com/post/`]. The Web Inspector window opens, attached to the live tab.
2. In the Web Inspector, switch to the **Network** tab. Leave it open.
3. On the iPhone: tap the **Note** tab if it isn't already active, type a few characters, tap **Post note**. Wait for "Posted to: …".
4. In the Mac's Web Inspector Network tab, find the row whose URL ends in `/wp-json/micropub/1.0/endpoint`. Click it.
5. In the right panel, expand **Request Headers**. Find `Authorization: Bearer xyz...`. Copy everything after `Bearer ` (the token itself, no quotes).

**Step 2 — Set the token in your terminal session.**

In a Mac terminal, paste the token into a shell variable so you don't have to retype it for each curl:

```bash
TOKEN="paste-your-token-here"
```

**Step 3 — Run the curl checks one at a time.**

Each command is annotated with what to expect.

**Happy path** — should return JSON with `html`, `finalUrl`, `contentType`. The `head -c 500` truncates the (potentially-large) HTML to the first 500 characters so the terminal isn't flooded.

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://courtneyr.dev/"}' \
  "https://qkf.b0d.myftpupload.com/wp-json/outpost/v1/preview" | head -c 500
```

Expect: a JSON blob beginning with `{"html":"<!DOCTYPE html>...`.

**Bad scheme** — `javascript:` URLs are rejected by the SSRF defense. Should return HTTP 400 with `invalid_scheme`.

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"url":"javascript:alert(1)"}' \
  "https://qkf.b0d.myftpupload.com/wp-json/outpost/v1/preview"
```

Expect: `{"code":"invalid_scheme","message":"Only http and https URLs are allowed.","data":{"status":400}}`.

**Wrong content type** — image URLs return PNG, which isn't HTML. Should return HTTP 415.

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/image.png"}' \
  "https://qkf.b0d.myftpupload.com/wp-json/outpost/v1/preview"
```

Expect: `{"code":"unsupported_content_type",..."status":415,...}`. (Or `fetch_failed` 502 if `example.com/image.png` doesn't actually exist — substitute any real image URL if you want to exercise the 415 path specifically.)

**Anonymous (no Authorization header)** — should return HTTP 401.

```bash
curl -sS -H 'Content-Type: application/json' \
  -d '{"url":"https://courtneyr.dev/"}' \
  "https://qkf.b0d.myftpupload.com/wp-json/outpost/v1/preview"
```

Expect: `{"code":"rest_forbidden",..."status":401,...}`.

**Route hidden from index** — Outpost's preview endpoint is registered with `show_in_index => false`, so it should NOT appear when you list the namespace's public routes:

```bash
curl -sS "https://qkf.b0d.myftpupload.com/wp-json/outpost/v1" | python3 -m json.tool | head -20
```

Expect: a JSON listing that does NOT mention `/preview` in any of the route paths. (If `python3 -m json.tool` isn't available, just pipe to `head` for a raw view.)

**Stop here if:** any curl returns the wrong status code, the happy path returns an empty `html` field, or `/preview` shows up in the public route index. All four checks should match the expected status; the happy path's HTML should be substantive (not "").

### C1-Stage 1 — Reply mode UI

Open `https://qkf.b0d.myftpupload.com/post/?_cb=<timestamp>`. Tap the **Reply** tab.

**Expected:**

- Card titled "Reply" (was a placeholder card before; now a real form).
- "In reply to" label above a URL input (placeholder `https://example.com/post`).
- "Your reply" label above a textarea.
- Two buttons: **Post reply** (primary, disabled until both fields have content) and **Show preview** (secondary, disabled until URL is filled).

### C1-Stage 2 — Preview a target URL

1. Paste a URL of one of your own published posts (or any IndieWeb-shaped post) into the "In reply to" field.
2. Tap **Show preview**.
3. **Expected:**
   - Button briefly says "Fetching…".
   - A citation card appears below the URL input: small "Replying to:" label, the page title in bold, the URL as a clickable link.
   - No errors. If you see `unsupported_content_type` the URL didn't return HTML; try a different page. If you see `unauthorized` your IndieAuth token is stale — sign out and back in.

### C1-Stage 3 — Post a reply end-to-end

1. With a URL filled in, type a reply in the textarea.
2. Tap **Post reply**.
3. **Expected sequence:**
   - "Finding endpoint…" (first post in the session) → "Posting…" → button returns to "Post reply" + status line: "Posted to: `https://qkf.b0d.myftpupload.com/...`".
4. Open the posted URL. **Expected:** the post renders, and on staging WordPress with Post Kinds active it appears as a Reply post type with the `in-reply-to` URL surfaced as the citation. Without Post Kinds, the post is a regular note with the in-reply-to URL stored as post meta.
5. Form should reset (URL + content cleared, preview gone) ready for the next reply.

### C1-Stage 4 — Switch to Note tab + back, verify state preservation

1. Tap **Note** tab — should show NoteMode (textarea, etc.), unchanged from C0.
2. Type a few characters in Note's textarea.
3. Tap **Reply** tab — should still show the Reply form (post-submit, fresh) without losing whatever you typed in Note.
4. Tap **Note** tab again — your typed characters are still there.

State preservation is confirmed by the `hidden`-attribute pattern: panels render eagerly, only visibility toggles, so component state survives switches.

## Reporting

After running, reply with one of:

- "All stages pass" → close the session, queue the next.
- "Stage X failed because Y" + screenshot → triage, ship a fix commit if needed.
- "Got blocked on Z, need a tweak before I can test" → describe the obstacle.

If you only have iOS Chrome at hand: "Stages 1/2/3/5 pass on iOS Chrome, Stages 4 and 6 deferred until I have Android Chrome / iPhone Safari" is an acceptable partial sign-off — the user-visible flow is verified; the inspector + install paths land later.
