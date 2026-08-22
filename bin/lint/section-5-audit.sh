#!/usr/bin/env bash
#
# Section 5 audit lint — five checks that protect Outpost's
# WordPress.org §5 (Trademarks) posture and the plugin's promise to
# work for any IndieWeb user, not just the case-study author whose
# accounts the research docs are keyed to.
#
# Runs against the source tree. Exits 0 on clean, non-zero on any
# violation. File:line:violation output for every hit so CI's failure
# log points the contributor at exactly what to fix.
#
#   B1  Case-study handle leakage (forbidden tokens)
#   B2  Embedded credential heuristics
#   B3  Hardcoded silo instance URLs outside the canonical allowlist
#   B4  Untranslated user-facing strings in capabilities() arrays
#   B5  Personal data in test fixtures (handles outside allowlist)
#   B6  Hex literals in component CSS (Hard Contract: tokens.css is the
#       single source of truth for paint values; structure.css must
#       reference paint via var(--outpost-*) with NO fallback hex)
#
# Configuration files (siblings to this script):
#
#   case-study-tokens.txt        forbidden tokens (B1)
#   credential-patterns.txt      credential regexes (B2)
#   instance-allowlist.txt       allowed canonical hostnames (B3)
#   fixture-handle-allowlist.txt allowed fixture-handle names (B5)
#
# Usage:
#
#   bash bin/lint/section-5-audit.sh           run all checks
#   bash bin/lint/section-5-audit.sh --check B3 run a single check
#
# Suppression markers:
#
#   B1: lines that contain `concepts/posse-outbound-may-2026.md` or
#       `concepts/capture-inbound-may-2026.md` are exempt — research-doc
#       citations are allowed to name handles by reference. The generated
#       POT's `msgid "https://courtneyr.dev"` is also exempt: that string
#       is the plugin header's own Author URI, which `wp i18n make-pot`
#       copies into languages/. The same value is allowed in outpost.php
#       (the root file is outside B1_PATHS), so flagging the POT that
#       mirrors it is a false positive, not a leak.
#   B2: the marker `outpost-lint:fixture-credential` exempts test
#       fixtures that intentionally embed fake-but-real-shaped values.

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_ROOT="$( cd "$SCRIPT_DIR/../.." && pwd )"
TOKENS_FILE="$SCRIPT_DIR/case-study-tokens.txt"
CRED_FILE="$SCRIPT_DIR/credential-patterns.txt"
INSTANCE_FILE="$SCRIPT_DIR/instance-allowlist.txt"
FIXTURE_HANDLE_FILE="$SCRIPT_DIR/fixture-handle-allowlist.txt"

ONLY_CHECK="${1:-}"
if [[ "$ONLY_CHECK" == "--check" ]]; then
    ONLY_CHECK="${2:-}"
fi

cd "$REPO_ROOT"

# Source-tree scopes per check. Excludes vendor/, node_modules/, and the
# Vite build output's source maps (.js.map files contain inlined
# source paths that re-include hits already covered by the source scan).
B1_PATHS=(includes/ tests/ bin/ languages/)
B1_BUILD_PATHS=(build/)
B2_PATHS=(includes/ tests/ bin/ languages/)
B3_PATHS=(includes/ tests/ bin/ languages/)
B4_PATHS=(includes/companions/)
B5_PATHS=(tests/fixtures/)
B6_PATHS=(pwa/src/styles/structure.css)

EXCLUDE_PATTERNS=(
    --exclude-dir=vendor
    --exclude-dir=node_modules
    --exclude-dir=.git
    --exclude='*.map'
)

# The lint's own config files and script intentionally contain the
# forbidden tokens / patterns / hostnames they're meant to detect.
# Excluding them from the scan keeps the lint from self-flagging.
LINT_SELF_EXCLUDE=(
    --exclude-dir=lint
)

# Load patterns from a config file, dropping comments and blank lines.
load_patterns() {
    local file="$1"
    grep -v '^[[:space:]]*#' "$file" | grep -v '^[[:space:]]*$' || true
}

# `grep` exits 1 on no-match by default; that's success for the lint.
# Wrap it so set -e doesn't bail on clean scans.
safe_grep() {
    grep "$@" || true
}

