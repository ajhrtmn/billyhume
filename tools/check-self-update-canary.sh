#!/usr/bin/env bash
# Canary for OUS_GithubUpdates (class-github-updates.php) -- the
# self-hosted update mechanism this ecosystem's own plugins/theme use
# instead of wordpress.org, hand-rolling repo-archive-to-plugin-zip logic
# rather than depending on a third-party update-checker library. Real,
# genuine risk this exists to catch: nothing currently notices if the
# raw.githubusercontent.com endpoints that mechanism depends on stop
# resolving (a repo rename, a branch rename, a path that moved) until a
# real site depends on the update and it silently fails.
#
# Deliberately does NOT need a live WordPress install. class-github-
# updates.php's own load_sources() derives its plugin list from
# OUS_Registry::all() at runtime, but the same list -- one entry per
# ecosystem plugin, its own folder-name.php as the main file -- is
# already the exact shape tools/ecosystem-plugins.txt canonically
# provides, matching this project's own "one canonical list, not a
# per-tool re-derivation" rule (see that file's own header).
#
# Mirrors the REAL request class-github-updates.php's remote_main_file_
# path()/fetch_remote_version() make: same repo, same default branch
# ('dev' -- confirmed by reading load_sources() directly, not assumed),
# same raw.githubusercontent.com path shape. A plain HTTP check, not a
# WordPress request -- this can't exercise the PHP parsing/zip-install
# side of the mechanism, only confirm the URLs it depends on still
# resolve to real plugin headers.
set -euo pipefail
cd "$(dirname "$0")/.."

REPO="ajhrtmn/billyhume"
BRANCH="dev"
FAILED=0

check_url() {
  local label="$1" url="$2"
  local code body
  code=$(curl -s -o /tmp/canary-body -w '%{http_code}' "$url" || echo "000")
  body=$(cat /tmp/canary-body 2>/dev/null || echo "")
  if [ "$code" != "200" ]; then
    echo "FAIL: $label — HTTP $code — $url"
    FAILED=1
    return
  fi
  if ! echo "$body" | grep -q "Version:"; then
    echo "FAIL: $label — reachable (200) but no 'Version:' header found — $url"
    FAILED=1
    return
  fi
  echo "OK: $label"
}

while IFS= read -r plugin; do
  [ -z "$plugin" ] && continue
  [ "$plugin" = "self-hosted-self-admin-skin" ] && continue # not registered with OUS_GithubUpdates (a skin, not one of OUS_Registry's own bundled_zip entries)
  url="https://raw.githubusercontent.com/$REPO/$BRANCH/wp-content/plugins/$plugin/$plugin.php"
  check_url "$plugin" "$url"
done < <(grep -vE '^\s*(#|$)' tools/ecosystem-plugins.txt)

# The theme is registered separately (a fixed entry in load_sources(),
# not derived from OUS_Registry) with style.css as its "main file" —
# same shape class-github-updates.php's own remote_main_file_path()
# uses for type=theme.
check_url "the-self-hosted-self-theme (theme)" \
  "https://raw.githubusercontent.com/$REPO/$BRANCH/wp-content/themes/the-self-hosted-self-theme/style.css"

rm -f /tmp/canary-body

if [ "$FAILED" -eq 1 ]; then
  echo
  echo "One or more self-update source URLs did not resolve as expected — see FAIL lines above."
  exit 1
fi
echo
echo "All self-update source URLs resolve and contain a real Version: header."
