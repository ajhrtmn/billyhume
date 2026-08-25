#!/usr/bin/env bash
# Advisory check for CLAUDE.md's own hard convention: "every real change
# bumps both the plugin header Version: and the OUS_VER constant... in
# the same commit," with the narrative in CHANGELOG.md, never a source
# comment. Nothing until now actually checked this -- it was a rule a
# person had to remember, which is exactly the class of thing this
# ecosystem's other tools (check-accent-on-tint.js, check-plugin-
# whitelist.sh) already convert into something a machine checks instead.
#
# Non-blocking by design, same reasoning as the PHPCS/PHPStan-level-7
# steps in checks.yml: this is a NEW check on a mature codebase, and a
# gate that fails on day one for pre-existing habits is a gate that gets
# disabled. It reports; it doesn't fail the build.
#
# Deliberately loose about WHAT counts as "the version moved" — it only
# checks whether the plugin's own CHANGELOG.md changed in the same diff,
# not whether the Version: header specifically incremented. A real
# per-plugin doc, once created, is the signal that's actually enforceable
# without parsing semver and false-failing on legitimate non-version
# changes (a README typo fix, a test-only change) that this check
# deliberately doesn't even consider in scope (see FILTERED below).
#
# Portable to bash 3.2 (macOS default) — no mapfile/readarray, matching
# tools/phpcs-changed.sh's own constraint.
set -euo pipefail

BASE="${1:-origin/master}"
cd "$(dirname "$0")/.."

CHANGED=$( {
    git diff --name-only --diff-filter=ACMR -- 'wp-content/plugins/*' || true
    git diff --name-only --cached --diff-filter=ACMR -- 'wp-content/plugins/*' || true
    git ls-files -o --exclude-standard -- 'wp-content/plugins/*' || true
    if git rev-parse --verify --quiet "$BASE" >/dev/null 2>&1; then
      git diff --name-only --diff-filter=ACMR "$BASE"...HEAD -- 'wp-content/plugins/*' || true
    fi
  } | sort -u )

if [ -z "$CHANGED" ]; then
  echo "version-bump: no changed files under wp-content/plugins/ — nothing to check."
  exit 0
fi

any_flagged=0
for plugin in $(bash tools/ecosystem-plugins.sh | sed 's#wp-content/plugins/##'); do
  # "Real" code change: anything under this plugin's own dir, excluding
  # tests, vendor, and doc/changelog files themselves — a test fixture or
  # a typo fix in a .md file was never the thing this convention is
  # about.
  set +e
  plugin_changed=$(printf '%s\n' "$CHANGED" \
    | grep "^wp-content/plugins/$plugin/" \
    | grep -v '/vendor/' \
    | grep -v '/tests/' \
    | grep -v '\.md$')
  changelog_changed=$(printf '%s\n' "$CHANGED" | grep -x "wp-content/plugins/$plugin/CHANGELOG.md")
  set -e

  if [ -n "$plugin_changed" ] && [ -z "$changelog_changed" ]; then
    any_flagged=1
    echo "NOTE: $plugin has real code changes in this diff but CHANGELOG.md didn't move:"
    printf '%s\n' "$plugin_changed" | sed 's/^/    /'
  fi
done

if [ "$any_flagged" -eq 0 ]; then
  echo "version-bump: every plugin with real code changes also touched its own CHANGELOG.md."
fi
# Advisory only — see this file's own header for why. Never a non-zero exit.
exit 0
