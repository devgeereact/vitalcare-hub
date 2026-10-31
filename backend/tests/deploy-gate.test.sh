#!/usr/bin/env bash
# End-to-end tests for the deploy pipeline, entirely local: build-release.sh -> deploy-sftp.sh (local transport)
# -> deploy-gate.sh, against the throwaway MariaDB (scripts/test-db.sh up) and PHP's built-in web server
# serving the real front controller. Nothing here touches a real server.
set -uo pipefail
cd "$(dirname "$0")/../.."
ROOT_DIR="$PWD"
GATE="${GATE_UNDER_TEST:-$ROOT_DIR/backend/bin/deploy-gate.sh}"
PORT="${TEST_DB_PORT:-33175}"
HOSTU="${TEST_DB_USER:-vc_test}"
HOSTP="${TEST_DB_PASS:-vc_test_local_only}"
if [ -n "${TEST_DB_EXTERNAL:-}" ]; then HOSTU="${TEST_DB_USER:-root}"; fi
DB="vitalcare_testgate"
CLIENT="$(command -v mariadb || command -v mysql)"
SQL() { "$CLIENT" --no-defaults -h127.0.0.1 -P"$PORT" -u"$HOSTU" -p"$HOSTP" "$@" 2>/dev/null; }
HP=33176

T="$ROOT_DIR/.local-test/tmp/gate-test-$$"
rm -rf "$T"; mkdir -p "$T"
SRV=""
cleanup() { [ -n "$SRV" ] && kill "$SRV" 2>/dev/null; rm -rf "$T"; }
trap cleanup EXIT

H="$T/home"; PRIV="$H/vitalcare-private"; DOC="$H/vitalcare.uk"; BK="$H/vitalcare-backups"; APP="$PRIV/app"
PASS=0; FAILN=0
ok()  { PASS=$((PASS+1)); }
bad() { FAILN=$((FAILN+1)); echo "FAIL: $1"; }
check() { if [ "$2" = "0" ]; then ok; else bad "$1"; fi; }

SQL -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4" || { echo "test database not reachable: run scripts/test-db.sh up"; exit 2; }

mkdir -p "$PRIV/config" "$PRIV/storage" "$DOC" "$BK"
cat > "$PRIV/config/prod.php" <<PHP
<?php
return [
  'db' => ['dsn' => 'mysql:host=127.0.0.1;port=$PORT;dbname=$DB;charset=utf8mb4', 'user' => '$HOSTU', 'password' => '$HOSTP'],
  'migrate' => ['dsn' => 'mysql:host=127.0.0.1;port=$PORT;dbname=$DB;charset=utf8mb4', 'user' => '$HOSTU', 'password' => '$HOSTP'],
  'office_email' => 'info@example.test',
  'turnstile' => ['secret' => '', 'dev_bypass' => false],
  'clerk' => ['issuer' => 'https://clerk.example.test', 'authorized_parties' => ['https://example.test']],
];
PHP
chmod 600 "$PRIV/config/prod.php"
printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\nport=%s\n' "$HOSTU" "$HOSTP" "$PORT" > "$PRIV/config/backup.cnf"; chmod 600 "$PRIV/config/backup.cnf"
cat > "$DOC/.htaccess" <<'HTA'
# === BEGIN Cloudflare-origin-lock (test) ===
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP:CF-RAY} ^$
  RewriteRule ^ - [F,L]
</IfModule>
# === END Cloudflare-origin-lock ===
# The app
<IfModule mod_rewrite.c>
  RewriteRule ^ index.html [L]
</IfModule>
HTA
cp "$DOC/.htaccess" "$T/htaccess.original"
echo "someone else's file" > "$DOC/keep-me.txt"

export VITALCARE_HOME="$H" VITALCARE_DOCROOT="$DOC" VITALCARE_PHP="$(command -v php)" VITALCARE_PYTHON="$(command -v python3)"
export VITALCARE_HEALTH_URL="http://127.0.0.1:$HP/api/health" VITALCARE_SITE_URL="http://127.0.0.1:$HP" VITALCARE_HEALTH_WAIT=0 VITALCARE_SETTLE=0
export VITALCARE_BACKUP_ROOT="$BK" VITALCARE_SFTP_SERVER="$T/fake-sftp-server"
printf '#!/bin/sh\necho "SFTP-ARGS: $*"\n' > "$T/fake-sftp-server"; chmod +x "$T/fake-sftp-server"

