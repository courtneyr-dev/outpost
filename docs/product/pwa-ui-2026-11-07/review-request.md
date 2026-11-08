# PWA UI appearance — review request

Implementation of the [PWA UI implementation brief](implementation-brief.md). Draft PR: [courtneyr-dev/outpost#284](https://github.com/courtneyr-dev/outpost/pull/284).

| Item | Value |
| --- | --- |
| Base | `main` at `d79277f67000199245bfcfe344684b7d0aa167cd`, via handoff commit `8a06bc0` |
| Branch | `feature/pwa-ui-appearance` |
| Head reviewed | see the PR's latest commit; implementation complete at `a94eb5440c995caf9b8ba81201f7899a67c79f20` |
| Decision record | [docs/decisions/session-pwa-ui-appearance.md](../../decisions/session-pwa-ui-appearance.md) |
| Evidence | [verification.md](verification.md), [evidence/](evidence/) |

## Scope

In: the Hard Contract amendment, per-user style source and migration, resolver component tokens and contrast checks, bearer-auth appearance transport, first-paint boot, header and signpost, settings sheet, About rebuild, error-state split (401 / 403 / unreachable), shared layout of option pickers and tabs, wp-admin Appearance style source, docs pages for settings and accessibility.

Out, or partial (see [verification.md](verification.md) § Not done): composer draft persistence across re-sign-in, per-mode visual redesign beyond the shared controls (Photo, Recipe, and Life still use their existing field layouts under the new tokens), web fonts, device testing on iOS Safari and the installed PWA, VoiceOver, 200% text and 400% zoom checks, the docs-site build, and docs-site screenshot refresh.

## Appearance precedence and migration

Resolution per token: the person's override, then the selected source (the theme layer only under Use Site Theme), then the Outpost default. Contrast checks run on the final component pairs.

- New install (`outpost_appearance_schema.legacy_cutoff = 0`): everyone without a stored source gets Outpost Default + System.
- Upgrade (Outpost ran before, so `outpost_rewrite_version` was set when the marker was written): people registered at or before the upgrade time keep Use Site Theme; people added later get Outpost Default. A stored mode value plays no part.
- Explicit choice (`outpost_appearance_source` user meta) always wins.

Local proof: the wp-env site ran 1.0.23, then 1.0.24 in place; the pre-existing admin resolved to `outpost-source-site` and the composer painted with Twenty Twenty-Five's white and `#111111`.

## Source-to-token mapping

`Outpost_Token_Resolver::component_tokens()` maps the semantic palette onto the tokens `structure.css` reads:

| Semantic | Component tokens |
| --- | --- |
| `bg` (page) | `surface-bg` |
| `surface` (fields, cards) | `input-bg`, `card-bg`, `button-primary-bg-disabled` |
| `text` | `surface-fg`, `input-fg`, `card-fg`, `button-secondary-fg`, `button-ghost-fg`, `chip-fg`, `tab-indicator`, `logo`, `scenery-near` |
| `text_secondary` | `text-muted`, `input-placeholder`, `tab-fg`, `button-primary-fg-disabled` |
| `border` | `border`, `input-border`, `card-border`, `chip-border`, `button-secondary-border` |
| `accent` | `button-primary-bg`, `tab-bg-active`, `chip-bg-pressed`, and `focus-ring-color` when it clears 3:1 (else `text`) |
| `accent_2` | `link` when it clears 4.5:1 (else `accent`, else `text`), `scenery-far` |
| button label | whichever of `text`, `bg`, `surface` reads best on the fill; the fill moves if none reaches 4.5:1 |
| fonts | `font-family-body`, `font-family-display`, `font-family-mono` |

Under Use Site Theme, Global Styles semantic values come first (`styles.color.background`, `styles.color.text`, button background, link color), then palette slugs. Missing muted and border tones derive from the theme's own text and page colors. Without a dark style variation, Dark keeps Outpost's dark surfaces with the theme's accent and fonts.

## Files touched

PHP: `includes/theming/class-style-source.php` (new), `class-token-resolver.php`, `class-theme-json-reader.php`, `class-mode-controller.php`, `includes/class-appearance-rest-controller.php`, `includes/class-pwa-shell.php`, `includes/admin/class-appearance-settings-page.php`, `outpost.php`, `assets/js/appearance-boot.js` (new).

PWA: `pwa/src/lib/appearance.ts`, `use-appearance.ts` (new); `pwa/src/components/app-header.tsx`, `settings-sheet.tsx`, `signpost.tsx`, `scenery.tsx` (new); `composer-tabs.tsx`, `drawer.tsx`, `modes/about-tab.tsx`, six mode ledes; `lib/composer-config.ts`; `styles/structure.css`, `styles/tokens.css`, `styles/outpost-tokens.css`.

Tests: `tests/theming/StyleSourceTest.php`, `AppearanceRestControllerTest.php`, `TokenFileParityTest.php` (new) and additions to the resolver, reader, mode, shell, and admin tests; `pwa/src/lib/appearance.test.ts`, `use-appearance.test.tsx`, `components/signpost.test.tsx`, `settings-and-about.test.tsx` (new), `composer-tabs.test.tsx`, `composer-config.test.ts`.

## Risks

1. The Field Notes treatments now draw the flat design. Their `--plain` selectors are unchanged, but anyone who relied on the tilted tabs, uppercase stamp button, underline fields, or SVG radios sees a different look. The five SVGs in `assets/svg/` remain; two (`radio-idle.svg`, `radio-selected.svg`) are no longer referenced.
2. A bearer-only PWA on a device with no cached appearance paints the new-user default until the first read returns (one round trip). A cookie session paints its stored choice server-side.
3. The FY rule that adjusts `accent` against `surface` at 3:1 still runs, so an Outpost Default user with any override sees a derived orange (`#e27700`) instead of the file's `#fb8500`. People without overrides are unaffected.
4. Bundle size: JS 44.4 → 50.0 kB gzip, CSS 6.5 → 7.5 kB gzip (C0's 40 kB note was "through Phase C").

## Questions for review

1. Visual fidelity: compare [outpost-light-390-note.png](evidence/outpost-light-390-note.png) and [outpost-dark-390-note.png](evidence/outpost-dark-390-note.png) with the [mockup](theme-adaptive-outpost-ui-mockup.png). Which differences matter enough to change, given the light button label is violet ink (white on orange measures 2.48:1)?
2. Signpost: does [signpost-light.png](evidence/signpost-light.png) (canonical coordinates, stroke held at 1.5 CSS px minimum) read as the canonical mark at 24 and 32 px?
3. About information architecture: is connection → diagnostics → help → guides → IndieWeb reference the right order, and is anything in [outpost-light-390-about.png](evidence/outpost-light-390-about.png) still missing from the brief's list?
4. Feature omissions: compare the seven destination captures with the brief's inventory table. Any option or field you expect but can't find?
5. Accessibility evidence: is anything in [verification.md](verification.md) insufficient to support the WCAG claims it makes, beyond the device checks it already marks unverified?

Please name a file and line or a screenshot, the steps to reproduce, the impact, and the spec or acceptance item for each finding. Findings are recorded in [review-response.md](review-response.md).
