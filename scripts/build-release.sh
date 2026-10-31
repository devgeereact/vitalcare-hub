#!/usr/bin/env bash
# Package one release for the deploy gate: api.tar (PHP code), site.tar (the built front end) and SHA256SUMS.
#
#   scripts/build-release.sh COMMIT_ID [--no-site]
#
# COMMIT_ID is the full 40-character commit SHA. It is written into the archives (RELEASE in the API,
# build-id.txt in the site) so the gate can refuse a mismatched upload and a deploy can prove what is live.
# Output: .release/COMMIT_ID/{api.tar,site.tar,SHA256SUMS}. DIST (default dist) is the built site.
set -euo pipefail
cd "$(dirname "$0")/.."

ID="${1:-}"; NOSITE="${2:-}"
[[ "$ID" =~ ^[0-9a-f]{40}$ ]] || { echo "usage: scripts/build-release.sh <40-hex commit id> [--no-site]" >&2; exit 64; }
[ -z "$NOSITE" ] || [ "$NOSITE" = "--no-site" ] || { echo "the only option is --no-site" >&2; exit 64; }
DIST="${DIST:-dist}"
BE="${BACKEND_DIR:-backend}"   # tests point this at a trimmed copy
OUT="${RELEASE_OUT:-.release}/$ID"

if command -v sha256sum >/dev/null 2>&1; then SHA="sha256sum"; else SHA="shasum -a 256"; fi
# No macOS extended attributes or AppleDouble files in the archives.
export COPYFILE_DISABLE=1
TAR=(tar)
if tar --version 2>/dev/null | grep -q 'GNU tar'; then TAR+=(--owner=0 --group=0 --numeric-owner); else TAR+=(--uid 0 --gid 0); fi

rm -rf "$OUT"; mkdir -p "$OUT"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

# --- API
A="$WORK/api"; mkdir -p "$A/public" "$A/tools"
rsync -rlt --exclude '*.test.*' --exclude 'tests/' --exclude '.DS_Store' "$BE"/src/ "$A/src/"
rsync -rlt --exclude '.DS_Store' "$BE"/bin/ "$A/bin/"
rsync -rlt --exclude '.DS_Store' "$BE"/migrations/ "$A/migrations/"
# Fonts embedded in the certificate PDF, with their licence (backend/assets/fonts; D-018 item 6).
if [ -d "$BE/assets" ]; then rsync -rlt --exclude '.DS_Store' "$BE"/assets/ "$A/assets/"; fi
cp "$BE"/public/index.php "$A/public/index.php"
cp scripts/merge-htaccess.py "$A/tools/merge-htaccess.py"
printf '%s\n' "$ID" > "$A/RELEASE"
"${TAR[@]}" -cf "$OUT/api.tar" -C "$A" .

# --- Site
if [ "$NOSITE" != "--no-site" ]; then
  [ -f "$DIST/index.html" ] || { echo "FAIL: $DIST/index.html missing: run pnpm build first (or pass --no-site)" >&2; exit 1; }
  if find "$DIST" \( -name '.env*' -o -name '*.map' -o -name '*.sql' -o -name '*.sql.gz' -o -name '*.pem' -o -name '*.key' -o -name '.htaccess' \) | grep -q .; then
    echo "FAIL: $DIST holds env files, source maps, dumps, keys or a .htaccess; refusing to package it" >&2; exit 1
  fi
  S="$WORK/site"; mkdir -p "$S"
  rsync -rlt --exclude '.DS_Store' "$DIST"/ "$S"/
  printf '%s\n' "$ID" > "$S/build-id.txt"
  "${TAR[@]}" -cf "$OUT/site.tar" -C "$S" .
fi

( cd "$OUT" && $SHA api.tar $([ -f site.tar ] && echo site.tar) > SHA256SUMS )
# shasum prints "hash  name" with two spaces on both platforms; the gate requires exactly that form.
echo "Built $OUT:"; ls -l "$OUT" | sed 's/^/  /'
