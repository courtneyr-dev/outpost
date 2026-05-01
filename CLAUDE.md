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

## Session A2 — Design Constraints

A2 introduces the `/post/*` route handler that consumes the detector for the PWA install-prompt page. Honor these when starting that session:

1. **PWA install-prompt page consumes `first_unsatisfied()`.** When the user hits `/post/*` and the chain is unsatisfied, render a friendly HTML page with the blocker's label and install/activate button — same presentation map shape as admin notices, just rendered on the frontend. Same upstream-first short-circuit. The map should probably extract into a shared helper (`outpost_dependency_presentation()` function or a static method on the detector) so admin notices and the PWA page reference one place. Decide where the helper lives when you reach A2; the constraint here is *don't duplicate the map*.
2. **REST 503 reasons (B2's territory, not A2's, but capture now).** When `outpost_is_ready()` is false and a REST request hits `/wp-json/outpost/v1/*`, return `503 Service Unavailable` with a JSON body that names `first_unsatisfied()` as the missing dependency. The PWA client uses this to drive its install prompt. Same single source of truth.
3. **`Outpost_Companion_Detector::optional_companions()` has no consumers yet.** Don't build them. Phase F adapters are where the optional set gets exercised — adapter classes consult `is_post_kinds_active()` etc. before declaring their capabilities. Premature consumers would invert the dependency direction.

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
