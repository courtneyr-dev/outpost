# Outpost

[![CI](https://github.com/courtneyr-dev/outpost/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/courtneyr-dev/outpost/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/courtneyr-dev/outpost)](https://github.com/courtneyr-dev/outpost/releases/latest)
[![License](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)

> *Post from your outpost. Reach your people everywhere.*

Mobile-first Progressive Web App composer for IndieWeb POSSE workflows. Built for WordPress 6.5+ / 7.0 development branch.

**Status:** Pre-release, [v0.1.4](https://github.com/courtneyr-dev/outpost/releases/tag/v0.1.4) — first functional end-to-end version. Sign in via IndieAuth, post a note via Micropub, see the new post URL. Composer modes (Reply, Photo, Article, Listen group) land in Phase C.

## What this is

Outpost is a WordPress plugin that ships a mobile-first PWA composer at `/post` (configurable). It's optimised for the post shapes IndieWeb people actually publish — quick notes, replies, likes, photos, and life-tracking entries (listen, watch, read, checkin, play) — with one-tap syndication chips that default to **on** for every configured destination.

It works standalone with the [Micropub plugin](https://wordpress.org/plugins/micropub/) (required) and lights up additional capabilities when companion plugins are also active. No Jetpack, no app store, no third-party auth.

## Why this exists

Mobile posting on a self-hosted WordPress site in 2026 is broken unless you accept Jetpack auth, which is a non-starter for IndieWeb-aligned users. Outpost replaces the mobile composer with a real PWA served from your own domain, using Micropub as the API and IndieAuth for browser-side auth.

## Companion plugins

Outpost detects companions at runtime (not at install time) and updates the composer UI as you activate plugins.

| Companion | When active |
|-----------|-------------|
| [Micropub](https://wordpress.org/plugins/micropub/) (David Shanske) | **Required.** Server endpoint. |
| [IndieAuth](https://wordpress.org/plugins/indieauth/) | Auth provider. Falls back to application passwords. |
| [Post Kinds for IndieWeb](https://github.com/courtneyr-dev/post-kinds-for-indieweb) | Listen / Watch / Read / Checkin / Play / Follow modes. |
| [Post Formats for Block Themes](https://github.com/courtneyr-dev/post-formats-for-block-themes) | Format selector + auto-detection. |
| [Link Extension for XFN](https://github.com/courtneyr-dev/link-extension-for-xfn) | Relationship picker on reply targets. |
| [Syndication Links](https://wordpress.org/plugins/syndication-links/) | Destinations populate syndication chips. |
| Yoast SEO | Focus keyphrase + meta description. |
| ActivityPub / Bridgy | Surface as syndication chips automatically. |

## The hard contract

**Plugin owns layout. Theme owns paint.**

- Plugin always owns: PWA shell, routes, service worker, manifest, composer interaction, structural CSS (layout, iOS safe-area, touch targets, focus).
- Theme always owns: every color, every font, spacing, radius, shadow, button fills, link colors.
- Plugin CSS only references colors and fonts through `var(--outpost-*, neutral-fallback)`. No part of Outpost ever forces a color the theme didn't ask for.
- One forced default: `padding-bottom: env(safe-area-inset-bottom)` on the iOS bottom toolbar.

## Prior art

Outpost evolves from prior IndieWeb work for WordPress.

- **IndieWeb Press This** (Pfefferle, Shanske, Barrett) — bookmarklet pattern for Reply/Like/Repost/RSVP/Follow. Outpost extends and modernizes.
- **Micropub plugin** (David Shanske) — required dependency.
- **IndieAuth plugin** (Pfefferle, Shanske) — auth.
- **IndieBlocks** (Jan Boddez) — companion for theme blocks.
- **Bridgy** (Ryan Barrett) — round-trip syndication.

The IndieWeb WordPress community built the foundation Outpost sits on top of.

## Development

```bash
composer install && npm install
npm run dev             # Vite dev server for PWA frontend
npm run build           # Production PWA build to /build/pwa/
composer test           # PHPUnit
composer lint           # PHPCS (WordPress-Extra)
composer lint:section5  # §5 audit (case-study leak / credential / instance / i18n)
composer analyze        # PHPStan
npm run lint            # ESLint
npm run test:e2e        # Playwright
```

See `CLAUDE.md` for the development conventions and session-based build plan, and `CONTRIBUTING.md` for the §5 audit lint that runs in CI on every PR.

## Status & roadmap

Outpost is being built across ~40 small, atomic sessions, each scoped to a specific deliverable. Phases:

- **A — Foundation** (slug, scaffold, companion detector, routes)
- **B — Auth and Micropub** (IndieAuth, Micropub client, server-side mf2 preview)
- **C — Composer modes** (Note → Reply → Photo → Listen group → Article → More pull-out)
- **D — PWA polish** (manifest, service worker, offline queue, voice, iOS safe-area)
- **E — Share-sheet and bookmarklets** (Web Share Target, iOS Shortcut, multi-action bookmarklet generator, Bridgy auto-suggest)
- **F — Companions** (adapters)
- **G — Security** (token hardening, CSP, file-upload, rate-limit, URL validation, pen test)
- **H — Settings and onboarding**
- **I — Distribution** (WordPress.org submission)
- **J — Documentation and launch**

## License

GPLv2 or later. See [`LICENSE`](LICENSE).

## Author

[Courtney Robertson](https://courtneyr.dev) — [@courtneyr-dev](https://github.com/courtneyr-dev).
