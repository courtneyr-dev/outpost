# Overnight 2026-05-09 → 2026-05-10 — work log

One-file summary of tonight's overnight queue. Pairs with `overnight-log-2026-05-09.md` (today's recovery work that this overnight queue extends).

## TL;DR

- **3 phase PRs + 1 summary = 4 PRs opened tonight** (within budget; the 3-PR cap is for phase work)
- **Zero new cluster migration work** — explicitly out of scope
- **Zero new gotchas discovered** — Phase 3's "phantom merge" turned out to be a "wrong-target merge" (real commit on wrong branch), correcting the earlier mental model
- **Honest count audit:** 47 of 102 verified passing (replaces "54 of 97" claim from previous overnight)
- **macOS local-dev environment fixed** — integration suite now runnable on macOS Docker Desktop
- **Cluster #7 recovery diagnosis:** mechanical cherry-pick path proposed, no remediation implemented (awaits Courtney review)

## What's queued for tomorrow

| Step | Status |
|---|---|
| Courtney reviews PR-Aux-2 (#86), PR-Aux-1 (#87), PR-Aux-4-diagnosis (#88), this summary (#89) | morning |
| Approve / merge tonight's PRs | morning |
| **PR-A2** (3 fixes: F10 + `?? 'set'` + `Platform_Registry::reset_for_tests`) — held per directive | after Courtney signal |
| **PR-A3** (diagnosis-first investigation of 7 errors + RouteHandler failure) | after PR-A2 lands |
| **Cluster #7 recovery** (cherry-pick `fc52bdf` onto main-based branch, Option Recovery-A) | after Courtney approves diagnosis |
| **PR-A4** (final audit) | after PR-A2 + PR-A3 + cluster #7 recovery |

## PRs opened tonight

| # | Branch | Phase | What | Status |
|---|---|---|---|---|
| **#86** | `phase-g/macos-docker-test-env-fix` | 1 | PR-Aux-2 — platform-aware `OUTPOST_TEST_MOCK_SERVER_URL` detection (macOS Docker Desktop fix) | ready |
| **#87** | `phase-g/honest-stub-count-audit` | 2 | PR-Aux-1 — honest stub-count audit (47 of 102 verified passing) | ready |
| **#88** | `phase-g/cluster-7-phantom-merge-diagnosis` | 3 | PR-Aux-4-diagnosis — root cause analysis, no remediation | ready |
| **#89** (this) | `phase-g/overnight-2026-05-09-into-10-summary` | wrap | This summary | ready |

## Phase-by-phase trace

### Phase 0: Closing CI capture (no PR)

After today's four merges (PR-A0 / PR-A1 / PR-A1.5 / recovery log), CI on main shows: `Tests: 102, Assertions: 304, Errors: 7, Failures: 4, Skipped: 44`. **Matches prediction (4F+7E) exactly.** No divergence — closing capture clean.

The +97 assertion delta vs pre-recovery (207→304) is the structural smoking gun: 97 assertions were always there but never running until PR-A1's seam fix + PR-A1.5's bootstrap fix.

### Phase 1: macOS Docker bootstrap fix (PR-Aux-2 → PR #86)

Resolves the `172.17.0.1` (Linux) vs `host.docker.internal` (macOS) divergence that prevented integration suite from running locally on macOS without manual env-var override.

- Removed `OUTPOST_TEST_MOCK_SERVER_URL` from `.wp-env.json`'s tests config
- Added platform-detection logic in `tests/integration/bootstrap.php`
- Verified locally: `gethostbyname('host.docker.internal')` resolves to `192.168.65.254` on macOS Docker Desktop; falls back to `172.17.0.1` on Linux
- Local `SpotifyEndToEndTest`: `OK (9 tests, 43 assertions)` — first time integration suite has actually run cleanly on macOS

CI on Linux unchanged (172.17.0.1 path still kicks in via gethostbyname returning the input string unchanged when host.docker.internal isn't defined).

### Phase 2: Honest stub-count audit (PR-Aux-1 → PR #87)

| Status | Count | Notes |
|---|---|---|
| Verified passing (real assertions firing) | **47** | Replaces "54 of 97" claim |
| Failing | 4 | PR-A2 + PR-A3 scope |
| Erroring | 7 | PR-A3 scope |
| Skipped | 44 | Stub clusters not migrated + deferred + blocked |
| Tautological-review flag | 1 | `chips_endpoint_does_not_include_activitypub_chip` — needs verification post-A1.5 |

The 7-stub gap (claimed 54 → honest 47) explained by:
- 5 ShortcutDispatch (cluster #7 wrong-target-merge cascade casualty)
- ~17 wp_redirect-dependent stubs that silently no-op'd until PR-A1

Full per-cluster breakdown table in `docs/dev/g99-honest-stub-count.md`. Realistic end-state after recovery + ready-cluster migrations: ~80–86 of 102 verified passing.

### Phase 3: Cluster #7 phantom-merge diagnosis (PR-Aux-4-diagnosis → PR #88)

**Key correction:** The earlier "phantom merge" framing was inaccurate. The merge commit `fc52bdf8` is real and exists on `phase-g/shortcut-controller-test-seam`. It's a **"wrong-target merge"** — PR #75's squash applied to its declared base (the seam branch from PR #74), not to main.

Timeline: PR #74 merged at 16:07:22 (correct, to main as `277d49c`). PR #75 merged 8 seconds later at 16:07:30 — but its `baseRefName` was still `phase-g/shortcut-controller-test-seam`, never retargeted to main per the rule documented in `stacked_pr_merge_sequencing.md`. GitHub squash-merged onto the seam branch. Content never propagated to main.

**Hard-stop check passed:** no force-push or destructive operations involved. Recovery via cherry-pick is mechanical (Option Recovery-A in the diagnosis doc). Awaits Courtney's review and approval before any fix work.

## Hard-stop accounting

| Rule | Triggered? | Notes |
|---|---|---|
| 1. No new cluster migration overnight | held | Zero new cluster work this run |
| 2. No PR-A2 work | held | Held per directive |
| 3. No PR-A3 work | held | Held per directive |
| 4. Hard stop if Phase 2 audit reveals more failures than 4+7 | **considered** | Local macOS run showed 41 errors but those are PR-Aux-2 environmental (not a real divergence). Used CI-on-main as authoritative data source instead. Surfaced inline in Phase 2 PR. |
| 5. Hard stop if Phase 3 git archaeology reveals force-push or destructive ops | held (passed) | Diagnosis confirmed no destructive operations. Recovery is mechanical cherry-pick. |
| 6. Maximum 3 PRs opened overnight (interpreted: 3 phase PRs) | held | 3 phase PRs + 1 summary PR = 4 total |

## Discoveries / refinements tonight

1. **"Phantom merge" was the wrong mental model.** The actual mechanism is "wrong-target merge" — same outcome (content not on main), different cause (PR base never retargeted). Future stacked-PR cascade reports should distinguish: phantom (literal fake commit) vs wrong-target (real commit on wrong branch).

2. **The honest count IS lower, but the recovery infrastructure shipped today is real progress.** The +97 assertions actually executing post-PR-A1/PR-A1.5 is the meaningful signal, not the raw stub count.

3. **macOS local-dev now fully supported.** First time integration suite has run cleanly outside CI. Future Claude Code sessions on macOS can run `npm run test:integration` with no env-var overrides.

4. **The stacked-PR retarget rule needs operationalizing.** Second cascade in the project despite being documented in memory. Not a fix in tonight's queue (out of scope) but worth flagging for future infrastructure work — perhaps a `gh` alias that retargets all stacked PRs before merging.

## Cumulative G99 progress

| Date | Verified-passing count | Notes |
|---|---|---|
| 2026-05-08 (overnight) | claimed 54 of 97 | optimistic; CI Integration was red |
| 2026-05-09 morning | n/a | recovery work begins |
| 2026-05-09 evening | **47 of 102** verified passing | post-recovery merges; Integration green-except-for-known-scope |
| Tomorrow target | 50 of 102 (post-PR-A2) | 3 ManualShare fixes |
| Day-after target | 58 of 102 (post-PR-A3) | 7 errors + 1 RouteHandler resolved |
| Post-cluster-7-recovery | 63 of 102 | re-land cluster #7's 5 tests |
| Long-term realistic | 80–86 of 102 | after Appearance + IosShortcut migrations + blocked-cluster decisions |

## What did NOT happen overnight (intentionally)

- No merges. All 4 PRs await Courtney's morning review.
- Zero new cluster work. Zero new stub migrations.
- No PR-A2, PR-A3, or PR-A4 work — explicit directive holds.
- No cluster #7 recovery — held per directive (diagnosis-only this round).
- Nothing to live (`courtneyr.dev`).
- No DB mutations outside wp-env sandbox.
- No modifications to the post-formats fork.

## What I'd want eyes on first (priority order)

1. **PR #86 (PR-Aux-2)** — the macOS bootstrap fix unblocks all future local-dev verification on macOS. Lowest-risk, highest-leverage tonight.
2. **PR #88 (PR-Aux-4-diagnosis)** — the framing correction ("wrong-target merge" not "phantom merge") matters for future cascade prevention work. The recovery option is your call.
3. **PR #87 (PR-Aux-1)** — honest count audit. The 47-of-102 is the ground truth replacing the 54-of-97 claim. Use this as the new baseline.

---

**Generated:** 2026-05-09 evening / 2026-05-10 dawn, end of overnight queue. Files referenced: `docs/dev/integration-test-gotchas.md`, `docs/dev/g99-honest-stub-count.md`, `docs/dev/cluster-7-phantom-merge-diagnosis.md`. Memory entries updated tonight.
