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

| Companion | Outpost behavior |
|-----------|------------------|
| `indieauth/indieauth.php` (Pfefferle, Shanske) | **Required.** The Micropub plugin hard-requires IndieAuth at its own preflight, so Outpost surfaces IndieAuth status as the most-upstream notice. Detected by `outpost_indieauth_status()`. |
| `micropub/micropub.php` (David Shanske) | **Required.** Hybrid gate: plugin loads, admin notice when IndieAuth-or-Micropub is absent or inactive, PWA route renders friendly install page, REST routes return 503. Detected by `outpost_micropub_status()`. |
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