# ---------------------------------------------------------------------
# B1 — case-study handle leakage
# ---------------------------------------------------------------------
check_b1() {
    local hits=0
    local pattern
    local matches
    while read -r pattern; do
        [[ -z "$pattern" ]] && continue
        matches="$(
            safe_grep -rniE "$pattern" "${B1_PATHS[@]}" "${B1_BUILD_PATHS[@]}" \
                "${EXCLUDE_PATTERNS[@]}" "${LINT_SELF_EXCLUDE[@]}" 2>/dev/null \
                | safe_grep -v 'concepts/posse-outbound-may-2026.md' \
                | safe_grep -v 'concepts/capture-inbound-may-2026.md' \
                | safe_grep -vE '\.pot:[0-9]+:msgid "https://courtneyr\.dev"'
        )"
        if [[ -n "$matches" ]]; then
            echo "B1: case-study token '$pattern' found:"
            echo "$matches" | sed 's/^/    /'
            hits=$(( hits + 1 ))
        fi
    done < <( load_patterns "$TOKENS_FILE" )
    return $hits
}

# ---------------------------------------------------------------------
# B2 — embedded credential heuristics
# ---------------------------------------------------------------------
check_b2() {
    local hits=0
    local pattern
    local matches
    while read -r pattern; do
        [[ -z "$pattern" ]] && continue
        matches="$(
            safe_grep -rnE "$pattern" "${B2_PATHS[@]}" \
                "${EXCLUDE_PATTERNS[@]}" "${LINT_SELF_EXCLUDE[@]}" 2>/dev/null \
                | safe_grep -v 'outpost-lint:fixture-credential'
        )"
        if [[ -n "$matches" ]]; then
            echo "B2: credential pattern '$pattern' found:"
            echo "$matches" | sed 's/^/    /'
            hits=$(( hits + 1 ))
        fi
    done < <( load_patterns "$CRED_FILE" )
    return $hits
}

# ---------------------------------------------------------------------
# B3 — hardcoded silo instance URLs outside allowlist
# ---------------------------------------------------------------------
check_b3() {
    # Build an allowlist regex by joining entries with `|` and escaping dots.
    local allowlist_alt
    allowlist_alt="$(
        load_patterns "$INSTANCE_FILE" \
            | sed 's/\./\\./g' \
            | tr '\n' '|' \
            | sed 's/|$//'
    )"
    # Match @user@instance.tld and bare https://instance.tld where the
    # instance hostname matches a fediverse-shaped pattern (mastodon,
    # pleroma, akkoma, misskey, friendica, hubzilla, pixelfed, bsky,
    # threads). The lint flags any such hostname NOT on the allowlist.
    local fedi_pattern='@[A-Za-z0-9_]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})'
    local url_pattern='https?://(([a-z0-9-]+\.)?(mastodon|pleroma|akkoma|misskey|friendica|hubzilla|pixelfed|bsky)[a-z0-9.-]*\.[a-z]{2,}|[a-z0-9-]+\.threads\.net)'

    local matches
    matches="$(
        safe_grep -rniE "$fedi_pattern|$url_pattern" "${B3_PATHS[@]}" \
            "${EXCLUDE_PATTERNS[@]}" "${LINT_SELF_EXCLUDE[@]}" 2>/dev/null \
            | safe_grep -v 'concepts/posse-outbound-may-2026.md' \
            | safe_grep -v 'concepts/capture-inbound-may-2026.md' \
            | safe_grep -vE "($allowlist_alt)"
    )"
    if [[ -n "$matches" ]]; then
        echo "B3: hardcoded fediverse instance URL or @handle outside the allowlist:"
        echo "$matches" | sed 's/^/    /'
        return 1
    fi
    return 0
}

