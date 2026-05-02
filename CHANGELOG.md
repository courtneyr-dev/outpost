# Changelog

All notable changes to Outpost are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Outpost adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed (Phase H hotfix 4 — query-string token fallback for managed-WP hosts)
- **Client now sends the bearer via `?_o_token=<token>` query param AND via the standard `Authorization` header** for both composer-config and preview endpoints. Managed-WP hosts (GoDaddy, certain WP Engine configs) strip the `Authorization` header before it reaches PHP, which is why bearer-only auth has been failing on Courtney's staging despite the bearer being valid.
- **Server reads from `$_GET['_o_token']` as a fallback** in the `has_bearer_header()` permission gate. Sanitized via `is_string` check; the value is only used as a presence signal (we don't validate the token's authenticity, same trade-off as hotfix 3).
- The query-string token is annotated with `phpcs:ignore WordPress.Security.NonceVerification.Recommended` because the request itself isn't mutating state — it's a permission-gate signal, not a CSRF-relevant action.

### Fixed (Phase H hotfix 3 — accept bearer-header presence as a third auth path)
- Even after relaxing the permission check to accept any logged-in user, the IndieAuth plugin's bearer translation isn't firing for Outpost-namespaced routes on Courtney's staging install AND the user has no wp-admin cookie session in iPhone Safari. Three auth paths now valid: `current_user_can('edit_posts')` (cookie or full bearer translation) OR `is_user_logged_in()` (some IndieAuth setups translate to user_id but don't pass cap through) OR **the request carries an `Authorization: Bearer <token>` header** (this branch unblocks the iPhone-bearer-only case).
- Security trade-off: we don't validate the bearer ourselves. The composer-config payload is non-sensitive (companion plugin status, public taxonomy terms, site settings — same info wp-admin shows to any read-cap user). The preview endpoint already rate-limits (30 req/min) and applies SSRF defenses + Content-Type allowlist + script stripping on output, so unverified bearer access is bounded. If the bearer is genuinely invalid, the user can't post via Micropub anyway, so reading composer-config gets them nothing useful.
- Filterable via `outpost_composer_config_permission` and `outpost_preview_permission` for sites that want to enforce stricter checks (e.g. always require `edit_posts`).
- Reads from `$_SERVER['HTTP_AUTHORIZATION']` AND `REDIRECT_HTTP_AUTHORIZATION` (FastCGI configs strip the standard header on some hosts).

