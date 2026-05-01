# Changelog

All notable changes to Outpost are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Outpost adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/courtneyr-dev/outpost/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/courtneyr-dev/outpost/releases/tag/v0.1.0
