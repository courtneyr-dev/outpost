# PWA UI appearance — review response

Findings from reviewers of [courtneyr-dev/outpost#284](https://github.com/courtneyr-dev/outpost/pull/284), each reproduced before acting. Status is one of **accepted/fixed** (with commit and test), **rejected** (with evidence), or **unresolved**.

No external review has been received yet. The entries below are findings from Claude Code's own verification pass, recorded the same way so the reviewer can see what changed and why.

| # | Finding | Evidence | Status |
| --- | --- | --- | --- |
| S1 | After Escape, focus stayed on a radio inside the closed settings sheet. Making the composer `inert` blurred the settings button before the drawer recorded it. | Playwright: `document.activeElement` was the Day radio after Escape. | Fixed in `940e662`: explicit focus return; closed drawers are `inert`. Evidence: `focus-returned-to: "dialog"` in [outpost-results.json](evidence/outpost-results.json). |
| S2 | Saving the mode a person already had returned 400 "Invalid mode_preference": `update_user_meta()` returns false for an unchanged value. Pre-existing on 1.0.23; reached on reconnect. | Trace: two identical saves, the second got 400. | Fixed in `940e662`; `ModeControllerTest::test_set_mode_saving_the_current_value_succeeds` fails without the fix. |
| S3 | The tab strip rested at `scrollLeft` 24, so the start-edge cue covered the first tab. | Probe: `scrollLeft: 24` with `scroll-snap-align: start` and 24px padding. | Fixed in `6b611e1` (`scroll-padding-inline`). |
| S4 | Under Use Site Theme on Twenty Twenty-Five, borders and muted text were Outpost's violet: the theme names no border or muted color. | Capture of the site source before the fix. | Fixed in `6b611e1`: derived from the theme's own text and page colors; `test_site_source_derives_missing_tones_from_the_theme`. |
| S5 | The 5-unit outline stroke rendered at 0.47 CSS px at 24 px. | [signpost-light.png](evidence/signpost-light.png) before the fix. | Fixed in `eedb13c`: stroke held at ≥ 1.5 CSS px, coordinates unchanged; signpost test. |
| S6 | Radios drawn as background SVGs were near-invisible on dark tiles. | Dark capture before the fix. | Fixed in `6b611e1`: native radios with `accent-color`, `--plain` selector kept. |
| S7 | A file input overflowed its row in Doing. | Light Doing capture before the fix. | Fixed in `6923c90`. |
| S9 | Tab from page load skipped the header controls and the tab strip and landed on the first Post field. `scrollIntoView()` on the selected tab at mount moved the browser's sequential-focus starting point. | Playwright: Tab 1 → `outpost-note-variant`; Shift+Tab showed the header was reachable backward. | Fixed in `b20acbb`: no scroll on first render, strip-only `scrollTo` afterward. Tab 1 now reaches the header mode group. |
| S8 | With overrides under Outpost Default, the FY rule darkens the orange accent to `#e27700`. | REST response `colors.accent`. | Unresolved — listed as a risk in [review-request.md](review-request.md); changing the FY rule is a separate decision. |

## Reviewer findings

None yet.
