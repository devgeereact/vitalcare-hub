#!/usr/bin/env bash
# Run any test command with temp/cache files kept inside the ignored .local-test/ folder.
# Example: bash scripts/with-local-tests.sh pnpm test
set -euo pipefail
project_root=$(cd "$(dirname "$0")/.." && pwd -P)
cd "$project_root"
if [ "$#" -eq 0 ]; then echo 'Usage: bash scripts/with-local-tests.sh <test command> [arguments...]' >&2; exit 64; fi
git rev-parse --show-toplevel >/dev/null 2>&1 || { echo 'BLOCKED: initialise/adopt the intended Git project before using this helper.' >&2; exit 1; }
git check-ignore -q --no-index .local-test/probe || { echo 'BLOCKED: add /.local-test/ to .gitignore first.' >&2; exit 1; }
git check-ignore -q --no-index .local-state/probe || { echo 'BLOCKED: add /.local-state/ to .gitignore first.' >&2; exit 1; }
if [ -n "$(git ls-files -- .local-test .local-state)" ]; then echo 'BLOCKED: private local paths are already tracked; review/remediate before testing.' >&2; exit 1; fi
for folder in .local-test .local-state; do
  [ ! -L "$folder" ] || { echo 'BLOCKED: local artifact root must not be a symlink.' >&2; exit 1; }
done
umask 077
mkdir -p .local-test/{tmp,cache,db,config,keys,logs,reports} .local-state/backups
for folder in .local-test/{tmp,cache,db,config,keys,logs,reports} .local-state/backups; do
  [ ! -L "$folder" ] || { echo 'BLOCKED: local artifact directory must not be a symlink.' >&2; exit 1; }
done
chmod 700 .local-test .local-state .local-test/{tmp,cache,db,config,keys,logs,reports} .local-state/backups
export TMPDIR="$project_root/.local-test/tmp"
export TMP="$TMPDIR"
export TEMP="$TMPDIR"
export XDG_CACHE_HOME="$project_root/.local-test/cache"
exec "$@"
