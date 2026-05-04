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

## WordPress.org Compliance

Outpost is GPLv2-or-later, free, fully functional. Per [WordPress.org Plugin Guideline §5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#5-trialware-is-not-permitted), every feature in the WordPress.org version must work without payment. There are no:

- License keys
- Trial periods or time limits
- Usage quotas
- Features gated by payment
- Intrusive nag screens

**Adapter behavior corollary (Phase F):** when Outpost integrates with paid companion plugins (Yoast SEO Premium, paid ActivityPub variants), the *integration* may detect the paid plugin's presence and surface its features (focus keyphrase, custom federation routing). But Outpost's *own* functionality (note posting, reply, photo, etc.) MUST work whether or not the user has the paid companion. Detection enables; absence does not disable Outpost.

**Reject contributions that propose:**
- A "Pro" tier of Outpost itself
- Feature flags gated by license keys
- Time-limited trials
- Per-post or per-action quotas
- Adapter logic that disables Outpost features when a free companion is present but a paid one isn't

Informational comparison tables and feature-detection patterns are fine. The Hard Contract above (plugin owns layout, theme owns paint) takes precedence — Outpost's UI must not become an upsell surface for any third-party plugin or service either.

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

## Session B0b — Locked Decisions (shipped)

Closed 2026-05-01. The constraints below are now production code; the rationale stays here so future sessions don't re-litigate. B0b lands the login screen UI, the PHP manifest reader, the auth-callback handler, and the build-artefact tracking story.

1. **`Outpost_PWA_Assets` is `final`, static-only, with per-request caching.** Reads `build/pwa/.vite/manifest.json` once per request via `manifest()`. `entry_url()` resolves the hashed JS file URL; `entry_css_urls()` resolves the entry's CSS chunks (`css` array on the manifest record). All three fail gracefully — null/empty when the manifest is missing or invalid — so dev environments without a build still render the install-prompt path cleanly.
2. **`override_paths_for_tests()` is the only test seam on Outpost_PWA_Assets.** The class reads `OUTPOST_PLUGIN_DIR` and `OUTPOST_PLUGIN_URL` directly in production; tests substitute a temp directory + fake URL prefix via the override. Pattern reusable for future filesystem-touching classes.
3. **`build/pwa/` is committed to git** via `build/*` (ignore contents) + `!build/pwa/` (re-include) in `.gitignore`. The `gd-wordpress-deployer` rsync reflects the working tree, so the production build needs to ride along. `npm run build` is part of the deploy ritual, documented in `docs/STAGING-DEPLOY.md`. Symptom of forgetting: empty `<main id="outpost-root">` on staging, no console activity from the bundle.
4. **`/.vite/` anchored at root.** Vite's dev cache lives at `<project>/.vite/`; the production manifest lives at `build/pwa/.vite/manifest.json`. Anchoring with a leading slash keeps the dev cache ignored while letting the production manifest through. **Don't unanchor** — the manifest disappearing from git is a silent failure mode (the PHP shell skips the script tag, page loads, nothing happens).
5. **`render_shell()` skips both `<script type="module">` and `<link rel="stylesheet">` tags when the manifest is missing.** Dev environments without a build still render the install-prompt cleanly. The composer route renders an empty `<main id="outpost-root">` until the build exists — visible failure mode is "page loads but nothing happens," which is debuggable from the browser console.
6. **Auth flow orchestration in `pwa/src/lib/auth-flow.ts`.** Two public functions: `begin_login(me, client_id, redirect_uri, scope?)` and `handle_callback(query, client_id, redirect_uri)`. State persists in `sessionStorage` under keys `outpost.auth.{verifier, state, me, token_endpoint}`. sessionStorage was chosen deliberately over localStorage: a stale verifier from yesterday's abandoned flow shouldn't validate today's callback, and same-tab persistence across the auth-endpoint redirect is exactly what sessionStorage provides.
7. **`AuthFlowError` discriminates failure paths.** Codes: `state_mismatch`, `missing_state`, `exchange_failed`, `no_code`. The UI surfaces a different message per code so the user can pick the right recovery action. `handle_callback` always clears sessionStorage in `finally` — a failure must not leave orphaned state for the next attempt to inherit.
8. **Three Preact components for the user-facing surfaces.** `LoginScreen` (single form, `me` URL pre-filled with `location.origin + '/'`, submits via `begin_login`, navigates same-tab via `location.assign`). `AuthCallback` (mounts on `/post/auth/callback`, runs `handle_callback`, redirects via `location.replace` so the back button doesn't replay the consumed code). `ComposerPlaceholder` (logged-in state for B0b, shows `me` + scope, "Sign out" button that calls `clear_token` and reloads).
9. **Mount logic in `index.tsx`'s `App` component reads `detect_route(location.pathname)` once.** For `composer` route, calls `read_token` to choose between `LoginScreen` and `ComposerPlaceholder`. The route doesn't change without a navigation, so no `popstate` listener — a successful login does `location.replace('/post/')` which remounts the whole app fresh.
10. **`mount(root, props)` exported separately from the auto-mount block.** Top-level auto-mount only runs when `document.getElementById('outpost-root')` exists. The test-time module import doesn't accidentally mount because happy-dom's document doesn't have that element. Lets B0c+ tests assert the component tree against an injected root if needed without re-running auto-mount.
11. **CSS in the bundle for B0b; A3 will move tokens to server-rendered.** `pwa/src/styles/structure.css` ships in the Vite build. Per A3 design constraint #1, `styles/outpost-tokens.css` will be server-rendered and the structural CSS may relocate. Structure is plugin territory; color/font/radius/shadow tokens become theme-overridable via `var(--outpost-*, neutral-fallback)`.
12. **`OUTPOST_VERSION` bumped to `0.1.2`.** A2 Locked Decision #16: any deployable behaviour change moves the version, even when it's not a rewrite-table change. B0b ships the JS bundle enqueue + the login UI + the manifest reader — all observable changes.

## Session B1 — Locked Decisions (shipped)

Closed 2026-05-01. The constraints below are now production code; the rationale stays here so future sessions don't re-litigate. B1 lands the Micropub client + a minimal note-posting form, replacing B0b's `ComposerPlaceholder`.

1. **Per-post discovery, not persistent in token meta.** `discover_micropub_endpoint(me)` runs each fresh post; `NoteForm` caches the result in component state for the session. IDB schema unchanged — keeping it stable matters more than saving one HTTP request per post. B1c+ may persist `micropub_endpoint` in `StoredToken.meta` if discovery latency becomes a measurable UX issue.
2. **Form-encoded body, not JSON.** `h=entry&content=<text>`. Simplest valid Micropub request; every server accepts it. JSON Micropub (richer microformat shapes for replies/photos/articles) lands when Phase C's modes need it.
3. **201 Created and 202 Accepted both treated as success** per the Micropub spec. UI shows the same "Posted to: <url>" message for both — the async-vs-sync distinction isn't user-facing.
4. **`MicropubError` codes:** `discovery_failed`, `no_endpoint`, `post_failed`, `no_location`. Mirrors the `AuthFlowError` pattern from B0b. UI prefixes message with the code (`<code>: <message>`) so screenshot triage from staging is fast.
5. **`MicropubEnvironment { fetch }` is minimal.** No `crypto`/`random`/`indexedDB`. The Micropub client doesn't need them; fewer test stub surfaces is cheaper to maintain. Compare `IndieAuthEnvironment` (fetch + crypto + random) and `TokenStoreEnvironment` (indexedDB + crypto) — each env exposes only what its client actually consumes.
6. **`micropub.ts` reuses parse helpers from `indieauth.ts`** rather than re-implementing DOM/Link-header parsing. `parse_link_header` and `parse_html_endpoints` are exported from indieauth specifically so micropub can import them. Discovery shape is identical between the two specs.
7. **`composer-placeholder.tsx` deleted in this commit, not just unimported.** Replaced by `note-form.tsx`. Recoverable from `0cc82c9` if a future need re-emerges. Reducing surface area is the right call when "is there still a use for this?" is "no, never again."
8. **Hard Contract verified for B1 CSS.** `.outpost-textarea` paint fallbacks: `background: var(--outpost-input-bg, transparent)`, `color: var(--outpost-input-fg, inherit)`, `border: 1px solid var(--outpost-border, currentColor)`. **Not `revert`** — `revert` for form inputs would force UA defaults that ignore the theme (opposite of what we want for inputs). The B0b button hotfix used `revert` correctly because BOTH `background` and `color` collapsed to the same value; for textareas the two properties have different fallbacks (`transparent` vs `inherit`) so they don't collapse. The `revert` rule is for elements where same-property collapse is the failure mode, not for all paint fallbacks.
9. **`OUTPOST_VERSION` bumped to `0.1.4`** per A2 Locked Decision #16 (any deployable behaviour change moves the version). B1 ships a JS bundle change — observable behaviour on `/post/`.

## Session C0 — Locked Decisions (shipped)

Closed 2026-05-01. Phase C kickoff: tab framework first, Note plugged in, four stubs for the remaining modes. Establishes the design pattern explicitly before any of the bigger modes (Reply, Photo, Listen group, Article) lands.

1. **Tab framework first, modes second.** C0 ships the WAI-ARIA tabs scaffold with Note as the only real mode; Reply, Photo, Listen, and Article render placeholder cards naming which Phase C session lands the real implementation. Honest WIP indicator. No tabs hidden — the user sees all five modes are coming.
2. **WAI-ARIA tabs pattern (APG).** `role="tablist"` on the container with `aria-label="Composer modes"`. Each tab: `role="tab"`, `aria-selected`, `aria-controls`, roving tabindex (selected=0, others=-1). Each panel: `role="tabpanel"`, `aria-labelledby` pointing back at the tab. Closes A11Y-CHECKLIST Phase C focus-management forward gate.
3. **Automatic activation.** Arrow keys move focus AND switch the active tab in one keystroke. Appropriate because tab switches are cheap (toggle visibility on already-rendered panels). Manual activation (focus only, Enter/Space activates) is for tabs with side effects.
4. **All panels rendered eagerly; visibility toggles via the `hidden` attribute.** Keeps mode state across tab switches — text typed into Note's textarea persists when the user clicks Reply and back. Inactive panels are `display: none` via the UA stylesheet's `[hidden]` rule (and explicit fallback in `structure.css`).
5. **Wrap-around at the ends.** ArrowRight from last tab → first; ArrowLeft from first → last. Home/End jump unconditionally to first/last. The APG explicitly allows either wrap or stop-at-end; wrap is more keyboard-friendly for mobile/desktop both.
6. **Heading hierarchy: panel titles are `<h2>`, not `<h1>`.** B1's NoteForm used `<h1>` (page-level). With panels nested under the tablist, the panel headings drop to `<h2>`. The tablist's `aria-label` provides the page-level structural label; no separate `<h1>` is needed because the tablist IS the primary content. If a future surface (Phase H settings, B2 install-prompt) needs a page heading, it adds its own `<h1>`.
7. **Mode files live at `pwa/src/components/modes/*-mode.tsx`.** Clean separation: ComposerTabs is the framework; modes/ contains the per-mode components. Naming convention `<kind>-mode.tsx` matches IndieWeb post-kinds vocabulary.
8. **Stubs name their landing session.** Each placeholder card includes a one-line lede mentioning which Phase C session lands the real mode (`"Reply, Like, Repost… land in Phase C1"`). Future contributors see the placeholder and the timeline, not just "TODO."
9. **Bundle budget.** Phase C0 bundle: 30.71 KB / 10.84 KB gzipped (up from 27.88 / 10.00 at v0.1.8). PERFORMANCE-CHECKLIST.md sets a 40 KB gzipped ceiling for the entry through Phase C; current usage is 27% of budget. Future modes that exceed this budget code-split via dynamic `import()`.
10. **`exactOptionalPropertyTypes: true` requires conditional spread.** Passing `micropubEnv={micropubEnv}` to NoteMode when `micropubEnv` is `MicropubEnvironment | undefined` fails type-check under the strict ceiling. ComposerTabs spreads conditionally: `{...(micropubEnv ? { micropubEnv } : {})}`. Pattern reusable for any optional env that flows through wrapper components.

## Session B2 + C1 — Locked Decisions (shipped)

Closed 2026-05-01. Phase B2 (server-side mf2 preview endpoint with SSRF defenses) lands together with Phase C1 first variant (Reply mode). C1's other variants (Like, Repost, Bookmark, RSVP, Follow) extend Reply with a sub-mode picker in C1b — all share the same form shape and the same B2 endpoint.

1. **B2 endpoint at `/wp-json/outpost/v1/preview`.** POST with `{ url }` body returns `{ html, finalUrl, contentType }`. `Outpost_Preview_Endpoint` is `final`, static-only, `register()` hooks `rest_api_init` to register the route. `show_in_index => false` keeps it out of `/wp-json/`'s public route index — closes the AI Engine CVE-2025-11749 vulnerability class. Per PHP-SURFACE-CHECKLIST.md.
2. **Permission via the IndieAuth plugin's REST middleware.** `permission_callback` checks `current_user_can( 'edit_posts' )` — no custom bearer-token validation. The IndieAuth plugin's `authenticate` filter translates `Authorization: Bearer ...` headers into a WP current user before the permission callback runs, so the standard cap check works for both cookie auth (admin) and bearer auth (PWA).
3. **SSRF defenses.** Scheme allowlist (http/https only, before host check so `file://` gets `invalid_scheme` not `invalid_url`). `wp_safe_remote_get` for the fetch — auto-blocks loopback + private network ranges via WP's `http_request_host_is_external` filter chain. 3-second timeout. 5 MB response-size cap (post-fetch length check; not true streaming, but adequate for typical IndieWeb post sizes).
4. **Content-Type allowlist.** `text/html` and `application/xhtml+xml` only. Charset suffixes (`text/html; charset=utf-8`) accepted via `strpos === 0` prefix match. Rejects JSON, images, PDFs, anything else with a 415.
5. **Per-user rate limit, 30 requests/minute.** Transient-backed counter keyed on `outpost_preview_rl_{user_id}`. Returns 429 with `retryAfter: 60` when exceeded. Defends against accidental burst (autocomplete fires on every keystroke) and intentional abuse.
6. **Script + iframe stripping in returned HTML.** Defense in depth: the client never executes returned HTML (it parses for `<title>` via regex and forwards to microformats parsers when needed), but a future code path that naively renders shouldn't be a security regression. Strips `<script>`, `<iframe>`, `<object>`, `<embed>` blocks; `on*=` event handlers; `javascript:` / `data:` href and src.
7. **Client `extract_title()` via regex, not DOMParser.** Page titles are simple — a regex match plus entity decoding (named entities + numeric character references via pure string replace) covers 99%+ of real-world cases. Avoids the `innerHTML` hook flag and any DOM dependency. Richer mf2 parsing (h-card, h-entry properties beyond name) lands when other reply variants need it.
8. **`PreviewError` codes mirror the response shape.** `unauthorized` (401/403), `invalid_url` (client-side scheme check), `unsupported_content_type` (415), `rate_limited` (429), `server_error` (5xx), `fetch_failed` (network). UI surfaces `code: message` for screenshot triage, same as `MicropubError` and `AuthFlowError`.
9. **`post_h_entry({ properties, accessToken, micropubEndpoint })` is the new general-purpose Micropub poster.** Form-encodes h-entry properties from a `HEntryProperties` interface (content, name, summary, in-reply-to, like-of, repost-of, bookmark-of, category, mp-syndicate-to). Arrays get `[]` suffix per the Micropub spec. `post_note` is now a thin wrapper that calls `post_h_entry({ properties: { content } })` — preserves backward compat with B1 callers.
10. **Reply mode props don't carry `tokenStore`.** Sign-out is global (the page reloads on Sign Out, unmounting all modes). Only NoteMode needs `tokenStore` because it owns the Sign Out button. ReplyMode's props are `{ token, micropubEnv? }` — minimum it needs to discover the endpoint and post.
11. **Optional preview step in Reply mode.** User pastes target URL, optionally clicks "Show preview" to fetch citation context (page title + final URL after redirects). Preview is informational — submitting doesn't require previewing. Keeps the form usable when staging is slow or the target fails preview.
12. **`OUTPOST_VERSION` bumped to `0.1.10`.** Per A2 #16 — both PHP (REST route registration) and JS (Reply mode + post_h_entry refactor) ship deployable behavior.

## Session C1b — Locked Decisions (shipped)

Closed 2026-05-02. Adds three reply variants — Like, Repost, Bookmark — to the existing Reply mode under a single tab. RSVP and Follow defer to a future C1c (RSVP needs an extra `rsvp:` yes/no/maybe control; Follow's spec is contested across servers).

1. **One tab, four variants via a radio picker.** Reply, Like, Repost, Bookmark all share the same form shape (URL + optional content + target-property name); a single component handles all four. Tab label stays "Reply" as the umbrella; the visible affordance is the radio group at the top of the form.
2. **`VARIANTS: Record<Variant, VariantConfig>` is the table-data source.** Each entry maps a variant id to its label, target h-entry property name (`in-reply-to` / `like-of` / `repost-of` / `bookmark-of`), whether content is required, target-input label, content-textarea label, submit button label, and preview-intro string. Adding RSVP or Follow in C1c is a single-row addition (plus any UI quirks they need beyond the shared shape).
3. **Content-required only for Reply.** Reply needs both URL and content; Like, Repost, Bookmark only require URL. The `contentRequired` boolean per variant drives the submit-disabled logic and the `required` attribute on the textarea.
4. **`<fieldset>` + `<legend>` + `<label>`-wrapped radios** for a11y. Browser-native arrow-key navigation between radios in the same `name` group; Tab moves between fieldsets. Generous padding (`0.375rem 0.625rem`) on each `<label>` so the touch target is comfortable on mobile.
5. **`:has(input:checked)` for selected-state styling.** Modern selector, supported in iOS Safari 15.4+ and Chrome 105+. Outpost's existing browser baseline (the PWA already requires modern fetch/IDB/crypto.subtle) accommodates it. The pattern lets the visual state of the parent `<label>` reflect the radio's checked state without JS.
6. **Submit button label per variant.** "Post reply" / "Post like" / "Post repost" / "Post bookmark". The "Finding endpoint…" and "Posting…" transient labels still override during submission. The button stays a single primary action — switching variant changes its label, not its position.
7. **Property assignment uses computed key.** `properties[config.property] = trimmed_url` would be a TypeScript error under `exactOptionalPropertyTypes`; the working pattern is `{ [config.property]: trimmed_url, ...(content ? { content } : {}) }` (computed property in literal + conditional spread). Captured here because future modes that build dynamic `HEntryProperties` shapes will hit this same constraint.
8. **State preservation across tab switches.** Variant selection, target URL, content text, and component-cached endpoint URL all survive when the user clicks Note → Reply → Note again, courtesy of the C0 eager-render-and-hide pattern. Going across the whole composer round-trip without losing form state is the load-bearing UX win of the tab framework.
9. **7 new vitest tests for ReplyMode.** Component-level tests covering: 4 radios in order, Reply default, heading updates on variant change, submit-label updates per variant, Reply requires both URL+content, Like requires URL only, target-input label updates per variant. First component test for a mode (NoteMode/AuthCallback are still untested at the component level — covered by integration via micropub.test.ts and auth-flow.test.ts respectively). Vitest 115 → 122.
10. **`OUTPOST_VERSION` bumped to `0.1.11`.** Per A2 #16 — JS bundle change + new behavior (3 new post kinds posting through the same form). Bundle: 36.38 KB JS / 12.34 KB gzipped (was 35.23 / 11.97 — +0.37 KB gzipped). 31% of the 40 KB Phase C budget.

## Session F1 — Locked Decisions (shipped)

Closed 2026-05-04. First Phase F deliverable. Adds the outbound `?q=syndicate-to` chip surface, with ActivityPub as the single shipping adapter. Detection + chip surfacing only — no publishing logic in F1; the Pfefferle/Automattic ActivityPub plugin handles federation on its own `transition_post_status` hook.

1. **`Outpost_Companion_Base` extends with `syndicate_chip(): ?array`, default null.** The new method declares the chip-shape contract (`id`, `label`, `accepts`, `detected`) every adapter that contributes a syndication target overrides. Adapters that don't surface a chip (Post Kinds, Post Formats, XFN, Yoast, Accessibility Checker, Syndication Links) keep the default null; adapters that do (ActivityPub now, Bridgy Publish in F14, ManualShare in F9) override. The base remains abstract for the original three methods (`file()`, `label()`, `capabilities()`).
2. **The chip shape mirrors what the F5 inbound `Outpost_Source_Base` will need.** Fields are deliberately bidirectional: `id`, `label`, `accepts`, `detected` all carry over with the same semantics; F5's source adapters add `extractor` (oembed, og, mf2, rss, api) and `host_patterns`. The composer should iterate either family with a uniform vocabulary instead of branching on direction. Captured in the base's class-level docblock.
3. **The Shanske Micropub plugin's `micropub_syndicate-to` filter is the single integration point.** Outpost does NOT host its own `?q=syndicate-to` route. The Micropub plugin owns Micropub server semantics; Outpost contributes chips through the documented filter `(array $targets, ?int $user_id) => array` where each target is `[ 'uid' => string, 'name' => string ]`. The original prompt referenced a non-existent `Micropub_Server.php` — the actual home is `Outpost_Micropub_Bridges`, which already pairs the read-side filter with the write-side `after_micropub` action.
4. **`Outpost_Micropub_Bridges::merge_syndicate_chips()` projects the rich chip shape down to `[ uid, name ]`.** Adapters declare in the four-key shape; the merger projects `id` → `uid` and `label` → `name` for the filter consumer. De-duplication is by `uid`: if a chip with the same uid already exists in `$targets` (from another filter caller), the merger keeps the existing entry and skips the companion's. Existing chips win — adapters never overwrite a pre-registered destination.
5. **Naming divergence from the F1 prompt is intentional.** The prompt requested `Companion_Base.php`, `Companion_ActivityPub.php`, `Micropub_Server.php`, `tests/companions/Companion_ActivityPubTest.php`, `tests/fixtures/activitypub-plugin-fixture.php` — but the repo's pre-existing convention is `class-companion-base.php` (kebab-case files), `Outpost_*_Adapter` class names, all tests in `tests/unit/`, no fixtures directory. Following the existing convention preserves the eight already-shipped adapters, the Companion_Registry contract, and the existing test suite organization. Future Phase F prompts should expect `class-*-adapter.php` filenames.
6. **§5 posture preserved.** Detection is `is_plugin_active()` only. No calls into ActivityPub plugin internals, no embedded credentials, no external API calls. The chip is a pure intent signal — the user's "yes, federate this" toggle.
7. **i18n boundary.** The chip label `__( 'Fediverse (via ActivityPub plugin)', 'outpost' )` is wrapped per the project's i18n contract. The wrapping is safe because `merge_syndicate_chips()` runs as a filter callback on `micropub_syndicate-to`, which fires during request handling — well after `init`, so the textdomain JIT trap (covered separately) doesn't apply.
8. **Test posture: `WP_Mock::userFunction('is_plugin_active')` + `userFunction('get_plugins')` are both required for inactive-state tests.** When `is_plugin_active()` returns false, `Outpost_Companion_Detector::status()` falls through to `get_plugins()` to distinguish 'inactive' from 'absent'. Tests that exercise the inactive path must stub both. Active-state tests only need `is_plugin_active`.
9. **Generic test fixture values only.** The two research wiki documents (`concepts/posse-outbound-may-2026.md`, `concepts/capture-inbound-may-2026.md`) are keyed to a specific user's case-study handles. NONE of those handles appear anywhere in code or test fixtures; the only example values used are `example.social`, `example.com`, and `manual-mastodon-example-social`. Acceptance criterion grep confirms zero leakage.
10. **Tests added: 9.** PHPUnit count 128 → 137. New tests cover `Outpost_ActivityPub_Adapter` shape (file/label/capabilities/active+inactive `syndicate_chip()` returns), `Outpost_Companion_Base` default-null chip behavior, and `Outpost_Micropub_Bridges::merge_syndicate_chips()` across five paths (active append, inactive omit, existing-passthrough, dedupe-by-uid, non-array input). No version bump — F1 is a static scaffold + filter registration; no observable behavior change without the user installing a Micropub client and the ActivityPub plugin together.

## Session F2 — Locked Decisions (shipped)

Closed 2026-05-04. Builds on F1. Defines the `capabilities()` contract on `Outpost_Companion_Base`, implements it for ActivityPub with the conservative shape, adds `chips_for_mode()` on the registry, and exposes a per-mode chip listing endpoint at `/wp-json/outpost/v1/syndicate-targets`.

1. **`capabilities()` reclaimed for the chip shape; old slug accessor renamed `feature_slugs()`.** F1 introduced `syndicate_chip()` as the chip-shape method, but the F2 prompt called the same surface `capabilities()` — already taken by the slug-based method on `Outpost_Companion_Base` (used by all 8 adapters). Resolution: rename the slug accessor to `feature_slugs()` (8 adapter methods + base abstract + registry's `all_active_capabilities()` → `all_active_feature_slugs()` + 6 test assertions) and replace `syndicate_chip()` with `capabilities()` returning the richer F2 shape. Net effect — clearer naming (slugs vs chips don't share a method name) and `capabilities()` matches the contract every Phase F prompt will reference.
2. **`capabilities(): ?array` is concrete with default null, not abstract.** The F2 prompt specified abstract — but the 8 existing feature-surface adapters (Post Kinds, Yoast, XFN, Post Formats, Syndication Links, Accessibility Checker) don't surface syndicate-to chips and shouldn't be forced to declare an empty shape. Concrete-default-null lets feature-surface adapters keep doing what they do; syndication-target adapters (ActivityPub now, F9 ManualShare, F14 BridgyPublish) override. The two adapter categories are explained in the base class's class-level docblock.
3. **The full F2 chip shape is `{id, label, detected, accepts_modes, accepts_media, max_attachments, alt_passthrough, char_limit, caveats, requires_auth}`.** Documented in `Outpost_Companion_Base::capabilities()`'s docblock. Adapters that only need a subset (e.g. F1's old four-key shape) supply null/empty-array values for the unused keys.
4. **F2 → F5 symmetry remains.** The inbound `Outpost_Source_Base` planned for F5 mirrors capabilities() inverted: same field names — `id`, `label`, `detected`, `accepts_modes` (which composer modes the source can extract into), `caveats` — plus an `extractor` key naming the metadata recipe and `host_patterns` for host-match dispatch. Captured in the base's docblock so the F5 author can build on this without re-deriving the shape.
5. **`Outpost_Companion_Registry::chips_for_mode(?string $mode)` is the per-mode filter.** Iterates active companions, calls each `capabilities()`, returns those where `detected === true` AND the requested mode appears in `accepts_modes`. Pass null (or omit) to get every detected chip without filtering. The composer hits this for its syndication strip; `Outpost_Micropub_Bridges::merge_syndicate_chips()` calls it with null because the Shanske `micropub_syndicate-to` filter has no per-mode parameter.
6. **Mode validation is fail-OPEN.** Unknown modes return every detected chip rather than zero. The Outpost composer always sends a known mode; defensive callers (third-party Micropub clients passing unknown modes through future filter extensions) get the full set so a typo doesn't silently hide all destinations. Codified in the registry's `is_known_mode()` private helper plus the `outpost_known_composer_modes` filter so site owners can extend the recognized set.
7. **`/wp-json/outpost/v1/syndicate-targets?mode=...` is the new Outpost-owned endpoint.** The F2 prompt asked to extend the Shanske Micropub plugin's `?q=syndicate-to` handler with `&mode=`, but the Shanske filter contract has no mode parameter and Outpost can't change Shanske's plugin. Instead, Outpost ships its own REST route mirroring the security posture of preview/composer-config endpoints (cookie OR `edit_posts` cap OR bearer presence; `show_in_index => false`; static-data only with no SSRF surface or rate limiting needed). Composer reads from this endpoint when rendering per-mode strips; Shanske's `?q=syndicate-to` continues to serve all-modes calls for any third-party Micropub client.
8. **Filter `outpost_companion_capabilities` is the per-companion override hook.** Signature `(?array $caps, string $companion_id) => ?array`. Site owners can: (a) replace any capability key, (b) extend `caveats`, (c) restrict `accepts_modes` per a federation policy, (d) return null to force-hide a chip even when the underlying plugin is active. Applied inside `Outpost_ActivityPub_Adapter::capabilities()` before the return; future syndication-target adapters apply it the same way. The companion ID is passed so callers filtering on multiple companions can dispatch.
9. **Test fixture as a real file at `tests/fixtures/companion-restricted-modes.php`.** The fixture defines `Outpost_F2TestRestricted_Adapter` matching the registry's `^Outpost_[A-Z][A-Za-z0-9_]*_Adapter$` regex gate so tests can register it via the `outpost_companion_adapters` filter. The fixture's chip declares `accepts_modes => ['photo']` so per-mode filtering can be proven without depending on F9's ManualShare. Generic example-only values throughout — no real plugin file path, no instance handles, no case-study identifiers.
10. **`WP_Mock::onFilter()` requires `withAnyArgs()` not `with()`.** Discovered during F2 test development: `WP_Mock\Functions::type('array')` matchers passed to `with()` don't intercept the filter, leaving the default passthrough behavior. `withAnyArgs()` is the working form. Captured here so future tests setting up filter mocks default to the working pattern. Pairs with the existing CLAUDE.md A2 #8 workaround (`Filter::$filtersWithAnyArgs` static-state reset in `setUp()`).
11. **Tests added: 14.** PHPUnit count 137 → 151. New tests cover the rename (existing 4 adapter-shape tests), capabilities() shape with all 10 required keys, default-null when plugin inactive, the `outpost_companion_capabilities` filter (replace + force-null), `chips_for_mode()` across five paths (photo, note, restricted-fixture-excluded-on-note, restricted-fixture-included-on-photo, undetected-companions, unknown-mode-fail-open, null-returns-all), `known_modes()` shape, and the new endpoint across four paths (photo, unknown-mode-fail-open, no-mode, restricted-fixture-filtering). No version bump — F2 is contract evolution + new endpoint with no observable behavior change for users not running both Micropub + ActivityPub plugins.

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
