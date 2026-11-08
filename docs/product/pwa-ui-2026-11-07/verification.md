# PWA UI appearance — verification

Evidence for [the implementation brief](implementation-brief.md). States are kept apart: **implemented**, **locally verified** (a command or capture on this machine), **CI-verified** (a GitHub Actions job on [courtneyr-dev/outpost#284](https://github.com/courtneyr-dev/outpost/pull/284)), and **device-reviewed** (a real phone). Nothing here is device-reviewed.

## Environment

- macOS (Darwin 25.6), PHP 8.5.9, Composer 2.10.2, Node 26.11.1 (Node 20 via `npx node@20` for vitest), npm 11.20.0.
- Locked dependencies only (`npm ci`, `composer install`); no package added. axe-core 4.13.0 was already in `package-lock.json` as a transitive dependency.
- Local site: this repo's own wp-env (WordPress 6.7, IndieAuth 5.0.0, Micropub 2.5.4, Twenty Twenty-Five), `http://localhost:8888`, wp-env's synthetic `admin` account. Outpost ran 1.0.23 there first, then 1.0.24 in place, which exercised the upgrade path. No real site or account was used; nothing was published.
- Local deviation: IndieAuth 5.0.0 rejects `me=http://localhost:8888/` as invalid, so the capture script removes `me` from the authorize redirect to complete sign-in. Production sites use an https `me` and are unaffected.
- Captures: Playwright 1.62.1 (Chromium), persistent profile, `bypassCSP` only so axe can be injected.

## Commands and results (local, final branch state)

| Command | Result |
| --- | --- |
| `composer test` | pass — 1820 tests, 4060 assertions, 185 skipped (skips unchanged from baseline) |
| `composer lint` | pass — no PHPCS errors or warnings |
| `composer analyze` | pass — PHPStan level 6, no errors |
| `composer lint:section5` | pass — "Section 5 audit passed", B6 OK |
| `npm run typecheck` | pass |
| `npm run lint` | pass |
| `npm test -- --run` (Node 26) | fail — 58 failed / 497 passed. The same 58 fail on the untouched baseline: Node 25+ defines its own `localStorage` global, which shadows happy-dom's. Not a regression. |
| vitest on Node 20 (CI's version): `npx -y node@20 node_modules/vitest/vitest.mjs run` | pass — 50 files, 556 tests (baseline 513) |
| `npm run check:tokens` | pass — 253 declarations match |
| `npm run build` | pass — JS 50.1 kB gzip (was 44.4), CSS 7.6 kB gzip (was 6.5) |
| `npm run test:integration` | not run as written: the script's `--env-cwd=wp-content/plugins/outpost` doesn't match this worktree's mount name. Run with `--env-cwd=wp-content/plugins/outpost-pwa-ui`: 185 tests, 129 passed, 32 skipped, 56 errors/failures, all in mock-backed classes that can't reach the WireMock sidecar (`host.docker.internal:8080`). WireMock wasn't started locally (disk full). CI runs it with the sidecar. |
| `npm run test:e2e` | fail — "No tests found": `tests/e2e/` holds only `.gitkeep`, so Playwright collects vitest files. Pre-existing; CI doesn't run e2e. |
| docs site build | skipped locally — needs a separate `docs/` install; the volume had under 500 MB free. CI's "Docs" workflow passed at `a94eb54` ([run 42435035738](https://github.com/courtneyr-dev/outpost/actions/runs/42435035738)). The six About help URLs each returned HTTP 200. |

## CI

Draft PR [courtneyr-dev/outpost#284](https://github.com/courtneyr-dev/outpost/pull/284). Status when this file was written: Section 5 audit, PHPCS + PHPStan, and PHPUnit on PHP 8.2, 8.3, and 8.4 passed; TypeScript + Vitest + build smoke, the integration suite, and Build and quality checks were pending. See the PR's checks for the current state; this file doesn't claim CI green.

## Tests added (what each guards)

- `tests/theming/StyleSourceTest.php` — new install vs. upgrade default, registration cutoff, stored choice wins, mode value ignored, marker written once, invalid values rejected without writes.
- `tests/theming/AppearanceRestControllerTest.php` — 401 with no writes, bearer token without a writing scope refused, `create` scope passes, token-only POST reads without writing, source save returns scoped CSS, invalid source rejected without writes, mode and source independent, overrides survive a source change, `no-store, private` + `Vary`, one user's save never touches another.
- `tests/theming/TokenFileParityTest.php` — `styles/outpost-tokens.css` equals the resolver's derivation from `DEFAULTS` for both schemes, and 26 pairs per scheme meet their WCAG targets.
- `tests/theming/TokenResolverTest.php` additions — Outpost Default ignores the theme but keeps overrides; semantic Global Styles; pale-accent button label; dark fallback without a dark variation; dark variation honored; per-source cache; Site Editor palette edit invalidates without a version change; CSS-injection values dropped; scoped CSS structure; theme-derived muted and border tones.
- `tests/theming/ModeControllerTest.php` — saving an unchanged mode succeeds (fails before the fix; the mock now returns false for unchanged values, as WordPress does).
- `tests/unit/PWAShellTest.php`, `tests/admin/AppearanceSettingsPageTest.php` — source class, boot script before the root, no per-user CSS under Outpost Default; admin save persists source, rejects unknown.
- `pwa/src/lib/appearance.test.ts` — cache round trip, storage denied or full, class replacement (never two mode classes), one style element, transport (token in body and header, never URL, `credentials: 'omit'`), 401/403/500/offline/timeout mapping, reconciliation.
- `pwa/src/lib/use-appearance.test.tsx` — new-user default, offline save stays pending and says so, pending resent and marked saved, server wins over a stale copy, other account's copy ignored, other-tab change applied, System follows OS changes and Light doesn't, denied storage.
- `pwa/src/components/signpost.test.tsx` — paths and rects equal the canonical SVG's, outline in `currentColor` with no hex, unique mask per instance, decorative vs. named, stroke ≥ 1.5 CSS px at 24–64.
- `pwa/src/components/settings-and-about.test.tsx` — two labeled radio groups, independent handlers, persistent status region, honest device-only copy, header names icon-only controls, About runtime values and no publish or profile fields, real help URLs, diagnostics without a token.
- `pwa/src/components/composer-tabs.test.tsx` — 401 offers sign-in and doesn't retry; 403 says "didn't allow", offers no sign-out, doesn't retry.

Payload preservation: no Micropub property mapping, validation, or submission code changed; the existing mode, micropub, photo, recipe, life, listen, and reply tests pass unchanged.

## Measured contrast (Outpost Default token file)

Ratios from `Outpost_Contrast::ratio()` on the shipped `styles/outpost-tokens.css`; the same pairs are asserted in `TokenFileParityTest`. Site Theme values are checked at runtime by `component_tokens()` at the same thresholds.

| Pair | Light | Dark | Target |
| --- | --- | --- | --- |
| Body text on page | #241c4a on #fbfaf5 = 15.00:1 | #f7f4ff on #1e1838 = 15.56:1 | 4.5:1 |
| Field text | #241c4a on #ffffff = 15.67:1 | #f7f4ff on #2a2350 = 13.29:1 | 4.5:1 |
| Placeholder | #5f5873 on #ffffff = 6.71:1 | #c9c1ef on #2a2350 = 8.50:1 | 4.5:1 |
| Muted text on page | #5f5873 on #fbfaf5 = 6.42:1 | #c9c1ef on #1e1838 = 9.94:1 | 4.5:1 |
| Primary button label | #241c4a on #fb8500 = 6.31:1 | #1e1838 on #ff9a2e = 7.98:1 | 4.5:1 |
| Disabled button label | #5f5873 on #ffffff = 6.71:1 | #c9c1ef on #2a2350 = 8.50:1 | 4.5:1 |
| Selected tab label | #241c4a on #fb8500 = 6.31:1 | #1e1838 on #ff9a2e = 7.98:1 | 4.5:1 |
| Unselected tab label | #5f5873 on #fbfaf5 = 6.42:1 | #c9c1ef on #1e1838 = 9.94:1 | 4.5:1 |
| Link on card | #5b4a9e on #ffffff = 7.20:1 | #a59bd6 on #2a2350 = 5.68:1 | 4.5:1 |
| Error text | #241c4a on #fbe9e4 = 13.34:1 | #ffd9d2 on #4a1f2a = 10.59:1 | 4.5:1 |
| Field boundary | #7a70b0 on #ffffff = 4.40:1 | #9a90cf on #2a2350 = 4.99:1 | 3:1 |
| Card boundary | #7a70b0 on #fbfaf5 = 4.21:1 | #9a90cf on #1e1838 = 5.84:1 | 3:1 |
| Focus ring on page | #241c4a on #fbfaf5 = 15.00:1 | #ff9a2e on #1e1838 = 7.98:1 | 3:1 |
| Focus ring on field | #241c4a on #ffffff = 15.67:1 | #ff9a2e on #2a2350 = 6.81:1 | 3:1 |
| Selected-tab edge | #241c4a on #fbfaf5 = 15.00:1 | #f7f4ff on #1e1838 = 15.56:1 | 3:1 |
| Signpost outline | #241c4a on #fbfaf5 = 15.00:1 | #f7f4ff on #1e1838 = 15.56:1 | 3:1 |
| Orange fill on page (informational) | #fb8500 on #fbfaf5 = 2.38:1 | #ff9a2e on #1e1838 = 7.98:1 | n/a |

The light orange fill is 2.38:1 against paper, so no state relies on it: the button carries a 6.31:1 label, and the selected tab adds a 3px violet edge and heavier weight. The mockup's white-on-orange (2.48:1) is not used. Spec: [color contrast](https://specification.website/spec/accessibility/color-contrast/).

## Automated accessibility scan

axe-core 4.13.0, tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`, run on the rendered page after each capture. 22 views, 0 violations: Outpost Default × light/dark × seven destinations plus the open settings sheet (16), and Use Site Theme × light/dark × Post, About, settings (6). Raw results: [outpost-results.json](evidence/outpost-results.json), [site-results.json](evidence/site-results.json). An automated scan doesn't establish conformance.

## Scripted interaction checks (local Chromium)

| Check | Result | Spec |
| --- | --- | --- |
| No page-level horizontal scroll at 320 CSS px | `scrollWidth` 320 = viewport 320 | [touch targets](https://specification.website/spec/accessibility/touch-target-size/) / reflow |
| Tab order from page load | header Light/Dark/System group → Settings → selected tab → first field (Shift+Tab reverses it) | [keyboard navigation](https://specification.website/spec/accessibility/keyboard-navigation/) |
| End key on the tab strip | selects About and scrolls the strip so About is fully visible | [keyboard navigation](https://specification.website/spec/accessibility/keyboard-navigation/) |
| Composer inert while settings open | `inert === true` | focus containment |
| Escape closes settings and returns focus | focus on the settings button (`aria-haspopup="dialog"`); fixed in `940e662` | [focus indicators](https://specification.website/spec/accessibility/focus-indicators/) |
| Offline change | body switches to `outpost-mode-night`; sheet: "Applied on this device; not saved to your site because you're offline." | form errors / honest state |
| Reconnect | pending choice sent once; sheet: "Saved to your site."; pending cleared from the device copy | — |
| System follows the OS while open | `data-outpost-scheme` light → dark; body `rgb(251, 250, 245)` → `rgb(30, 24, 56)` | — |
| Bearer read and save | POST `/appearance/tokens` 200, `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` | — |
| Measured sizes at 390 | tabs 44 px tall, option tiles 62 px tall, header controls 44 × 44, input font 16 px | [touch targets](https://specification.website/spec/accessibility/touch-target-size/) |

Bugs these checks found and fixed: Tab from page load skipped the header and tab strip (a `scrollIntoView()` on mount moved the sequential-focus starting point); focus left inside the closed sheet; a repeat save of the same mode returned 400; the tab strip rested at `scrollLeft` 24 so the start cue showed; a file input overflowed its row; radios drawn as background SVGs vanished on dark surfaces.

## Evidence index

All captures are synthetic: `localhost:8888`, the wp-env `admin` account, no drafts, no locations.

| File | What it shows | Acceptance item |
| --- | --- | --- |
| [outpost-light-390-note.png](evidence/outpost-light-390-note.png) | Post, Outpost Default, light, 390 px: header with signpost and wordmark, Light/Dark/System shortcut, settings button, tab strip with the end-edge cue, five Style tiles, boxed fields, orange Post button with violet label, scenery below the actions. | Default design, Post inventory |
| [outpost-dark-390-note.png](evidence/outpost-dark-390-note.png) | Same in the violet dark scheme. | Dark scheme |
| [outpost-light-390-reply.png](evidence/outpost-light-390-reply.png), [dark](evidence/outpost-dark-390-reply.png) | Reply with all 11 types as tiles and the target URL field. | Reply inventory |
| [outpost-light-390-photo.png](evidence/outpost-light-390-photo.png), [dark](evidence/outpost-dark-390-photo.png) | Photo with the file picker and its existing alt-text flow. | Photo |
| [outpost-light-390-listen.png](evidence/outpost-light-390-listen.png), [dark](evidence/outpost-dark-390-listen.png) | Doing with all 15 kinds as wrapping tiles, lookup, rating, optional photo. | Doing inventory |
| [outpost-light-390-life.png](evidence/outpost-light-390-life.png), [dark](evidence/outpost-dark-390-life.png) | Life with all six kinds. | Life inventory |
| [outpost-light-390-recipe.png](evidence/outpost-light-390-recipe.png), [dark](evidence/outpost-dark-390-recipe.png) | Recipe's structured fields. | Recipe |
| [outpost-light-390-about.png](evidence/outpost-light-390-about.png), [dark](evidence/outpost-dark-390-about.png) | About: site, Connected, Micropub, IndieAuth, Outpost 1.0.24, Check connection again, Diagnostics, six help links, collapsed guides. | About |
| [outpost-light-390-settings.png](evidence/outpost-light-390-settings.png), [dark](evidence/outpost-dark-390-settings.png) | Settings sheet over the dimmed composer: Appearance tiles (System selected) and Style source cards (Outpost Default selected). | Appearance controls |
| [outpost-offline-save.png](evidence/outpost-offline-save.png) | Settings after choosing Dark offline, with the device-only message. | Offline honesty |
| [outpost-light-390-focus.png](evidence/outpost-light-390-focus.png) | Header after two Tab presses from load: focus ring on the Settings button. | Visible focus, focus order |
| [outpost-light-320-note.png](evidence/outpost-light-320-note.png) | Post at 320 px with no horizontal scroll. | Small phone |
| [outpost-light-1280-note.png](evidence/outpost-light-1280-note.png) | Post at 1280 px, centered column. | Desktop |
| [site-light-390-note.png](evidence/site-light-390-note.png), [site-dark-390-note.png](evidence/site-dark-390-note.png) | Use Site Theme on Twenty Twenty-Five: white page and near-black text in light; Outpost dark surfaces with TT5's adjusted accent in dark (TT5 has no dark variation). | Site Theme, missing-scheme fallback |
| [site-light-390-settings.png](evidence/site-light-390-settings.png), [site-dark-390-settings.png](evidence/site-dark-390-settings.png), [site-light-390-about.png](evidence/site-light-390-about.png), [site-dark-390-about.png](evidence/site-dark-390-about.png) | Settings and About under Use Site Theme. | Both sources × both schemes |
| [signpost-light.png](evidence/signpost-light.png), [signpost-dark.png](evidence/signpost-dark.png), [signpost-forced.png](evidence/signpost-forced.png) | The outline signpost at 24, 32, 48, and 64 px in violet on paper, light outline on night, and under forced colors. | Signpost |

## Not done or not verified

- **Device review:** no iOS Safari, installed PWA, or Android test; no VoiceOver or TalkBack pass. Unverified.
- **Zoom and text size:** 200% text and 400% zoom/reflow were not tested; the header wraps by design but wasn't measured at large text sizes.
- **Keyboard-visible layout:** the sticky-toolbar and virtual-keyboard checks weren't run (no device); the composer has no sticky toolbar, and the body field caps at 50dvh.
- **Draft retention across re-sign-in:** built on `feature/pwa-composer-drafts` (stacked on this branch): `pwa/src/lib/drafts.ts` keeps each mode's unsent text in localStorage per `me`, restores it after "Sign in again" without posting, and clears it on publish, queue, and sign-out. Covered by vitest only; not yet checked on a device.
- **Per-mode redesign:** Photo, Recipe, and Life keep their existing field layouts under the new tokens; the mockup's compact "Add media" / "Categories & tags" rows weren't built, and More options still opens the existing drawer.
- **Web fonts:** none ship; IBM Plex and Newsreader render only when installed on the device.
- **Bridge:** no site appearance bridge exists in this plugin (`outpost_shell_mode_class` and `outpost_shell_style_handles` are the hooks); nothing here reads one.
- **Classic-theme fallback:** covered by unit tests (no theme.json → Outpost values with the theme layer empty) but not captured on a classic theme.
- **Integration suite:** needs the WireMock sidecar; left to CI.
- **Docs site:** build, link check, and screenshot refresh skipped (disk).
