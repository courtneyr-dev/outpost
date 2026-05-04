#!/usr/bin/env bash
# bin/sync-research-from-vault.sh
#
# Sync Outpost research docs from the Obsidian vault into the repo's
# docs/research/ directory so Claude Code prompts can reference them
# via repo-relative paths instead of absolute vault paths.
#
# The vault copies are authoritative (with frontmatter + backlinks);
# the repo copies are the working-set reference for Claude Code
# sessions. Run this whenever the vault docs change.
#
# Usage:
#   bin/sync-research-from-vault.sh
#
# Idempotent. Safe to run repeatedly. Strips Obsidian frontmatter
# from the repo copies (the repo copies are plain markdown).

set -euo pipefail

# --- Vault discovery ---------------------------------------------------
# Try the new-laptop path first, then the old-laptop path. Edit if
# your vault lives somewhere else.
VAULT_CANDIDATES=(
    "$HOME/Documents/2nd Brain"
    "$HOME/no-icloud/2nd Brain"
)

VAULT_ROOT=""
for candidate in "${VAULT_CANDIDATES[@]}"; do
    if [[ -d "$candidate/1. Projects/llm-wiki-outpost/wiki/concepts" ]]; then
        VAULT_ROOT="$candidate"
        break
    fi
done

if [[ -z "$VAULT_ROOT" ]]; then
    echo "ERROR: Could not find Outpost vault at any known location:" >&2
    for c in "${VAULT_CANDIDATES[@]}"; do
        echo "  - $c" >&2
    done
    echo "" >&2
    echo "Edit VAULT_CANDIDATES in $0 if your vault is elsewhere." >&2
    exit 1
fi

VAULT_CONCEPTS="$VAULT_ROOT/1. Projects/llm-wiki-outpost/wiki/concepts"

# --- Repo destination --------------------------------------------------
# Resolve repo root from the script's location (bin/ is at repo root).
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
REPO_RESEARCH="$REPO_ROOT/docs/research"

mkdir -p "$REPO_RESEARCH"

# --- Files to sync -----------------------------------------------------
# source-in-vault → destination-in-repo
declare -a FILES=(
    "posse-outbound-may-2026.md"
    "capture-inbound-may-2026.md"
)

# --- Strip Obsidian frontmatter ----------------------------------------
# Removes the leading `---\n...\n---\n` block. Repo copies are plain
# markdown without frontmatter (vault copies retain it).
strip_frontmatter() {
    local input="$1"
    local output="$2"
    awk '
        BEGIN { in_fm = 0; fm_done = 0 }
        NR == 1 && /^---$/ && !fm_done { in_fm = 1; next }
        in_fm && /^---$/ { in_fm = 0; fm_done = 1; next }
        in_fm { next }
        { print }
    ' "$input" > "$output"
}

# --- Sync loop ---------------------------------------------------------
echo "Syncing research docs from vault → repo..."
echo "  Vault:  $VAULT_CONCEPTS"
echo "  Repo:   $REPO_RESEARCH"
echo ""

changed=0
for filename in "${FILES[@]}"; do
    src="$VAULT_CONCEPTS/$filename"
    dest="$REPO_RESEARCH/$filename"

    if [[ ! -f "$src" ]]; then
        echo "  WARN: source missing, skipping: $src" >&2
        continue
    fi

    tmp="$(mktemp)"
    strip_frontmatter "$src" "$tmp"

    if [[ ! -f "$dest" ]] || ! cmp -s "$tmp" "$dest"; then
        mv "$tmp" "$dest"
        echo "  ✓ updated: docs/research/$filename"
        changed=$((changed + 1))
    else
        rm "$tmp"
        echo "  · unchanged: docs/research/$filename"
    fi
done

echo ""
if [[ $changed -gt 0 ]]; then
    echo "Synced $changed file(s). Review with: git diff docs/research/"
else
    echo "All files already in sync."
fi
