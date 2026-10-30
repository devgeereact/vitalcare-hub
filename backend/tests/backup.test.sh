#!/usr/bin/env bash
# Tests for backend/bin/backup.sh against the throwaway local MariaDB (scripts/test-db.sh up).
# Everything lives under .local-test/, with a database named vitalcare_testbackup*.
set -uo pipefail
cd "$(dirname "$0")/../.."
ROOT_DIR="$PWD"
SCRIPT="$ROOT_DIR/backend/bin/backup.sh"
PORT="${TEST_DB_PORT:-33175}"
HOSTU="${TEST_DB_USER:-vc_test}"
HOSTP="${TEST_DB_PASS:-vc_test_local_only}"
DB="vitalcare_testbackup"
SCRATCH="vitalcare_testbackup_restore"
if [ -n "${TEST_DB_EXTERNAL:-}" ]; then HOSTU="${TEST_DB_USER:-root}"; fi
CLIENT="$(command -v mariadb || command -v mysql)"
SQL() { "$CLIENT" --no-defaults -h127.0.0.1 -P"$PORT" -u"$HOSTU" -p"$HOSTP" "$@" 2>/dev/null; }

T="$ROOT_DIR/.local-test/tmp/backup-test-$$"
mkdir -p "$T"; trap 'rm -rf "$T"' EXIT
PRIV="$T/private"; BK="$T/backups"
mkdir -p "$PRIV/config" "$PRIV/storage" "$BK"
echo "uploaded file one" > "$PRIV/storage/a.txt"
write_cnf() { printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\nport=%s\n' "$HOSTU" "$1" "$PORT" > "$PRIV/config/backup.cnf"; chmod 600 "$PRIV/config/backup.cnf"; }
write_cnf "$HOSTP"

PASS=0; FAILN=0
ok()   { PASS=$((PASS+1)); }
bad()  { FAILN=$((FAILN+1)); echo "FAIL: $1"; }
check() { if [ "$2" = "0" ]; then ok; else bad "$1"; fi; }

export VITALCARE_PRIVATE_ROOT="$PRIV" VITALCARE_BACKUP_ROOT="$BK" VITALCARE_DB="$DB"

# database with the real schema and a few rows
SQL -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4; DROP DATABASE IF EXISTS $SCRATCH; CREATE DATABASE $SCRATCH CHARACTER SET utf8mb4" || { echo "test database not reachable: run scripts/test-db.sh up"; exit 2; }
ROOT_DIR="$ROOT_DIR" PORT="$PORT" DB="$DB" HOSTU="$HOSTU" HOSTP="$HOSTP" php -r '
require getenv("ROOT_DIR")."/backend/src/Autoload.php";
$pdo = Vitalcare\Support\Db::connect(["dsn"=>"mysql:host=127.0.0.1;port=".getenv("PORT").";dbname=".getenv("DB").";charset=utf8mb4","user"=>getenv("HOSTU"),"password"=>getenv("HOSTP")]);
(new Vitalcare\Support\Migrator($pdo, getenv("ROOT_DIR")."/backend/migrations"))->run();
$pdo->prepare("INSERT INTO users (id,email,display_name,role) VALUES (?,?,?,?)")->execute([Vitalcare\Support\Db::uuid(),"backup.test@example.test","Backup Test","learner"]);
$pdo->prepare("INSERT INTO enquiries (id,name,email,message) VALUES (?,?,?,?)")->execute([Vitalcare\Support\Db::uuid(),"A","a@example.test","hello hello hello"]);
' || { echo "could not prepare the test database"; exit 2; }
TABLES="$(SQL -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_TYPE='BASE TABLE'")"

autos() { ls -1 "$BK/db" 2>/dev/null | grep -cE '^auto-[0-9]{8}-[0-9]{6}$'; }

# 1. a first backup succeeds, is complete, and its checksums verify
OUT="$(bash "$SCRIPT" 2>&1)"; rc=$?
check "first backup exits 0" $rc
FIRST="$(ls -1 "$BK/db" | grep -E '^auto-' | head -1)"
[ -f "$BK/db/$FIRST/COMPLETE" ]; check "backup is marked COMPLETE" $?
( cd "$BK/db/$FIRST" && { sha256sum -c MANIFEST.sha256 >/dev/null 2>&1 || shasum -a 256 -c MANIFEST.sha256 >/dev/null 2>&1; } ); check "manifest checksums verify" $?
grep -q "^tables=$TABLES$" "$BK/db/$FIRST/meta.txt"; check "meta records the table count ($TABLES)" $?
grep -q "^migrations_applied=" "$BK/db/$FIRST/meta.txt"; check "meta records migrations applied" $?
[ -z "$(ls -A "$BK/db" | grep -E '^\.(auto|pinned).*\.part$')" ]; check "no unfinished .part folder is left behind" $?
[ ! -e "$BK/db/.backup.lock" ]; check "the lock is released" $?

# 2. it can actually be restored into an isolated database, and the data matches
gzip -dc "$BK/db/$FIRST/db.sql.gz" | "$CLIENT" --no-defaults -h127.0.0.1 -P"$PORT" -u"$HOSTU" -p"$HOSTP" "$SCRATCH" 2>/dev/null; check "dump restores into a scratch database" $?
RT="$(SQL -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SCRATCH' AND TABLE_TYPE='BASE TABLE'")"
[ "$RT" = "$TABLES" ]; check "restored table count matches ($RT of $TABLES)" $?
[ "$(SQL -N -B -e "SELECT email FROM $SCRATCH.users")" = "backup.test@example.test" ]; check "restored rows match" $?
[ "$(SQL -N -B -e "SELECT COUNT(*) FROM $SCRATCH.schema_migrations")" = "$(SQL -N -B -e "SELECT COUNT(*) FROM $DB.schema_migrations")" ]; check "restored migration history matches" $?
tar -xzf "$BK/db/$FIRST/storage.tar.gz" -C "$T" && [ "$(cat "$T/storage/a.txt")" = "uploaded file one" ]; check "storage archive restores" $?

