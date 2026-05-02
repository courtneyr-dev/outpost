# Changelog

All notable changes to Outpost are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Outpost adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
