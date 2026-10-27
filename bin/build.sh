#!/usr/bin/env bash
#
# Rebuild assets/tracker.min.js after editing assets/tracker.js. Needs Node
# (npx fetches esbuild on first run).
#
# tracker.min.js is printed inline on every page with a running test, so
# keep it small: the size is printed at the end, and the build fails over
# 1.5 KB gzipped (the readme promises under 1.5 KB).
#
# Usage: bin/build.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../assets"

npx --yes esbuild@0.24.0 tracker.js --minify --target=es2017 --outfile=tracker.min.js

# PHP swaps this placeholder for the settings, so it must survive minifying.
grep -q '__FABT_CONFIG__' tracker.min.js || { echo "Error: __FABT_CONFIG__ is missing from tracker.min.js" >&2; exit 1; }

raw=$(wc -c < tracker.min.js | tr -d ' ')
gz=$(gzip -9 -c tracker.min.js | wc -c | tr -d ' ')
printf '\ntracker.min.js  %5d bytes, %5d gzipped\n' "$raw" "$gz"

[ "$gz" -lt 1536 ] || { echo "Error: tracker.min.js is $gz bytes gzipped; keep it under 1536." >&2; exit 1; }