# ---------------------------------------------------------------------
# B4 — untranslated user-facing strings in capabilities() arrays
# ---------------------------------------------------------------------
#
# Targeted heuristic: in any companion adapter file under
# includes/companions/, find every `'label' =>` and `'caveats' =>`
# array entry inside a capabilities() return. Bare string literals
# (single- or double-quoted) without a __()/_x()/_n() wrapper are
# violations; gettext-wrapped strings are fine.
#
# Operationally: scan with PHP regex for the two key shapes. Two
# patterns are violations:
#
#     'label' => 'literal'
#     'label' => "literal"
#
# Allowed:
#
#     'label' => __( 'literal', 'outpost' )
#
# Caveats values are always inside arrays of strings; we walk the
# caveats array entries individually.
check_b4() {
    # Pattern 1: 'label' => '<literal>' that ISN'T already gettext-wrapped.
    # The matched text contains the value side; if it includes a __() / _x()
    # / _n() opener anywhere, we treat it as wrapped (false-positive prone
    # only on multi-line forms, which are rare).
    local label_hits
    label_hits="$(
        safe_grep -rnE "['\"]label['\"][[:space:]]*=>[[:space:]]*['\"][^'\"]+['\"]" \
            "${B4_PATHS[@]}" \
            "${EXCLUDE_PATTERNS[@]}" "${LINT_SELF_EXCLUDE[@]}" 2>/dev/null \
            | safe_grep -v '__(' \
            | safe_grep -v '_x(' \
            | safe_grep -v '_n(' \
            | safe_grep -v 'esc_html__(' \
            | safe_grep -v 'esc_attr__('
    )"

    # Pattern 2: caveats array entries with a bare string immediately after
    # `array(`. Same gettext drop applied.
    local caveats_hits
    caveats_hits="$(
        safe_grep -rnE "['\"]caveats['\"][[:space:]]*=>[[:space:]]*array\([[:space:]]*['\"][^'\"]+['\"]" \
            "${B4_PATHS[@]}" \
            "${EXCLUDE_PATTERNS[@]}" "${LINT_SELF_EXCLUDE[@]}" 2>/dev/null \
            | safe_grep -v '__(' \
            | safe_grep -v '_x(' \
            | safe_grep -v '_n('
    )"

    local rc=0
    if [[ -n "$label_hits" ]]; then
        echo "B4: untranslated 'label' string in companion capabilities() output:"
        echo "$label_hits" | sed 's/^/    /'
        rc=1
    fi
    if [[ -n "$caveats_hits" ]]; then
        echo "B4: untranslated 'caveats' array entry in companion capabilities() output:"
        echo "$caveats_hits" | sed 's/^/    /'
        rc=1
    fi
    return $rc
}

# ---------------------------------------------------------------------
# B5 — personal data in test fixtures
# ---------------------------------------------------------------------
#
# Test fixtures must use generic example values. Any handle-shaped
# token (a name appearing in a string-context like '@<handle>' or
# `username = "<handle>"`) that is NOT in the fixture-handle allowlist
# AND is NOT a generic example.* domain triggers a B5 failure.
#
# Heuristic: extract names from common assignment patterns in fixture
# files; check each against the allowlist; report any that are neither
# allowlisted nor a structural keyword.
check_b5() {
    local handle_alt
    handle_alt="$(
        load_patterns "$FIXTURE_HANDLE_FILE" \
            | tr '\n' '|' \
            | sed 's/|$//'
    )"

    # Extract `@handle` patterns. Skip PHPDoc tags (lines starting with
    # `*` are doc-comment continuations like `* @param`, `* @return`).
    # We extract via -no first then post-filter via the file:line lookup.
    local matches
    matches="$(
        safe_grep -rnE "@[A-Za-z0-9_]+" "${B5_PATHS[@]}" \
            "${EXCLUDE_PATTERNS[@]}" "${LINT_SELF_EXCLUDE[@]}" 2>/dev/null \
            | safe_grep -vE "^[^:]+:[0-9]+:[[:space:]]*\*" \
            | safe_grep -vE "^[^:]+:[0-9]+:[[:space:]]*//" \
            | safe_grep -vE "@($handle_alt)([^A-Za-z0-9_]|$)"
    )"
    # Also catch 'name' => 'value' assignments that look like handles.
    local handle_assignments
    handle_assignments="$(
        safe_grep -rnE "^[[:space:]]*'(name|user|user_login|handle|username|display_name)'[[:space:]]*=>[[:space:]]*['\"][A-Za-z0-9_]+['\"]" \
            "${B5_PATHS[@]}" \
            "${EXCLUDE_PATTERNS[@]}" "${LINT_SELF_EXCLUDE[@]}" 2>/dev/null \
            || true
    )"

    # Filter handle_assignments to drop any line whose value is allowlisted
    # or is a generic example.* literal.
    local clean_assignments=""
    if [[ -n "$handle_assignments" ]]; then
        while IFS= read -r line; do
            [[ -z "$line" ]] && continue
            local quoted_value
            quoted_value="$(echo "$line" | grep -oE "['\"][A-Za-z0-9_]+['\"]" | tail -n1 | tr -d "'\"")"
            if [[ -z "$quoted_value" ]]; then
                continue
            fi
            if echo "$quoted_value" | grep -qE "^($handle_alt)$"; then
                continue
            fi
            # Allow numeric-only IDs and "test" / "example".
            if [[ "$quoted_value" =~ ^[0-9]+$ ]]; then
                continue
            fi
            if [[ "$quoted_value" == "test" || "$quoted_value" == "example" ]]; then
                continue
            fi
            clean_assignments+="$line"$'\n'
        done <<< "$handle_assignments"
    fi

    local hits=0
    if [[ -n "$matches" ]]; then
        echo "B5: non-allowlisted @handle token in test fixture:"
        echo "$matches" | sed 's/^/    /'
        hits=$(( hits + 1 ))
    fi
    if [[ -n "$clean_assignments" ]]; then
        echo "B5: non-allowlisted handle-shaped assignment in test fixture:"
        echo "$clean_assignments" | sed 's/^/    /'
        hits=$(( hits + 1 ))
    fi
    return $hits
}

