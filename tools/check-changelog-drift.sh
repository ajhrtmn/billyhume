#!/usr/bin/env bash
# Advisory docs-drift check: does each plugin's own CHANGELOG.md's newest
# (topmost) entry actually match its main file's `Version:` header?
#
# WHY this exists: check-version-bump.sh already checks that a diff
# touching a plugin's code ALSO touches that plugin's CHANGELOG.md, but
# it deliberately doesn't check WHAT changed in the changelog -- a
# CHANGELOG.md edit that isn't a new top entry (a typo fix, a reworded
# old entry) satisfies that check while the header keeps moving without
# a matching entry ever landing. This is the narrower, mechanical half of
# "docs drift" that's actually checkable without parsing prose or
# understanding intent: two numbers that are supposed to always agree,
# diffed against each other.
#
# Real, this-repo-not-hypothetical finding from writing this check:
# bh-contest.php's header already reads 3.7.33 while CHANGELOG.md's
# newest entry is only 3.7.30 -- three version bumps with no
# corresponding entry, pre-existing debt this check surfaces rather than
# invents.
#
# Deliberately narrow scope, matching this project's own "measure, don't
# assume" discipline: this checks ONLY the two version numbers agree. It
# does not (and cannot, without genuine language understanding) check
# whether a changelog entry's PROSE actually describes what the diff
# does, whether STATE.md/OPEN.md's claims still hold, or any other
# semantic drift between docs and code. Scope was deliberately kept to
# what a machine can check honestly rather than stretched to sound more
# thorough than it is.
#
# Advisory only, same reasoning as check-version-bump.sh: a mature
# codebase with real pre-existing drift (see bh-contest above) would
# fail a brand-new gate on day one, and a gate that fails on day one for
# pre-existing habits is a gate that gets disabled. This reports; it
# never fails the build.
#
# Portable to bash 3.2 (macOS default) -- no mapfile/readarray.
set -euo pipefail
cd "$(dirname "$0")/.."

SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+'

any_flagged=0
while IFS= read -r plugin; do
  [ -z "$plugin" ] && continue

  main_file="wp-content/plugins/$plugin/$plugin.php"
  changelog="wp-content/plugins/$plugin/CHANGELOG.md"

  if [ ! -f "$main_file" ]; then
    echo "SKIP: $plugin — no $main_file found"
    continue
  fi
  if [ ! -f "$changelog" ]; then
    echo "SKIP: $plugin — no CHANGELOG.md found"
    continue
  fi

  header_version=$(grep -m1 -oE 'Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "$main_file" \
    | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)
  # Newest entry is the first line matching the semver pattern anywhere
  # in the file, since entries are plain "X.Y.Z — description" paragraphs
  # (see CONVENTIONS.md), not markdown headings.
  changelog_version=$(grep -m1 -oE "$SEMVER_RE" "$changelog" || true)

  if [ -z "$header_version" ]; then
    echo "SKIP: $plugin — could not find a Version: header in $main_file"
    continue
  fi
  if [ -z "$changelog_version" ]; then
    echo "SKIP: $plugin — could not find a version-numbered entry in $changelog"
    continue
  fi

  if [ "$header_version" != "$changelog_version" ]; then
    any_flagged=1
    echo "DRIFT: $plugin — header says $header_version, CHANGELOG.md's newest entry is $changelog_version"
  else
    echo "OK: $plugin — $header_version"
  fi
done < <(grep -vE '^\s*(#|$)' tools/ecosystem-plugins.txt)

if [ "$any_flagged" -eq 1 ]; then
  echo
  echo "One or more plugins have a Version: header ahead of (or behind) their own CHANGELOG.md's newest entry — see DRIFT lines above."
fi
# Advisory only — see this file's own header for why. Never a non-zero exit.
exit 0
