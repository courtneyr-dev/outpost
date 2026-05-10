# G99 honest stub-migration count audit

Audit produced 2026-05-09 evening (post-recovery merges). Replaces the optimistic "54 of 97" claim from the 2026-05-08 overnight log with verified data.

## Methodology

- **Data source:** CI Integration suite run on `main` after PRs #82/#83/#84/#85 merged (run ID 25614919358, commit `a2e9a63`).
- **Per-test classification:** PASS / FAIL / ERROR / SKIPPED, mapped from PHPUnit dot encoding + per-file source inspection. PHPUnit reports `Tests: 102, Assertions: 304, Errors: 7, Failures: 4, Skipped: 44, Passing: 47`.
- **Tautological review:** flagged separately when a passing test's assertions don't actually verify the SUT (e.g. asserting on a default value the SUT never sets, or asserting absence of a key that's never set anywhere).

## Per-cluster results

| Test class | Source PR | Tests | Passes | Fails | Errors | Skipped | Tautological? | Notes |
|---|---|---|---:|---:|---:|---:|---|---|
| `AppearanceSettingsFlowTest` | (not migrated) | 13 | 0 | 0 | 0 | 13 | — | All stubs; ready cluster, deferred to follow-up |
| `CompanionActivityPubPassthroughTest` | (blocked) | 4 | 0 | 0 | 0 | 4 | — | Blocked-on-platform (AP plugin missing from `.wp-env.json`) |
| `G4bAppleMusicIntegrationTest` | (blocked) | 4 | 0 | 0 | 0 | 4 | — | Blocked-on-platform (gotcha #4 — `music.apple.com` not rewritable) |
| `G4bCompositeInboundIntegrationTest` | #67 | 4 | 2 | 0 | 0 | 2 | NO | 2 migrated cleanly (PR #67 cascade-recovery); 2 stubs remain (deferred) |
| `G4bOgInboundIntegrationTest` | #65 | 3 | 3 | 0 | 0 | 0 | NO | All migrated cleanly. Cluster #1. |
| `IntegrationInfrastructureSmokeTest` | #64 | 5 | 5 | 0 | 0 | 0 | NO | wp-env CI bootstrap smoke; assertions are real (verifies WP loaded, autoloader works, mock-server constant set) |
| `IosShortcutConnectionFlowTest` | (not migrated) | 10 | 0 | 0 | 0 | 10 | — | All stubs; ready cluster, deferred |
| `ManualShareIntegrationTest` | #80 | 9 | 6 | 3 | 0 | 0 | **REVIEW** | 3 fails are PR-A2 scope (F10 docblock, `?? 'set'`, custom-vsco). Of the 6 passes, `chips_endpoint_does_not_include_activitypub_chip` previously passed for the wrong reason (chips array empty due to gotcha #11) — re-verify post-A1.5 |
| `MicropubPhotoWriteShapeTest` | #78 | 2 | 2 | 0 | 0 | 0 | NO | F3 `_wp_attachment_image_alt` bridge writes verified; assertions check actual post-meta values |
| `NotionShareTargetPreviewTest` | #68 | 6 | 3 | 0 | 0 | 3 | NO | 3 migrated (PR #68); 3 deferred (gotcha #4 / Issue #69) |
| `PreviewSourceDispatchTest` | (blocked) | 4 | 0 | 0 | 0 | 4 | — | Blocked-on-product (stale post-F16 docblock) |
| `RouteHandlerIntegrationTest` | #77 | 1 | 0 | 1 | 0 | 0 | — | Migrated but failing — PR-A3 scope |
| `ShareTargetDispatchTest` | #72 | 5 | 3 | 0 | 2 | 0 | NO | 3 passes verified post-PR-A1 seam fix; 2 errors are PR-A3 scope (`unambiguous_dispatch_writes_prefill_transient`, `localhost_url_dispatches_but_b2_blocks_fetch`) |
| `ShortcutDispatchTest` | #75 (phantom) | 5 | 0 | 0 | 0 | 5 | — | **Phantom-merge cascade casualty.** PR #75 GitHub-merged but content reverted to stubs on main. Cluster #7 recovery awaits Courtney review of phantom-merge diagnosis (`PR-Aux-4-diagnosis`). |
| `SpotifyEndToEndTest` | #70 | 9 | 9 | 0 | 0 | 0 | NO | wp_redirect seam (PR-A1) made these actually run. +9 verified passes vs pre-PR-A1 silent failure |
| `SyndicationCaptureFlowTest` | #79 | 6 | 1 | 0 | 5 | 0 | NO | 1 pass (auth gate test correctly returns 401/403); 5 errors (`Undefined array key "audit_log_id"` — PR-A3 scope) |
| `YouTubeEndToEndTest` | #71 | 12 | 12 | 0 | 0 | 0 | NO | wp_redirect seam (PR-A1) made these actually run. +12 verified passes vs pre-PR-A1 silent failure |
| **TOTAL** | | **102** | **47** | **4** | **7** | **44** | 1 review | |

## Honest "actually migrated" count

**Stubs verified passing on CI as of 2026-05-09:**

| Category | Count | Includes |
|---|---|---|
| **Verified passing (real assertions firing against SUT)** | **47** | G4b Composite (2) + G4b OgInbound (3) + IntegrationSmoke (5) + ManualShare (6 of 9) + MicropubPhoto (2) + Notion (3 of 6) + ShareTargetDispatch (3 of 5) + Spotify (9) + SyndicationCaptureFlow (1 of 6) + YouTube (12) |
| Failing (assertions fire and fail) | 4 | PR-A2 + PR-A3 scope |
| Erroring (test throws before assertions) | 7 | PR-A3 scope |
| Skipped (intentional or env-conditional) | 44 | Stub clusters not yet migrated + deferred + blocked clusters |

**The "47 verified passing" is the honest project count.** Of these:
- 1 flagged for tautological review (`chips_endpoint_does_not_include_activitypub_chip`) — needs verification that gotcha #11 fix didn't accidentally make it pass for the right reason
- The remaining 46 have real assertions verified firing against real SUT behavior

## Comparison to claimed counts

| Claim | Date | Source | Honest revision |
|---|---|---|---|
| "54 of 97 migrated" | 2026-05-08 | overnight log | **47 of 102 verified passing** (with 1 needing tautological review) |
| "11 PRs merged at PR-level success" | 2026-05-08 | overnight CI dashboard | True PR-merge-level, but CI Integration was red on each merge — assertions silently no-op'd |
| "Stub-counter shows progress" | various | aggregate stub claims | True at the stub-level, false at the assertions-actually-running level until 2026-05-09 |

The 7-stub gap (54 claimed → 47 honest) plus the ~17 wp_redirect-dependent stubs that were silently no-op'ing comes from:
- 5 ShortcutDispatch stubs phantom-merged (cluster #7) — counted in 54, actually 0 on main
- ~17 wp_redirect-dependent stubs (Spotify 9 + YouTube 12 + ShareTarget 5 - 9 stubs that don't exercise dispatch redirect = ~17 effectively no-op assertions before PR-A1)

After PR-A1 merged today, those 21 wp_redirect-dependent stubs flip from "silently no-op" to verified-passing or verified-failing. The Spotify (9) and YouTube (12) clusters jumped from 0 honest passes to 21 verified passes in this audit.

## Tomorrow's path to higher numbers

| Step | Honest count change |
|---|---|
| PR-A2 lands (3 ManualShare fixes) | 47 → 50 verified passing |
| PR-A3 diagnosis + fix (7 errors + 1 RouteHandler) | 50 → 58 verified passing (if all 8 fix cleanly) |
| Cluster #7 phantom-merge recovery | 58 → 63 verified passing (re-lands PR #75's 5 tests) |
| Tautological review of `chips_endpoint_does_not_include_activitypub_chip` | possibly −1 (if rewrite needed) or +0 (if real) |
| Full audit-driven cleanup of remaining stub clusters (Appearance 13, IosShortcut 10) | 63 → 86 verified passing if all migrate cleanly |

Realistic end-state after recovery + ready-cluster migrations: ~80–86 of 102 verified passing. The remaining ~16–22 are blocked-on-platform / blocked-on-product clusters per the existing readiness audit at `docs/dev/g99-readiness-audit.md`.

## What this audit does NOT change

- The recovery infrastructure shipped today (PR-A0 gating, PR-A1 seam, PR-A1.5 bootstrap fix) is real progress — moves the Integration suite from "always red, ignored" to "honest gate that catches regressions"
- The ~46–47 verified-passing tests are real — they exercise real production code paths against real WP via wp-env
- Tomorrow's recovery PRs (A2, A3, A4) move the count up cleanly from this honest baseline

## Methodology notes for future audits

1. **Run the audit on CI's data, not local-macOS data** — local environment quirks (PR-Aux-2 in flight) can produce false negatives that aren't real failures.
2. **Cross-reference dot-encoding with per-file inspection** — PHPUnit's `--testdox` output gives per-method ✔/✘, but the alphabetic class ordering plus method count gives the same data more compactly.
3. **Tautological review is judgment-heavy** — automation can flag suspect patterns (assertions on absence-of-key in a response shape that's never set, etc.) but final review needs eyes on each test's relationship to its SUT.
4. **Track the assertion-execution count over time** — 2026-05-08: 207 assertions executing. 2026-05-09: 304 assertions executing (+97). That delta is the meaningful progress signal, more than stub counts.

---

**Generated:** 2026-05-09 evening, Phase 2 of overnight queue. Source: CI run 25614919358 on commit `a2e9a63`.
