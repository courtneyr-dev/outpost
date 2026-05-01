# Changelog

All notable changes to Outpost are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Outpost adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Gate now detects IndieAuth status as the most-upstream dependency, ahead of Micropub. Discovered during Session A0 staging test: the WordPress.org Micropub plugin hard-requires IndieAuth at its own preflight, so a Micropub-active-but-IndieAuth-missing environment looks `is_plugin_active()`-true but has no Micropub endpoints registered. Notices now surface IndieAuth → Micropub → ready in upstream-first order. New helper `outpost_companion_plugin_status()` factors the detection logic; new `outpost_indieauth_status()` parallels the Micropub detector. `outpost_is_ready()` now requires both to be active.

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
