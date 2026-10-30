#!/usr/bin/env bash
# Verified rolling backup of the application database and private storage.
#
#   backup.sh            create an automatic backup, verify it, then prune the oldest automatic ones
#   backup.sh --pin      create a pinned pre-migration backup (never pruned by rotation)
#
# Order is fixed: create, verify, mark complete, and only then prune. Any failure exits non-zero,
# removes the unfinished folder and prunes NOTHING. Keeps the newest 3 automatic backups by default.
# Reads credentials ONLY from <private root>/config/backup.cnf (mode 0600) via --defaults-file, which
# replaces every other option file. (--defaults-extra-file would let the account's ~/.my.cnf, which on a
# shared host may belong to another site, override these credentials.) Never prints them.
#
# Environment (all optional):
#   VITALCARE_PRIVATE_ROOT  default $HOME/vitalcare-private
#   VITALCARE_BACKUP_ROOT   default $HOME/vitalcare-backups
#   VITALCARE_DB            database name; default read from config/prod.php (the dbname= part only)
#   VITALCARE_KEEP          automatic backups to keep (default 3)
set -euo pipefail
umask 077

ROOT="${VITALCARE_PRIVATE_ROOT:-$HOME/vitalcare-private}"
BK="${VITALCARE_BACKUP_ROOT:-$HOME/vitalcare-backups}"
KEEP="${VITALCARE_KEEP:-3}"
CNF="$ROOT/config/backup.cnf"
MODE=auto
[ "${1:-}" = "--pin" ] && MODE=pinned

fail() { echo "BACKUP FAILED: $*" >&2; exit 1; }

[ -f "$CNF" ] || fail "missing $CNF"
[ "$(stat -c %a "$CNF" 2>/dev/null || stat -f %Lp "$CNF")" = "600" ] || fail "$CNF must be mode 0600"
case "$KEEP" in ''|*[!0-9]*) fail "VITALCARE_KEEP must be a number";; esac
[ "$KEEP" -ge 1 ] || fail "VITALCARE_KEEP must be at least 1"

DB="${VITALCARE_DB:-}"
if [ -z "$DB" ] && [ -f "$ROOT/config/prod.php" ]; then
  DB="$(grep -o "dbname=[A-Za-z0-9_]*" "$ROOT/config/prod.php" | head -1 | cut -d= -f2)"
fi
[ -n "$DB" ] || fail "database name not found"
case "$DB" in *[!A-Za-z0-9_]*) fail "unsafe database name";; esac

# Portable across the server (GNU) and macOS (BSD): checksum tool and gzip reader.
if command -v sha256sum >/dev/null 2>&1; then SHA="sha256sum"; else SHA="shasum -a 256"; fi
UNGZ="gzip -dc"

DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"; [ -n "$DUMP" ] || fail "no mysqldump or mariadb-dump"
CLIENT="$(command -v mariadb || command -v mysql || true)"; [ -n "$CLIENT" ] || fail "no mariadb or mysql client"

mkdir -p "$BK/db"
[ -d "$ROOT/storage" ] || fail "missing $ROOT/storage"

# One run at a time.
LOCK="$BK/db/.backup.lock"
mkdir "$LOCK" 2>/dev/null || fail "another backup is running (or a stale lock at $LOCK)"
TS="$(date -u +%Y%m%d-%H%M%S)"
if [ "$MODE" = pinned ]; then NAME="pinned-pre-migration-$TS"; else NAME="auto-$TS"; fi
PART="$BK/db/.$NAME.part"
FINAL="$BK/db/$NAME"
cleanup() { rc=$?; rmdir "$LOCK" 2>/dev/null || true; if [ $rc -ne 0 ] && [ -d "$PART" ]; then rm -rf -- "$PART"; fi; exit $rc; }
trap cleanup EXIT
[ ! -e "$FINAL" ] || fail "backup folder already exists: $NAME"
mkdir "$PART"

# 1. create
if ! "$DUMP" --defaults-file="$CNF" --single-transaction --routines --triggers --events --no-tablespaces --hex-blob "$DB" 2>"$PART/dump.err" | gzip -9 > "$PART/db.sql.gz"; then
  # Say why. The tool's error text names the user and host but never the password.
  { echo "dump tool said:"; head -n 5 "$PART/dump.err"; } >&2
  fail "the database dump failed"
fi
[ -s "$PART/db.sql.gz" ] || fail "empty database dump"
tar -C "$ROOT" -czf "$PART/storage.tar.gz" storage

# 2. verify
gzip -t "$PART/db.sql.gz" || fail "dump is not a valid gzip file"
$UNGZ "$PART/db.sql.gz" | tail -n 5 | grep -q "Dump completed" || fail "dump does not end with a completion marker (truncated?)"
tar -tzf "$PART/storage.tar.gz" >/dev/null || fail "storage archive is not readable"
IN_DUMP="$($UNGZ "$PART/db.sql.gz" | grep -c '^CREATE TABLE' || true)"
LIVE="$("$CLIENT" --defaults-file="$CNF" -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_TYPE='BASE TABLE'")"
[ "$IN_DUMP" = "$LIVE" ] || fail "table count differs: $IN_DUMP in the dump, $LIVE in the database"
MIG="$("$CLIENT" --defaults-file="$CNF" -N -B -e "SELECT COUNT(*) FROM $DB.schema_migrations" 2>/dev/null || echo none)"

# 3. mark complete
rm -f "$PART/dump.err"
( cd "$PART" && $SHA db.sql.gz storage.tar.gz > MANIFEST.sha256 )
{
  echo "created_utc=$TS"
  echo "mode=$MODE"
  echo "database=$DB"
  echo "tables=$LIVE"
  echo "migrations_applied=$MIG"
  echo "db_bytes=$(wc -c < "$PART/db.sql.gz" | tr -d ' ')"
} > "$PART/meta.txt"
echo "complete" > "$PART/COMPLETE"
mv "$PART" "$FINAL"
echo "backup complete: $NAME (tables: $LIVE, migrations: $MIG)"

# 4. only now prune: oldest COMPLETE automatic backups beyond $KEEP. Pinned backups are never touched.
AUTOS=()
while IFS= read -r line; do AUTOS+=("$line"); done < <(ls -1 "$BK/db" | grep -E '^auto-[0-9]{8}-[0-9]{6}$' | sort)
if [ "${#AUTOS[@]}" -gt "$KEEP" ]; then
  DELETE=$(( ${#AUTOS[@]} - KEEP ))
  for old in "${AUTOS[@]:0:$DELETE}"; do
    [ -f "$BK/db/$old/COMPLETE" ] || continue
    case "$old" in auto-????????-??????) rm -rf -- "${BK:?}/db/$old"; echo "pruned: $old";; esac
  done
fi
