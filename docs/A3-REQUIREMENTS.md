# A3 Requirements

A3's scope per CLAUDE.md "Session A3 — Design Constraints" is the structural CSS, the `--outpost-*` token defaults, and the icon set. This file collects discovered-during-smoke-test follow-ups that are A3 work, not B0b regressions.

Each item lists what was observed, where the gap is, and what to ship.

## A3-1 — `apple-touch-icon` link in shell HTML head

**Observed:** B0b smoke test on iPhone Safari, Add-to-Home-Screen step (Stage 6). iOS doesn't read `manifest.json` `icons[]` for A2HS — it falls back to a Safari-screenshot-derived favicon when no `<link rel="apple-touch-icon">` is present in the page head.

**Gap:** `includes/class-pwa-shell.php` `render_shell()` emits `<link rel="manifest">` and the iOS standalone meta tags (`apple-mobile-web-app-capable`, `apple-mobile-web-app-title`, `apple-mobile-web-app-status-bar-style`) but no `<link rel="apple-touch-icon">`.

**Ship:**

- Single `<link rel="apple-touch-icon" href="<plugin-url>/assets/icons/apple-touch-icon-180.png">` in the shell head.
- 180×180 PNG at `assets/icons/apple-touch-icon-180.png` (iOS's preferred size; iOS down-scales for smaller display contexts).
- No `sizes` variants needed — iOS uses the 180 fallback for everything.

**Verification:** re-run B0b smoke Stage 6 on iPhone Safari. The home-screen icon should be the Outpost icon, not a Safari screenshot.

## A3-2 — Tune `apple-mobile-web-app-status-bar-style` to brand palette

**Observed:** B0b smoke test on iPhone Safari A2HS (Stage 6). The standalone window opened with iOS's default status-bar styling, which doesn't match Courtney's color palette (`russian-violet`, `prussian-blue`, etc.).

**Gap:** `class-pwa-shell.php:68` ships `<meta name="apple-mobile-web-app-status-bar-style" content="default">`. The `default` value gives iOS a white status bar with black text — fine for a neutral page, untuned for a brand.

**Ship:** change the content value once A3 server-renders `styles/outpost-tokens.css`. Three valid values:

- `default` — white background, black text (current — drop)
- `black` — solid black background, white text
- `black-translucent` — status bar overlays page content (use with `padding-top: env(safe-area-inset-top)` in structural CSS so content doesn't disappear behind the bar)

**Likely choice:** `black-translucent` paired with the `safe-area-inset-top` padding (mirrors the existing `safe-area-inset-bottom` pattern from A2 #6 and the Hard Contract). Page content fills the entire screen height; the status bar tints based on what's beneath it.

**Verification:** re-run B0b smoke Stage 6 on iPhone Safari A2HS. Status bar should match the design intent.

## A3-3 — Ship icon-192.png and icon-512.png assets

**Observed:** B0b smoke test on iPhone Safari Stage 4. `manifest.json` references:

- `/wp-content/plugins/outpost/assets/icons/icon-192.png`
- `/wp-content/plugins/outpost/assets/icons/icon-512.png`

Neither file ships yet. Browsers tolerate this (the manifest stays valid, install prompts may use a fallback icon), so the smoke test passed regardless — but a real install on Android would land with a missing icon.

**Gap:** `assets/icons/` directory exists (per A0 scaffold) but is empty.

**Ship:**

- `assets/icons/icon-192.png` (192×192 PNG, transparent background, brand-aligned).
- `assets/icons/icon-512.png` (512×512 PNG, same aesthetic).
- `assets/icons/apple-touch-icon-180.png` (180×180 PNG, same aesthetic) — pairs with A3-1.
- Optional: `assets/icons/icon-maskable-192.png` and `icon-maskable-512.png` with extra safe-area padding for Android's adaptive-icon mask. Add `purpose: "any maskable"` to the manifest icon entries that have masking variants.

**Verification:** Stage 4 of any future smoke test inspects the manifest and confirms the icon URLs return 200 OK with the expected dimensions. Android Chrome install (when available) shows the brand icon, not a fallback.

## A3-4 — Token defaults must verify at WCAG 4.5:1 contrast

**Observed:** v0.1.8 wordpress-accessibility audit. Outpost's CSS uses `var(--outpost-*, theme-fallback)` for all paint, which means contrast at runtime depends on the theme's tokens (or the cascade fallback). Outpost can't claim WCAG 1.4.3 Contrast (Minimum, Level AA) compliance until A3 ships measurable defaults.

**Gap:** No `styles/outpost-tokens.css` exists yet. A3 design constraint #1 (server-rendered tokens) is the right venue but it doesn't currently include a contrast requirement.

**Ship:** when authoring `styles/outpost-tokens.css`:

- [ ] `--outpost-primary-bg` and `--outpost-primary-fg` must meet 4.5:1 against each other.
- [ ] `--outpost-input-bg` and `--outpost-input-fg` must meet 4.5:1.
- [ ] `--outpost-error-bg` and `--outpost-error-fg` must meet 4.5:1 (errors are critical text per WCAG severity).
- [ ] `--outpost-focus` must have 3:1 contrast against the surface where the focus ring will render (typically the page background).

**Verification:** after building the token defaults, paste the values into a contrast checker (https://webaim.org/resources/contrastchecker/) for each pair. Document the resulting ratios in `THEME-INTEGRATION.md` (Phase J) so theme integrators know the *floor*; their overrides must meet or exceed the same ratios.

**Theme contract:** the Hard Contract guarantees that themes can override Outpost's tokens. The corollary is that themes MUST themselves meet 4.5:1 in their override values. Outpost can't enforce this at runtime, but documenting the contract in `THEME-INTEGRATION.md` makes it explicit.

## Cross-cutting

The four items above plus the existing CLAUDE.md A3 design constraints (server-rendered tokens, forced `safe-area-inset-bottom` only paint default, SW fetch handler stays out of A3) form the full A3 scope. CHANGELOG.md will reference both this file and the CLAUDE.md section when A3 closes.
