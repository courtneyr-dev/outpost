# Recovery log — 2026-05-09 (post-overnight session)

One-file summary of today's recovery work. Pairs with `overnight-2026-05-08-into-09.md` (which was the optimistic narrative that this log corrects). Read this in the morning to see what actually happened, what was discovered, and what's queued.

## TL;DR

- **3 PRs opened today**, zero merged, all awaiting morning review
- **Discovered the integration suite has been failing on every PR since PR #70 merged on 2026-05-04** — 11 PRs merged on the strength of unit suite + lint passing while Integration suite was silently red
- **The wp_redirect filter capture pattern shipped across PRs #70/#71/#72/#75/#80 was architecturally broken** — assertions never executed under `OUTPOST_TESTING_PWA_SHELL`. The G99 stub-migration arc did review theater on no-op tests
- **Honest stub count revision: ~36–40 of ~97 actually migrated, not 54 as claimed** — the claimed count counted PRs whose tests' assertions never ran. PR-A4 audit will produce the verified number
- **Cluster #7 (PR #75) phantom-merged** — GitHub shows MERGED but the commit doesn't exist on main; cluster #7's content reverted to stub form. Same shape as PR #66 → #67 cascade earlier

## Starting state (what Courtney saw at sunrise)

- **9 PRs from the 2026-05-08 → 2026-05-09 overnight queue, all merged** (per the optimistic narrative in `overnight-2026-05-08-into-09.md`)
- **Claimed cumulative: 54 of ~97 stubs migrated**
- **Recovery sequence anticipated:** A1 (test seam) + A2 (PR #80 bugs) + A3 (cluster #7 phantom-merge) + A4 (audit)

The starting state assumed Integration suite was green (it wasn't), assumed cluster #7's content landed on main (it didn't), and treated 54-of-97 as a verified count (it wasn't).

## Discovery sequence — six items in cascade

### 1. Integration suite was always red

Pre-flight CI check showed PR #80's post-merge run on main: `Tests: 102, Assertions: 207, Errors: 7, Failures: 24, Skipped: 44`. Investigation into prior PRs showed the same pattern:

```
PR #70 (Spotify, cluster #4) Integration suite: FAIL
PR #72 (ShareTarget, cluster #6) Integration suite: FAIL
```

11 PRs merged with red Integration on each one. Unit suite + PHPCS + PHPStan + Section 5 Audit + TypeScript + Vitest were all green; Integration was treated as informational.

### 2. Root cause of the wp_redirect failures: architecturally broken pattern

`Outpost_Share_Target_Controller::redirect()` and the equivalent on `Outpost_Shortcut_Controller` skip `wp_safe_redirect()` under `OUTPOST_TESTING_PWA_SHELL`. Integration tests define that constant → `wp_safe_redirect` never runs → `wp_redirect` filter never fires → tests using `add_filter('wp_redirect', ...)` capture had silent no-op assertions across PRs #70/#71/#72/#75/#80.

The "+47 assertions actually executed" delta after PR-A1's seam fix is the smoking gun: 47 assertions on those tests were running but had nothing to assert against (`captured_redirects` always empty, returned null, `assertNotNull(null)` failed silently in the integration suite that no one was reading).

### 3. Docker daemon dead at pre-flight

Tried to verify the seam locally before pushing. `docker info` returned "Cannot connect to the Docker daemon." Took ~30 minutes of false starts (`open -a Docker` not actually launching anything, `~/.docker/run/docker.sock` not present, `pgrep -f "Docker Desktop"` empty) before Courtney manually opened Docker Desktop from Finder. Resolved.

### 4. macOS Docker network routing differs from Linux

Once Docker came up, ran integration suite against the seam fix. All 23 tests in the affected clusters errored at WireMock connect with `cURL error 28: Connection timed out after 10003ms`. Diagnosed: `.wp-env.json` sets `OUTPOST_TEST_MOCK_SERVER_URL=http://172.17.0.1:8080`. On Linux (CI runners) `172.17.0.1` is the docker bridge gateway. On macOS Docker Desktop, that IP isn't routable from inside the tests-cli container — host.docker.internal is the macOS equivalent. Carry-forward item: separate environmental fix PR for macOS local-dev parity.

Fell back to Option II: push to remote, let CI on Linux verify. Worked cleanly — CI delta matched expectation.

### 5. Gotcha #11: Outpost loaded as muplugin, not as activated plugin

While starting PR-A2 (PR #80 ManualShare bugs), local diagnostic inside wp-env tests-cli showed:

```
$adapter = new Outpost_Manual_Share_Adapter();
echo $adapter->is_active();          // → false (!)
Platform count: 10
chips_for_mode(photo) count: 0       // ← smoking gun
```

The integration test bootstrap loads Outpost via `tests_add_filter('muplugins_loaded', ...)` which executes the plugin file but doesn't add it to `active_plugins` option. `is_plugin_active(OUTPOST_PLUGIN_BASENAME)` returns false → every companion adapter reports inactive → chip-dependent tests see zero chips. Not the wp_redirect issue; a separate same-shape failure (test environment failing to model production faithfully).

### 6. Off-by-one prediction divergence

Predicted PR-A1.5 would resolve 4 of the 5 PR #80 ManualShare failures. Actual: only 2 had the gotcha #11 root cause. The third (custom-vsco) had separate root cause — `Platform_Registry::$resolved` static-cache leakage — that the original PR-A2 hypothesis correctly identified. The 4 tests grouped together had different root causes that happened to manifest the same way (returned 0 chips for unrelated reasons).

Honest revision: PR-A2 has 3 fixes, not 2. The hypothesis revision was healthy — confirmed by local diagnostic before any production code changed.

## Four gotchas / principles documented today

1. **Integration-suite-gating gap (PR-A0 fix).** A CI check that's red and informational doesn't surface failures — it surfaces them only to someone who reads CI output. Eleven PRs merged because no one was. Fix: required-status enforcement via branch protection. The cost of fixing the check (~30 min) is always less than the cost of not gating on it.

2. **Docker pre-flight ritual.** Before any local integration verification, verify `docker info` shows `Server Version: ...` not just `Server:`. If only the helper process (`com.docker.vmnetd`) is up but Docker Desktop itself isn't, no further commands succeed. Don't loop through `open -a Docker` retries — surface and ask for human intervention.

3. **macOS Docker network routing differs from Linux.** `172.17.0.1` (Linux bridge gateway) ≠ `host.docker.internal` (macOS equivalent). Tests written assuming one fail on the other. Carry-forward fix: bootstrap.php conditional that swaps the URL based on the host platform.

4. **Test environment must model production faithfully.** Three same-shape failures today (gotcha #9 `OUTPOST_TESTING_PWA_SHELL` skipping `wp_safe_redirect`; gotcha #10 `php://input` empty under PHPUnit-CLI; gotcha #11 `is_plugin_active` returning false because plugin isn't in `active_plugins`). Pattern: test-environment quirks silently no-op'd assertions across multiple PRs. Meta-rule: when adding integration test infrastructure, verify the assertions actually run end-to-end — don't trust "looks right" review.

Plus two principles:

5. **Hypothesis revision is healthy.** The "Platform_Registry stale cache" hypothesis was wrong for tests 2/4 (those were gotcha #11) and right for test 5 (real cache issue). Surfacing the divergence before pushing PR-A1.5 was correct — the user's "if delta diverges, hard stop" rule caught a real subtlety. Revising the hypothesis based on diagnostic output is healthy; defending the original hypothesis would have been bad.

6. **Deduplicate failures by root cause, not by symptom.** 24 failures from PR #80's CI run looked like 24 distinct bugs. Reality: 18 shared a root cause (wp_redirect seam), 2 shared another (gotcha #11), 1 was its own (Platform_Registry cache), 3 were assertion-logic bugs in their own test code. One PR per root cause, not one PR per failing test.

## Recovery work shipped today

| PR | Branch | What | Status | Type |
|---|---|---|---|---|
| **#82** | `phase-g/ci-gating-policy` | PR-A0 — Required-CI-gating policy. Branch protection live; setup script + policy doc. | **draft** (bootstrap problem; see merge sequencing) | docs + script |
| **#83** | `phase-g/wp-redirect-test-seam` | PR-A1 — `set_redirect_callback_for_tests` seam on share-target + shortcut controllers. Tests in PRs #70/#71/#72 updated to use it. | **ready** | production + tests |
| **#84** | `phase-g/integration-bootstrap-active-plugins` | PR-A1.5 — `option_active_plugins` filter in tests/integration/bootstrap.php so `is_plugin_active(OUTPOST_PLUGIN_BASENAME)` returns true. Gotcha #11 fix. | **ready** | tests + docs |

Plus this PR (#85) — the recovery log.

## Carry-forward state for tomorrow

### Recovery sequence

| Step | PR | What |
|---|---|---|
| 1 | #82 (PR-A0) | Merge first. Gates everything else. Likely needs bypass since it can't pass through its own gate (chicken-and-egg: Integration suite is still red on main). OR merge #83 first to clear Integration, then #82 merges through clean. |
| 2 | #83 (PR-A1) | Merge after #82 (or with bypass alongside #82). Resolves 18 wp_redirect failures. |
| 3 | #84 (PR-A1.5) | Merge after #83. Resolves 2 chip-count failures. |
| 4 | PR-A2 | Open after #82/#83/#84 land. Three fixes: F10 stale docblock, broken `?? 'set'` assertion, `Platform_Registry::reset_for_tests()` cycle in setUp/tearDown. ~30–50 lines tests-only. |
| 5 | PR-A3 | Diagnosis-only doc PR for the 7 errors + 1 RouteHandler failure that have never been investigated. Each gets root cause analysis before any code changes. Recovery PR per root cause. |
| 6 | PR-A4 | Full audit of all 11 migrated clusters. Honest "tests that run real assertions" count. Public commitment to corrected number. |
| 7 | Cluster #7 phantom-merge recovery | PR #75 merged to a phantom commit that doesn't exist. Cherry-pick `258cbf4` from `phase-g/f6-shortcut-dispatch-migration` onto fresh main-based branch. Same recovery as PR #67 did for PR #66. **Awaiting Courtney review of phantom-merge diagnosis before this proceeds** (full diagnosis in `.session-progress.md`). |

### Honest stub count revision

| Source | Count | Notes |
|---|---|---|
| Claimed 2026-05-08 overnight log | 54 of 97 (56%) | Counted PRs whose Integration suite was red and tests' assertions never ran |
| Conservative estimate after today's discovery | **~36–40 of 97 (37–42%)** | Subtracts ~14–18 PR #70/#71/#72/#80 stubs whose wp_redirect-dependent assertions never executed |
| Verified count after PR-A4 audit | TBD | PR-A4 will produce the audited number |

### Open questions awaiting Courtney's morning review

1. **PR-A0 merge bypass.** PR #82 itself can't pass through the Integration gate it establishes (Integration is red on main). Two paths: bypass #82 once (legitimate textbook bootstrap case), or merge #83 first to clear Integration then merge #82 clean. Recommendation in PR #82's description: Option D (merge #83 first).

2. **Cluster #7 phantom-merge recovery.** Mechanical cherry-pick. Awaits Courtney's read of the diagnosis in `.session-progress.md`.

3. **PR-A2 scope confirmation.** Three fixes, not two: F10 + `?? 'set'` + Platform_Registry cycle. ~30–50 lines tests-only.

## Tomorrow's plan (proposed)

```
07:00–08:00  Courtney reviews #82 #83 #84 (this morning's PRs)
              Courtney decides on PR-A0 bypass-or-merge-A1-first
              Courtney signals to proceed

08:00–09:00  Merge #82, #83, #84 in chosen order
              Verify Integration suite goes green on main after all three land

09:00–11:00  PR-A2 (3 fixes, tests-only, ~30–50 lines)
              Open as draft → CI confirms green → mark ready

11:00–14:00  PR-A3 (diagnosis-first doc PR for 7 errors + 1 failure)
              No code changes. Just root cause analysis per error.

14:00+       PR-A4 (audit) — only after PR-A2/PR-A3 land
              Cluster #7 recovery — only after Courtney signals on phantom-merge
```

**Velocity expectation:** PR-A2 closes today. PR-A3 may spill into next session given investigation depth. PR-A4 + cluster #7 recovery likely next-next session.

Hard stops still apply: 12th gotcha = halt + surface; readiness regression = halt + surface; >15-line production change in PR-A2 = halt + surface.

## Files modified today (across all 3 PRs)

```
.github/workflows/                              (no changes — workflow already runs Integration on PRs)
bin/setup-branch-protection.sh                  (NEW, PR-A0) — idempotent gh api script
docs/dev/ci-gating-policy.md                    (NEW, PR-A0) — full policy + rationale
docs/dev/integration-test-gotchas.md            (UPDATED, PR-A1.5) — gotcha #11 entry
includes/sources/class-share-target-controller.php  (UPDATED, PR-A1) — seam method
includes/sources/class-shortcut-controller.php  (UPDATED, PR-A1) — seam method
tests/integration/SpotifyEndToEndTest.php       (UPDATED, PR-A1) — uses seam, removes filter
tests/integration/YouTubeEndToEndTest.php       (UPDATED, PR-A1) — uses seam, removes filter
tests/integration/ShareTargetDispatchTest.php   (UPDATED, PR-A1) — uses seam, removes filter
tests/integration/bootstrap.php                 (UPDATED, PR-A1.5) — option_active_plugins filter
docs/dev/overnight-log-2026-05-09.md            (this file)
```

Plus memory entries:
- `~/.claude/projects/-Users-crobertson/memory/integration_suite_was_always_red_lesson.md` (5 lessons)
- `~/.claude/projects/-Users-crobertson/memory/outpost_companion_active_plugins_test_env.md` (gotcha #11)

## What did NOT happen (intentionally)

- No merges. All 3 PRs await Courtney's morning review.
- Zero new cluster work. Zero new stub migrations.
- Nothing to live (`courtneyr.dev`).
- No DB mutations outside wp-env sandbox.
- No modifications to the post-formats fork.
- No PR-A2 work in parallel — explicit directive to observe each PR's CI before stacking.

## What I'd want eyes on first (priority order)

1. **PR #84's bootstrap fix.** The `option_active_plugins` filter is the real meta-fix — it makes the test environment honestly model production for plugin-active state. If reviewers verify the pattern is right, future tests can rely on `is_plugin_active()` returning true without per-test boilerplate.

2. **PR #82's policy doc.** Required-status gating is the floor that prevents this whole class of failure recurring. The policy doc explicitly calls out the failure mode so future "this PR is urgent, just merge" pressure has documented friction.

3. **PR #83's seam pattern.** The constraint receipt table in the description names exactly which assertions changed and why. The 207→257 assertion count is the structural smoking gun: those assertions were always there but never running.

---

**Generated:** 2026-05-09 evening, after PR-A1.5 marked ready. Files referenced: `docs/dev/integration-test-gotchas.md`, `docs/dev/ci-gating-policy.md`, `.session-progress.md`. Memory entries in `~/.claude/projects/-Users-crobertson/memory/`.
