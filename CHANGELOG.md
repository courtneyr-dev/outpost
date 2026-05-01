# Changelog

All notable changes to Outpost are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Outpost adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
