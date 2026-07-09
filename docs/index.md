# Outpost documentation

Outpost is a mobile-first Progressive Web App (PWA) composer for WordPress. This is the user documentation hub — start here to install the plugin, set it up, and post from your phone.

## What Outpost does

Outpost serves a posting app at `/post` on your own WordPress site. Open it on your phone, write a note, reply to a URL, or log something you're listening to or watching, and the post publishes to your site through the Micropub API. Syndication chips let you send the same post to other platforms — the IndieWeb pattern called POSSE (Publish on your Own Site, Syndicate Elsewhere).

Because the composer is a real PWA, it installs to your iOS or Android home screen, works offline with a queued-draft system, and hooks into the OS share sheet so you can reply to any page from any app.

Outpost is for WordPress site owners who want fast mobile posting without Jetpack, app stores, or third-party accounts. It authenticates with IndieAuth — a sign-in protocol where your own website is your identity.

## Current status

Outpost is pre-release software, as of version 0.1.114 (plugin header). It is not confirmed to be available in the WordPress.org plugin directory — install it from GitHub (see [Installation](installation.md)).

## Requirements

- WordPress 6.5 or newer
- PHP 8.2 or newer
- The [IndieAuth plugin](https://wordpress.org/plugins/indieauth/) (required)
- The [Micropub plugin](https://wordpress.org/plugins/micropub/) (required)

If either required plugin is missing or inactive, Outpost shows an admin notice with an install/activate link and the `/post` page shows a setup prompt instead of the composer.

Optional companion plugins light up extra features when active: Post Kinds for IndieWeb (media "Look it up" search and proper display for Listen/Watch/Read/Checkin/Play posts), Post Formats for Block Themes, Link Extension for XFN, Syndication Links, Yoast SEO, and ActivityPub.

## Read this first

1. [Installation](installation.md) — install from GitHub, activate the dependencies.
2. [Getting started](getting-started.md) — sign in, post your first note, install to your home screen.

## All pages

- [Installation](installation.md)
- [Getting started](getting-started.md)
- [Settings](settings.md)
- [Common tasks](common-tasks.md)
- [Screenshots](screenshots.md)
- [Playground preview](playground.md)
- [Troubleshooting](troubleshooting.md)
- [FAQ](faq.md)
- [Privacy and data](privacy-and-data.md)
- [Accessibility](accessibility.md)
- [Documentation plan](documentation-plan.md)

## For developers

The rest of this `docs/` directory is developer-facing. Key entry points:

- [Adapter notes](adapters/) — one file per syndication/integration adapter.
- [Concepts](concepts/) — encryption key handling, OAuth foundation, POSSE base class, settings UI, and [why we recommend these platforms](concepts/why-we-recommend-these-platforms.md).
- [Accessibility checklist](accessibility/A11Y-CHECKLIST.md) — WCAG 2.1/2.2 AA audit status.
- [Security surface checklist](security/PHP-SURFACE-CHECKLIST.md).
- [UX test suite](UX-TESTS.md) and [staging deploy notes](STAGING-DEPLOY.md).
- Repo root: [README](../README.md), [CHANGELOG](../CHANGELOG.md), [CONTRIBUTING](../CONTRIBUTING.md), [SECURITY](../SECURITY.md).

---

[Documentation home](index.md) · Next: [Installation](installation.md)
