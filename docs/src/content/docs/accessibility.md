---
title: Accessibility
description: "Where Outpost's accessibility work stands in version 1.0.24: what the code and changelog record as fixed, and what still needs testing. Evidence, not a conformance claim."
---

Where Outpost's accessibility work stands in version 1.0.24: what the code and the changelog record as done, and what still needs testing. This page describes evidence, not compliance. Outpost doesn't claim WCAG conformance, and neither do these docs.

## The surfaces that matter

- **The composer app at `/post`:** the main interface, with its header, tabs, form fields, photo upload, syndication chips, Home launcher, Preferences sheet, and status messages.
- **wp-admin pages:** the Outpost pages (bookmarklets and composer defaults, Settings tabs, Appearance, OAuth Connections, iOS Shortcut) use standard WordPress admin markup: form tables, nav tabs, and notices.
- **The block editor sidebar:** the Outpost panel and its **Add from connected platforms** picker.
- **Frontend output:** the `u-syndication` links Outpost adds sit inside a `hidden` element, so they add nothing to the visible page or the accessibility tree.

## What's in place

The target is WCAG 2.2 Level AA. These come from the code and from the accessibility entries in the [changelog](https://github.com/courtneyr-dev/outpost/blob/main/CHANGELOG.md), including the 1.0.22 WCAG 2.2 AA source review:

- **Structure:** a single `h1` ("Outpost composer"), a tab list built to the WAI-ARIA tabs pattern with arrow-key navigation, and a page title that names the active tab, such as "Reply – Outpost".
- **Native controls:** buttons, links, and radio buttons, not click-handling `div` elements. Choices in Preferences are native radio groups and a native select. Home's cards are native buttons.
- **Focus:** a visible focus ring with at least 3:1 contrast in every scheme. Drawers keep focus inside while open, and a closed drawer is inert (1.0.24).
- **Touch targets:** at least 44px. Style and type choices are at least 44px tiles: Post's five styles are icon-and-label tiles, and Reply's and Doing's long type lists are tiles that wrap so every type stays visible (1.0.25). Home's cards and the "← Home" link are native buttons at least 44px tall.
- **Status messages:** each tab's status and error regions stay mounted and only their text changes, so VoiceOver on iOS announces them. Urgent errors use `role="alert"`; routine notices use `role="status"`.
- **Labels:** every input has a label, link text matches the visible text, and links that open a new tab say so to screen readers.
- **Language:** the page language comes from your site's locale.
- **Contrast:** the Outpost Default colors are tested against WCAG 2.2 AA (4.5:1 for text; 3:1 for borders, focus, and the logo). Under Use Site Theme, Outpost checks your theme's text, button, link, border, and focus colors at the same thresholds and adjusts the ones that fall short. Token overrides you mark "override anyway" skip that check.
- **Disabled buttons** keep their contrast: they use dedicated colors instead of lowered opacity (1.0.24). A disabled publish button has a line above it that names the missing required fields, so the reason isn't only visual (1.0.25).

Two behaviors also help the content you publish: photo posts require alt text (the post won't submit without it, and a separate control marks an image as decorative), and Outpost links to the Accessibility Checker report after each post when that plugin is active.

## What still needs testing

- **Real-device screen reader testing** with VoiceOver on iOS and TalkBack on Android hasn't been recorded for 1.0.24.
- **Focus when switching composer tabs** is still listed as open.
- **No automated accessibility tests** run against the composer. The repository's tests don't include axe, pa11y, or similar tools; the documentation site you're reading runs axe in its own build.
- **The repository checklist** at [docs/accessibility/A11Y-CHECKLIST.md](https://github.com/courtneyr-dev/outpost/blob/main/docs/accessibility/A11Y-CHECKLIST.md) records a full audit at version 0.1.8 and needs a re-audit against 1.0.24.

## Reporting accessibility issues

Accessibility reports are bug reports. Open an issue at [github.com/courtneyr-dev/outpost/issues](https://github.com/courtneyr-dev/outpost/issues) with the assistive technology, browser, device, and composer tab involved.
