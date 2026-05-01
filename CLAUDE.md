# Outpost

## Project

- **Slug:** outpost
- **Text Domain:** outpost
- **Prefix:** `outpost_` (functions, options); `Outpost_` (classes); `OUTPOST_` (constants)
- **Min WP:** 6.5 | **Tested up to:** 6.9 | **Min PHP:** 8.2
- **Repo:** https://github.com/courtneyr-dev/outpost
- **Tagline:** *Post from your outpost. Reach your people everywhere.*

## What It Does

Mobile-first PWA composer at `/post` (configurable). Five modes: Note, Reply/Like/Repost/Bookmark/RSVP/Follow, Listen/Watch/Read/Checkin/Play, Photo, Article. Optimised for IndieWeb post shapes and life-tracking entries. POSSE-first: every configured syndication destination is on by default per post. iOS-primary, Android-compatible. No Jetpack, no app store, no third-party auth.

Uses Micropub as the API and IndieAuth for browser-side auth. PWA frontend uses Vite + TypeScript + Preact, output to `/build/pwa/`.

## Hard Contract — plugin owns layout, theme owns paint

If anything else in this file contradicts this rule, the rule wins.

### Plugin always owns

- The PWA shell, routes, service worker, manifest, offline behavior.
- Composer interaction: tab structure, syndication chips, voice-input slot, file upload UI.
- The Micropub client implementation.
- IndieAuth flow and encrypted IndexedDB token storage.
- Structural CSS: layout grids, iOS safe-area insets, touch-target sizes, focus states.
- Capability detection for companion plugins.
- CSS custom property *names* and structural defaults.

### Theme always owns

- Every color value.
- Every font choice.
- Spacing scale, border radius, shadow, surface treatment.
- Button fills, accent colors, link colors.
- Whether the PWA inherits the site's block theme typography or sets its own.

### The handshake

Plugin structural CSS only references colors and fonts through `var(--outpost-*, neutral-fallback)`:

```css
.outpost-composer {
	background: var(--outpost-surface-bg, transparent);
	color: var(--outpost-surface-fg, inherit);
}
.outpost-syndication-chip[aria-pressed="true"] {
	background: var(--outpost-chip-active-bg, currentColor);
	color: var(--outpost-chip-active-fg, inherit);
}
```

If a theme sets the variable, the plugin uses it. Otherwise the composer inherits the theme's normal styling. **No part of Outpost ever forces a color the theme didn't ask for.**

One forced default: `padding-bottom: env(safe-area-inset-bottom)` on the iOS bottom toolbar. Structural, not paint.

## Companion Strategy

Detection runs at runtime (not install-time) so the UI updates as users activate plugins. Adapter classes live at `includes/companions/`. Composer code never imports companion-specific code directly.

