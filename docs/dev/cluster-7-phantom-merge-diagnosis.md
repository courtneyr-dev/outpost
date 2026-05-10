# Cluster #7 phantom-merge diagnosis

**Status:** Diagnosis only. No remediation in this PR. Courtney reviews the diagnosis tomorrow and approves the remediation approach before any fix work.

**Phase 3 of the 2026-05-09 → 2026-05-10 overnight queue.**

## Summary

PR #75 (`test: F6 ShortcutDispatch migration — 5 of 5 stubs (cluster #7, stacked on #74)`) shows MERGED on GitHub with merge commit `fc52bdf8`. **But the migrated test content never reached `main`.** `tests/integration/ShortcutDispatchTest.php` on main is in its original stub form, last modified by commit `4969ead` (PR #11, the original F6 share-target work that created the stub).

This is **NOT a "phantom merge"** in the literal sense — the merge commit `fc52bdf8` IS real and exists on `phase-g/shortcut-controller-test-seam`. **It's a "wrong-target merge"**: PR #75's squash-merge applied to its declared base branch (the seam branch from PR #74), not to main.

The earlier diagnosis ("phantom merge / reverted on main") was incorrect on the mechanism but correct on the substantive outcome (cluster #7's migration content is not on main).

## Reproducing the diagnosis

### Timeline (from PR metadata + git log)

| Time (UTC) | Event | Commit | Where |
|---|---|---|---|
| 2026-05-09 16:07:22 | PR #74 squash-merged | `277d49c` | `main` |
| 2026-05-09 16:07:30 | PR #75 squash-merged (8 seconds later) | `fc52bdf` | **`phase-g/shortcut-controller-test-seam`** (PR #74's branch) — NOT main |

### Critical PR metadata

```
$ gh pr view 75 --json mergedAt,mergeCommit,baseRefName,headRefName,state
{
  "baseRefName": "phase-g/shortcut-controller-test-seam",  ← stuck on PR #74's branch
  "headRefName": "phase-g/f6-shortcut-dispatch-migration",
  "mergeCommit": {"oid": "fc52bdf8bd595be6e2f3762938c92e44263b7fe5"},
  "mergedAt": "2026-05-09T16:07:30Z",
  "state": "MERGED"
}
```

PR #75's `baseRefName` was never retargeted to `main`. When the merge button was clicked, GitHub squash-merged onto the declared base branch (the seam branch). The commit `fc52bdf` is real and exists — just on the wrong branch.

### Verification

```
$ git show fc52bdf8 --stat
commit fc52bdf8bd595be6e2f3762938c92e44263b7fe5
Author: Courtney Robertson
Date:   Sat May 9 12:07:30 2026 -0400
    test: F6 ShortcutDispatch migration — 5 of 5 stubs (cluster #7, stacked on #74) (#75)

$ git branch -a --contains fc52bdf8
  remotes/origin/phase-g/shortcut-controller-test-seam       ← only on the seam branch

$ git log --oneline -- tests/integration/ShortcutDispatchTest.php
4969ead F6: Source_Detector dispatcher + ambiguity routing (#11)   ← only entry on main

$ git show main:tests/integration/ShortcutDispatchTest.php | head -20
... [stub form, 5 markTestSkipped methods] ...

$ git show fc52bdf:tests/integration/ShortcutDispatchTest.php | head -40
... [migrated form, 5 real test methods using set_payload_source_for_tests seam] ...
```

The migrated content lives on `fc52bdf` (on the seam branch, on remote) but NOT on main.

## Root cause

**Stacked-PR cascade prevention rule was not applied.** Per `stacked_pr_merge_sequencing.md` memory entry:

> Before merging an upstream PR, retarget any downstream PRs' bases to `main` via `gh pr edit <downstream> --base main`. Otherwise:
> - GitHub squash-merges the downstream PR onto its declared base (the upstream PR's branch), not onto main
> - The merge commit is real, but lands on a feature branch
> - Downstream PR shows MERGED but content doesn't propagate to main

PR #74's branch was `phase-g/shortcut-controller-test-seam`. PR #75 was opened with that branch as base. When PR #74 merged at 16:07:22, the operator should have run `gh pr edit 75 --base main` BEFORE letting PR #75 merge. That step was missed.

Same shape as the PR #66 → #67 cascade earlier in the project (the recovery for which is documented in `stacked_pr_merge_sequencing.md`).

## Remediation (proposed, NOT implemented in this PR)

The migrated content for cluster #7 is fully preserved on `fc52bdf`. Two viable recovery paths:

### Option Recovery-A: cherry-pick fc52bdf onto a fresh main-based branch

```bash
git fetch origin
git checkout -b phase-g/cluster-7-recovery main
git cherry-pick fc52bdf8bd595be6e2f3762938c92e44263b7fe5
git push -u origin phase-g/cluster-7-recovery
gh pr create --base main --head phase-g/cluster-7-recovery \
    --title "test: F6 ShortcutDispatch migration recovery (cluster #7 wrong-target-merge fix)"
```

The cherry-pick will produce a clean diff against main because PR #74's content (the seam method) is already on main as `277d49c`. Only PR #75's content (the test file changes + version bump) will appear in the diff.

**Recommended.** Mechanical, low-risk, mirrors the PR #66 → #67 recovery from earlier in the project.

### Option Recovery-B: open new PR from the seam branch with base=main

```bash
gh pr create --base main --head phase-g/shortcut-controller-test-seam \
    --title "test: F6 ShortcutDispatch migration recovery (cluster #7)"
```

GitHub will compute the diff between the seam branch and main. Since PR #74's content is already on main, only the cluster #7 changes will show.

**Less clean.** The seam branch carries `d53f367` (PR #74's original squash, now redundant with main's `277d49c`) plus `fc52bdf`. GitHub may show extra context. Recovery-A produces a tighter PR.

## What this diagnosis does NOT propose

- Force-push, history rewrite, or any destructive git operation. The seam branch + fc52bdf stay intact.
- Modifying PR #75's GitHub metadata (status flips, comment-bombing, etc.).
- Modifying main's history. Main is correct (cluster #7 simply never landed there).

## Hard-stop check on Phase 3 (per overnight rules)

> If Phase 3's git archaeology reveals the phantom-merge involved a force-push or destructive operation that's hard to recover from, hard stop and surface — don't attempt remediation.

✅ **No force-push or destructive operations involved.** The cascade was mundane: PR #75's base was never retargeted to main before PR #74 merged. Every commit involved is intact and reachable. Recovery via cherry-pick is mechanical (Option Recovery-A above).

## Lessons captured

1. **The original "phantom merge" framing was inaccurate.** The merge commit exists; it's just on the wrong branch. Future stacked-PR cascade reports should distinguish:
   - **Phantom merge (literal):** mergeCommit oid is fake; commit doesn't exist anywhere
   - **Wrong-target merge:** mergeCommit oid is real, on a feature branch, never propagates to main

   Cluster #7 is the second case. Same recovery (cherry-pick to main-based branch) but different mental model.

2. **Stacked-PR retarget rule must be honored.** This is the second cascade in the project (PR #66 → #67 was the first). Both happened despite the rule being documented in memory (`stacked_pr_merge_sequencing.md`). Suggests the rule needs operationalizing — perhaps a pre-merge hook, or a `gh` alias that auto-retargets downstream PRs before merging.

3. **The user's instruction earlier today to use `--admin` bypass on PR-A0 / PR-A1 / PR-A1.5 / PR-A2 / PR-A3 is unrelated to cluster #7 cascade.** Recovery PR for cluster #7 should NOT be a stacked PR.

## Path forward

1. **Tomorrow morning:** Courtney reviews this diagnosis, approves Option Recovery-A.
2. **Implementation PR (PR-Aux-3 or whatever number):** opens with the cherry-pick. Subject to standard review.
3. **PR-A4 (audit) updated:** the 5 ShortcutDispatch tests flip from "skipped (phantom-merge casualty)" to "verified passing" once the recovery PR lands and Integration runs cleanly.

## Related documents

- `stacked_pr_merge_sequencing.md` (memory entry) — the prevention rule that wasn't applied here
- `docs/dev/integration-test-gotchas.md` § Gotcha #7 — the same shape documented in the public reference doc
- `docs/dev/g99-honest-stub-count.md` — counts ShortcutDispatch as 0/5 verified, awaiting this recovery
- PR #67 (PR #66 cascade recovery) — the precedent for Option Recovery-A

---

**Generated:** 2026-05-09 evening, Phase 3 of overnight queue. No code changes.