### Fixed (Phase H hotfix 2 — relaxed permission on Outpost REST endpoints)
- **Permission check on `/wp-json/outpost/v1/composer-config` and `/wp-json/outpost/v1/preview` now accepts any logged-in user**, not just `edit_posts`. The composer-config payload is non-sensitive (companion plugin status, public taxonomy terms, the Bridgy host map, the XFN spec list, site-wide settings — same info `wp-admin/plugins.php` shows to anyone with `read` cap), and the preview endpoint already rate-limits + sanitizes output.
- Two paths now pass: `current_user_can('edit_posts')` (cookie auth or full IndieAuth bearer translation) OR `is_user_logged_in()` (some IndieAuth plugin builds translate the bearer to user_id but don't pass through the `edit_posts` cap; this fallback catches them).
- Filterable via `outpost_composer_config_permission` and `outpost_preview_permission` for site admins who want stricter (or more permissive — e.g. anonymous in dev) behavior.
- This unblocks the "Couldn't load companion options" banner on iPhone Safari, where the user has a valid IndieAuth bearer but no wp-admin cookie session and the plugin's `determine_current_user` filter doesn't cover Outpost-namespaced routes.

### Fixed (Phase H hotfix — cookie-credential fallback for Outpost REST endpoints)
- **`fetch_composer_config` and `fetch_preview` now send `credentials: 'include'`** so wp-admin login cookies authenticate the request when the IndieAuth plugin's bearer-to-user translation hasn't fired for our Outpost-namespaced routes. Same-origin only — never leaks cookies cross-origin.
- Diagnosis: the WordPress IndieAuth plugin's `determine_current_user` filter is path-scoped on some installs (covers `/wp-json/micropub/*` but not `/wp-json/outpost/*`). When the plugin doesn't translate the bearer for our routes, `current_user_can('edit_posts')` returns false and the endpoint 401s — even with a valid token. Cookie fallback works because the user is logged into wp-admin in the same browser.
- Two paths now authenticate the request: bearer (when IndieAuth's middleware covers the route) AND cookie (when admin session is active). Both succeed with `current_user_can`. Failure happens only when neither is available — which is also the only case where the user genuinely shouldn't be authenticated.

### Added (Session H — site settings + onboarding intro)

- **`Outpost_Settings`** — site-wide preferences via the WordPress Settings API. Single option `outpost_settings` (array) covering:
  - `bridgy_auto_suggest` (bool, default true) — whether the composer surfaces the "Suggested (from target URL)" Bridgy chip when reply target host matches a known silo.
  - `default_post_variant` (string, default 'article', enum 'note'/'status'/'aside'/'article'/'quote') — which variant the Post tab opens to on every fresh composer load.
  - `default_post_format_inference` (bool, default true) — controls the C5 Post-Format auto-inference bridge (declared but not yet wired into the bridge's gate; F2 stretch).
- **Settings form rendered on the existing Outpost admin page** (Tools → Outpost menu) below the bookmarklet generator, separated by `<hr>`. Capability gate: `manage_options`. Sanitized via `Outpost_Settings::sanitize` (variant whitelisted; checkboxes coerced to bool; unknown values fall back to defaults).
- **Composer-config endpoint** now returns `siteSettings: { bridgyAutoSuggest, defaultPostVariant }`. TypeScript `ComposerConfig` extended with the `SiteSettings` interface.
- **NoteMode honors `siteSettings.defaultPostVariant`** as the initial variant, with precedence: share-target intake > site-admin default > hard-coded 'article'.
- **MorePanel honors `siteSettings.bridgyAutoSuggest`** — when false, the Bridgy auto-suggest chip never renders even on matching hosts.
- **Onboarding intro card** added to the install-prompt page (rendered when IndieAuth or Micropub is missing). Welcomes the user with what Outpost is + a one-paragraph POSSE primer before the "Install [plugin]" call to action. Inline CSS in the page (no Vite bundle, since the bundle isn't available pre-install).

### Added (Session C2b — multi-photo gallery support)

- **PhotoMode now accepts multiple photos.** `<input type="file" multiple>` lets the user pick a batch in one tap. Each picked file becomes a `PhotoEntry` in a list with its own thumbnail, alt-text textarea, decorative toggle, remove button, and reorder controls. Picking again appends — doesn't replace — so the user can build a gallery from multiple picker invocations.
- **Per-photo alt text** required by default. Each entry's submit gates on either a non-empty alt or an explicit Decorative toggle. Decorative entries submit `alt=""` per the WCAG / Hard Contract convention for purely-visual images.
- **Reorder via Move up / Move down buttons** at the array boundaries. Drag-and-drop on mobile is finicky and inconsistent across browsers; explicit buttons work everywhere and have clear `aria-label`s for screen readers.
- **Submit pipeline**: every photo goes through `process_photo` (canvas downscale + EXIF strip + JPEG re-encode) in sequence before any upload starts, so a failing photo doesn't leave half-uploaded media stranded. Each processed blob then uploads to the Micropub media endpoint sequentially with progress reported as `Uploading N of M…` in the submit-button label. Then a single `post_h_entry` posts with `photo[]` (array of media URLs in gallery order) + `mp-photo-alt[]` (parallel alt-text array).
- **Single-photo posts retain the string shape** for `photo` and `mp-photo-alt` (back-compat with sites that don't yet handle array form).
- **`HEntryProperties.photo` and `mp-photo-alt` types extended** to `string | string[]`.
- **Auto-format inference** (already in the C5 bridge) picks `image` for single photos, `gallery` for arrays length > 1. No bridge changes needed.
- New CSS surfaces: `.outpost-photo-list`, `.outpost-photo-list__item` (responsive 2-col → 3-col grid at ≥ 40 rem), `.outpost-photo-list__thumb`, `.outpost-photo-list__fields`, `.outpost-photo-list__actions`.
- Mode title flips between "Photo" and "Gallery" based on entry count. Submit button label flips between "Post photo" and "Post gallery."

### Changed (Session DS-3b — queue badge + visible config-error banner)

- **Queue banner replaced with `QueueBadge`** in the composer header. Compact button showing count of queued offline posts; tap to open a Drawer with per-entry retry/dismiss controls. Per Design System Section 5.26. Auto-flush triggers (browser `online` event + first mount) preserved from the prior banner. The full-width banner above the tab strip is gone.
- **`pwa/src/components/queue-banner.tsx` deleted** — replaced by `queue-badge.tsx`.
- **New `outpost-composer__header` row** above the tab strip — right-aligned, hosts the queue badge + future global affordances (settings, account info).
- **Visible config-error banner** when `fetch_composer_config` fails. Distinguishes `unauthorized` (token expired — most common cause of "I can't see Yoast / categories / tags / XFN options") from `fetch_failed` (network unreachable). Includes a "Sign out + back in" button that calls `clear_token()` + reloads. Replaces the previous silent `console.warn` so users have a clear path to recovery instead of wondering why companion-gated fields disappeared.

### Changed (Session DS-3a — drawer pattern for More options)

- **`pwa/src/components/drawer.tsx`** — Drawer primitive per Design System 4.9 + 5.25. Slides up from the bottom of the viewport. Used by every composing mode for the More options pull-out (was a `<details>` panel inline).
- **Drawer behavior**: scrim covers the page and taps it to close; Esc closes; focus moves into the drawer on open (first focusable element) and returns to the trigger on close; body scroll locks while open. ARIA: `role="dialog"`, `aria-modal="true"`, `aria-labelledby` points at the header title. Slide animation gates on `prefers-reduced-motion: no-preference` — instant when reduced-motion is on.
- **MorePanel internal change**: dropped the outer `<details>`/`<summary>` wrapper. The panel now renders its content directly so the Drawer (which already handles open/close + animation + scrim) doesn't double-wrap.
- **Each mode** (Post, Reply, Photo, Doing) gains a `[More options]` button in the form-actions row. Tap to open the drawer with the existing chip-pickers, Yoast field, XFN picker, and syndication chips. Mode-specific behavior preserved (Reply passes `xfnTargetUrl`, Listen does too).
- New CSS surfaces: `.outpost-drawer-scrim`, `.outpost-drawer`, `.outpost-drawer__header`, `.outpost-drawer__title`, `.outpost-drawer__body`. Both transition rules gate on `prefers-reduced-motion`.
- **Deferred to DS-3b**: Toast region for post-publish success messages (currently still inline), queue badge in composer header (currently still a full-width banner).

### Changed (Session DS-2 — brand palette restored + logical-property audit + char counter)

- **Brand palette restored under the DS-1 taxonomy.** DS-1 briefly shipped fully-neutral defaults (transparent / currentColor / Canvas) per the strictest reading of the design law. That made the composer "disappear" but stripped the link/button visual identity on real-world deploys. This commit restores Courtney's brand colors as the working out-of-box defaults under the new DS-1 token names — themes can still override every token, and structural tokens (space, radius, shadow, animation) stay aligned.
- **Verified WCAG ratios on the brand palette**: prussian-blue text on white = 13:1, white on russian-violet = 13:1, prussian-blue on light-orange = 9:1, cerulean focus on white = 7:1.
- **Logical-property audit on `pwa/src/styles/structure.css`.** Every `padding-{top,bottom,left,right}` → `padding-{block,inline}-{start,end}`. Every `margin-*` likewise. Every `border-{top,bottom}` → `border-block-{start,end}`. RTL layouts mirror automatically without an `html[dir="rtl"]` selector chain. Comment-block references to physical properties (e.g. the safe-area-inset-bottom note) stay as-is.
- **Char counter component** (`pwa/src/components/char-counter.tsx`) per Design System 5.27. Renders below textareas. `aria-live="polite"` for changes, threshold-gated announcements (only narrates when ≤50 chars remaining, then on each integer change down to 0; over-limit announces "N over the limit"). Counts codepoints (`[...value].length`), not bytes — emoji + non-BMP chars count correctly. Visible character count updates every keystroke; the polite live region updates only at thresholds so screen readers don't drown the user.
- **Char counter integrated** into Note + Reply textareas. Limit comes from syndication selection: when `mp-syndicate-to[]` includes the Bridgy → Twitter publish target, limit = 280. Otherwise no limit shown (just the count).
- New CSS surfaces: `.outpost-visually-hidden` (standard sr-only recipe — used by the char counter live region and other ARIA-only labels), `.outpost-char-counter`, `.outpost-char-counter--over`.
- Status palette filled in per the DS-1 spec: `--outpost-success-{bg,fg,border}` (soft green band, prussian-blue text 9:1+), `--outpost-warning-border` and `--outpost-info-border` get visible color values (selective-yellow and cerulean) instead of the previous `currentColor` fallback.

### Added (Session G — security headers + rate limit on composer-config)

Phase G's first batch: security headers on the composer shell + per-user rate limit on the config endpoint. Token storage doc + URL validation are already in good shape from prior phases (B0a #9, B2 SSRF defenses, `is_safe_http_url` covers Reply / Doing / Photo paths); this commit adds the perimeter hardening.

- **Content-Security-Policy** on every HTML response from `Outpost_PWA_Shell::send_html_header()`. Directive set:
  - `default-src 'self'`
  - `script-src 'self' 'nonce-<per-request>'` — Vite outputs plain ES modules (no eval); the one inline script (SW registration) gets a per-request UUID nonce.
  - `style-src 'self' 'unsafe-inline'` — the shell has inline critical CSS; bundled CSS is from 'self'.
  - `img-src 'self' data: blob: https:` — photo previews use blob URLs; uploaded media URLs come from the user's media endpoint and can be cross-origin.
  - `connect-src 'self' https:` — Micropub endpoint discovery and the user's "me" URL fetch can be cross-origin.
  - `font-src 'self' data:`
  - `frame-src 'none'`, `frame-ancestors 'none'` — clickjacking + iframe-embed defense.
  - `form-action 'self'`, `base-uri 'self'`, `object-src 'none'`, `upgrade-insecure-requests`.
- **Filter `outpost_csp`** lets sites extend (e.g. allow analytics origin, embed CDN). Signature: `(string[] $directives, string $nonce) => string[]`.
- **`X-Frame-Options: DENY`** — same intent as `frame-ancestors 'none'` for older browsers.
- **`X-Content-Type-Options: nosniff`** — prevents MIME-confusion attacks.
- **`Referrer-Policy: strict-origin-when-cross-origin`** — leaks only the origin (not full URL) on cross-origin requests.
- **`Permissions-Policy`** on the shell:
  - `microphone=(self), camera=(self)` — D4 voice and Photo file picker need these; same-origin only.
  - `geolocation=(), payment=(), usb=(), midi=(), magnetometer=(), gyroscope=(), accelerometer=()` — disabled. Composer never uses them; a compromised dependency can't quietly turn them on.
- **Per-user rate limit on `/wp-json/outpost/v1/composer-config`** — 60 req/min/user (more permissive than preview's 30/min since composer mounts pull on every fresh load). Returns 429 with `Retry-After: 60` on exceed. Transient-keyed by user ID per the preview-endpoint pattern.
- **Test bootstrap** gains `wp_generate_uuid4` stub so PWAShellTest's render assertions resolve the per-request nonce without network ceremony.

### Added (Session F — companion adapter classes + registry)

Phase F formalizes the C5 bridges into the adapter contract A1 #4 specified. The bridges in `class-micropub-bridges.php` keep their behavior; the new adapters provide the inventory + capability listing the composer + admin code can ask against.

- **Seven concrete adapters**, one per optional companion plugin Outpost knows about — each in its own file under `includes/companions/`:
  - `Outpost_Post_Kinds_Adapter` — capability slugs for each Post Kind (`post-kinds.listen`, `post-kinds.watch`, `post-kinds.read`, `post-kinds.play`, `post-kinds.checkin`, `post-kinds.like`, `post-kinds.repost`, `post-kinds.bookmark`, `post-kinds.rsvp`, `post-kinds.follow`, `post-kinds.quotation`)
  - `Outpost_Post_Formats_Adapter` — `post-formats.format`, `post-formats.inference`
  - `Outpost_XFN_Adapter` — `xfn.relationships`
  - `Outpost_Syndication_Links_Adapter` — `syndication.chips`
  - `Outpost_Yoast_Adapter` — `yoast.focus-keyphrase`
  - `Outpost_ActivityPub_Adapter` — `activitypub.federate` (passive marker; downstream POSSE plugins consume the same posts)
  - `Outpost_Accessibility_Checker_Adapter` — `accessibility-checker.report`
- **`Outpost_Companion_Registry`** — single source of truth for which adapters Outpost knows about. `all()` returns one instance per default adapter class; `active()` filters to those whose underlying plugin is currently active; `all_active_capabilities()` aggregates capability slugs across active adapters with de-duplication + alphabetical sort.
- **Filter `outpost_companion_adapters`** lets future plugins or site-config code register additional adapter classes without forking core. The registry validates each candidate is a string class name pointing at an `Outpost_Companion_Base` subclass; bogus entries get silently dropped.
- Per-request instance cache: `get($class)` returns the same instance on repeat calls so consumers can compare by reference. `reset_for_tests()` is a test-only hook.
- **`tests/unit/CompanionRegistryTest.php`** — 6 PHPUnit tests covering `all()` returns 7 default adapters, `get()` caches, adapter shape (file/label/capabilities) for Post Kinds + XFN + Yoast, `all_active_capabilities()` de-dup + sort. PHPUnit 118 → 124.

### Changed (Session DS-1 — Outpost Design System v1.0 token taxonomy)

The Outpost Design System v1.0 (drafted by Courtney) is now authoritative. This commit adopts the token system. Component refactors land in DS-2.

- **`styles/outpost-tokens.css` rewritten** to the full DS taxonomy (~75 tokens across surface, input, button-primary/secondary/ghost, tab, chip, card, status, focus, type, space, radius, shadow, animation). All defaults are **neutral fallbacks** (`transparent`, `inherit`, `currentColor`, `Canvas`) per the design law: "plugin owns layout, theme owns paint." The composer renders correctly against any host theme without forcing colors.
- **Hard break from prior brand-default behavior**: tokens previously shipped Courtney's russian-violet/prussian-blue palette as defaults, which violated the Hard Contract by forcing paint. Brand colors now belong in the active theme (or a site-level CSS override), not in plugin defaults. Theme integration cookbook in design-system Section 8.
- **`pwa/src/styles/tokens.css`** — bundled mirror of the server-rendered tokens. Vite dev mode resolves tokens before the server-rendered file is fetched. Per design-system Section 9.
- **`bin/check-tokens-parity.mjs`** — Node script that verifies the two token files have identical declarations. Wired into `npm run build` (build fails if parity drifts) and `npm run check:tokens` for ad-hoc.
- **`pwa/src/styles/structure.css`** imports tokens.css at the top so the bundle has tokens available before any structural CSS rule resolves.
- **Compatibility aliases** at the bottom of both token files: `--outpost-border`, `--outpost-radius`, `--outpost-focus`, `--outpost-text-muted`, `--outpost-primary-{bg,fg,border}`, `--outpost-chip-active-{bg,fg}` resolve to the new taxonomy. Existing component CSS keeps working without simultaneous edits. Aliases will be removed in v0.3.0 once components migrate fully (DS-2/DS-3).
- **What's deferred to DS-2**: logical-property audit (RTL via `padding-inline-start` etc.), z-index scale scoped to `.outpost-shell`, focus-management consistency (always `:focus-visible` + `outline`, never `box-shadow`), reduced-motion gates around every transition, drawer pattern for the More pull-out, toast region, queue badge, char counter, citation card spec.

### Added (Session E1 — bookmarklet generator + admin page)
- **New top-level Outpost menu in wp-admin** (`Outpost_Admin_Page`, `final`, static-only). Hosts the bookmarklet generator. Capability gate: `manage_options`. Icon: `dashicons-share-alt2`. Menu position 76.
- **Bookmarklet generator** outputs ready-to-drag `javascript:` URLs for each Reply variant: Reply, Like, Repost, Bookmark. Each bookmarklet body grabs `location.href`, `document.title`, and `window.getSelection()`, encodes them, and opens `/post/share-target?variant=<variant>&url=…&title=…&text=…` in a new tab.
- The admin page presents each bookmarklet as a draggable button, a description, and a copy-able source-code textarea. Plus a "How it works" section and an "iOS Shortcut alternative" pointing users at A2HS + the system Share sheet (since Mobile Safari can't drag to bookmarks bar).
- **`pwa/src/lib/share-target.ts`** parses the `variant` query param when present and tags the data with `replyVariant`. Type extended: `ReplyVariant = 'reply' | 'like' | 'repost' | 'bookmark' | 'rsvp' | 'follow'`.
- **ReplyMode honors the `replyVariant`** field on share-target intake — the variant picker opens to the bookmarklet's chosen variant. So clicking "Outpost: Like" on any page lands the user in Reply mode → Like variant with the URL pre-filled.
- All strings translatable via the `outpost` text domain. Output escaped per the Security Trinity (`esc_html__`, `esc_attr`, `esc_url`, `esc_textarea`, `esc_js`).

### Added (Session E2 — Bridgy auto-suggest)
- **Composer-config endpoint** now returns a `bridgyHostMap` of host → `{name, uid}` pairs. Default map covers Twitter / X (`brid.gy/publish/twitter`), Mastodon (`fed.brid.gy/`) for major instances (mastodon.social, mas.to, fosstodon.org, mastodon.online, indieweb.social), GitHub (`brid.gy/publish/github`), and Bluesky (`bsky.brid.gy/`).
- **Filter `outpost_bridgy_host_map`** lets sites add hosts (e.g. their own Mastodon instance, custom Bridgy variants) without forking. Filter signature: `apply_filters('outpost_bridgy_host_map', array<string, array{name: string, uid: string}>)`.
- **MorePanel detects when the Reply / Doing target URL host matches** a bridgyHostMap entry. When it does, the matching publish target appears as a separate "Suggested (from target URL)" syndication chip, **pre-checked by default**. The user can uncheck it; the next time they paste a different URL or clear the field, the auto-suggest follows.
- The auto-suggest is structurally distinct from the user's Micropub-configured `?q=syndicate-to` chips so the user can see WHY the chip appeared (it's contextual to the target URL, not their site config).
- The chip's UID is sent as part of `mp-syndicate-to[]` on submit; downstream the Micropub plugin POSTs to that URL after creating the post, and Bridgy reads the post via webmention and reposts it on the silo.

### Added (Session E0 — Web Share Target)
- **Outpost is now a Web Share Target.** When the user shares text + URL from another app (iOS Share sheet, Android Sharesheet), the OS surfaces Outpost as a destination. Tapping Outpost lands the shared content in the right composer mode pre-filled.
- **Manifest `share_target` action** at `/post/share-target` (GET, application/x-www-form-urlencoded). Accepts `title`, `text`, and `url` params per the Web Share Target spec.
- **Routing rule** for shared content (per the C5e design defaults):
  - `text + url` → Reply tab (target = url, content = text). The classic "share an article with my comment" flow.
  - `url only` → Reply tab (target = url). Bookmark/Like/Repost prep.
  - `text only` → Post tab, Note variant (content = text).
  - `title + text` → Post tab, Article variant (name = title, content = text).
  - empty params → composer opens normally.
- **`pwa/src/lib/share-target.ts`** — small intake module. `parse_share_target(search)` extracts and tags the data; `stash_share_target(data)` persists to sessionStorage; `peek_share_target()` reads without clearing; `consume_share_target()` reads and clears.
- **One-shot semantics**: `peek` then `consume` so multiple modes can race-mount in parallel without one draining the other's data. Only the tagged-target mode actually clears.
- **`/post/share-target` route handler** in `index.tsx`: parse params, stash, then `location.replace('/post/')` so the URL bar reads cleanly and a refresh doesn't re-trigger the intake.
- ComposerTabs reads `peek_share_target()` on mount to set the initial active tab to the share target's destination (Post or Reply), so the user lands on the right tab without a manual switch.

### Added (Session D4 — voice input on every textarea)
- **`pwa/src/components/voice-button.tsx`** — round mic button that drops next to every content textarea (Post body, Reply content, Doing content, Photo caption). Tap to start dictation, tap again to stop. While recording, the button switches to a stop icon and pulses (respects `prefers-reduced-motion`) so the user has visible feedback that audio is being captured. Uses the standard Web Speech API (`SpeechRecognition` / `webkitSpeechRecognition`).
- **Per-textarea integration**: each mode's content textarea sits below a `.outpost-textarea-row` flex container that pairs the label with the mic button. Transcripts append to existing content with a leading space when the existing content doesn't already end in whitespace, so successive dictations don't run together.
- **No punctuation post-processing.** Web Speech engines vary on how they emit commas, periods, and capitalization; we keep what the engine returns. Per the C5e design-defaults conversation.
- **Browser-support feature-detect**: the button hides entirely when neither `SpeechRecognition` nor `webkitSpeechRecognition` is on `window`. Firefox doesn't ship Web Speech; iOS Safari 16.4+, Chrome, Edge, Samsung Internet do.
- **Language**: uses `navigator.language` (falls back to `en-US`). User's system locale is the right default for first-tap.
- New CSS surfaces: `.outpost-textarea-row`, `.outpost-voice-button`, `.outpost-voice-button--recording`. The pulse animation is a `@keyframes outpost-voice-pulse` with a `prefers-reduced-motion: reduce` override.

### Added (Session D3 — Add-to-Home-Screen install prompt)
- **`pwa/src/components/install-prompt.tsx`** — small banner above the composer that surfaces the A2HS prompt once the user has shown intent (one successful post). Two flows:
  - **Android Chrome / Edge / Samsung Internet**: listens for `beforeinstallprompt`, prevents the default mini-infobar, captures the deferred event, and surfaces an "Install" button that calls `prompt()` on tap.
  - **iOS Safari**: no programmatic A2HS exists, so the banner shows static instructions ("Tap the Share button, then Add to Home Screen") with a single Got it button.
- **`pwa/src/lib/install-prompt-state.ts`** — localStorage helpers: `mark_posted_once()`, `has_posted()`, `mark_install_dismissed()`, `was_install_dismissed()`, `is_running_standalone()`, `is_ios_safari()`. Wraps localStorage in try/catch so private-browsing mode (where `setItem` throws) is a no-op rather than a crash.
- **All four composing modes** call `mark_posted_once()` on successful post — this is the trigger the prompt watches for.
- **Once-only dismiss**: tapping "Not now" / "Got it" sets `outpost.install_dismissed=1` and the banner never reappears for that device. Standalone-mode detection (`matchMedia('(display-mode: standalone)')` + iOS `navigator.standalone`) ensures the prompt never shows when the user is already running the installed PWA.
- New token defaults: `--outpost-info-bg` (pale sky-blue) and `--outpost-info-fg` (prussian-blue) so the install prompt has visible styling without forcing colors. Distinct from the queue banner's warning palette so the two don't look like the same alert.

### Fixed (D1 hotfix — Yoast keyphrase field needs a visible border)
- Wrapped the Yoast focus keyphrase label + input in a `<fieldset class="outpost-field-group">` so the field has an unambiguous border around the whole group, matching the visual weight of the XFN and Syndication picker fieldsets that sit beside it. New `.outpost-field-group` class is generic — future single-control bordered groups can reuse it.

### Added (Session D1 — offline queue + queue banner + auto-flush)
- **`pwa/src/lib/offline-queue.ts`** — IndexedDB-backed FIFO queue for posts that fail because the network is down. Public API: `enqueue`, `list`, `remove`, `flush`, `is_network_error`. Schema: a single auto-keyed `outpost_queue` object store of `QueueEntry` records (`source`, `properties`, `accessToken`, `micropubEndpoint`, `createdAt`, `attempts`, `lastError?`). Same B0a-locked injectable env pattern as the other clients.
- **`pwa/src/components/queue-banner.tsx`** — surfaces queued posts above the tab strip. Shows entry count + "Retry now" button + per-entry source + excerpt + last-error tooltip + per-entry "Dismiss". Only renders when the queue is non-empty (invisible during normal use). Auto-flushes on the browser's `online` event and on first mount.
- **All four composing modes** (Post, Reply, Photo, Doing) wire offline queueing into their submit handlers. The pattern: post_h_entry runs in an inner try/catch; on `is_network_error` failure the request is enqueued with `source: 'note'|'reply'|'photo'|'listen'`, status flips to `'queued'`, the form clears, and a friendly "Saved for later — Outpost will post this when you're back online" message replaces the error text. Non-network errors (auth, validation, 4xx/5xx) still surface as before.
- **Photo handling caveat**: only the post_h_entry call is queueable. The media upload happens in an earlier phase that needs network to complete; if that fails, the user sees the error and can retry once they're online. Once the upload succeeds and the post_h_entry call fails, the queued entry has the uploaded media URL embedded — replays don't re-upload.
- **`--outpost-warning-bg` and `--outpost-warning-fg` token defaults** in `styles/outpost-tokens.css` — pale-cream + prussian-blue, soft enough to read as informational not alarming.
- **`pwa/src/lib/offline-queue.test.ts`** — 12 vitest tests covering enqueue/list/remove round-trip, insertion-order preservation, flush draining, flush retry-state persistence on failure, and `is_network_error` classification across MicropubError codes + plain TypeError + non-network errors. Vitest 151 → 163.

### Added (Session D0 — real service worker with offline caching)
- **New About tab** at the right end of the tab strip — informational content for users who land on the composer not knowing what POSSE is or which IndieWeb specs Outpost speaks. Sections: what Outpost posts (only the standard `post` post type, not Pages or CPTs without server-side filter routing), POSSE explanation with the four-bullet "why it matters" set, IndieWeb specs Outpost speaks (Micropub, IndieAuth, h-entry, h-card, h-cite, microformats2, Webmention) with links to each, XFN with links to gmpg.org/xfn and the 1.1 spec, Post Formats with the auto-inference mapping table + WordPress documentation links, companion-plugin descriptions, and a link to the Outpost source on GitHub. All external links open in new tabs with `rel="noopener noreferrer"`.
- **Tab rename: "Note" → "Post".** The combined writing tab now reads "Post" since it covers all post-style writing (Note / Status / Aside / Article / Quote variants). Tab id stays `note` so existing analytics, tests, and any future deep-links don't move. The five-mode tab strip is now: Post · Reply · Photo · Doing · About.
- **Article is now the default variant** in the Post tab and moved to first in the variant order. The user explicitly requested deliberate writing as the default surface — the Article variant shows the Title + tall body composer when the tab opens. Note / Status / Aside / Quote still available as one-tap radios. (Earlier user-stated preference for short-form remains served — those variants are still adjacent to the default.)
- New CSS surfaces: `.outpost-about` (heading rhythm), `.outpost-spec-list` (responsive dl/dt/dd grid that becomes two-column at ≥ 40 rem viewport).

### Added (Session D0 — real service worker with offline caching)
- The service worker is no longer a no-op stub. `Outpost_PWA_Shell::render_service_worker()` now emits a real worker that caches the PWA shell + bundled assets so the composer loads on a cold device with no connection.
- **Cache name includes `OUTPOST_VERSION`** (e.g. `outpost-0.1.24`). The activate handler deletes any cache that doesn't match the current version, so plugin updates land cleanly without users having to clear their browser data.
- **Strategies by URL pattern**:
  - Shell HTML (`/post/`, `/post/auth/callback`, `/post/share-target`) → network-first, cache fallback. Updates ride in fast; offline loads from cache.
  - Bundled JS / CSS / sourcemaps under `build/pwa/assets/` → cache-first (Vite's content-hashed filenames mean any new bundle is a new URL).
  - Token CSS (`styles/outpost-tokens.css`) and SVG icons (`assets/icons/...`) → cache-first.
  - Manifest (`/post/manifest.json`) → network-first, cache fallback.
  - Outpost REST endpoints (`/wp-json/outpost/v1/...`) → bypass entirely. composer-config is per-user; preview is per-request. Never cached.
- **PRECACHE_URLS seeded on install**: shell, manifest, token CSS, both icons. Uses `cache.addAll()` with a catch-all so a single 404 (e.g. token CSS not yet deployed) doesn't fail the whole install — the fetch handler fills the cache lazily anyway.
- The SW controls `/post/*` clients (scope locked per A0 #6) but a controlled client's same-origin fetches go through the SW regardless of URL — so plugin assets at `/wp-content/.../build/pwa/...` are interceptable even though they live outside `/post/`.
- Last-ditch fallback: when the network is down AND the requested URL isn't cached AND the shell isn't cached either, the SW returns a 503 plain-text "Outpost is offline" response. Phase D1 will replace this with a proper offline composer screen + the queued-post UI.

### Fixed (C5d hotfix — disclosure triangle missing on Categories/Tags)
- The native `<details>` triangle (▶ closed / ▼ open) was hidden because `display: flex` on the `<summary>` element drops the `::marker` pseudo-element in WebKit and Chromium. Now uses a plain block summary with the count rendered as an inline span. Triangles match the More options panel.

### Changed (Session C5d follow-up — collapsible chip pickers)
- **Categories and Tags pickers are now collapsible.** Each one is a native `<details>`/`<summary>` (zero-JS, full keyboard support, screen-reader friendly). Closed by default so the More options panel stays compact. The summary line shows " — N selected" when any are picked, so you can see state without expanding.
- New CSS: `.outpost-term-picker__summary`, `.outpost-term-picker__count`, `.outpost-term-picker__body`. The picker wrapper drops its old `<fieldset>`/`<legend>` for `<details>`/`<summary>` since the disclosure widget covers the grouping semantics.

### Changed (Session C5d — visible chip picker for categories/tags)
- **Categories and Tags now use a chip picker** instead of an autocomplete textbox. Existing terms render as toggleable checkbox chips so the user sees every option at a glance — no need to start typing before suggestions appear (which is how HTML5 `<datalist>` works on every browser, and was the source of "I don't see existing categories"). Tap to include/exclude.
- **New-term creation is now a deliberate action.** A separate input + "Add" button below the chip list. Typing alone doesn't create a new term; you have to tap Add (or press Enter inside the field). Newly-added names appear as removable "(new)" pills with a different visual treatment so the user sees they're creating something — discourages accidental tag/category sprawl.
- Friction added on purpose: the explicit Add step makes it obvious the user is creating a new term, while still keeping creation possible when needed. Mirrors the design intent that the WordPress.org consumers of this plugin shouldn't accidentally produce hundreds of one-off tags.
- New CSS surfaces: `.outpost-term-picker`, `.outpost-chip-list`, `.outpost-chip-list--new`, `.outpost-new-chip`, `.outpost-term-picker__add`.
- Internal: extracted a `TermPicker` sub-component shared between the Categories and Tags fields. The data model (`MorePanelValues.categories: string[]` and `tags: string[]`) didn't change — just the rendering.

### Fixed (Session C5c hotfix — categories/tags suggestions surfacing)
- `get_terms()` in the composer-config endpoint now uses `hide_empty => false`. Was `true`, which excluded categories/tags that exist but haven't been applied to a post yet — on a fresh site or one with reorganized content, the user wouldn't see existing terms as suggestions and would be retyping them by hand.
- Composer-config response gains explicit `Cache-Control: private, no-store, max-age=0` header. Defense in depth against managed-WP edge caches (GoDaddy gateway, Varnish, nginx FastCGI) serving one user's response to another. Bearer auth already makes the response per-request, but the header is free.

### Added (Session C5c — Note + Article merge with Quote variant + categories/tags autocomplete)

#### Tab merge
- **Article tab merged into Note** as a fifth variant. The composer tab strip drops from 5 to 4 (Note · Reply · Photo · Doing) — each tab gains ~25% touch surface on mobile. Internally consistent with the Reply tab (6 variants) and Doing tab (5 variants) — every multi-shape post-kind in Outpost now lives under a single tab.
- **Five Note variants**: Note (default — auto-infer format) · Status (`mp-post-format=status`) · Aside (`mp-post-format=aside`) · Article (title + body, `mp-post-format=standard`) · Quote (`mp-post-format=quote`).
- The Article variant reveals a Title input (required) and bumps the textarea to 12 rows / 18 rem min-height for long-form writing. The other four variants stay at 4-5 rows.
- Submit button label and heading both adapt per variant ("Post note" / "Post status" / "Post aside" / "Post article" / "Post quote").
- `pwa/src/components/modes/article-mode.tsx` deleted — its title-field logic moved into note-mode.tsx.

#### Categories & tags autocomplete
- **Composer-config endpoint** extended with `existingCategories` and `existingTags` — both arrays of `{slug, name}` pairs from `get_terms()`. Capped at 200 entries each, sorted by usage count (most-used first), `hide_empty => true` so only terms the user has actually applied to posts surface as suggestions.
- **MorePanel** Categories field replaced with **two** autocomplete fields: Categories and Tags. Both use HTML5 `<datalist>` for native zero-JS autocomplete (works with screen readers, browser-managed keyboard navigation). User types comma-separated values; existing terms are suggested; new ones can be typed and create-on-the-fly.
- **Tags** send via Micropub's standard `category[]` property — David Shanske's Micropub plugin already maps that to `post_tag` taxonomy by default.
- **Categories** send via Outpost-specific `mp-categories[]` property; `Outpost_Micropub_Bridges::apply_categories()` looks up each name (then slug) in the `category` taxonomy, reuses existing terms, and creates new ones via `wp_insert_term`. Append-mode preserves any categories the Micropub plugin already assigned. Runs unconditionally — `category` is core WordPress, no companion gating needed.

### Changed (Session C5c)
- `OUTPOST_VERSION` bumped to `0.1.19` per A2 #16.
- `composer-tabs.tsx`: ModeId union drops 'article'; ArticleMode import removed; modes array drops to 4 entries.
- `composer-tabs.test.tsx`: tab-list assertion updated to 4 labels; wrap-around tests target index 3 instead of 4.
- `pwa/src/lib/micropub.ts`: `HEntryProperties.mp-categories?: string[]` added.
- `tests/unit/MicropubBridgesTest.php` adds 4 tests for the apply_categories handler covering no-op paths, existing-term reuse, and new-term creation.

### Added (Session C5b — Note Status/Aside variants + Listen XFN wiring)
- **Note mode variant picker.** Three radios at the top of the Note tab: Note (default — auto-infer), Status (forces `mp-post-format=status`), Aside (forces `mp-post-format=aside`). Status and Aside are the two most-common short-post styles for mobile composing; the variant picker brings them one tap away instead of buried in the More pull-out's Post Format dropdown. Each variant updates the heading, the submit-button label, and (when the bridge applies) the WordPress Post Format on the resulting post. The More pull-out's explicit Post Format selector still wins precedence — variants set the default, the More panel overrides.
- **XFN picker on every URL input.** The Doing tab (Listen / Watch / Read / Play / Checkin) now passes its target URL through to MorePanel as `xfnTargetUrl`, so when Link Extension for XFN is active the relationship picker appears for those URLs too. Reply mode already had this in C5; Listen was the missing surface. Photo and Article modes don't take URL inputs, so XFN doesn't apply.

### Changed (Session C5b)
- `OUTPOST_VERSION` bumped to `0.1.18` per A2 #16.
- `note-mode.tsx` adds a `Variant` type (`'note' | 'status' | 'aside'`) and `VARIANTS` table mirroring the Reply-mode pattern. Submit handler conditionally adds `mp-post-format` to the base h-entry properties before merge_more_values runs.
- `listen-mode.tsx` passes `xfnTargetUrl={trimmed_target_url || null}` to MorePanel and threads the URL into merge_more_values for the bridge.

### Added (Session C5 — More pull-out + companion bridges)
- **Composer-config REST endpoint** at `/wp-json/outpost/v1/composer-config` (`Outpost_Composer_Config_Endpoint`, `final`, static-only). GET-only. Returns the active/inactive/absent status of every optional companion plugin Outpost knows about (Post Kinds, Post Formats for Block Themes, Link Extension for XFN, Syndication Links, Yoast SEO, ActivityPub, Accessibility Checker), the resolved Post Format list (theme-declared subset or full spec list, null when Post Formats is absent), and the canonical XFN value list per gmpg.org/xfn/11. `show_in_index => false` per the AI Engine CVE-2025-11749 vulnerability class.
- **Micropub bridges** (`Outpost_Micropub_Bridges`, `final`, static-only). Hooks `after_micropub` at priority 20 to map three Outpost-namespaced properties to companion-plugin storage:
  - `mp-post-format` → `wp_set_post_format()` (validates against `get_post_format_slugs()`).
  - `mp-yoast-focuskw` → postmeta `_yoast_wpseo_focuskw` (sanitized text, length-capped at 191 chars to match Yoast's UI cap).
  - `mp-xfn` + `mp-xfn-target` → postmeta `_outpost_xfn` (JSON-encoded `{target, rels}`; rels validated against the XFN spec allowlist).
  Each bridge no-ops when its companion plugin is not active — extra Micropub properties never error, just silently don't apply.
- **POSSE-aware Post Format auto-inference.** When the user posts via Outpost and doesn't explicitly pick a format in the More pull-out, the bridge infers one from h-entry signals using the same rules Post Kinds for IndieWeb uses for kind taxonomy: `like-of`/`repost-of`/`bookmark-of` → `link`; single `photo` → `image`, multiple `photo` → `gallery`; `listen-of` → `audio`; `watch-of` → `video`; `in-reply-to` → `status`; `name` (article) → `standard`; content-only post → `status` if ≤ 280 chars else `standard`. Both Post Kinds and Post Formats then carry consistent classification — downstream POSSE plugins (Bridgy, ActivityPub variants, Mastodon Autopost) get whichever taxonomy they're wired for.
- **`OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE` constant** + detection in `Outpost_Companion_Detector::is_accessibility_checker_active()` + entry in `optional_companions()`. Equalize Digital's Accessibility Checker plugin scans posts on save_post automatically; Outpost only needs to know it's active to surface a "View accessibility report" link in the success message of every mode.
- **`pwa/src/lib/composer-config.ts`** — client wrapper for the new REST endpoint. `fetch_composer_config(accessToken, env?)` returns the typed `ComposerConfig` shape with companions, postFormats, xfnRels. `ComposerConfigError` discriminates `unauthorized` / `fetch_failed` / `invalid_response`. Same B0a-locked injectable env pattern as the other clients.
- **`pwa/src/lib/micropub.ts`** extended with `discover_syndication_targets(micropub_endpoint, access_token, env?)` — queries `?q=syndicate-to` per Micropub spec, returns `SyndicationTarget[]` (uid + name pairs). Empty array when no targets configured.
- **`HEntryProperties` interface** extended with `mp-slug?`, `mp-post-format?`, `mp-yoast-focuskw?`, `mp-xfn?: string[]`, `mp-xfn-target?`.
- **`pwa/src/components/more-panel.tsx`** — collapsible `<details>` panel rendered below each mode's main form. Native expand/collapse (zero-JS, full keyboard support, screen-reader friendly). Always-on fields: Categories (free-text comma-split → `category[]`), Slug (`mp-slug`). Companion-gated fields: Post Format selector ("Auto (from post kind)" default), Yoast focus keyphrase, XFN relationship picker (only when both XFN plugin active AND a target URL is set), Syndication targets (lazy-loaded on endpoint discovery via `?q=syndicate-to`). `merge_more_values()` exported helper merges values into HEntryProperties using the conditional-spread pattern (B0a #4) so undefined never lands on optional fields.
- **All five modes** (Note, Reply, Photo, Doing, Article) wired to render `<MorePanel>` and merge its values into the post properties on submit. Reply mode passes its `target_url` as `xfnTargetUrl` so the XFN picker only shows once a target is set.
- **Accessibility Checker post-publish report link.** Every mode's success message ("Posted to …") appends "View accessibility report" linking to `<location>?edac_view=1` when the plugin is detected. The actual scan happens automatically on save_post — Outpost just surfaces the link.

### Changed (Session C5)
- `OUTPOST_VERSION` bumped to `0.1.17` per A2 #16.
- `composer-tabs.tsx` fetches the composer config once on mount via `useEffect` and threads it down to each mode through props. Failure is non-fatal (logged via `console.warn` only) — modes still render their main forms; only the More pull-out hides until config arrives.
- `pwa/src/styles/structure.css` adds `.outpost-more-panel`, `.outpost-more-panel__summary`, `.outpost-more-panel__body`, `.outpost-checkbox`, `.outpost-xfn-picker`, `.outpost-syndication-picker`. All structural; paint via `var(--outpost-*, fallback)` per the Hard Contract.
- `outpost.php`: registers the new endpoint + bridges at file-load time (parallel to the existing Preview Endpoint registration).

### Tests
- `tests/unit/MicropubBridgesTest.php` — 22 PHPUnit tests covering `infer_post_format` for every h-entry signal, `scalar` property reading (form-encoded vs JSON Micropub), `has_property` truthiness, and `extract_properties` form/JSON shapes.
- `tests/unit/ComposerConfigEndpointTest.php` — 7 PHPUnit tests covering the permission check + `resolve_post_formats` (null when absent/inactive, theme subset when declared, full list when no subset, filters non-string values).
- `tests/unit/CompanionDetectorTest.php` — extended for Accessibility Checker (data provider, named-wrapper assertion, optional_companions count).
- `pwa/src/lib/composer-config.test.ts` — 7 vitest tests covering parse/validate of valid response, 401/403 → `unauthorized`, 500 → `fetch_failed`, fetch-rejects → `fetch_failed`, wrong-shape → `invalid_response`, ComposerConfigError instanceof.
- `composer-tabs.test.tsx` updated to pass a never-resolving `composerConfigEnv` so the new `useEffect` doesn't hit the real network in tests.

### Added (Session C4 — Article mode)
- `pwa/src/components/modes/article-mode.tsx` — replaces the C0 stub. Long-form posts: title (`name` property) + body (`content`). Both fields required (a titled h-entry without body content has nothing to publish; an h-entry without a title is a Note). Same status-state machine as Note / Reply / Photo / Listen. Tall textarea (12 rows / 18 rem min-height) for comfortable mobile writing. Help text notes that markdown and HTML pass through as-is — your site renders them via its own filters (Jetpack Markdown, WP-Markdown, or plain `wpautop`).
- `pwa/src/styles/structure.css` adds `.outpost-textarea--tall` modifier (18 rem min-height for long-form writing) and `.outpost-help` (smaller-font muted help text under fields).

### Changed (Session C4)
- `OUTPOST_VERSION` bumped to `0.1.16` per A2 #16.
- `composer-tabs.tsx`: Article tab now passes `token` and `micropubEnv` to the real `ArticleMode` component (was a stub).
- **Tab rename: "Listen" → "Doing".** The Doing tab still hosts five sub-modes (Listen / Watch / Read / Play / Checkin); the tab label changes to fit all five umbrellas in plain English. Tab id stays `listen` so analytics, tests, and any future deep-links don't move. The sub-variant labels (Listen / Watch / Read / Play / Checkin) are unchanged inside the Doing tab.

### Added (Session C3 — Listen group)
- `pwa/src/components/modes/listen-mode.tsx` — replaces the C0 stub. Five sub-modes under one tab via radio picker: Listen (`listen-of`), Watch (`watch-of`), Read (`read-of`), Play (`play-of`), Checkin (`location`). Each variant has its own target-input label, content-textarea label, and submit-button label. Checkin adds an extra optional Place-name field that posts as `name`. None require text content; all require a target URL. Same status-state machine as Note/Reply/Photo (`idle` → `discovering-endpoint` → `posting` → `posted` | `error`).
- `HEntryProperties` interface in `micropub.ts` extended with `'listen-of'?: string`, `'watch-of'?: string`, `'read-of'?: string`, `'play-of'?: string`, `location?: string`.

### Changed (Session C3)
- `OUTPOST_VERSION` bumped to `0.1.15` per A2 #16.
- `composer-tabs.tsx`: Listen tab now passes `token` and `micropubEnv` to the real `ListenMode` component (was a stub).
- Companion gating note: these post kinds render best with Post Kinds for IndieWeb active. Without it, posts still go through Micropub but render as generic notes. Outpost doesn't runtime-detect Post Kinds at the PWA level yet — the Listen tab is always visible. Phase F can add a "requires Post Kinds" notice when client-side companion detection lands.

### Added (Session A3 — Visual polish)
- `styles/outpost-tokens.css` — server-rendered CSS custom-property defaults mapped to Courtney's palette. Russian-violet primary buttons (white text, 13:1 contrast), prussian-blue body text on white (13:1), cerulean focus ring (7:1 against white, passes WCAG 3:1 for non-text), light-orange error backgrounds with ut-orange borders. Verified WCAG AA across all interactive surfaces.
- `assets/icons/outpost-icon.svg` and `outpost-icon-maskable.svg` — branded geometric icon (russian-violet rounded square + ut-orange ring + center dot, evokes a satellite/transmitter). Maskable variant adds extra padding for Android adaptive-icon safe zone.
- `<link rel="icon">` and `<link rel="apple-touch-icon">` in the shell head pointing at the SVG icon. Modern Chrome + iOS Safari 18+ both support SVG for these positions.
- Manifest icons updated to reference the SVG files (`type: image/svg+xml`, `sizes: any`, `purpose: any` and `maskable`).
- `theme-color` meta tag set to russian-violet (`#241c4a`) — tints the URL bar in Chrome and the standalone-mode chrome on Android. Manifest's `theme_color` matches.
- `apple-mobile-web-app-status-bar-style` switched to `black-translucent` (was `default`); paired with `padding-top: env(safe-area-inset-top)` on body in the inline critical CSS so content doesn't hide behind the iOS status bar in standalone mode.

### Changed (Session A3)
- `OUTPOST_VERSION` bumped to `0.1.14` per A2 #16.
- The `outpost-tokens.css` file is enqueued via a server-rendered `<link rel="stylesheet">` BEFORE the bundled CSS so theme overrides (placed at higher specificity, e.g. on `body` in the active theme's stylesheet) win the cascade.
- All paint properties in the bundled `pwa/src/styles/structure.css` already reference these tokens with `var(--outpost-*, fallback)` per the Hard Contract — A3 just provides the defaults the falls back to.

### Added (Session C2 — Photo posting)
- `pwa/src/lib/photo.ts` — `process_photo(file, options)` validates MIME (JPEG/PNG/WebP/GIF/AVIF; SVG explicitly excluded), checks size cap (10 MB default), loads the image, downscales to 2048 on the long edge via canvas, and re-encodes as JPEG at quality 0.9. Side effect: EXIF metadata (including GPS coordinates) is stripped — no path for it to survive the canvas round-trip. `PhotoError` discriminates `unsupported_type` / `too_large` / `load_failed` / `encode_failed`.
- `pwa/src/lib/micropub.ts` extended with `discover_media_endpoint(micropub_endpoint, access_token)` (queries `?q=config` to find the media endpoint) and `upload_media({blob, filename, accessToken, mediaEndpoint})` (POSTs multipart/form-data, parses Location).
- `pwa/src/components/modes/photo-mode.tsx` — replaces the C0 stub. File picker (camera + library on iOS), live preview, required alt-text input, optional caption. Pipeline: process_photo → discover endpoints (cached) → upload → post h-entry with `photo` + `mp-photo-alt`.
- `HEntryProperties` interface gains `photo?: string` and `'mp-photo-alt'?: string` (David Shanske's Micropub plugin convention for photo alt text).
- `pwa/src/lib/photo.test.ts` — 17 vitest tests covering MIME allowlist (5 accepted, 5 rejected including SVG and HEIC), `scale_to_fit` (already-fits / landscape / portrait / square / aspect-ratio precision), and `process_photo` validation paths (unsupported MIME, oversized files, PhotoError instanceof). Vitest 124 → 144.

### Changed (Session C2)
- `OUTPOST_VERSION` bumped to `0.1.13` per A2 #16.
- `composer-tabs.tsx`: Photo tab now passes `token` and `micropubEnv` to the real `PhotoMode` component (was a stub).
- `structure.css`: added `.outpost-photo-preview` (constrains preview height to 24rem so portrait phone photos don't blow out the card) and `.outpost-required` (small italic-style label modifier for "(required)" notes).

### Added (Session C1c — RSVP + Follow variants)
- Two more variants under the Reply tab: RSVP (h-entry with `in-reply-to` event URL + `rsvp` value yes/no/maybe/interested) and Follow (h-entry with `follow-of` person/feed URL).
- RSVP gets a second radio group ("Response") that appears only when RSVP is selected — Yes / No / Maybe / Interested. The chosen value is added as the `rsvp` property on submission.
- `HEntryProperties` interface in `micropub.ts` extended with `'follow-of'?: string` and `rsvp?: 'yes' | 'no' | 'maybe' | 'interested'`.
- `reply-mode.test.tsx` updated for 6-variant order; new tests for Follow URL-only and RSVP picker show/hide. Vitest 122 → 124.

### Changed (Session C1c)
- `OUTPOST_VERSION` bumped to `0.1.12` per A2 #16.
- VARIANT_ORDER now includes `'rsvp'` and `'follow'`. The Reply tab's radio group has 6 options.

### Added (Session C1b — Like, Repost, Bookmark variants under the Reply tab)
- `pwa/src/components/modes/reply-mode.tsx` extended with a 4-way variant picker. `VARIANTS: Record<Variant, VariantConfig>` table maps each id to its target property name (`in-reply-to`, `like-of`, `repost-of`, `bookmark-of`), required-content boolean, and per-variant labels (target-input label, content-textarea label, submit button label, preview-intro string). Single `<fieldset>` + `<legend>` + 4 `<label>`-wrapped radios at the top of the form; selecting a variant switches the heading, labels, submit button, and target h-entry property.
- `pwa/src/components/modes/reply-mode.test.tsx` — 7 new component-level vitest tests (first mode-component test in the suite). Covers radio order/default-Reply/heading-updates-on-change/submit-label-updates/Reply-requires-content/Like-only-requires-URL/target-input-label-per-variant. Vitest 115 → 122.
- `pwa/src/styles/structure.css` adds `.outpost-variant-picker`, `.outpost-radio`, and `:has(input:checked)` selected-state styling. Modern `:has()` selector (iOS Safari 15.4+ / Chrome 105+) gives parent-state styling without JS.

### Changed (Session C1b)
- `OUTPOST_VERSION` bumped to `0.1.11` per A2 Locked Decision #16.
- Reply mode now defaults to the Reply variant; tab label stays "Reply" as the umbrella for all 4 kinds. Submit button label is dynamic per variant ("Post reply" / "Post like" / "Post repost" / "Post bookmark").
- Bundle: 36.38 KB JS / 12.34 KB gzipped (was 35.23 / 11.97 at v0.1.10 — +0.37 KB gzipped for the picker + table data + 3 additional submit paths). 31% of the 40 KB Phase C budget.

### Added (Session B2 + C1 — server-side preview endpoint + Reply mode)
- **B2: `Outpost_Preview_Endpoint`** at `/wp-json/outpost/v1/preview` — POST `{ url }` returns `{ html, finalUrl, contentType }`. `final`, static-only. SSRF defenses: scheme allowlist (http/https), `wp_safe_remote_get` (auto-blocks loopback + private networks), 3-second timeout, 5 MB response-size cap, Content-Type allowlist (text/html + application/xhtml+xml), per-user rate limit 30/minute via transient. Response sanitization strips `<script>`, `<iframe>`, `<object>`, `<embed>`, event handlers, `javascript:`/`data:` URLs. `show_in_index => false` keeps the endpoint out of the public REST route index. Permission via IndieAuth plugin's bearer-token-to-WP-user translation; `current_user_can( 'edit_posts' )` works for both cookie and bearer auth.
- **`pwa/src/lib/preview.ts`** — client wrapper for the B2 endpoint. `fetch_preview({ url, accessToken })` validates the URL client-side, POSTs with the bearer token, parses the response, extracts the page `<title>` via regex with entity decoding (no DOM/innerHTML dependency). `PreviewError` discriminates `unauthorized`, `invalid_url`, `unsupported_content_type`, `rate_limited`, `server_error`, `fetch_failed`.
- **`pwa/src/components/modes/reply-mode.tsx`** — replaces the C0 stub. URL input + optional "Show preview" button (fetches citation context) + reply textarea + submit. Posts via `post_h_entry` with `content` and `in-reply-to`. Same status-state machine as NoteMode (`idle` → `fetching-preview` | `discovering-endpoint` | `posting` → `posted` | `error`).
- **`tests/unit/PreviewEndpointTest.php`** — 18 PHPUnit tests via WP_Mock covering URL validation (empty / javascript / data / file / no-host paths plus accepted http(s)), Content-Type validation (html / xhtml / json / image / pdf), and HTML stripping (script tags, iframes, event handlers, `javascript:` href, preserving safe anchors). Uses reflection to invoke private static methods.
- **`pwa/src/lib/preview.test.ts`** — 17 vitest tests covering `extract_title` (normal / whitespace / entities / case-insensitive / null cases) and `fetch_preview` (URL validation, Authorization header, status code mapping, success path, error paths, instanceof check).
- **Test surface bump:** PHPUnit 59 → 77 (1 skipped). Vitest 98 → 115.
- **Test bootstrap stubs added:** `WP_Error` and `WP_REST_Request` minimal class stubs in `tests/bootstrap.php`, plus `is_wp_error()` and `wp_parse_url()` function stubs. Real WP supplies these; unit tests need stubs.

### Changed (Session B2 + C1)
- **`pwa/src/lib/micropub.ts` extended** — new `post_h_entry({ properties: HEntryProperties, accessToken, micropubEndpoint })` is the general-purpose Micropub poster. `HEntryProperties` interface enumerates supported h-entry fields (content, name, summary, in-reply-to, like-of, repost-of, bookmark-of, category, mp-syndicate-to). Arrays get `[]` suffix per Micropub spec. `post_note` is now a thin wrapper calling `post_h_entry({ properties: { content } })`.
- **`outpost.php`**: requires `class-preview-endpoint.php` and calls `Outpost_Preview_Endpoint::register()` to hook `rest_api_init`. `OUTPOST_VERSION` bumped to `0.1.10` per A2 #16.
- **`pwa/src/components/composer-tabs.tsx`**: Reply tab now renders the real `ReplyMode` component (was a stub). Conditional spread for optional `micropubEnv` per the Locked Decision pattern.
- **`pwa/src/styles/structure.css`**: added `.outpost-citation` for the preview card in Reply mode (border + padding via theme tokens with `currentColor` fallback).
- **Bundle**: 35.23 KB JS / 11.97 KB gzipped (was 30.71 / 10.84 at v0.1.9 — +1.13 KB gzipped for B2 client + Reply mode + `post_h_entry` refactor). 30% of the 40 KB Phase C budget.

### Added (Session C0 — Phase C kickoff: tab framework + Note mode plugged in)
- `pwa/src/components/composer-tabs.tsx` — WAI-ARIA tabs scaffold (`role="tablist"` + `role="tab"` + `role="tabpanel"`, roving tabindex, automatic activation on arrow-key navigation, Home/End jumps, wrap-around at ends). `aria-label="Composer modes"` on the tablist. Closes A11Y-CHECKLIST Phase C focus-management forward gate.
- `pwa/src/components/modes/note-mode.tsx` — Note mode plugged into the tab framework. Functionally identical to B1's NoteForm; heading shifts from `<h1>` (page-level) to `<h2>` (panel-level under the tablist). State persists across tab switches because all panels render eagerly with `hidden` toggling visibility.
- `pwa/src/components/modes/reply-mode.tsx`, `photo-mode.tsx`, `listen-mode.tsx`, `article-mode.tsx` — placeholder cards naming which Phase C session lands the real implementation (Reply at C1, Photo at C2, Listen group at C3, Article at C4). Honest WIP indicator.
- `pwa/src/components/composer-tabs.test.tsx` — 12 vitest tests covering: 5 tabs render with correct labels; Note selected by default; only active panel is visible; aria-controls / aria-labelledby pairing; click selection; ArrowRight / ArrowLeft / Home / End keyboard nav; wrap-around at both ends; non-handled keys don't change selection.
- `pwa/src/styles/structure.css` — `.outpost-composer`, `.outpost-tablist`, `.outpost-tab` (selected variant + focus state with negative outline-offset so it doesn't clip on the bottom border), `[role="tabpanel"][hidden]` fallback. All paint via theme tokens with neutral fallbacks per the Hard Contract.

### Changed (Session C0)
- `OUTPOST_VERSION` bumped to `0.1.9` per A2 Locked Decision #16.
- `pwa/src/index.tsx`: App mounts `ComposerTabs` instead of `NoteForm` for the authenticated composer route.
- Bundle: 30.71 KB JS / 10.84 KB gzipped (was 27.88 / 10.00 at v0.1.8 — +0.84 KB gzipped for the tab framework + 4 stub components + tab CSS). 27% of the 40 KB Phase C bundle budget.

### Removed (Session C0)
- `pwa/src/components/note-form.tsx` — replaced by `modes/note-mode.tsx`. Same component logic; new file path matches the per-mode-file convention.

## [0.1.8] — 2026-05-01

This release consolidates a 7-skill audit cycle (`/wordpress-testing`, `/wordpress-pro`, `/wordpress-security`, `/wordpress-performance`, `/wordpress-plugin-core`, `/wordpress-accessibility`, `/wordpress-org-compliance`) plus the wordpress-performance-best-practices follow-up. Six concrete shipped improvements: CI workflow, URL-scheme validation hardening, modulepreload + critical layout CSS, `uninstall.php` hygiene, `<html lang>` site-locale fix, plus six durable forward-looking checklists (security, performance, accessibility, A3 token requirements, smoke tests, plugin-core cleanup enumeration). Outpost is end-to-end functional: sign in via IndieAuth, post a note via Micropub, see the new post URL — verified on iPhone Safari with desktop Safari Web Inspector.

### Added (WordPress.org compliance — wordpress-org-compliance audit)
- CLAUDE.md gains a "WordPress.org Compliance" section locking the no-trialware, no-license-key, no-paid-tier policy. Includes the adapter-behavior corollary for Phase F: integrations may detect paid companions and surface their features, but Outpost's own functionality must never be gated by whether a user has the paid version of any companion.
- Explicit rejection list for future contributions (Pro tier, license keys, time-limited trials, per-post quotas, adapter logic that disables Outpost when a free companion is present but a paid one isn't).
- No code change; audit found no violations to fix. Outpost is GPL v2-or-later, single-tier, fully functional out of the box.

### Added (Accessibility — wordpress-accessibility audit)
- `docs/accessibility/A11Y-CHECKLIST.md` — WCAG 2.1/2.2 Level AA per-surface audit. Status at v0.1.8 documents what's already correct (semantic landmarks, single `<h1>` per surface, `<label for>` ↔ `<input id>` association, `aria-labelledby` on cards, `aria-live="polite"` on AuthCallback transient states, `role="alert"` on error blocks, real `<button>`/`<a>`/`<input>` elements, 44px touch targets, focus rings). Forward-looking gates for A3 contrast verification, Phase C composer-mode focus management (WAI-ARIA tabs pattern), Phase D reduced-motion + offline announcements, Phase G axe-core CI job, Phase J real-device screen reader testing matrix.
- `docs/A3-REQUIREMENTS.md` extended with **A3-4** — token defaults must verify at WCAG 4.5:1 contrast. Documents the theme contract corollary (themes that override Outpost tokens must themselves meet 4.5:1).

### Changed (Accessibility)
- `<html lang>` in `render_shell()`, `render_install_prompt()`, and `render_host_unmet_prompt()` now reflects the WordPress site locale via `get_locale()` (with `_` → `-` substitution for BCP 47 format). Previously hardcoded `"en"` regardless of site language. Closes WCAG 3.1.1 Language of Page.
- `tests/bootstrap.php` adds a `get_locale()` stub returning `'en_US'` for unit tests (the SUT now references the function at file-load time via the shell render paths).

### Added (Plugin-core hygiene — wordpress-plugin-core audit)
- `uninstall.php` — runs once when an admin clicks "Delete" on the plugin (after deactivation). Clears `outpost_rewrite_version` (the only persistent option Outpost stored at v0.1.6, added by A2's flush guard). Forward-looking comments mark where future cleanup belongs as B2 / Phase F / Phase H surfaces add their own persistent state, including a multisite iteration pattern.
- `phpcs.xml.dist` and `phpstan.neon.dist` extended to include `uninstall.php` in lint + analyze scope.

### Added (Security hardening — wordpress-security follow-up audit)
- `pwa/src/lib/url-validation.ts` — `is_safe_http_url(value)` rejects malformed URLs and any scheme other than `http://` / `https://`. Closes two attack vectors:
  - **User-input `me` URL** (typed into LoginScreen) is now validated in `auth-flow.ts:begin_login` before reaching `fetch()`. Pasting `javascript:alert(1)` produces a clear inline error rather than failing opaquely.
  - **Micropub-returned Location header** (rendered as `<a href>` in NoteForm) is now validated in `micropub.ts:post_note` before returning. A compromised endpoint or MitM that injects `javascript:` / `data:` Location can't get a clickable link rendered. New `MicropubError` code: `invalid_location`.
- `pwa/src/lib/url-validation.test.ts` — 20 tests covering accepted schemes (http/https), rejected schemes (javascript, data, file, mailto, ws, wss, ftp, mixed-case javascript), and rejected malformed input (empty, whitespace, plain text, relative paths, bare hostname, protocol-relative).
- 8 new tests added to `auth-flow.test.ts` and `micropub.test.ts` covering the validation paths. Vitest total: 58 → 86.

### Added (between-session tooling)
- `.github/workflows/ci.yml` — CI gate for every push to `main` and every PR. Three jobs: `lint-php` (PHPCS + PHPStan), `test-php` matrix across PHP 8.2 / 8.3 / 8.4 (PHPUnit unit suite), `test-js` (TypeScript strict typecheck + Vitest + production-build smoke). Composer + npm caches keyed on lockfile hashes; concurrency group cancels in-flight runs on the same branch when a new commit lands; `permissions: contents: read` only.
- `docs/security/PHP-SURFACE-CHECKLIST.md` extended with three cross-cutting patterns from the wordpress-security audit: object-injection avoidance (`json_decode` over `unserialize`), path-traversal validation (`realpath()` + base-directory check), inline JS / `data-*` attribute escaping context.
- README badges (CI status, latest release, license) and v0.1.4 status line.

### Changed (Security hardening)
- `OUTPOST_VERSION` bumped to `0.1.5` per A2 Locked Decision #16: the JS bundle change ships new validation behavior on `/post/`, which counts as deployable behavior even though no PHP shifted.
- `pwa/src/lib/auth-flow.ts:begin_login` validates `me` before discovery; throws a descriptive Error on non-http(s).
- `pwa/src/lib/micropub.ts:post_note` validates the response Location header; throws `MicropubError('invalid_location')` on non-http(s).
- `MicropubError`'s `code` union extended: `'discovery_failed' | 'no_endpoint' | 'post_failed' | 'no_location' | 'invalid_location'`.
- Bundle: 27.88 KB JS / 10.00 KB gzipped (was 27.58 / 9.88 — +0.3 KB for the validator + its call sites).

### Changed (between-session tooling)
- `phpstan.neon.dist`: `node_modules/` exclude path marked optional (`(?)`) so PHPStan tolerates absence in the lint-php CI job (which only runs `composer install`, not `npm install`).

## [0.1.4] — 2026-05-01

This release consolidates Sessions A1 + A2 (foundation → routes/shell), B0a + B0b (build pipeline → IndieAuth login), and B1 (Micropub client → note posting). The plugin is functional end-to-end on staging: open `/post/`, sign in with IndieAuth, post a note via Micropub, see the new post URL. v0.1.0 was the scaffold; v0.1.4 is the first version that actually posts.

### Tooling (between-session work)
- `docs/SMOKE-TESTS.md` — durable test plan covering B0b + B1 sign-off across the device matrix (Android Chrome, iPhone Safari, iOS Chrome) with platform-specific DevTools setup and stage-by-stage verification including the AES-GCM IV-freshness check.
- `docs/A3-REQUIREMENTS.md` — three follow-ups discovered during the B0b smoke test: apple-touch-icon link, status-bar-style tuning, icon-192.png + icon-512.png assets.
- `phpcs.xml.dist` — WordPress-Extra ruleset pinned to PHP 8.2+ and WP 6.5+, locks the i18n text_domain to `"outpost"`. Excludes `WordPress.Files.FileName.InvalidClassFileName` (Outpost convention is `includes/class-{thing}.php` without the redundant slug prefix).
- `phpstan.neon.dist` + `phpstan-bootstrap.php` — Level 6 static analysis with `szepeviktor/phpstan-wordpress`. Bootstrap mirrors outpost.php's constant block so analysis sees `OUTPOST_*` when scanning files in isolation. `treatPhpDocTypesAsCertain: false` keeps the runtime PHP-version check from being marked redundant.
- Defensive `esc_html()` on `_doing_it_wrong()` messages in `outpost.php` and `class-pwa-shell.php`. The messages reach Query Monitor's panel which renders HTML.
- Suppressions with rationale: `class-pwa-assets.php` `file_get_contents` (local build artefact, not HTTP), `class-pwa-shell.php` enqueue rules (the shell IS the entire HTML document; `wp_head`/`wp_footer` aren't called).

### Added (Session B1 — Micropub client + note-posting form)
- `pwa/src/lib/micropub.ts` — `discover_micropub_endpoint(me)` reuses `parse_link_header` and `parse_html_endpoints` from `indieauth.ts`; `post_note({content, accessToken, micropubEndpoint})` POSTs `h=entry&content=...` form-encoded with the bearer token, parses the Location header for the new post URL. Handles 201 Created and 202 Accepted as success. `MicropubError` discriminates failure paths (`discovery_failed`, `no_endpoint`, `post_failed`, `no_location`).
- `pwa/src/components/note-form.tsx` — minimal note-posting Preact form. Replaces `ComposerPlaceholder` from B0b. Discovers micropub endpoint on first post (cached in component state for the session), shows discovering / posting / posted / error states with `aria-live` regions, surfaces the new post URL as a clickable link.
- `pwa/src/lib/micropub.test.ts` — 13 vitest tests covering Link-header / HTML-link-rel / first-wins discovery, network errors, 404s, 201 + 202 success cases, 4xx with body, missing Location header, lowercase Location header (HTTP/2 convention), fetch rejection, and `MicropubError` instanceof.
- `pwa/src/styles/structure.css` — added `.outpost-textarea` (multi-line input geometry, same fallback pattern as `.outpost-input`) and `.outpost-form-actions` (button row layout). All paint goes through `var(--outpost-*, theme-fallback)` per the Hard Contract.

### Changed (Session B1)
- `outpost.php`: `OUTPOST_VERSION` bumped to `0.1.4` per A2 Locked Decision #16 (any deployable behaviour change moves the version).
- `package.json`: bumped to `0.1.4` to match.
- `pwa/src/index.tsx`: `App` mounts `NoteForm` instead of `ComposerPlaceholder` for the authenticated composer route.

### Removed (Session B1)
- `pwa/src/components/composer-placeholder.tsx` — replaced by `note-form.tsx`. Git history preserves the original (commit `0cc82c9` introduced it; this commit removes it).

### Added (Session B0b — login screen + auth-callback + PHP manifest reader)
- `includes/class-pwa-assets.php` — `final`, static-only `Outpost_PWA_Assets`. Reads `build/pwa/.vite/manifest.json` (cached per request), resolves `entry_url()` and `entry_css_urls()` to plugin-URL + hashed asset paths. Fails gracefully (null / empty array) when manifest missing or invalid. `override_paths_for_tests()` substitutes a temp filesystem path + fake URL prefix for unit tests.
- `pwa/src/lib/auth-flow.ts` — `begin_login(me, client_id, redirect_uri, scope?)` and `handle_callback(query, client_id, redirect_uri)`. State persists in sessionStorage. `AuthFlowError` with discriminating `code` (`state_mismatch`, `missing_state`, `exchange_failed`, `no_code`). Always clears sessionStorage in `finally` so partial flow doesn't poison retries.
- `pwa/src/components/login-screen.tsx` — single-form Preact component with the `me` URL field defaulting to `location.origin + '/'`. Surfaces discovery errors inline.
- `pwa/src/components/auth-callback.tsx` — handles the IndieAuth callback on mount, redirects on success via `location.replace` (so the back button doesn't replay the consumed code), renders a "Start over" affordance on error.
- `pwa/src/components/composer-placeholder.tsx` — logged-in state for B0b, shows `me` + scope and a "Sign out" button. Phase C replaces with the actual composer modes.
- `pwa/src/styles/structure.css` — minimal structural CSS for B0b's three surfaces. Every paintable property goes through `var(--outpost-*, neutral-fallback)` per the Hard Contract. A3 will relocate tokens to a server-rendered file.
- `pwa/src/index.tsx` — `App` component with mount logic that branches on `detect_route` and token state. `mount(root, props)` exported for testability; auto-mount runs on import only when `#outpost-root` exists.
- `tests/unit/PWAAssetsTest.php` — 7 PHP unit tests covering missing/unreadable/invalid/valid manifest states plus the per-request cache assertion.
- `pwa/src/lib/auth-flow.test.ts` — 7 vitest tests covering happy-path exchange, all four error codes, and 4xx token-endpoint responses.
- 7 new tests bring vitest total to 45 → 52; PHP unit total to 51 → 58.

### Changed (Session B0b)
- `outpost.php`: `OUTPOST_VERSION` bumped to `0.1.2`. New `require_once` for `class-pwa-assets.php`.
- `includes/class-pwa-shell.php` `render_shell()`: emits `<script type="module">` for the entry plus `<link rel="stylesheet">` for any associated CSS chunks, both via `Outpost_PWA_Assets`. Skips both tags entirely when the manifest is missing.
- `.gitignore`: `build/` ignore changed to `build/*` (ignore contents) plus `!build/pwa/` exception so the bundle ships via the staging rsync. `.vite/` anchored to project root with a leading slash so the production manifest at `build/pwa/.vite/manifest.json` is tracked while the dev-server cache stays ignored.
- `docs/STAGING-DEPLOY.md`: added "The `npm run build` deploy ritual" section documenting the build-then-commit-then-bump sequence and the symptom of forgetting (empty `<main>` on staging).
- `build/pwa/` is now tracked in git. Initial bundle: 25 KB JS (9.28 KB gzipped) + 1.81 KB CSS (0.55 KB gzipped) + manifest.

### Added (Session B0a — PWA build pipeline + IndieAuth client + token store)
- `vite.config.ts`, `tsconfig.json`, `vitest.config.ts` — build + typecheck + test configuration. Vite runs in manifest mode (`build.manifest: true`) so `build/pwa/.vite/manifest.json` maps source paths to hashed output filenames; B0b reads the manifest from PHP. TypeScript at strict ceiling: `strict + noUncheckedIndexedAccess + exactOptionalPropertyTypes + noImplicitOverride + noImplicitReturns`. Vitest runs in happy-dom for DOM-touching tests.
- `pwa/src/index.tsx` — entry stub with `detect_route(pathname)` that maps `/post/`, `/post/share-target/*`, and `/post/auth/callback/*` to discriminated route values. Reads `location.pathname` because the PHP shell hard-codes `data-outpost-route="composer"` for all three paths.
- `pwa/src/lib/indieauth.ts` — IndieAuth client. `discover_endpoints()` parses Link header then HTML `<link rel>` then `<a rel>`. `parse_link_header()` and `parse_html_endpoints()` exposed for tests. `generate_pkce()` returns 32-byte verifier + SHA-256 challenge, both base64url. `generate_state()` returns CSRF-token bytes. `build_authorization_url()` produces the spec-compliant redirect URL with PKCE params. `exchange_code_for_token()` POSTs the code + verifier and returns the bearer token. All functions take an injectable `IndieAuthEnvironment { fetch, crypto, random }` defaulting to globals.
- `pwa/src/lib/token-store.ts` — encrypted IndexedDB token storage. AES-GCM 256-bit with non-extractable `CryptoKey` persisted via structured clone. `write_token()`, `read_token()`, `clear_token()` all take an injectable `TokenStoreEnvironment { indexedDB, crypto }`. Threat model documented in the file's docblock: defeats DevTools inspection, does not defeat same-origin XSS.
- 38 unit tests across `pwa/src/index.test.ts`, `pwa/src/lib/indieauth.test.ts`, `pwa/src/lib/token-store.test.ts`. Cover Link-header parsing edge cases (multi-rel, first-wins), PKCE determinism with stubbed random, code-exchange success + 4xx + missing-token paths, IDB round-trip + clear + key persistence + IV randomness assertion.

### Changed (Session B0a)
- `package.json`: replaced `@vitejs/plugin-preact` (non-existent package — A0 hallucination) with `@preact/preset-vite ^2.10.0`. Added `@types/node ^22.0.0` (config-file `__dirname`), `happy-dom ^15.0.0` (vitest env), `fake-indexeddb ^6.0.0` (token-store tests). Bumped npm package version to `0.1.1` to match `OUTPOST_VERSION`.
- `includes/class-pwa-shell.php` `send_html_header()`: prepends `nocache_headers()` before the Content-Type header. Defends against managed-WP page caches (Varnish/nginx FastCGI) serving an authenticated user's install-prompt or auth-callback HTML to another user. Manifest and SW responses keep their existing cache semantics.

### Added (Session A2 — /post/* route handler + PWA shell)
- `Outpost_Route_Handler` (`includes/class-route-handler.php`) — `final`, static-only class that owns the five `/post/*` rewrite rules. Registers `outpost_route` query var, hooks `init` for rule registration, and dispatches on `template_redirect`. Public `rules()` returns the rule table so tests and downstream code can iterate it without poking at WP internals. Order is upstream-first: `manifest.json` and `sw.js` register before the `/post/?$` catch-all so they never get hijacked.
- `Outpost_PWA_Shell` (`includes/class-pwa-shell.php`) — `final`, static-only renderer. `render()` branches on `outpost_is_ready()`: `render_shell()` emits the composer envelope HTML (empty body in A2 — Phase C lands the modes); `render_install_prompt()` consumes `first_unsatisfied()` + `outpost_dependency_presentation()` to render install/activate UI for the absent or inactive blocker. `render_manifest()` emits a PWA manifest JSON with scope `/post/`; `render_service_worker()` emits a no-op SW stub that handles `install` and `activate` events so the registration script in the shell succeeds.
- `outpost_dependency_presentation($plugin_file): ?array` in `outpost.php` — the shared label/wp.org-slug source consumed by both the admin notice path and the PWA install-prompt page. Filterable via `apply_filters('outpost_dependency_presentation', $map)` — future chain extensions and third-party integrations register through the filter without editing core.
- Filter hook `outpost_dependency_presentation` documented in the helper's docblock.
- `tests/unit/RouteHandlerTest.php` — 7 tests covering the rule-table shape, query-var registration, `register_rewrite_rules()` calling `add_rewrite_rule` once per entry, and dispatch routing each query-var value to the correct shell method.
- `tests/unit/PWAShellTest.php` — 5 tests covering: composer-shell HTML when ready, install-prompt HTML when IndieAuth is absent, filter-driven label override, manifest JSON shape with `/post/` scope, and SW stub with at least one event listener.
- `tests/unit/DependencyPresentationTest.php` — 5 tests covering known-file lookups, unknown-file `null` return, filter-driven entry override, and filter-driven new-entry registration for future chain extensions.
- `tests/integration/RouteHandlerIntegrationTest.php` — stubbed via `markTestSkipped` until wp-env lands. Top-of-file comment documents the assertions the wp-env-backed test will make (Content-Type per route, query-var dispatch, register_activation_hook side effects).
- Static-state-leak workaround in `PWAShellTest::setUp()` and `DependencyPresentationTest::setUp()`: WP_Mock 1.x's `Filter::$filtersWithAnyArgs` is a class-level static that `flush()` doesn't clear; reflection-based reset prevents `withAnyArgs()` in one test from poisoning `apply_filters()` calls in the next.

### Changed (Session A2)
- `outpost.php`: `outpost_render_admin_notices()` now consumes `outpost_dependency_presentation()` instead of an inline associative map. The `_doing_it_wrong()` guard moved with it — the helper returns `null` for unknown files and the consumer surfaces the gap. Activation hook now registers rewrite rules eagerly before flushing so `/post/*` works immediately after activation. Deactivation only flushes; rules drop naturally on the next request.
- `tests/bootstrap.php`: stubs the file-load-time WordPress functions outpost.php calls (`add_action`, `add_filter`, `register_activation_hook`, `register_deactivation_hook`, `plugin_dir_path`, `plugin_dir_url`, `plugin_basename`, `wp_json_encode`, `_doing_it_wrong`) and now `require`s outpost.php so unit tests can call procedural helpers (`outpost_dependency_presentation`, `outpost_is_ready`) directly.

### Fixed (Session A2 — staging verify follow-up)
- `outpost_maybe_flush_rewrite_rules()` (`outpost.php`): version-pinned rewrite-rule flush on `init` priority 11. Without this, an in-place plugin update (rsync deploy) leaves the previous version's rewrite_rules option cached, and `/post/*` falls through to WP's canonical-redirect path. Discovered during the A2 staging verify when `/post/` redirected to a blog post whose slug contained "post". Compares `OUTPOST_VERSION` against the stashed `outpost_rewrite_version` option and flushes once on mismatch.
- `Outpost_PWA_Shell::halt()` and an `OUTPOST_TESTING_PWA_SHELL` test-bootstrap constant: every render method calls `halt()` after writing the response so WordPress doesn't continue past `template_redirect` and render the theme template on top of our output. Symptom before the fix: `/post/manifest.json` returned the JSON immediately followed by 280KB of theme HTML. The constant lets unit tests skip the `exit` so `ob_start` can still capture output.
- `Outpost_Route_Handler::init()` hooks `dispatch` on `template_redirect` at priority 1 (was default 10). `redirect_canonical` is at priority 10 and registered earlier in WP's `default-filters.php`, so a same-priority handler loses the race and the request gets 302'd into a trailing-slash variant before our handler sees it.
- Service worker URL changed from `/post/sw.js` to `/post/sw` (no extension). Most managed-WP hosts configure nginx to short-circuit `.js` requests with a static-file lookup before WordPress runs, so the previous URL returned nginx 404. Stripping the extension keeps the request in WP's hands. Rule pattern updated to `^post/sw/?$`; registration script in the shell updated to match.
- `OUTPOST_VERSION` bumped to `0.1.1`. The rewrite-rule table changed shape (SW pattern), and per A2 Locked Decision #11 the version-pinned flush guard only fires on a version mismatch. The plugin header `Version` annotation matches.

### Added (Session A1 — Companion detector + adapter base)
- `Outpost_Companion_Detector` — `final`, static-only class wrapping companion-plugin state detection. Methods: `status($file)`, `dependency_chain()`, `optional_companions()`, `first_unsatisfied()`, plus eight named wrappers (`is_indieauth_active()` through `is_activitypub_active()`).
- `Outpost_Companion_Base` — abstract base every Phase F adapter extends. Abstract: `file()`, `label()`, `capabilities()`. Final concrete: `status()`, `is_active()`. The composer code branches on capability slugs, never on adapter class identity.
- Six optional companion file-path constants in `outpost.php`: `OUTPOST_POST_KINDS_PLUGIN_FILE`, `OUTPOST_POST_FORMATS_PLUGIN_FILE`, `OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE`, `OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE`, `OUTPOST_YOAST_PLUGIN_FILE` (file `wp-seo.php`, not the slug), `OUTPOST_ACTIVITYPUB_PLUGIN_FILE`. The two required chain entries (`OUTPOST_INDIEAUTH_PLUGIN_FILE`, `OUTPOST_MICROPUB_PLUGIN_FILE`) move into a labelled "Required chain (upstream-first)" block alongside them.
- `tests/unit/CompanionDetectorTest.php` — 13 test methods, including a data-provider matrix that asserts each of the eight companion file paths against all three states (`active`, `inactive`, `absent`). Yoast's slug-vs-file mismatch is captured as test data.
- `tests/bootstrap.php` — PHPUnit bootstrap that mirrors `outpost.php`'s constant block instead of `require_once`'ing the bootstrap (which would call WP hooks at file-load time). Loads the detector and adapter-base classes for the unit suite.
- `phpunit.xml.dist` — PHPUnit 9.6 config with `failOnRisky`, `failOnWarning`, `convert*ToExceptions` strict flags. Two test suites: `unit` and `integration`.
- `_doing_it_wrong()` guard on the presentation-map silent-drop branch in `outpost_render_admin_notices()`. Surfaces missing map entries via Query Monitor's `doing_it_wrong` panel even when `WP_DEBUG=false`.

### Changed (Session A1)
- `outpost.php` refactored to thin procedural shims: `outpost_companion_plugin_status()`, `outpost_indieauth_status()`, `outpost_micropub_status()` now delegate to the detector class. The shims preserve backwards compat for early call sites; new code calls `Outpost_Companion_Detector::*` directly.
- `outpost_is_ready()` now calls `Outpost_Companion_Detector::first_unsatisfied()` instead of hard-coding the chain order. Future chain extensions propagate automatically.
- `outpost_render_admin_notices()` collapsed onto `first_unsatisfied()` plus a small co-located presentation map. Removed the per-state hand-written branches; the same `outpost_render_dependency_notice()` helper now handles every dependency.
- `composer.json`: added `10up/wp_mock ^1.0`. PHPUnit pinned to `^9.6` (was `^10.5`) for WP_Mock's Mockery compat window. Test PSR-4 namespaces split into `Outpost\Tests\Unit\` and `Outpost\Tests\Integration\`.

### Changed (Session A0 follow-up — already shipped)
- Gate detects IndieAuth status as the most-upstream dependency, ahead of Micropub. Discovered during Session A0 staging test: the WordPress.org Micropub plugin hard-requires IndieAuth at its own preflight, so a Micropub-active-but-IndieAuth-missing environment looks `is_plugin_active()`-true but has no Micropub endpoints registered. Notices now surface IndieAuth → Micropub → ready in upstream-first order.

## [0.1.0] — 2026-05-01

### Added
- Initial plugin scaffold (Session A0).
- Plugin bootstrap (`outpost.php`) with header metadata, requirements check (WP 6.5+, PHP 8.2+), and hybrid Micropub gate.
- `outpost_micropub_status()` helper returns `'active'`, `'inactive'`, or `'absent'` for the three observable states of the required Micropub companion.
- Admin notice that adapts to the Micropub status: install link when absent, activation link when inactive, silence when active.
- Directory tree per the plugin's architectural plan (Section 9.1 of the design prompt).
- Development tooling: PHPCS, PHPStan, PHPUnit, Vite, TypeScript, Preact, ESLint, Prettier, Playwright, vite-plugin-pwa.
- Project metadata: `CLAUDE.md`, `README.md`, `readme.txt` with Credits section naming prior art.

### Notes
- This release does not yet include the PWA shell, composer modes, REST endpoints, or service worker. Those land in subsequent sessions per the build plan.

[Unreleased]: https://github.com/courtneyr-dev/outpost/compare/v0.1.8...HEAD
[0.1.8]: https://github.com/courtneyr-dev/outpost/compare/v0.1.4...v0.1.8
[0.1.4]: https://github.com/courtneyr-dev/outpost/compare/v0.1.0...v0.1.4
[0.1.0]: https://github.com/courtneyr-dev/outpost/releases/tag/v0.1.0
