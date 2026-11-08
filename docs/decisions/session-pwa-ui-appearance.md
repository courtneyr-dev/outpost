## Decision — Outpost Default design with optional Site Theme, independent of Light / Dark / System

Recorded 2026-11-07. Base: `main` at `d79277f`, handoff branch `feature/pwa-ui-design-handoff-2026-11-07` (`8a06bc0`). Implements [the PWA UI implementation brief](../product/pwa-ui-2026-11-07/implementation-brief.md) and the [approved mockup](../product/pwa-ui-2026-11-07/README.md).

### Amendment to the Hard Contract

The FY-Theming contract read "plugin owns layout, theme owns paint", and its default let the active theme's palette flow into every composer. Courtney authorized a deliberate change: **Outpost Default** is the shipped design, and **Use Site Theme** is an option each person can pick.

The contract now reads:

- Outpost owns layout, interaction, accessibility, and the default design tokens (`styles/outpost-tokens.css` and its bundled mirror).
- The person's selected style source supplies paint through the `--outpost-*` tokens. Outpost Default uses the token file. Use Site Theme gets tokens the resolver derives from the active theme.
- Components never hardcode paint. B6 (no hex in `structure.css`), token-file parity, shell isolation, and the seven public `--plain` opt-outs are unchanged.
- Site Theme stays portable and optional. No other site's palette, fonts, or theme slug is a plugin dependency.

[session-fy-theming.md](session-fy-theming.md) stays as the historical record. This decision supersedes its default-selection rule (theme first for everyone). Its override → theme → default chain survives as the Site Theme path. Its per-user storage, contrast enforcement, `--plain` API, and B6 rule are unchanged.

### Two independent choices

| Choice | Values | Storage |
| --- | --- | --- |
| Style source | `outpost` (Outpost Default), `site` (Use Site Theme) | user meta `outpost_appearance_source` |
| Appearance | `day` (Light), `night` (Dark), `system` (System) | user meta `outpost_appearance_mode` (unchanged) |

Overrides stay in `outpost_appearance_overrides` and apply under either source.

Resolution order per token: the person's explicit override, then the selected source (the theme layer under Site Theme, nothing extra under Outpost Default), then the Outpost default. Contrast checks run on the final component pairs (`Outpost_Token_Resolver::component_tokens()`).

### New users and upgrades

`Outpost_Style_Source::ensure_schema()` writes the site option `outpost_appearance_schema` once, on `init` (priority 10, before the rewrite flush writes `outpost_rewrite_version`) and on activation:

- Fresh activation (Outpost never ran here): `legacy_cutoff = 0`. Everyone without a stored source gets Outpost Default + System.
- Upgrade (`outpost_rewrite_version` already set): `legacy_cutoff` is the upgrade time. A person registered at or before it, with no stored source, keeps Use Site Theme, which is what they saw before. Anyone registered later gets Outpost Default.

A stored mode value plays no part. Tests: `tests/theming/StyleSourceTest.php`.

### Delivery to the standalone shell

Observed on 1.0.23 (local wp-env, 2026-11-07):

1. `render_shell()` printed `styles/outpost-tokens.css` and a mode class, and never called `Outpost_Token_Resolver::to_css()`. A theme palette or override never reached `/post/`; only the admin preview used it.
2. The resolver emitted `--outpost-bg`, `--outpost-text`, `--outpost-accent`; `structure.css` reads `--outpost-surface-bg`, `--outpost-input-*`, `--outpost-button-*`. Even emitted, the values would have missed most components.
3. `structure.css` read `--outpost-font-body` / `--outpost-font-heading` / `--outpost-font-mono`, which no token file defines, so text fell back to the browser serif.
4. `/appearance/tokens` had no bearer-token path, so the installed PWA (which a page load can't tie to a user) could neither read nor save preferences.

Now:

- The resolver derives the full component token set and emits it with `to_scoped_css()`, scoped to `.outpost-source-{outpost|site}` plus the mode classes, so it outranks the token file only for the chosen source.
- The appearance controller uses `Outpost_Bearer_Auth`; the PWA POSTs its token in the body (header-stripping hosts) to read state without writing, and with `style_source` / `mode_preference` to save. A token needs `create`, `draft`, `update`, or `profile` scope. Responses send `Cache-Control: no-store, private`.
- For a cookie-identified user the shell prints their scoped CSS and classes. For everyone, a classic script, `assets/js/appearance-boot.js`, runs right after `<body>` opens and applies the device's cached mode, source, and CSS before first paint (the CSP has no inline script, and a module script runs after paint). The cache holds preferences and colors, never a credential.
- The shell prints the theme's `@font-face` rules through `wp_print_font_faces()`, so Site Theme can render the theme's fonts. Nothing else from the theme's asset queue loads.

### Site Theme reading

- Semantic Global Styles values win over palette-slug guesses: `styles.color.background`, `styles.color.text`, the button element's background (accent), and the link color (secondary accent). Preset references resolve through the palette; anything that isn't a hex color is ignored.
- `primary` no longer counts as body text: many themes use it for a brand color.
- No dark style variation: the dark scheme keeps Outpost's dark surfaces and text and carries over the theme's accent and fonts. The light palette is never reused for dark surfaces, and colors aren't inverted.
- Cache entries are keyed on user, scheme, and source, and checked against the theme identity plus a signature of the theme layer and overrides, so a Site Editor palette edit invalidates them without a theme version change.

### Fonts

No web fonts ship. The CSP allows `font-src 'self'` and the plugin carries no font files. The stacks name IBM Plex Sans / Mono and Newsreader first and fall back to the system UI stack and Georgia.

### Install identity

`assets/icons/outpost-icon.svg`, the PNG manifest icons, the maskable icon, and the Apple touch icon are unchanged. The in-app signpost is the outline derivative in `docs/product/pwa-ui-2026-11-07/outpost-signpost-outline.svg`, colored by `--outpost-logo`.

### Rejected

- A second theme engine or a UI framework.
- The Outpost & Co. forest-green marketing kit. It belongs to a separate company, and the old `:root` values from it are replaced.
- Inline `<script>` for first paint; it would need `'unsafe-inline'` or nonces, which break under edge caching.
- Inferring the upgrade path from a stored mode value.
