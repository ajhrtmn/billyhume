#!/usr/bin/env bash
# Fails if deploy-ftp.yml's PLUGIN_FOLDERS disagrees with the canonical
# tools/ecosystem-plugins.txt -- the two are supposed to be kept in step
# (ecosystem-plugins.txt's own header says so directly), but nothing
# actually enforced that until this. Found the hard way: this exact drift
# happened at least twice already, most recently self-hosted-self-admin-
# skin shipping real code for a full session while silently absent from
# the FTP deploy list, discovered only by reading both files side by side
# rather than by any check catching it.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

canonical=$(grep -vE '^\s*(#|$)' "$DIR/tools/ecosystem-plugins.txt" | sort)

# PLUGIN_FOLDERS is a YAML block scalar (`>-`), one plugin name per line,
# indented, terminated by the next unindented line (a blank line before
# `jobs:`). Extracting it without a YAML parser: everything between the
# `PLUGIN_FOLDERS: >-` line and the next line that isn't indented.
deployed=$(awk '
  /PLUGIN_FOLDERS: >-/ { capture=1; next }
  capture && /^[[:space:]]+[^[:space:]]/ { gsub(/^[[:space:]]+|[[:space:]]+$/, ""); print; next }
  capture { exit }
' "$DIR/.github/workflows/deploy-ftp.yml" | sort)

if [ "$canonical" = "$deployed" ]; then
  echo "OK — deploy-ftp.yml's PLUGIN_FOLDERS matches tools/ecosystem-plugins.txt ($(echo "$canonical" | wc -l | tr -d ' ') plugins)."
  exit 0
fi

echo "MISMATCH between deploy-ftp.yml's PLUGIN_FOLDERS and tools/ecosystem-plugins.txt:"
echo
echo "In ecosystem-plugins.txt but NOT deployed (would never reach a stable promotion):"
comm -23 <(echo "$canonical") <(echo "$deployed") | sed 's/^/  - /'
echo
echo "Deployed but NOT in ecosystem-plugins.txt (deploying something the allowlist doesn't know about):"
comm -13 <(echo "$canonical") <(echo "$deployed") | sed 's/^/  - /'
echo
echo "Fix: keep .github/workflows/deploy-ftp.yml's PLUGIN_FOLDERS and tools/ecosystem-plugins.txt in step, per ecosystem-plugins.txt's own header."
exit 1
