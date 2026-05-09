#!/usr/bin/env bash
# Apply branch protection rules for main. Idempotent; rerun anytime to
# restore the documented gating policy.
#
# Required status checks: ALL CI jobs. Critically includes the
# Integration suite, which historically was non-gating and silently
# failed across multiple PRs (PR #70 onward). See
# docs/dev/ci-gating-policy.md for the full rationale.
#
# Usage:
#   bin/setup-branch-protection.sh
#
# Requires: gh CLI authenticated as a repo admin.

set -euo pipefail

REPO="courtneyr-dev/outpost"
BRANCH="main"

echo "Applying branch protection on ${REPO}#${BRANCH}..."

gh api \
    --method PUT \
    -H "Accept: application/vnd.github+json" \
    "repos/${REPO}/branches/${BRANCH}/protection" \
    --input - <<'JSON'
{
    "required_status_checks": {
        "strict": true,
        "contexts": [
            "Section 5 Audit",
            "PHPCS + PHPStan",
            "PHPUnit (PHP 8.2)",
            "PHPUnit (PHP 8.3)",
            "PHPUnit (PHP 8.4)",
            "TypeScript + Vitest + build smoke",
            "Integration suite (wp-env + WireMock)"
        ]
    },
    "enforce_admins": false,
    "required_pull_request_reviews": null,
    "restrictions": null,
    "required_linear_history": false,
    "allow_force_pushes": false,
    "allow_deletions": false,
    "required_conversation_resolution": false
}
JSON

echo ""
echo "Branch protection applied. Verifying..."
gh api "repos/${REPO}/branches/${BRANCH}/protection/required_status_checks" \
    --jq '.contexts'
