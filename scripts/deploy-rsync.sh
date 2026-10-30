#!/usr/bin/env bash
# Upload a built dist/ to a web root with rsync over SSH.
#
#   scripts/deploy-rsync.sh TARGET [--go]
#
# TARGET is host:/absolute/path (for example cpanel:/home/ACCOUNT/vitalcare.uk) or a local folder.
# Without --go this is a CHECKSUM DRY RUN: it prints every file it would change and changes nothing.
#
# Safety rules, enforced here:
# - Never deletes anything on the target (no --delete of any kind).
# - Never touches /.htaccess: the live file carries the Cloudflare origin lock and is merged by hand.
# - Uploads hashed assets and other files first, then index.html, sw.js and the manifest LAST,
#   so a visitor never gets an index.html that points at files that are not there yet.
# - Refuses to run if dist/ is missing index.html or contains env files, source maps or dumps.
set -euo pipefail

DIST=${DIST:-dist}
TARGET=${1:-}
MODE=${2:-}

die() { echo "FAIL: $*" >&2; exit 1; }

[[ -n $TARGET ]] || die "usage: scripts/deploy-rsync.sh TARGET [--go]"
[[ -z $MODE || $MODE == "--go" ]] || die "the only option is --go"
[[ -f $DIST/index.html ]] || die "$DIST/index.html missing: run pnpm build first"
if find "$DIST" \( -name '.env*' -o -name '*.map' -o -name '*.sql' -o -name '*.sql.gz' -o -name '*.pem' -o -name '*.key' \) | grep -q .; then
  die "$DIST holds env files, source maps, dumps or keys; refusing"
fi
command -v rsync >/dev/null || die "rsync is not installed"

LAST=(index.html sw.js manifest.webmanifest)
COMMON=(-rlt --checksum --itemize-changes --exclude '/.htaccess')
EXCL_LAST=()
for f in "${LAST[@]}"; do EXCL_LAST+=(--exclude "/$f"); done

if [[ $MODE != "--go" ]]; then
  echo "== DRY RUN (checksum). Nothing is changed. Target: $TARGET"
  rsync "${COMMON[@]}" --dry-run "$DIST"/ "$TARGET"/
  echo "== DRY RUN done. Review the list, then re-run with --go once approved."
  exit 0
fi

echo "== Pass 1: everything except the entry files. Target: $TARGET"
rsync "${COMMON[@]}" "${EXCL_LAST[@]}" "$DIST"/ "$TARGET"/
echo "== Pass 2: entry files last"
args=()
for f in "${LAST[@]}"; do [[ -f $DIST/$f ]] && args+=("$DIST/$f"); done
rsync -lt --checksum --itemize-changes "${args[@]}" "$TARGET"/
echo "== Upload complete. A deploy is DONE only when the public URL shows the new build ID."
