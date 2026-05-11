# G99 honest stub-migration count audit

Audit refreshed 2026-05-11 (post-recovery-arc close). Supersedes the 2026-05-09 snapshot (47 verified passing on commit `a2e9a63`) with the post-PR-A3 state.

## Recovery arc summary

| Field | Value |
|---|---|
| Start | PR #70 (2026-05-08) — first F appeared on main after the wp-env upgrade exposed previously-silent assertion failures |
| End | PR #99 (2026-05-11) — 0F + 0E restored on main; tagged `main-0f-0e-restored` at `42bd078` |
| Duration | 5 days |
| PRs merged | 30 (recovery-window total) |
| Bypasses used | 14 (final count for the window; PR-A4 is the first non-bypass PR after closure) |
| Principles codified in vault | 34 |
| Gotchas documented in repo | 12 (no #13 — recovery surfaced none) |
| Memory files codified | 4 (`outpost_source_registry_bootstrap_drift.md`, `outpost_test_assertion_discipline.md`, `stacked_pr_merge_sequencing.md`, `feedback_anticipate_cascade.md`) |
| First non-bypass PR after recovery | PR-A4 (this PR) |

The recovery arc closed when PR #99's squash-merge brought main to 0F + 0E for the first time since the wp-env upgrade. This audit refresh is the formal close.

## Methodology

- **Data source:** PROJECT STATE captured at the close of CI run [25672635606](https://github.com/courtneyr-dev/outpost/actions/runs/25672635606) on commit `42bd078` (main HEAD, tagged `main-0f-0e-restored`).
- **Per-test classification:** PASS / FAIL / ERROR / SKIPPED, mapped from PHPUnit dot encoding + per-file source inspection. PHPUnit reports `Tests: 102, Assertions: 374, Errors: 0, Failures: 0, Skipped: 39, Passing: 63`.
- **Tautological review:** flagged separately when a passing test's assertions don't actually verify the SUT (e.g. asserting on a wrapper dictionary's non-emptiness instead of the inner collection).

## Per-cluster results

| Test class | Tests | Passes | Fails | Errors | Skipped | Last-affecting PR | Notes |
|---|---:|---:|---:|---:|---:|---|---|
| `AppearanceSettingsFlowTest` | 13 | 0 | 0 | 0 | 13 | — | All stubs; ready-cluster deferred to follow-up |
| `CompanionActivityPubPassthroughTest` | 4 | 0 | 0 | 0 | 4 | — | Blocked-on-platform (AP plugin missing from `.wp-env.json`) |
| `G4bAppleMusicIntegrationTest` | 4 | 0 | 0 | 0 | 4 | — | Blocked-on-platform (gotcha #4 — `music.apple.com` not rewritable) |
| `G4bCompositeInboundIntegrationTest` | 4 | 2 | 0 | 0 | 2 | #67 | 2 migrated; 2 stubs remain deferred |
| `G4bOgInboundIntegrationTest` | 3 | 3 | 0 | 0 | 0 | #65 | Cluster #1; all migrated |
| `IntegrationInfrastructureSmokeTest` | 5 | 5 | 0 | 0 | 0 | #64 | wp-env CI bootstrap smoke |
| `IosShortcutConnectionFlowTest` | 10 | 0 | 0 | 0 | 10 | — | All stubs; ready-cluster deferred |
| `ManualShareIntegrationTest` | 9 | 9 | 0 | 0 | 0 | #91 (PR-A2) | All 3 PR-A2 fails resolved (F10 docblock, `?? 'set'`, custom-vsco) |
| `MicropubPhotoWriteShapeTest` | 2 | 2 | 0 | 0 | 0 | #78 | F3 `_wp_attachment_image_alt` bridge writes verified |
| `NotionShareTargetPreviewTest` | 6 | 3 | 0 | 0 | 3 | #68 | 3 migrated; 3 deferred (gotcha #4 / Issue #69) |
| `PreviewSourceDispatchTest` | 4 | 0 | 0 | 0 | 4 | — | Blocked-on-product (stale post-F16 docblock) |
| `RouteHandlerIntegrationTest` | 1 | 1 | 0 | 0 | 0 | #94 (PR-A3c) | Cluster C resolved via permalink_structure + `query_vars` filter mirror |
| `ShareTargetDispatchTest` | 5 | 5 | 0 | 0 | 0 | #98 (PR-A3a) | Cluster A's 2 errors resolved via `mark_bootstrapped_for_tests` seam (gotcha #12) |
| `ShortcutDispatchTest` | 5 | 5 | 0 | 0 | 0 | #90 | Cluster #7 phantom-merge recovery cherry-picked + seam-migrated |
| `SpotifyEndToEndTest` | 9 | 9 | 0 | 0 | 0 | #70 | wp_redirect seam (PR-A1) made these actually run |
| `SyndicationCaptureFlowTest` | 6 | 6 | 0 | 0 | 0 | #99 (PR-A3b-prime-β) | Cluster B closed across PR-A3b + α + β (helper schema + setup_postdata + set_current_screen) |
| `YouTubeEndToEndTest` | 12 | 12 | 0 | 0 | 0 | #71 | wp_redirect seam (PR-A1) made these actually run |
| **TOTAL** | **102** | **63** | **0** | **0** | **39** | | |

## Honest "actually migrated" count

**Stubs verified passing on CI as of 2026-05-11:**

| Category | Count | Includes |
|---|---:|---|
| **Verified passing (real assertions firing against SUT)** | **63** | G4b Composite (2) + G4b OgInbound (3) + IntegrationSmoke (5) + ManualShare (9) + MicropubPhoto (2) + Notion (3 of 6) + RouteHandler (1) + ShareTargetDispatch (5) + ShortcutDispatch (5) + Spotify (9) + SyndicationCaptureFlow (6) + YouTube (12) |
| Failing | 0 | Recovery arc closed |
| Erroring | 0 | Recovery arc closed |
| Skipped (intentional or env-conditional) | 39 | Stub clusters not yet migrated + deferred + blocked clusters |

**The "63 verified passing" is the honest project count.** All 63 have real assertions verified firing against real SUT behavior. One test carries a tautological-shape candidate flagged in the "Future test-strengthening candidates" section below; it passes correctly today but the assertion shape could be tightened.

## Comparison to claimed counts

| Claim | Date | Source | Honest revision |
|---|---|---|---|
| "54 of 97 migrated" | 2026-05-08 | overnight log | Materially overstated; 5 cluster #7 stubs phantom-merged, 17+ wp_redirect-dependent stubs silently no-op'ing |
| "47 of 102 verified passing" | 2026-05-09 | first honest audit | Accurate snapshot at the time (commit `a2e9a63`); pre-recovery |
| **"63 of 102 verified passing"** | **2026-05-11** | **this audit** | **Post-recovery state, 0F + 0E baseline** |

The 47 → 63 delta (+16) decomposes as:
- +5 ShortcutDispatch (PR #90 cluster #7 phantom-merge recovery via cherry-pick + seam migration)
- +3 ManualShare (PR-A2 Fix 1+2+3, including the Platform_Registry cache-reset bonus)
- +1 RouteHandler (PR-A3c)
- +2 ShareTargetDispatch (PR-A3a, cluster A's 2 errors)
- +5 SyndicationCaptureFlow (PR-A3b primary + PR-A3b-prime-α F1+F3 + PR-A3b-prime-β F2)

## Bonus assertion deltas across the recovery arc

The structural smoking gun pattern from PR-A1 (+47 assertions unmasked by a single seam fix) repeated across the arc. Each recovery PR landed its predicted minimum and unmasked additional assertions that had been silently no-op'ing behind upstream short-circuits.

| PR | Bonus assertions | Mechanism |
|---|---:|---|
| PR-A1 (#83) | +47 | `wp_redirect` seam fix unmasked Spotify (9) + YouTube (12) + ShareTargetDispatch assertions that had been no-op'ing without `redirect()` capture |
| PR-A2 (#91) | +23 | Fix 3's `Platform_Registry::reset_for_tests()` in setUp/tearDown unblocked tests sharing the static `$resolved` cache |
| PR-A3c (#94) | +16 | `permalink_structure` bootstrap fix + the `query_vars` filter mirror let rewrite-dependent assertions execute |
| PR-A3b (#95) | +5 | Cluster B primary helper key-name fix (`audit_log_id` → `id`) let the `added_at` rollback foreach actually run |
| PR-A3a (#98) | +10 | Cluster A's `mark_bootstrapped_for_tests` seam (gotcha #12) let custom-source registrations stop colliding with the implicit `ensure_bootstrapped` re-fire |
| PR-A3b-prime-α (#97) | +2 | F1 + F3 `setup_postdata` fixes let the `the_content`/`the_excerpt` filter callbacks see a non-zero `get_the_ID()` |
| PR-A3b-prime-β (#99) | +1 | F2 `set_current_screen('post.php')` let `admin_notices` dispatch reach `Outpost_Pending_Syndication_Notice::maybe_render()` past its `null === $screen` guard |

The +47 from PR-A1 remains the largest single delta; the cascade-anticipation discipline (codified in `feedback_anticipate_cascade.md` after PR-Aux-6-diagnosis) was developed because each subsequent fix kept unmasking smaller deltas the diagnosis hadn't predicted.

## Future test-strengthening candidates

Candidates surfaced during the recovery arc that pass correctly today but whose assertion shape could be tightened. **Out of scope for PR-A4** — this section documents the candidates so a follow-up PR can pick them up.

### F1 — `full_capture_loop_writes_audit_log_and_renders_u_syndication` tautological assertion

- **Test:** `tests/integration/SyndicationCaptureFlowTest.php::full_capture_loop_writes_audit_log_and_renders_u_syndication` (method at line 146).
- **Line:** `tests/integration/SyndicationCaptureFlowTest.php:154`.
- **Current shape:** `$this->assertNotEmpty( $pending_data, 'Pending endpoint should surface the seeded entry.' );` where `$pending_data` is the dictionary wrapper `['pending' => $results]` returned from `$pending->get_data()`.
- **Weakness:** The wrapper is non-empty even when the inner `'pending'` array is empty (the outer dict has the `'pending'` key). The assertion passes regardless of whether the detector actually surfaced the seeded entry. Currently passing for the right reason (PR-A3b-prime-α's `setup_postdata` fix means the renderer fires and the seed is present), but if the inner collection ever silently empties, this test will not catch it.
- **Recommended shape:** `$this->assertNotEmpty( $pending_data['pending'], 'Pending endpoint should surface the seeded entry.' );` (assert on the inner collection, not the wrapper).
- **Owner:** future test-strengthening PR. Apply the same review to other `assertNotEmpty` calls against REST response wrappers in the integration suite.

## Methodology notes for future audits

1. **Run the audit on CI's data, not local-macOS data** — local environment quirks (e.g. Docker bridge IP differences) can produce false negatives that aren't real failures.
2. **Cross-reference dot-encoding with per-file inspection** — PHPUnit's `--testdox` output gives per-method ✔/✘, but the alphabetic class ordering plus method count gives the same data more compactly.
3. **Tautological review is judgment-heavy** — automation can flag suspect patterns (`assertNotEmpty` against wrapper dictionaries, `assertNull` on a key that's never set) but final review needs eyes on each test's relationship to its SUT.
4. **Track the assertion-execution count over time** — the recovery arc's full trajectory: 2026-05-08 207 assertions, 2026-05-09 304 (+97), 2026-05-10 329 (+25), 2026-05-11 374 (+45). The deltas are the meaningful progress signal — more than stub counts, more than pass counts. Bonus deltas (PR-A1's +47, etc.) are individual data points within that trajectory.

---

**Generated:** 2026-05-11 (post-recovery refresh). Source: CI run 25672635606 on commit `42bd078` (`main-0f-0e-restored`). Supersedes the 2026-05-09 audit on commit `a2e9a63`.
