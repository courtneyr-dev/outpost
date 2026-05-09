# CI gating policy for `main`

**Effective:** 2026-05-09
**Required for merge:** all CI jobs green, including Integration suite

## What this policy gates

Every PR targeting `main` must show all of the following CI checks green before merge:

| Check | What it covers |
|---|---|
| `Section 5 Audit` | Case-study leakage, embedded credentials, hardcoded silos, untranslated strings, PII fixture handles |
| `PHPCS + PHPStan` | WordPress-Extra coding standards + level-6 static analysis |
| `PHPUnit (PHP 8.2)` | Unit suite on PHP 8.2 (minimum supported) |
| `PHPUnit (PHP 8.3)` | Unit suite on PHP 8.3 |
| `PHPUnit (PHP 8.4)` | Unit suite on PHP 8.4 |
| `TypeScript + Vitest + build smoke` | TS strict typecheck, Vitest unit suite, Vite production build |
| **`Integration suite (wp-env + WireMock)`** | **NEW required gate** — full integration tests against real WP via wp-env + WireMock sidecar |

`required_status_checks.strict = true` — branches must be up-to-date with `main` before merge.

## Why the Integration suite is now required (not just informational)

**Discovered 2026-05-09:** The Integration suite has been failing on every PR since PR #70 (cluster #4 Spotify migration) merged on 2026-05-04. We merged 11 PRs across the G99 stub-migration arc on the strength of unit suite + lint passing, treating Integration as informational. **Result:** the wp_redirect filter capture pattern shipped across PRs #70/#71/#72/#75/#80 was architecturally broken (skipped under `OUTPOST_TESTING_PWA_SHELL`), and none of those tests' assertions actually ran. We had review theater on assertions whose execution path never fired.

The pattern is documented in `integration-test-gotchas.md` and the corresponding Claude memory entry.

**This policy is the meta-fix.** Without it, the same class of failure rediscovers itself in three weeks. Required-status enforcement is the floor that protects every other code-quality investment.

## How the protection is configured

Settings live in repo branch protection (no source of truth in the repo file tree). For reproducibility, `bin/setup-branch-protection.sh` codifies the API call and is idempotent — re-run anytime to restore the documented configuration.

```bash
bin/setup-branch-protection.sh
```

Requires `gh` authenticated as a repo admin.

## Implications

- **PRs in flight at policy adoption:** any open PR whose Integration suite is red can no longer be merged without remediation. As of the PR opening this policy, no PRs are in flight beyond this one.
- **Recovery work:** PRs #70/#71/#72/#75/#80 introduced tests whose assertions never ran. PR-A1 through PR-A4 of the recovery sequence will fix those. None of those PRs can merge until each one's Integration suite is green, by definition of this policy.
- **Cluster #7 phantom-merge:** PR #75 was GitHub-merged but its content didn't land on main (cascade casualty when its base PR #74 was deleted). PR-A3 will re-land it on a fresh branch — and the new PR is bound by this policy.
- **Future PRs:** every subsequent migration cluster must show Integration green. The readiness check (a/b/c/d) gains an implicit fifth criterion: "the test's assertions actually run end-to-end."

## Disabling the policy

Don't, except temporarily for documented infrastructure migration. If you must:

1. `gh api -X DELETE repos/courtneyr-dev/outpost/branches/main/protection`
2. Document why in a commit message
3. Re-enable via `bin/setup-branch-protection.sh` ASAP

Disabling for "this PR is urgent" is the failure mode that produced the original problem this policy fixes.

## See also

- `docs/dev/integration-test-gotchas.md` — gotchas including the wp_redirect filter capture issue surfaced 2026-05-09
- Memory: `integration_suite_was_always_red_lesson.md` — the lesson from this discovery
- PR description for this policy PR — the full investigation trail