# ---------------------------------------------------------------------
# B6 — hex literals in component CSS
#
# Hard Contract (CLAUDE.md): plugin owns layout, theme owns paint.
# Token files (`pwa/src/styles/tokens.css`, `styles/outpost-tokens.css`)
# are the single source of truth for paint values. Component CSS must
# reference paint via `var(--outpost-{token})` with NO fallback hex —
# fallback hexes inside `var(...)` are still hex code in a non-token
# block and shadow theme overrides under service-worker cache misses.
#
# Catches both forms:
#   - direct hex:   color: #241c4a;
#   - fallback hex: color: var(--outpost-foo, #241c4a);
#
# B6 only scans component CSS (`pwa/src/styles/structure.css`). Token
# files are exempt by design — they ARE the source of truth.
# ---------------------------------------------------------------------
check_b6() {
    local hits=0
    local matches
    matches="$(
        safe_grep -niE '#[0-9a-fA-F]{3,8}\b' "${B6_PATHS[@]}"
    )"
    if [[ -n "$matches" ]]; then
        echo "B6: hex literal in component CSS (use a token from tokens.css instead):"
        echo "$matches" | sed 's/^/    /'
        hits=$(( hits + 1 ))
    fi
    return $hits
}

# ---------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------
run_check() {
    local name="$1"
    local fn="$2"
    local rc=0
    "$fn" || rc=$?
    if [[ $rc -ne 0 ]]; then
        echo "[FAIL] $name"
        return 1
    fi
    echo "[OK]   $name"
    return 0
}

main() {
    local failed=0

    if [[ -z "$ONLY_CHECK" || "$ONLY_CHECK" == "B1" ]]; then
        run_check "B1 case-study handle leakage" check_b1 || failed=$(( failed + 1 ))
    fi
    if [[ -z "$ONLY_CHECK" || "$ONLY_CHECK" == "B2" ]]; then
        run_check "B2 embedded credential heuristics" check_b2 || failed=$(( failed + 1 ))
    fi
    if [[ -z "$ONLY_CHECK" || "$ONLY_CHECK" == "B3" ]]; then
        run_check "B3 hardcoded silo instance URLs" check_b3 || failed=$(( failed + 1 ))
    fi
    if [[ -z "$ONLY_CHECK" || "$ONLY_CHECK" == "B4" ]]; then
        run_check "B4 untranslated capabilities() strings" check_b4 || failed=$(( failed + 1 ))
    fi
    if [[ -z "$ONLY_CHECK" || "$ONLY_CHECK" == "B5" ]]; then
        run_check "B5 personal data in test fixtures" check_b5 || failed=$(( failed + 1 ))
    fi
    if [[ -z "$ONLY_CHECK" || "$ONLY_CHECK" == "B6" ]]; then
        run_check "B6 hex literals in component CSS" check_b6 || failed=$(( failed + 1 ))
    fi

    if [[ $failed -gt 0 ]]; then
        echo ""
        echo "Section 5 audit FAILED: $failed check(s) reported violations."
        exit 1
    fi

    echo ""
    echo "Section 5 audit passed."
    exit 0
}

main "$@"