gate() { SSH_ORIGINAL_COMMAND="$1" bash "$GATE"; }

# --- a local copy of the backend with a chosen number of migrations, so a later release can add one
mkbackend() { # $1 dir  $2 how many migrations (1..5)
  mkdir -p "$1/migrations" "$1/public"
  cp -R backend/src backend/bin backend/assets "$1/"; cp backend/public/index.php "$1/public/"
  local n=0 f
  for f in $(ls backend/migrations/*.sql | sort); do n=$((n+1)); [ "$n" -le "$2" ] && cp "$f" "$1/migrations/"; done
}
mkdist() { mkdir -p "$1/assets"; echo "<html>$2</html>" > "$1/index.html"; echo "console.log('$2')" > "$1/assets/app-$2.js"; echo "sw $2" > "$1/sw.js"; echo "{}" > "$1/manifest.webmanifest"; }
build() { # $1 id  $2 backend dir  $3 dist dir (optional)
  if [ -n "${3:-}" ]; then BACKEND_DIR="$2" DIST="$3" RELEASE_OUT="$T/rel" bash scripts/build-release.sh "$1" >/dev/null
  else BACKEND_DIR="$2" RELEASE_OUT="$T/rel" bash scripts/build-release.sh "$1" --no-site >/dev/null; fi
}
dep() { DEPLOY_TRANSPORT=local DEPLOY_LOCAL_GATE="$GATE" RELEASE_OUT="$T/rel" bash scripts/deploy-sftp.sh "$@"; }
hrelease() { curl -fsS --max-time 10 "$VITALCARE_HEALTH_URL" 2>/dev/null | sed -n 's/.*"release":"\([^"]*\)".*/\1/p'; }
pinned() { ls -1 "$BK/db" 2>/dev/null | grep -c '^pinned-pre-migration-'; }
# The first 12 characters are what health reports, so the ids must differ there.
id() { printf '%s%039d' "$1" 0; }
ID1=$(id 1); ID2=$(id 2); ID3=$(id 3); ID4=$(id 4); ID5=$(id 5)

# =========================================================== 1. what the gate refuses outright
refuses() { local out rc; out="$(SSH_ORIGINAL_COMMAND="$1" bash "$GATE" 2>&1)"; rc=$?; [ $rc -eq 2 ]; }
refuses "";                                  check "no command is refused" $?
refuses "ls";                                check "an arbitrary command is refused" $?
refuses "verify abc";                        check "a short id is refused" $?
refuses "verify $(printf 'A%.0s' $(seq 40))"; check "an upper-case id is refused" $?
refuses "verify $ID1; touch $T/pwned";       check "a command chained after an id is refused" $?
refuses "verify $ID1 extra";                 check "extra arguments are refused" $?
refuses "rollback-api ../../etc";            check "a path in a rollback stamp is refused" $?
refuses "rollback-api 2026-01-01";           check "a malformed rollback stamp is refused" $?
refuses "sftp-server";                       check "a made-up sftp command is refused" $?
refuses "scp -t /tmp";                       check "scp is refused" $?
[ ! -e "$T/pwned" ]; check "the chained command did not run" $?

# =========================================================== 2. the sftp branch is restricted
OUT="$(gate "$VITALCARE_SFTP_SERVER" 2>&1)"
for req in remove rmdir rename posix-rename symlink hardlink setstat fsetstat; do
  echo "$OUT" | grep -q -- "-P [a-z,-]*\b$req\b"; check "sftp is started with $req refused" $?
done
echo "$OUT" | grep -q -- "-u 0027"; check "sftp uploads use umask 0027" $?

# =========================================================== 3. a fresh account is refused (no installed backup script)
mkbackend "$T/be1" 4; mkdist "$T/dist1" one
build "$ID1" "$T/be1" "$T/dist1"
OUT="$(dep "$ID1" --api 2>&1)"; rc=$?
[ $rc -ne 0 ] && echo "$OUT" | grep -q "backup script is missing"; check "migrations on a machine with no installed backup script are refused" $?
[ ! -e "$APP/RELEASE" ]; check "...and nothing was switched" $?
[ -d "$ROOT_DIR" ] ; ok
# Simulate the installed state the live account is in: code in place, migrations 001-004 applied.
mkdir -p "$APP"; cp -R "$T/be1/src" "$T/be1/bin" "$T/be1/migrations" "$T/be1/public" "$APP/"
VITALCARE_PRIVATE_ROOT="$PRIV" php "$APP/bin/migrate.php" >/dev/null
rm -rf "$PRIV/rollback" "$PRIV/releases" "$PRIV/incoming"

# the web server for health and site checks
cat > "$T/router.php" <<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($p, '/api/')) { $_SERVER['SCRIPT_NAME'] = '/api/index.php'; require getenv('DOC') . '/api/index.php'; return true; }
return false;
PHP
DOC="$DOC" php -d opcache.enable=0 -S "127.0.0.1:$HP" -t "$DOC" "$T/router.php" >/dev/null 2>&1 &
SRV=$!
for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$HP/" && break; sleep 0.2; done

# =========================================================== 4. a bad upload never gets near the live code
before="$(find "$APP" -type f | sort | xargs cat | $(command -v sha256sum || echo "shasum -a 256") | cut -c1-16)"
verify_fails() { local label="$1"; shift; local out rc; out="$(gate "verify $ID2" 2>&1)"; rc=$?; [ $rc -ne 0 ] && [ ! -e "$PRIV/releases/$ID2/.verified" ]; check "$label" $?; }
inc="$PRIV/incoming/$ID2"
mkbackend "$T/be2" 5; mkdist "$T/dist2" two
build "$ID2" "$T/be2" "$T/dist2"
reset_inc() { rm -rf "$inc"; mkdir -p "$inc"; cp "$T/rel/$ID2/"* "$inc/"; }
mktar() { python3 -I - "$@" <<'PY'
import sys, tarfile, io
out, kind = sys.argv[1], sys.argv[2]
with tarfile.open(out, "w") as t:
    def add(name, data=b"x"):
        i = tarfile.TarInfo(name); i.size = len(data); t.addfile(i, io.BytesIO(data))
    add("RELEASE", sys.argv[3].encode() + b"\n")
    for f in ["src/Autoload.php", "src/Bootstrap.php", "bin/migrate.php", "bin/backup.sh", "public/index.php"]:
        add(f, b"<?php\n")
    if kind == "dotdot": add("../evil.php", b"<?php\n")
    if kind == "abs": add("/tmp/evil.php", b"<?php\n")
    if kind == "symlink":
        i = tarfile.TarInfo("src/link"); i.type = tarfile.SYMTYPE; i.linkname = "/etc/passwd"; t.addfile(i)
    if kind == "top": add("evil/x.php", b"<?php\n")
    if kind == "syntax": add("src/Broken.php", b"<?php\nthis is not php {{{\n")
    if kind == "oddname": add("src/we ird;name.php", b"<?php\n")
    if kind == "nofonts": add("src/Certificates/CertificatePdf.php", b"<?php\n")
    if kind == "assetphp": add("assets/fonts/evil.php", b"<?php\n")
PY
}
resum() { ( cd "$inc" && { sha256sum api.tar $([ -f site.tar ] && echo site.tar) 2>/dev/null || shasum -a 256 api.tar $([ -f site.tar ] && echo site.tar); } > SHA256SUMS ); }

rm -rf "$inc"; verify_fails "nothing uploaded is refused"
reset_inc; rm "$inc/SHA256SUMS"; verify_fails "a missing SHA256SUMS is refused"
reset_inc; echo "tamper" >> "$inc/api.tar"; verify_fails "a damaged archive (checksum mismatch) is refused"
reset_inc; echo hi > "$inc/extra.txt"; verify_fails "an unexpected extra file is refused"
reset_inc; echo "$(printf 'a%.0s' $(seq 64))  evil.tar" >> "$inc/SHA256SUMS"; verify_fails "an unexpected SHA256SUMS line is refused"
for kind in dotdot abs symlink top syntax oddname nofonts assetphp; do
  reset_inc; mktar "$inc/api.tar" "$kind" "$ID2"; resum; verify_fails "api.tar with a '$kind' entry is refused"
done
reset_inc; mktar "$inc/api.tar" none "$ID3"; resum; verify_fails "an archive built for a different commit is refused"
# site problems
mksite() { python3 -I - "$@" <<'PY'
import sys, tarfile, io
out, kind, rid = sys.argv[1:4]
with tarfile.open(out, "w") as t:
    def add(name, data=b"x"):
        i = tarfile.TarInfo(name); i.size = len(data); t.addfile(i, io.BytesIO(data))
    add("index.html", b"<html></html>"); add("build-id.txt", rid.encode() + b"\n")
    if kind == "htaccess": add(".htaccess", b"Deny from all\n")
    if kind == "api": add("api/index.php", b"<?php\n")
    if kind == "map": add("assets/app.js.map", b"{}")
    if kind == "wrongid": add("build-id.txt", b"nope\n") if False else None
PY
}
for kind in htaccess api map; do
  reset_inc; mksite "$inc/site.tar" "$kind" "$ID2"; resum; verify_fails "site.tar with $kind content is refused"
done
after="$(find "$APP" -type f | sort | xargs cat | $(command -v sha256sum || echo "shasum -a 256") | cut -c1-16)"
[ "$before" = "$after" ]; check "the live code was never touched by any bad upload" $?
[ ! -e "$DOC/api/index.php" ]; check "...and no shim was written yet" $?

# activation without verification is refused
reset_inc
OUT="$(gate "activate-api $ID2" 2>&1)"; rc=$?
[ $rc -ne 0 ] && echo "$OUT" | grep -q "not verified"; check "activate-api on an unverified release is refused" $?
OUT="$(gate "activate-site $ID2" 2>&1)"; rc=$?
[ $rc -ne 0 ] && echo "$OUT" | grep -q "not verified"; check "activate-site on an unverified release is refused" $?

# =========================================================== 5. the happy path: release 1 (no new migration), site and api
build "$ID1" "$T/be1" "$T/dist1"
P0=$(pinned)
OUT="$(dep "$ID1" --api --site 2>&1)"; rc=$?
[ $rc -eq 0 ] || echo "$OUT"
check "release 1 deploys (api + site)" $rc
[ "$(cat "$APP/RELEASE")" = "$ID1" ]; check "the API records its release" $?
[ "$(hrelease)" = "${ID1:0:12}" ]; check "the running API reports release ${ID1:0:12}" $?
[ "$(curl -fsS "http://127.0.0.1:$HP/build-id.txt")" = "$ID1" ]; check "the public site shows build 1" $?
[ "$(pinned)" = "$P0" ]; check "no pinned backup when no migration is pending" $?
[ "$(cat "$DOC/keep-me.txt")" = "someone else's file" ]; check "another file in the web root is untouched" $?
cmp -s "$DOC/.htaccess" "$T/htaccess.original"; check ".htaccess is untouched by an API and site deploy" $?
grep -q "Generated by" "$DOC/api/index.php" && grep -q "VITALCARE_PRIVATE_ROOT" "$DOC/api/index.php"; check "the API shim is written" $?
grep -q "AddHandler" "$DOC/api/.htaccess"; check "the API PHP-version pin is written" $?
cmp -s backend/assets/fonts/NotoSans-Regular.ttf "$APP/assets/fonts/NotoSans-Regular.ttf" && [ -f "$APP/assets/fonts/NotoSans-Bold.ttf" ] && [ -f "$APP/assets/fonts/OFL.txt" ]; check "the certificate fonts and their licence are installed beside src/" $?
[ ! -d "$(ls -1d "$PRIV"/rollback/api-* | head -1)/assets" ]; check "the first rollback copy predates assets/ (so rollback must cope without it)" $?
ls "$PRIV/rollback" | grep -q '^api-'; check "an API rollback copy is made" $?
ls "$PRIV/rollback" | grep -q '^site-'; check "a site rollback copy is made" $?
grep -q "verify $ID1 ok" "$PRIV/logs/deploy.log"; check "the deploy log records the verify" $?
! grep -qiE "password|vc_test_local_only" "$PRIV/logs/deploy.log"; check "the deploy log holds no credentials" $?

# deploy again: idempotent
OUT="$(dep "$ID1" --api --site 2>&1)"; check "redeploying the same release is fine" $?

# =========================================================== 6. a release with a migration: pinned backup first
P0=$(pinned)
OUT="$(gate "status" 2>&1)"
echo "$OUT" | grep -q "api-release: $ID1"; check "status shows the live API release" $?
echo "$OUT" | grep -q "gate-sha256: [0-9a-f]\{64\}"; check "status shows the gate's own checksum" $?
mkdist "$T/dist3" three
build "$ID3" "$T/be2" "$T/dist3"
OUT="$(dep "$ID3" --api --site 2>&1)"; rc=$?
[ $rc -eq 0 ] || echo "$OUT"
check "release 3 (adds a migration) deploys" $rc
echo "$OUT" | grep -q "005-outbox-backoff.sql"; check "the pending migration was announced" $?
[ "$(pinned)" = "$((P0+1))" ]; check "exactly one pinned backup was taken before migrating" $?
LATEST="$(ls -1d "$BK/db"/pinned-pre-migration-* | tail -1)"; [ -f "$LATEST/COMPLETE" ]; check "the pinned backup is complete" $?
[ "$(SQL -N -B -e "SELECT COUNT(*) FROM $DB.schema_migrations WHERE name='005-outbox-backoff.sql'")" = "1" ]; check "the migration was applied" $?
[ "$(hrelease)" = "${ID3:0:12}" ]; check "the API now reports release 3" $?
[ "$(curl -fsS "http://127.0.0.1:$HP/build-id.txt")" = "$ID3" ]; check "the site now shows build 3" $?
[ -f "$DOC/assets/app-one.js" ]; check "the previous release's assets are NOT deleted (no mirror delete)" $?
[ "$(cat "$DOC/keep-me.txt")" = "someone else's file" ]; check "another file in the web root is still untouched" $?
RB="$(ls -1d "$PRIV"/rollback/site-* | tail -1)"; grep -q "one" "$RB/files/index.html"; check "the site rollback copy holds the previous index.html" $?

# =========================================================== 7. a release that lints but cannot serve is rolled back automatically
mkbackend "$T/be4" 5
cat > "$T/be4/src/Bootstrap.php" <<'PHP'
<?php
declare(strict_types=1);
namespace Vitalcare;

require_once __DIR__ . '/Autoload.php';

final class Bootstrap
{
    public static function api(string $privateRoot, string $configName = 'prod'): never
    {
        throw new \RuntimeException('boom');
    }
}
PHP
mkdist "$T/dist4" four
build "$ID4" "$T/be4" "$T/dist4"
P0=$(pinned)
OUT="$(dep "$ID4" --api 2>&1)"; rc=$?
[ $rc -ne 0 ]; check "a release that fails its health check makes the deploy fail" $?
echo "$OUT" | grep -q "previous code was restored"; check "...and says it restored the previous code" $?
[ "$(cat "$APP/RELEASE")" = "$ID3" ]; check "the RELEASE marker is back at release 3" $?
[ "$(hrelease)" = "${ID3:0:12}" ]; check "the API is serving release 3 again" $?
grep -q "boom" "$APP/src/Bootstrap.php"; [ $? -ne 0 ]; check "the broken file is no longer in the live code" $?

# =========================================================== 8. a failing migration never switches the code
mkbackend "$T/be5" 5
printf 'CREATE TABLE gate_broken (id INT);\nTHIS IS NOT SQL;\n' > "$T/be5/migrations/006-broken.sql"
build "$ID5" "$T/be5"
P0=$(pinned)
OUT="$(dep "$ID5" --api 2>&1)"; rc=$?
[ $rc -ne 0 ]; check "a failing migration fails the deploy" $?
[ "$(pinned)" = "$((P0+1))" ]; check "...after a pinned backup was taken" $?
[ "$(cat "$APP/RELEASE")" = "$ID3" ]; check "...and the code was NOT switched" $?
echo "$OUT" | grep -q "NOT switched"; check "...and the message says so" $?

# =========================================================== 9. rollback by stamp
STAMP="$(ls -1 "$PRIV/rollback" | grep '^api-' | head -1 | sed 's/^api-//')"
OUT="$(gate "rollback-api $STAMP" 2>&1)"; rc=$?
[ $rc -eq 0 ] || echo "$OUT"
check "rollback-api restores a rollback copy" $rc
[ -f "$APP/assets/fonts/NotoSans-Regular.ttf" ]; check "...a rollback copy without assets/ leaves the installed fonts alone" $?
OUT="$(gate "rollback-api 20000101-000000" 2>&1)"; rc=$?
[ $rc -ne 0 ]; check "rollback-api with an unknown stamp fails" $?
# put release 3 back for the remaining tests
dep "$ID3" --api >/dev/null 2>&1; [ "$(hrelease)" = "${ID3:0:12}" ]; check "release 3 can be re-activated after a rollback" $?

# =========================================================== 10. .htaccess merge keeps the origin lock
build "$ID3" "$T/be2" "$T/dist3"
dep "$ID3" --api --htaccess >/dev/null 2>&1; check "activate-htaccess merges our block" $?
grep -q "BEGIN vitalcare-hub" "$DOC/.htaccess"; check "our block is now in .htaccess" $?
python3 -I - "$T/htaccess.original" "$DOC/.htaccess" <<'PY'
import sys
orig, new = open(sys.argv[1]).read(), open(sys.argv[2]).read()
a = orig.index("# === BEGIN Cloudflare-origin-lock"); b = orig.index("# === END Cloudflare-origin-lock ===\n") + len("# === END Cloudflare-origin-lock ===\n")
lock = orig[a:b]
sys.exit(0 if new.startswith(lock) else 1)
PY
check "the origin lock is byte-identical and still first" $?
cp "$DOC/.htaccess" "$T/htaccess.merged"
OUT="$(dep "$ID3" --htaccess 2>&1)"; echo "$OUT" | grep -q "already up to date"; check "a second merge changes nothing" $?
cmp -s "$DOC/.htaccess" "$T/htaccess.merged"; check "...byte for byte" $?
echo "# no lock here" > "$DOC/.htaccess"; cp "$DOC/.htaccess" "$T/htaccess.nolock"
OUT="$(dep "$ID3" --htaccess 2>&1)"; rc=$?
[ $rc -ne 0 ]; check "a live .htaccess without the origin lock is refused" $?
cmp -s "$DOC/.htaccess" "$T/htaccess.nolock"; check "...and left exactly as it was" $?
cp "$T/htaccess.merged" "$DOC/.htaccess"

# =========================================================== 11. the lock, and pruning
mkdir -p "$PRIV/tmp/deploy.lock"; sleep 30 & SL=$!; echo $SL > "$PRIV/tmp/deploy.lock/pid"
OUT="$(gate "verify $ID3" 2>&1)"; rc=$?
[ $rc -ne 0 ] && echo "$OUT" | grep -q "another deploy is running"; check "a deploy is refused while another holds the lock" $?
kill $SL 2>/dev/null; wait $SL 2>/dev/null
OUT="$(gate "verify $ID3" 2>&1)"; check "a stale lock (dead process) is cleared and the deploy proceeds" $?
[ ! -e "$PRIV/tmp/deploy.lock" ]; check "the lock is released at the end" $?
mkdir -p "$PRIV/releases/notes"; echo keep > "$PRIV/releases/notes/readme"
for i in 0 1 2 3 4 5 6 7; do d="$PRIV/releases/$(printf 'a%.0s' $(seq 39))$i"; mkdir -p "$d"; touch -t 20200101000$i "$d" 2>/dev/null; done
gate "verify $ID3" >/dev/null 2>&1
n="$(ls -1 "$PRIV/releases" | grep -cE '^[0-9a-f]{40}$')"
[ "$n" -le 5 ]; check "old staged releases are pruned to 5 (found $n)" $?
[ -f "$PRIV/releases/notes/readme" ]; check "pruning never touches a folder that is not a release" $?
[ -d "$PRIV/releases/$ID3" ]; check "the newest release survives pruning" $?

echo "deploy pipeline: $PASS passed, $FAILN failed"
[ "$FAILN" -eq 0 ]