The dependency chain is **upstream-first**: `[indieauth/indieauth.php, micropub/micropub.php]`. Notice rendering and REST 503 reasons short-circuit on the first unsatisfied entry — installing a downstream dependency before the upstream one is met produces a broken state (Micropub plugin's own preflight fails without IndieAuth).

| Companion | Outpost behavior |
|-----------|------------------|
| `indieauth/indieauth.php` (Pfefferle, Shanske) | **Required (plugin install).** The Micropub plugin hard-requires IndieAuth at its own preflight, so Outpost surfaces IndieAuth status as the most-upstream notice. App passwords are *not* an alternative to installing the IndieAuth plugin — they remain a runtime fallback only when the IndieAuth endpoint is unreachable for an already-installed IndieAuth plugin. Detected by `outpost_indieauth_status()`. |
| `micropub/micropub.php` (David Shanske) | **Required (plugin install).** Hybrid gate: plugin loads, admin notice when any chain entry is absent or inactive, PWA route renders friendly install page, REST routes return 503 with the chain entry name as the reason. Detected by `outpost_micropub_status()`. |
| Post Kinds for IndieWeb | Surface Listen/Watch/Read/Checkin/Play tabs. Surface Follow sub-mode. |
| Post Formats for Block Themes | Format selector in More pull-out. Auto-detect from content. |
| Link Extension for XFN | Relationship picker on reply targets. |
| Syndication Links | Read configured destinations into chip UI. |
| Yoast SEO | Focus keyphrase + meta description fields in More pull-out. |
| ActivityPub / Bridgy | Surface as syndication destinations automatically. |

## Bridgy Auto-Suggest

When the source URL host of a Reply/Like/Repost/Bookmark matches a Bridgy-supported network, the corresponding Bridgy publish URL is added as a syndication chip and enabled by default. Mapping lives at `includes/class-bridgy-detector.php` and is filterable via `outpost_bridgy_host_map`. Per-post and global opt-out toggles.

## Build Commands

```bash
composer install && npm install
npm run dev             # Vite dev server for PWA frontend
npm run build           # Production PWA build to /build/pwa/
composer test           # PHPUnit
composer lint           # PHPCS (WordPress-Extra)
composer analyze        # PHPStan
npm run lint            # ESLint
npm run test:e2e        # Playwright
```

## File Layout

```
outpost.php                 Bootstrap (header, requirements, Micropub gate)
includes/                   PHP classes
includes/companions/        Adapter classes for each companion plugin
admin/                      Settings page, bookmarklet generator, onboarding
pwa/src/                    PWA frontend source (TypeScript + Preact + Vite)
pwa/src/modes/              One file per composer mode
pwa/src/components/         Shared composer UI components
pwa/src/lib/                Micropub client, IndieAuth, token store, offline queue
pwa/src/styles/tokens.css   --outpost-* token defaults
styles/outpost-tokens.css   Server-rendered token defaults
build/                      Compiled PWA assets (gitignored)
assets/icons/               PWA icons at all required sizes
tests/unit/                 WP_Mock unit tests
tests/integration/          WP_UnitTestCase integration tests
tests/e2e/                  Playwright e2e
docs/                       User and integrator docs
docs/security/              CSP, threat-model docs
languages/                  POT and MO files
bin/                        Helper scripts
```

## Standards

- WordPress PHP + JS Coding Standards (tabs, Yoda conditions).
- Security Trinity: sanitize input → validate data → escape output.
- All strings wrapped in `__()` / `_e()` with text domain `outpost`.
- All blocks (when added) use `apiVersion: 3`.
- Escape at render: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- DB queries via `$wpdb->prepare()` only.
- Nonces + `current_user_can()` on every admin and REST action.
- TypeScript strict mode, no `any` (use `unknown` + type guards).
- Token in encrypted IndexedDB, never localStorage or cookies.
- Service worker scope is `/post/` only, never the whole site.

## Security Hot Spots

- **Server-side mf2 preview** (`/wp-json/outpost/v1/preview`) is the SSRF surface. Use `wp_safe_remote_get`, cap response size at 5MB, validate content-type, strip scripts before returning. See Section 8.4 of the original prompt and `docs/security/csp.md`.
- **Required alt text on photos.** Structural, not configurable. Decorative toggle submits `alt=""`.
- **EXIF stripping on upload.** Privacy: prevents location leak from camera roll.
- **Bookmarklet `url` parameter validation** at the composer endpoint: reject non-http(s), reject internal IPs, length-cap at 2048 chars.

## Prior Art

Outpost evolves from prior IndieWeb work for WordPress. Credit these in `readme.txt`, `README.md`, and the bookmarklet settings section.

- **IndieWeb Press This** (Pfefferle, Shanske, Barrett) — bookmarklet pattern for Reply/Like/Repost/RSVP/Follow from any page. Outpost extends and modernizes.
- **Micropub plugin** (David Shanske) — required dependency, our server endpoint.
- **IndieAuth plugin** (Pfefferle, Shanske) — auth.
- **IndieBlocks** (Jan Boddez) — companion for theme blocks.
- **Bridgy** (Ryan Barrett) — round-trip syndication service.

## Forbidden Vocabulary

In commits, code comments, copy, admin labels, and docs: avoid *delve, leverage, synergy, robust, seamless, ecosystem (non-tech sense), stakeholder, bandwidth (non-tech sense), pivot, agentic AI, AI agents*. Use plain language: "uses" not "leverages," "thorough" not "robust," "smooth" not "seamless," "people involved" not "stakeholders."

## Session A1 — Locked Decisions (shipped)

Closed 2026-05-01. The constraints below are now production code; the rationale stays here so future sessions don't re-litigate.

1. **`Outpost_Companion_Detector` is `final`, static-only.** No per-instance state — every caller (admin notices, PWA install prompt, REST 503 reasons) wants a single function-call answer against WordPress globals. `outpost.php`'s procedural functions (`outpost_companion_plugin_status`, `outpost_indieauth_status`, `outpost_micropub_status`) are thin shims that delegate to the static methods. Old call sites keep working; new code calls the class directly.
2. **`Outpost_Companion_Detector::dependency_chain()` is the single source of truth** for the IndieAuth → Micropub ordering. `first_unsatisfied()` short-circuits on the first miss. `outpost_is_ready()` calls `first_unsatisfied()` instead of hard-coding the order, so future chain extensions propagate automatically.
3. **IndieAuth is hard-required at the plugin-install level**, not at the runtime auth level. Discovered during Session A0 staging test 2026-05-01: the WordPress.org Micropub plugin self-disables without IndieAuth installed and active. The original prompt's "fall back to WordPress application passwords" path is moot. Application passwords remain a fallback only for the Micropub bearer token at runtime when an already-installed IndieAuth endpoint is unreachable, not as an alternative to installing the plugin. **Session B0 plan to be updated when reached** — the IndieAuth flow is required, not optional.
4. **`Outpost_Companion_Base` is abstract.** Three abstract methods every adapter must implement: `file()` (which `OUTPOST_*_PLUGIN_FILE` it wraps), `label()` (brand name), `capabilities()` (string[] of capability slugs). Two `final` concrete methods every adapter inherits: `status()` and `is_active()` — both delegate to the detector. Phase F adapters declare identity, never fork detection logic.
5. **`capabilities(): array` returns a flat slug list (Option A locked).** Adapters return things like `['post-kinds.listen', 'post-kinds.watch', 'syndication.chips']`. The composer in later sessions does `in_array('post-kinds.listen', $caps, true)`. Promotion to Option B (per-capability config map) only if a Phase F adapter surfaces a real need — premature structure now would just be guessing at requirements.
6. **Notice presentation map (`OUTPOST_INDIEAUTH_PLUGIN_FILE => ['IndieAuth', 'indieauth']`) is co-located with the consumer**, not baked into the detector. The detector is concerned with state; presentation belongs with whoever renders it.
7. **`_doing_it_wrong()` guards the silent-drop branch** in `outpost_render_admin_notices()`. If `dependency_chain()` ever extends without a matching presentation entry, the gap surfaces in Query Monitor's `doing_it_wrong` panel instead of disappearing as a missing notice. Pairs with `WP_DEBUG=false` because the `doing_it_wrong_run` action fires unconditionally.
8. **PHPUnit is pinned to `^9.6` because of `10up/wp_mock`'s Mockery compat window.** PHPUnit 9.6 has support through 2026; promoting to 10.x will wait until WP_Mock supports it. Test namespaces split into `Outpost\Tests\Unit\` and `Outpost\Tests\Integration\` so each suite has a clean PSR-4 root.
9. **Yoast file path is `wordpress-seo/wp-seo.php`**, not the slug-derived `wordpress-seo/wordpress-seo.php`. The slug-vs-file mismatch is a known gotcha for Yoast — captured as test data in `provide_companion_files()` so a regression flips the matrix.

## Session A2 — Locked Decisions (shipped)

Closed 2026-05-01 (same day as A1; A1 + A2 deployed together to staging because A1 was scaffolding A2 consumed). The constraints below are now production code; the rationale stays here so future sessions don't re-litigate.

1. **`outpost_dependency_presentation($plugin_file): ?array` is a procedural function in `outpost.php`, not a static method on the detector.** The detector is concerned with state (active/inactive/absent); presentation belongs with whoever renders it. Two consumers share the helper: `outpost_render_admin_notices()` and `Outpost_PWA_Shell::render_install_prompt()`. Returns `array{label:string,slug:string}` for known files, `null` otherwise.
2. **Filter `outpost_dependency_presentation` extends or overrides the map.** Future chain extensions and third-party integrations register through this filter without editing core. Filter signature: `apply_filters('outpost_dependency_presentation', array<string, array{label:string,slug:string}>)`. Locked associative shape (not positional `[label, slug]`) so filter callers can read by name.
3. **Five `/post/*` rewrite rules in upstream-first order.** Specific routes register before the catch-all so `/post/manifest.json` and `/post/sw` don't get hijacked by the composer regex:
   - `^post/manifest\.json$` → `manifest`
   - `^post/sw/?$` → `sw` (extension-less on purpose; see #14)
   - `^post/share-target/?$` → `share-target` (Phase E body)
   - `^post/auth/callback/?$` → `auth-callback` (Phase B body)
   - `^post/?$` → `composer`
   Every rule registers with the `top` flag so the order survives WP's internal rewrite-rule sort. The single source of truth is `Outpost_Route_Handler::rules()` — tests assert the whole table at once.
4. **`Outpost_Route_Handler::QUERY_VAR === 'outpost_route'`.** The query var carries the matched target (`composer`, `manifest`, etc.) into `template_redirect` where `dispatch()` hands off to the right `Outpost_PWA_Shell` method. Unknown values silently no-op so WP's normal 404 still applies.
5. **`Outpost_PWA_Shell` has two main rendering branches and two artefact endpoints.** `render()` branches on `outpost_is_ready()`: `render_shell()` emits the composer envelope HTML; `render_install_prompt()` consumes `first_unsatisfied()` + `outpost_dependency_presentation()` to produce the install/activate UI. `render_manifest()` and `render_service_worker()` emit JSON and JS with correct content-types. The composer body is intentionally empty in A2 — Phase C lands the modes.
6. **Service-worker scope is `/post/`** in both the registration script (`navigator.serviceWorker.register('/post/sw', { scope: '/post/' })`) and the manifest scope/start_url. The SW never tries to control the parent WordPress site (Standards §128 in this file).
7. **Activation registers rules eagerly + flushes.** `outpost_activate()` calls `Outpost_Route_Handler::register_rewrite_rules()` then `flush_rewrite_rules()` so the rules land in the rewrite cache without requiring the user to visit Settings → Permalinks. Deactivation only flushes — the rules drop naturally because the `init` hook stops firing.
8. **WP_Mock 1.x has a static-state leak via `Filter::$filtersWithAnyArgs`.** `withAnyArgs()` writes to a class-level static that `flush()` doesn't clear; the next test's `apply_filters` returns a random integer instead of the input. Workaround: `setUp()` resets the static with `ReflectionClass::getProperty()->setValue(null, [])`. Pattern travels to any future test file that calls `WP_Mock::onFilter()->withAnyArgs()`.
9. **Integration test for the rewrite flow is stubbed via `markTestSkipped`.** `tests/integration/RouteHandlerIntegrationTest.php` documents the assertions the wp-env-backed test will make (Content-Type per route, query-var dispatch, register_activation_hook side effects). Lands in a later session when wp-env is wired up — A2 can't run it because real WP_Rewrite needs a real WordPress core.
10. **REST 503 reasons stay deferred to B2.** `outpost_is_ready()` already drives them today via `first_unsatisfied()`; B2 just adds the `/wp-json/outpost/v1/*` route handler that returns the JSON 503 body. No work in A2.
### Decisions #11–#16: deployment + hosting gotchas (with scope tags)

Each is tagged with where it applies. The TypeScript port (indieweb-astro) and any other WordPress project pulling decisions from this file should filter on scope.

11. **[universal — applies to every WP plugin deployed via rsync]** Version-pinned rewrite-rule flush on `init`. Discovered during A2 staging verify on 2026-05-01: the activation hook only fires when an admin clicks "Activate", not when a deploy/update replaces the plugin in place. Without a flush guard, the cached `rewrite_rules` option holds the previous version's rule table and `/post/*` falls through to WP's canonical-redirect logic (which sent us to the most-similar post slug). `outpost_maybe_flush_rewrite_rules()` compares `OUTPOST_VERSION` against the stashed `outpost_rewrite_version` option and flushes once on mismatch. Hooked at `init` priority 11 so it lands right after `Outpost_Route_Handler::register_rewrite_rules` at priority 10.
12. **[universal — WordPress lifecycle, not hosting-specific]** Render methods call `Outpost_PWA_Shell::halt()` after writing the response. Without `exit`, WP continues past `template_redirect` and renders the theme template, concatenating it onto our shell/manifest/sw output. The unit-test bootstrap defines `OUTPOST_TESTING_PWA_SHELL` so `halt()` is a no-op in tests and `ob_start` can still capture render output for assertions.
13. **[universal — WordPress hook ordering, not hosting-specific]** `template_redirect` dispatch hooks at priority 1. WordPress's own `redirect_canonical` runs at priority 10 and was registered earlier in `default-filters.php`, so a same-priority handler loses the race. Priority 1 guarantees the route handler always sees the request before WP can 302 it into a trailing-slash variant.
14. **[managed-WP — applies to GoDaddy, WP Engine, Kinsta, and most nginx-fronted hosts]** Service worker URL has no `.js` extension — locked at `/post/sw`, registered via `navigator.serviceWorker.register('/post/sw', { scope: '/post/' })`. Most managed-WP hosts configure nginx to short-circuit `.js` requests with a static-file lookup before WordPress runs; with the extension our SW returns nginx 404 even when the rewrite rule is correct. Stripping the extension keeps the request in WP's hands. Discovered during A2 staging verify when `/post/sw.js` returned 404 nginx in 107ms while `/post/manifest.json` rendered correctly. The browser doesn't care about the script URL's extension as long as the response is JavaScript. Same caution applies to any future `.css`, `.png`, `.ico` paths that need to hit PHP — use extension-less URLs.
15. **[managed-WP — applies to any host with an edge cache that caches redirect responses]** Cache-buster query string needed for staging verify. GoDaddy's edge cache promotes 302/301 responses to cached entries (header `x-gateway-cache-key`); WP Engine and Kinsta have similar behavior with their own gateways. After deploying a fix, hitting the same URL serves the stale cached redirect. Append `?_cb=<timestamp>` (or any unique query parameter) when verifying staging changes — the new query string maps to a new cache key and forces a real PHP request. Confirmed by `x-gateway-skip-cache: 1` on the response.
16. **[universal — companion to #11]** Bump `OUTPOST_VERSION` in the same commit as any rewrite-rule table change. Decision #11's flush guard fires only on mismatch between `OUTPOST_VERSION` and the stashed `outpost_rewrite_version` option. If you change the rule table without bumping the version, deployed sites silently keep the old rule cache and the new routes don't activate. Caught my own footgun on 2026-05-01: shipped the `^post/sw/?$` rule without bumping, staging kept matching `^post/sw\.js$` until `0.1.0 → 0.1.1`. Both the plugin header `Version` and `OUTPOST_VERSION` constant must move together; they live two lines apart in `outpost.php`. Same rule applies to any future change in `Outpost_Route_Handler::rules()`, query-var names, or activation-hook side effects.

## Session A3 — Design Constraints (deferred — Phase B took priority)

A2's PWA shell renders an empty composer envelope with no styling tokens or static assets. A3 lands the structural CSS, the `--outpost-*` token defaults, and the icon set. Honor these when starting:

1. **`styles/outpost-tokens.css` is server-rendered, not bundled.** Themes need to inspect the cascade and override; a Vite-bundled-and-hashed token file makes that fragile. Keep the token file at a stable URL.
2. **The forced `padding-bottom: env(safe-area-inset-bottom)` on the iOS bottom toolbar is the only paint default Outpost ships.** Hard Contract above. Anything else is theme territory.
3. **Service worker fetch handler stays out of A3.** A2 ships a no-op SW so the registration script succeeds; the real fetch/cache strategy lands in Phase D after the composer modes exist (otherwise we cache a shell that's about to be replaced).

## Session B0a — Locked Decisions (shipped)

Closed 2026-05-01. The constraints below are now production code; the rationale stays here so future sessions don't re-litigate. B0a is the build pipeline + IndieAuth client + token store, no UI. B0b lands the login screen and the staging smoke test.

1. **Vite + Preact + manifest mode for the build pipeline.** Source at `pwa/src/`, output to `build/pwa/`, entry `pwa/src/index.tsx`. `build.manifest: true` produces `build/pwa/.vite/manifest.json` mapping source paths to hashed output filenames. B0b reads the manifest from PHP to enqueue the right `<script type="module">` — never hard-code a hash.
2. **`@preact/preset-vite`, not `@vitejs/plugin-preact`.** The latter is a hallucinated package name from A0's `package.json`; the canonical Preact-Vite integration is `@preact/preset-vite`. Fixed in B0a.
3. **`tsconfig.json` `include` is `pwa/src/**` only.** Vite + Vitest config files are excluded. Vitest brings its own pinned Vite version whose `Plugin` type diverges from the top-level Vite under `exactOptionalPropertyTypes: true` — writing casts to satisfy both isn't worth it. Config files run at build time; they don't need to pass app-grade typecheck.
4. **TypeScript strict ceiling: `strict + noUncheckedIndexedAccess + exactOptionalPropertyTypes + noImplicitOverride + noImplicitReturns + noFallthroughCasesInSwitch`.** Static safety where tests can't reach (public-API typing, exhaustive switch coverage, no-undefined-leaks-into-defined-shapes).
5. **happy-dom for the vitest environment, `fake-indexeddb/auto` for IDB-using tests.** happy-dom provides DOMParser + window + fetch shape; it does not provide IndexedDB. Token-store tests `import 'fake-indexeddb/auto'` and create a fresh `IDBFactory` per test so state never leaks between cases.
6. **Native `crypto.subtle` from Node 19+, used via `globalThis.crypto`.** No webcrypto polyfill needed for vitest. Tests run AES-GCM and SHA-256 against real Web Crypto. The IndieAuth + token-store libraries reach the same APIs in the browser via the same `globalThis.crypto` reference.
7. **Injectable environment pattern.** `IndieAuthEnvironment { fetch, crypto, random }` and `TokenStoreEnvironment { indexedDB, crypto }` flow into every public function as an optional second argument that defaults to globals. Tests pass deterministic stubs (fixed `random()`, mocked `fetch`, `fake-indexeddb` factories) without touching globals. Future `MicropubClient` and `OfflineQueue` follow the same shape.
8. **`detect_route(pathname)` reads `location.pathname`, not the shell's `data-outpost-route` attribute.** The PHP shell hard-codes `data-outpost-route="composer"` because the route handler dispatches `composer`, `share-target`, and `auth-callback` to the same `render_shell()`. The JS bundle is the only place that knows which of those three URLs the user actually landed on.
9. **Token store threat model — defeats DevTools inspection, does not defeat same-origin XSS.** AES-GCM 256-bit with non-extractable `CryptoKey` persisted in IndexedDB via structured clone. `crypto.subtle.exportKey()` refuses to dump raw bytes for non-extractable keys, raising the bar past simple IDB inspection. JS in the same origin can still call `read_token()` and get plaintext — that's the same threat any localStorage scheme has and why the CSP work in Phase G is the real defense. Documented honestly in the file's docblock so the next reader doesn't overestimate the protection.
10. **`nocache_headers()` on every HTML response from `Outpost_PWA_Shell::send_html_header()`.** Managed-WP page caches (Varnish on GoDaddy, nginx FastCGI on others) cache anonymous responses by default. Without `nocache_headers()` an install-prompt or auth-callback page rendered for one user could be served to another. Manifest and SW responses keep their existing cache semantics — `nocache_headers()` is HTML-only.
11. **`build/pwa/` gitignore tension flagged for B0b.** The `gd-wordpress-deployer` rsync reflects the working tree, so a production-shaped staging deploy needs committed build output. B0a's `.gitignore` keeps `build/` ignored; B0b will either un-ignore `build/pwa/` (mirrors the courtneyr-child theme pattern of committing built CSS) or wire a CI step that builds + force-adds to a deploy branch. Decision deferred to B0b because B0a ships no observable change to staging.

## Session B0b — Design Constraints

B0a ships the IndieAuth client and token store; B0b adds the login screen UI, the manifest read in PHP, the auth-callback handler, and the first staging smoke test. Honor these:

1. **Wire the Vite manifest read into `Outpost_PWA_Shell::render_shell()`.** Read `build/pwa/.vite/manifest.json` once per request, look up the `pwa/src/index.tsx` entry, emit `<script type="module" src="<plugin-url>build/pwa/<file>">`. Fail gracefully (no script tag, no error) when the manifest file is missing — dev environments might not have run `npm run build` yet, and the install-prompt path doesn't need the bundle.
2. **Resolve `build/pwa/` gitignore.** Lean toward un-ignoring `build/pwa/` specifically (`build/` general ignore + `!build/pwa/` exception). Mirrors the courtneyr-child theme pattern of committing built CSS for the same rsync deploy reason. Document in `docs/STAGING-DEPLOY.md` that running `npm run build` before commit is part of the deploy ritual.
3. **Login screen Preact component.** Single button + `me` URL field defaulting to `location.origin`. Pipeline: `discover_endpoints` → `generate_pkce` → `generate_state` → stash verifier + state in `sessionStorage` → `build_authorization_url` → `location.assign(url)`. Same-tab redirect; no popup window. Style hooks via `--outpost-*` tokens with neutral fallbacks per the Hard Contract.
4. **Auth-callback handling on `detect_route() === 'auth-callback'`.** Read `code` and `state` from `URLSearchParams`. Validate `state` against the sessionStorage value (CSRF defence). On mismatch, render error + clear all auth state. On success, `exchange_code_for_token` → `write_token` → `location.assign('/post/')`. Clear sessionStorage values whether or not the exchange succeeds.
5. **Staging smoke test once B0b ships.** First end-to-end "click and observe" verification on `qkf.b0d.myftpupload.com`: open `/post/`, see login screen, tap "Log in", get redirected to IndieAuth's authorization page, click Allow, land back on `/post/` with an authenticated PWA placeholder showing the `me` URL. Bumps `OUTPOST_VERSION` (A2 Locked Decision #16: version moves with deployable behaviour change). Confirms the SW registration, the manifest enqueue, the IndieAuth round-trip, and the token persistence all work on real Managed WordPress.

## Build Order (40 sessions)

Phase A: Foundation (slug, scaffold, companion detector, routes).
Phase B: Auth and Micropub (IndieAuth flow, Micropub client, server-side mf2 preview).
Phase C: Composer modes (Note → Reply → Photo → Listen group → Article → More pull-out).
Phase D: PWA polish (manifest, service worker, offline queue, voice, iOS safe-area).
Phase E: Share-sheet and bookmarklets (Web Share Target, iOS Shortcut, multi-action bookmarklet generator, Bridgy auto-suggest).
Phase F: Companions (Post Kinds, Post Formats, XFN, Yoast, ActivityPub/Bridgy adapters).
Phase G: Security (token hardening, SW review, CSP, file-upload, rate-limit, URL validation, pen test).
Phase H: Settings and onboarding.
Phase I: Distribution (readme.txt, screenshots, banner/icon, submission).
Phase J: Documentation and launch.

Each session is its own conversation, scoped to ~2-3 KB of context. Do not skip ahead.
