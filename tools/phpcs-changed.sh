#!/usr/bin/env bash
# Run the security sniffs against CHANGED PHP files only.
#
# WHY changed-files rather than the whole tree: adopting WPCS on a mature
# 72k-line codebase surfaces ~1,200 pre-existing findings, nearly all
# low-severity pedantry (an un-unslashed nonce, an esc_url_raw without
# wp_unslash) in code that is otherwise doing escaping correctly. Demanding
# that be cleared before the gate turns on means the gate never turns on.
# Gating the diff means new code is held to the standard from today, and the
# backlog can be paid down deliberately. See TOOLING-EVALUATION.md.
#
# Portable to bash 3.2 (macOS default) — no mapfile/readarray.
set -euo pipefail

BASE="${1:-origin/master}"
cd "$(dirname "$0")/.."

# Always include working-tree changes (staged, unstaged, untracked) so this is
# useful locally before committing; add the branch diff when the base ref exists
# so CI covers the whole PR.
CHANGED=$( {
    git diff --name-only --diff-filter=ACMR -- '*.php' || true
    git diff --name-only --cached --diff-filter=ACMR -- '*.php' || true
    git ls-files -o --exclude-standard -- '*.php' || true
    if git rev-parse --verify --quiet "$BASE" >/dev/null 2>&1; then
      git diff --name-only --diff-filter=ACMR "$BASE"...HEAD -- '*.php' || true
    fi
  } | sort -u )

set +e
FILTERED=$(printf '%s\n' "$CHANGED" \
  | grep -v '^$' \
  | grep '^wp-content/plugins/' \
  | grep -v '/vendor/' \
  | grep -v '/tests/')
set -e

if [ -z "$FILTERED" ]; then
  echo "phpcs: no changed PHP files in scope — nothing to check."
  exit 0
fi

COUNT=$(printf '%s\n' "$FILTERED" | wc -l | tr -d ' ')
echo "phpcs: checking $COUNT changed file(s)"
printf '%s\n' "$FILTERED" | xargs vendor/bin/phpcs
