#!/usr/bin/env bash
# Emits the ecosystem's own plugin paths, one per line. Source of truth is
# tools/ecosystem-plugins.txt — never hardcode this list in a script again.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
grep -vE '^\s*(#|$)' "$DIR/tools/ecosystem-plugins.txt" | while read -r p; do
  [ -d "$DIR/wp-content/plugins/$p" ] && echo "wp-content/plugins/$p"
done