# 3. permissions: nothing readable by others
perm() { stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"; }
[ "$(perm "$BK/db/$FIRST/db.sql.gz")" = "600" ] && [ "$(perm "$BK/db/$FIRST")" = "700" ]; check "backup folder is 0700 and files are 0600" $?

# 4. the password never appears in any output
echo "$OUT" | grep -q "$HOSTP"; [ $? -ne 0 ]; check "success output never contains the password" $?

# 4b. another site's credentials in the account's ~/.my.cnf must never be used (shared hosting hazard)
FAKEHOME="$T/fakehome"; mkdir -p "$FAKEHOME"; printf '[client]\nuser=another_site_user\npassword=another-site-secret\n' > "$FAKEHOME/.my.cnf"
sleep 1; HOME="$FAKEHOME" bash "$SCRIPT" >/dev/null 2>&1; check "a conflicting ~/.my.cnf does not override our credentials" $?
OTHER="$(ls -1 "$BK/db" | grep -E '^auto-' | tail -1)"
[ -f "$BK/db/$OTHER/COMPLETE" ]; check "...and the backup made under it is complete" $?

# 5. rotation keeps 3 automatic backups, prunes the oldest, and never touches a pinned one
for i in 1 2 3 4; do sleep 1; bash "$SCRIPT" >/dev/null 2>&1; done
[ "$(autos)" = "3" ]; check "five runs leave exactly 3 automatic backups" $?
[ ! -d "$BK/db/$FIRST" ]; check "the oldest was pruned" $?
sleep 1; PINOUT="$(bash "$SCRIPT" --pin 2>&1)"; check "a pinned backup is created" $?
PIN="$(ls -1 "$BK/db" | grep -E '^pinned-pre-migration-' | head -1)"
[ -f "$BK/db/$PIN/COMPLETE" ]; check "the pinned backup is complete" $?
for i in 1 2 3; do sleep 1; bash "$SCRIPT" >/dev/null 2>&1; done
[ -d "$BK/db/$PIN" ] && [ "$(autos)" = "3" ]; check "rotation never touches the pinned backup" $?

# 6. failure paths exit non-zero, leave no partial folder, and prune NOTHING
# ls -A so hidden unfinished (.part) folders and the lock are included in the comparison
BEFORE="$(ls -A "$BK/db" | sort | tr '\n' ' ')"
same() { [ "$(ls -A "$BK/db" | sort | tr '\n' ' ')" = "$BEFORE" ]; }
write_cnf "wrong-password-xyz"; sleep 1
FOUT="$(bash "$SCRIPT" 2>&1)"; rc=$?
[ $rc -ne 0 ]; check "wrong database password fails" $?
echo "$FOUT" | grep -q "BACKUP FAILED"; check "...and says why instead of failing silently" $?
same; check "...and prunes nothing and leaves nothing behind" $?
echo "$FOUT" | grep -q "wrong-password-xyz"; [ $? -ne 0 ]; check "...and the failure output does not contain the password" $?
write_cnf "$HOSTP"

chmod 644 "$PRIV/config/backup.cnf"
bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "a credentials file that is not 0600 is refused" $?
chmod 600 "$PRIV/config/backup.cnf"; same; check "...and nothing changed" $?

mkdir "$BK/db/.backup.lock"
bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "a held lock stops a second run" $?
rmdir "$BK/db/.backup.lock"; same; check "...and nothing changed" $?

VITALCARE_DB='x;drop database y' bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "an unsafe database name is refused" $?
VITALCARE_KEEP=0 bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "keeping zero backups is refused" $?
VITALCARE_KEEP=abc bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "a non-numeric keep count is refused" $?
same; check "...and nothing changed" $?

mv "$PRIV/storage" "$PRIV/storage.gone"
bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "missing storage folder fails" $?
mv "$PRIV/storage.gone" "$PRIV/storage"; same; check "...and prunes nothing" $?

# 7. a truncated dump is detected and rejected. The fake tool emits a COMPLETE-looking dump (so the table
#    count still matches) minus only the final completion marker, isolating that one check.
SHIM="$T/shim"; mkdir -p "$SHIM"
REAL="$(command -v mariadb-dump || command -v mysqldump)"
printf '#!/usr/bin/env bash\n"%s" "$@" | grep -v "Dump completed"\n' "$REAL" > "$SHIM/mariadb-dump"; chmod +x "$SHIM/mariadb-dump"
sleep 1; PATH="$SHIM:$PATH" bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "a dump without its completion marker is rejected" $?
same; check "...and nothing was pruned or left behind" $?

# 8. a dump that is missing a table is detected
printf '#!/usr/bin/env bash\n"%s" "$@" | grep -v "^CREATE TABLE .users." \n' "$(command -v mariadb-dump || command -v mysqldump)" > "$SHIM/mariadb-dump"; chmod +x "$SHIM/mariadb-dump"
sleep 1; PATH="$SHIM:$PATH" bash "$SCRIPT" >/dev/null 2>&1; [ $? -ne 0 ]; check "a dump missing a table fails the table-count check" $?
same; check "...and nothing was pruned or left behind" $?

SQL -e "DROP DATABASE IF EXISTS $DB; DROP DATABASE IF EXISTS $SCRATCH"
echo "backup tests: $PASS passed, $FAILN failed"
[ "$FAILN" = "0" ]
