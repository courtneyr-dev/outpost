---
title: Accessibility
description: "Where Outpost's accessibility work stands: what the repository's audit records as done and what still needs testing. Evidence, not a conformance claim."
---

Where Outpost's accessibility work stands: what the repo's own audit records as done, and what still needs testing. This page describes evidence, not compliance — Outpost does not claim WCAG conformance, and neither do these docs.

## The surfaces that matter

- **The composer PWA at `/post`** — the main user-facing UI: mode tabs, text fields, media upload, syndication chips, status messages.
- **wp-admin pages** — the Outpost pages (bookmarklets, settings tabs, Appearance, OAuth Connections, iOS Shortcut) use standard WordPress admin markup: form tables, nav tabs, notices.
- **Block editor sidebar** — the Outpost "fetch recent" picker panel.
- **Frontend output** — appended `u-syndication` links inside post content; plain anchor elements.

## What the repo's checklist records

The repo keeps a WCAG 2.1/2.2 Level AA checklist at [docs/accessibility/A11Y-CHECKLIST.md](https://github.com/courtneyr-dev/outpost/blob/main/docs/accessibility/A11Y-CHECKLIST.md). As of its v0.1.8 audit it records these as met in the composer:

- Semantic landmarks and a single `h1` per surface.
- Real interactive elements — buttons and links, no click-handling `div`s.
- A visible focus ring (themeable via the `--outpost-focus` token).
- Minimum 44px touch target size.
- Status messages announced via `aria-live` and `role="alert"`.
- Labels on all inputs.
- The page language set from your site's locale.

Two behaviors also help content accessibility: alt text is required on photo uploads (the post won't submit without it), and Outpost integrates with the Accessibility Checker plugin when that companion is active.

## Keyboard and screen reader notes

The checklist's items above are the relevant evidence: interactive controls are native elements (so they're keyboard-reachable), focus is visible, and posting status is announced. The composer is touch-first, though — if you rely on a keyboard or screen reader and hit a snag, that's worth reporting (see below).

## What still needs testing

Open items straight from the repo's checklist and test inventory:

- **Color contrast** — deferred in the checklist until the default design tokens ship; since colors come from your theme's tokens, contrast on your site also depends on your theme.
- **Focus management when switching composer modes** — listed as open.
- **Real-device screen reader testing** (VoiceOver, TalkBack) — listed as open.
- **No automated accessibility test suite** — the repo's tests don't include axe, pa11y, or similar tooling.

## Reporting accessibility issues

Accessibility reports are bug reports: open an issue at [github.com/courtneyr-dev/outpost/issues](https://github.com/courtneyr-dev/outpost/issues) with the assistive tech, browser, and composer mode involved.
