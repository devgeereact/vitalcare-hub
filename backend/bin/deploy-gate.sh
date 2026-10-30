#!/usr/bin/env bash
# The only thing the GitHub deploy key can run on the hosting account.
#
# Installed once, by the owner, at <private root>/gate/deploy-gate.sh and named in authorized_keys as
#   restrict,command="/home/ACCOUNT/vitalcare-private/gate/deploy-gate.sh" ssh-ed25519 ... github-deploy
# It is NOT updated by the pipeline it guards: a change to the gate is a deliberate, reviewed install.
# `status` prints the installed gate's sha256 so a pipeline run can tell that it is out of date.
#
# Two kinds of request arrive in SSH_ORIGINAL_COMMAND, and nothing else is accepted:
#   1. the SFTP subsystem, which is run through sftp-server with deletion, rename, link and attribute
#      requests switched off. The pipeline can upload, and cannot remove or rename anything.
#   2. one of these verbs, each taking a 40-character commit id (or a rollback timestamp):
#        verify <id>           check the uploaded release: checksums, safe archive contents, php -l, markers
#        activate-api <id>     pinned backup + migrations if any are pending, then code, then health check
#        activate-site <id>    upload the built site over the docroot (no deletes, entry files last)
#        activate-htaccess <id> merge our rules into the live .htaccess (fail-closed, origin lock untouched)
#        rollback-api <stamp>  put the API code back from a rollback copy made by activate-api
#        status                what is live, what is pending, and this gate's own checksum
#
# Environment (all optional; tests and the install set them):
#   VITALCARE_HOME        default $HOME
#   VITALCARE_DOCROOT     default $VITALCARE_HOME/vitalcare.uk
#   VITALCARE_PHP         default /opt/alt/php84/usr/bin/php (the web PHP; the account default lacks the DB driver)
#   VITALCARE_PYTHON      default python3
#   VITALCARE_HEALTH_URL  default https://vitalcare.uk/api/health
#   VITALCARE_SITE_URL    default https://vitalcare.uk
#   VITALCARE_SFTP_SERVER default /usr/libexec/openssh/sftp-server
#   VITALCARE_HEALTH_WAIT seconds between health retries (default 3)
#   VITALCARE_SETTLE      seconds to wait after the code is switched, before the first health check (default 4):
#                         the web PHP's opcache may serve the old files for a couple of seconds (ASSUMPTION: its
#                         revalidate interval is no longer than this)
set -euo pipefail
umask 027

HOME_DIR="${VITALCARE_HOME:-$HOME}"
PRIV="$HOME_DIR/vitalcare-private"
DOCROOT="${VITALCARE_DOCROOT:-$HOME_DIR/vitalcare.uk}"
PHP="${VITALCARE_PHP:-/opt/alt/php84/usr/bin/php}"
PYTHON="${VITALCARE_PYTHON:-python3}"
HEALTH_URL="${VITALCARE_HEALTH_URL:-https://vitalcare.uk/api/health}"
SITE_URL="${VITALCARE_SITE_URL:-https://vitalcare.uk}"
SFTP_SERVER="${VITALCARE_SFTP_SERVER:-/usr/libexec/openssh/sftp-server}"
HEALTH_WAIT="${VITALCARE_HEALTH_WAIT:-3}"
SETTLE="${VITALCARE_SETTLE:-4}"
APP="$PRIV/app"
INCOMING="$PRIV/incoming"
RELEASES="$PRIV/releases"
ROLLBACK="$PRIV/rollback"
LOGFILE="$PRIV/logs/deploy.log"
LOCKDIR="$PRIV/tmp/deploy.lock"
KEEP_RELEASES=5
KEEP_ROLLBACKS=3

CMD="${SSH_ORIGINAL_COMMAND:-}"

if command -v sha256sum >/dev/null 2>&1; then SHA="sha256sum"; else SHA="shasum -a 256"; fi

stamp() { date -u +%Y-%m-%dT%H:%M:%SZ; }
log() {
  mkdir -p "$PRIV/logs" 2>/dev/null || true
  printf '%s %s\n' "$(stamp)" "$*" >> "$LOGFILE" 2>/dev/null || true
}
say() { printf '%s\n' "$*"; }
die() { printf 'FAIL: %s\n' "$*" >&2; log "FAIL ${VERB:-?} ${ID:-}: $*"; exit 1; }

# ---------------------------------------------------------------- 1. the SFTP subsystem
if [ "$CMD" = "$SFTP_SERVER" ] || [ "$CMD" = "internal-sftp" ]; then
  log "sftp session opened"
  # -P lists the requests that are refused outright. -u sets the umask of uploaded files.
  exec "$SFTP_SERVER" -P remove,rmdir,rename,posix-rename,symlink,hardlink,setstat,fsetstat -u 0027
fi

# ---------------------------------------------------------------- 2. the verbs
VERB=""; ID=""
if [[ "$CMD" =~ ^(verify|activate-api|activate-site|activate-htaccess)\ ([0-9a-f]{40})$ ]]; then
  VERB="${BASH_REMATCH[1]}"; ID="${BASH_REMATCH[2]}"
elif [[ "$CMD" =~ ^rollback-api\ ([0-9]{8}-[0-9]{6})$ ]]; then
  VERB="rollback-api"; ID="${BASH_REMATCH[1]}"
elif [ "$CMD" = "status" ]; then
  VERB="status"
else
  log "REFUSED a command that is not on the list"
  printf 'Refused: this key can only upload files and run the deploy verbs.\n' >&2
  exit 2
fi

mkdir -p "$PRIV/tmp" "$PRIV/logs" "$INCOMING" "$RELEASES" "$ROLLBACK"

release_lock() { rm -f "$LOCKDIR/pid" 2>/dev/null; rmdir "$LOCKDIR" 2>/dev/null || true; }
take_lock() {
  if ! mkdir "$LOCKDIR" 2>/dev/null; then
    local other; other="$(cat "$LOCKDIR/pid" 2>/dev/null || true)"
    if [ -n "$other" ] && kill -0 "$other" 2>/dev/null; then die "another deploy is running (pid $other)"; fi
    rm -f "$LOCKDIR/pid"; rmdir "$LOCKDIR" 2>/dev/null || die "could not clear a stale lock"
    mkdir "$LOCKDIR" 2>/dev/null || die "could not take the deploy lock"
  fi
  echo $$ > "$LOCKDIR/pid"
  trap release_lock EXIT
}

# Only ever remove a folder this gate created, and only inside its own working folders.
remove_ours() {
  local d="$1"
  case "$d" in
    "$RELEASES"/[0-9a-f]*|"$INCOMING"/[0-9a-f]*|"$ROLLBACK"/api-[0-9]*|"$ROLLBACK"/site-[0-9]*|"$ROLLBACK"/htaccess-[0-9]*|"$RELEASES"/.tmp-*) ;;
    *) die "refusing to remove $d" ;;
  esac
  [ -d "$d" ] && rm -rf -- "$d"
  return 0
}

prune() {
  # prune DIR KEEP REGEX: keep the newest KEEP entries whose names match REGEX, remove the rest.
  local dir="$1" keep="$2" re="$3" name n=0
  while IFS= read -r name; do
    n=$((n + 1))
    [ "$n" -le "$keep" ] || remove_ours "$dir/$name"
  done < <(ls -1t "$dir" 2>/dev/null | grep -E "$re" || true)
}

safe_tar() {
  # $1 = archive. Refuse absolute paths, "..", anything but plain files and folders, and odd characters.
  local t="$1" names types
  names="$(tar -tf "$t")" || return 1
  [ -n "$names" ] || return 1
  if printf '%s\n' "$names" | grep -qE '(^/|(^|/)\.\.(/|$))'; then return 1; fi
  if printf '%s\n' "$names" | grep -qvE '^[A-Za-z0-9._/@+-]+$'; then return 1; fi
  types="$(tar -tvf "$t" | cut -c1)" || return 1
  if printf '%s\n' "$types" | grep -qvE '^[-d]$'; then return 1; fi
  return 0
}

health_json() { curl -fsS --max-time 20 "$HEALTH_URL" 2>/dev/null || true; }
# Wait for the health endpoint to report ok AND the release we expect. Opcache can serve the old code for a
# few seconds, so retry a handful of times before declaring failure.
wait_for_release() {
  local want="$1" i body
  for i in 1 2 3 4 5 6; do
    body="$(health_json)"
    if [[ "$body" == *'"status":"ok"'* && "$body" == *'"authConfigured":true'* && "$body" == *"\"release\":\"${want:0:12}\""* ]]; then
      return 0
    fi
    sleep "$HEALTH_WAIT"
  done
  return 1
}

app_sync() {
  # $1 = source folder holding src bin migrations public; copy by checksum, never deleting.
  local from="$1"
  local d
  for d in src bin migrations; do
    mkdir -p "$APP/$d"
    rsync -rlt --checksum "$from/$d/" "$APP/$d/"
  done
  mkdir -p "$APP/public"
  rsync -rlt --checksum --include='index.php' --exclude='*' "$from/public/" "$APP/public/"
  chmod +x "$APP/bin/"*.sh 2>/dev/null || true
}

ensure_shim() {
  # The two small files in the web root for the API: written only if absent or written by us.
  mkdir -p "$DOCROOT/api"
  local shim="$DOCROOT/api/index.php" mark="// Generated by the deploy gate. Contains no secrets; the code lives outside the web root."
  if [ -f "$shim" ] && ! grep -qF "// Generated by" "$shim"; then die "$shim exists and was not generated by us; refusing to replace it"; fi
  printf '<?php\ndeclare(strict_types=1);\n%s\nputenv('"'"'VITALCARE_PRIVATE_ROOT=%s'"'"');\nrequire '"'"'%s/public/index.php'"'"';\n' "$mark" "$PRIV" "$APP" > "$shim.tmp"
  mv "$shim.tmp" "$shim"
  local hta="$DOCROOT/api/.htaccess"
  if [ -f "$hta" ] && ! grep -qF "# Generated by" "$hta"; then die "$hta exists and was not generated by us; refusing to replace it"; fi
  local handler="application/x-httpd-alt-php84___lsphp"
  printf '# Generated by the deploy gate. Pins the PHP version for this folder only.\n<IfModule mod_mime.c>\n  AddHandler %s .php\n</IfModule>\n' "$handler" > "$hta.tmp"
  mv "$hta.tmp" "$hta"
  chmod 644 "$shim" "$hta" 2>/dev/null || true
}

# ---------------------------------------------------------------- verbs
do_status() {
  say "gate-sha256: $($SHA "$0" | cut -d' ' -f1)"
  say "api-release: $(cat "$APP/RELEASE" 2>/dev/null || echo none)"
  say "site-release: $(cat "$DOCROOT/build-id.txt" 2>/dev/null | head -c 40 || echo none)"
  local pend
  if pend="$(VITALCARE_PRIVATE_ROOT="$PRIV" "$PHP" "$APP/bin/migrate.php" --pending 2>/dev/null)"; then
    say "pending-migrations: $(printf '%s' "$pend" | tr '\n' ' ')"
  else
    say "pending-migrations: unknown"
  fi
  say "rollbacks: $(ls -1 "$ROLLBACK" 2>/dev/null | tr '\n' ' ')"
  say "staged: $(ls -1 "$RELEASES" 2>/dev/null | grep -E '^[0-9a-f]{40}$' | tr '\n' ' ')"
}

do_verify() {
  take_lock
  local inc="$INCOMING/$ID" rel="$RELEASES/$ID" tmp="$RELEASES/.tmp-$ID-$$"
  [ -d "$inc" ] || die "nothing was uploaded for $ID"
  [ -f "$inc/SHA256SUMS" ] || die "SHA256SUMS is missing"
  local f
  for f in $(ls -A "$inc"); do
    case "$f" in SHA256SUMS|api.tar|site.tar) ;; *) die "unexpected file in the upload: $f" ;; esac
  done
  if grep -qvE '^[0-9a-f]{64}  (api|site)\.tar$' "$inc/SHA256SUMS"; then die "SHA256SUMS has an unexpected line"; fi
  grep -q '  api.tar$' "$inc/SHA256SUMS" || die "SHA256SUMS does not list api.tar"
  # Every file present must be listed, and every listed file must match.
  for f in api.tar site.tar; do
    if [ -f "$inc/$f" ] && ! grep -q "  $f\$" "$inc/SHA256SUMS"; then die "$f is present but not in SHA256SUMS"; fi
  done
  ( cd "$inc" && $SHA -c SHA256SUMS >/dev/null 2>&1 ) || die "checksum mismatch: the upload is incomplete or damaged"
  say "checksums: ok"

  remove_ours "$tmp"; mkdir -p "$tmp/api"
  safe_tar "$inc/api.tar" || die "api.tar contains an unsafe or unexpected entry"
  tar -xf "$inc/api.tar" -C "$tmp/api" --no-same-owner --no-same-permissions
  local top
  for top in $(ls -A "$tmp/api"); do
    case "$top" in src|bin|migrations|public|tools|RELEASE) ;; *) die "api.tar has an unexpected top-level entry: $top" ;; esac
  done
  for f in src/Autoload.php src/Bootstrap.php bin/migrate.php bin/backup.sh public/index.php; do
    [ -f "$tmp/api/$f" ] || die "api.tar is missing $f"
  done
  [ "$(cat "$tmp/api/RELEASE" 2>/dev/null)" = "$ID" ] || die "api.tar was built for a different commit"
  local bad=0 p
  while IFS= read -r -d '' p; do "$PHP" -l "$p" >/dev/null 2>&1 || { bad=1; say "syntax error: ${p#"$tmp/api/"}" >&2; }; done < <(find "$tmp/api" -name '*.php' -print0)
  [ "$bad" = 0 ] || die "PHP syntax check failed"
  say "api: ok ($(find "$tmp/api" -name '*.php' | wc -l | tr -d ' ') PHP files lint clean)"

  if [ -f "$inc/site.tar" ]; then
    mkdir -p "$tmp/site"
    safe_tar "$inc/site.tar" || die "site.tar contains an unsafe or unexpected entry"
    tar -xf "$inc/site.tar" -C "$tmp/site" --no-same-owner --no-same-permissions
    [ -f "$tmp/site/index.html" ] || die "site.tar has no index.html"
    [ "$(cat "$tmp/site/build-id.txt" 2>/dev/null)" = "$ID" ] || die "site.tar was built for a different commit"
    [ ! -e "$tmp/site/api" ] || die "site.tar must not contain an api folder"
    [ -z "$(find "$tmp/site" -name '.htaccess' -print -quit)" ] || die "site.tar must not contain a .htaccess"
    if find "$tmp/site" \( -name '.env*' -o -name '*.map' -o -name '*.sql' -o -name '*.sql.gz' -o -name '*.pem' -o -name '*.key' \) | grep -q .; then
      die "site.tar holds env files, source maps, dumps or keys"
    fi
    say "site: ok ($(find "$tmp/site" -type f | wc -l | tr -d ' ') files)"
  else
    say "site: not part of this release"
  fi

  remove_ours "$rel"
  mv "$tmp" "$rel"
  date -u +%Y-%m-%dT%H:%M:%SZ > "$rel/.verified"
  prune "$RELEASES" "$KEEP_RELEASES" '^[0-9a-f]{40}$'; prune "$INCOMING" "$KEEP_RELEASES" '^[0-9a-f]{40}$'
  local pend=""
  if [ -f "$APP/bin/migrate.php" ]; then
    pend="$(VITALCARE_PRIVATE_ROOT="$PRIV" "$PHP" "$rel/api/bin/migrate.php" --pending 2>/dev/null | tr '\n' ' ' || true)"
  fi
  say "pending migrations: ${pend:-none}"
  log "verify $ID ok"
  say "VERIFIED $ID"
}

need_verified() {
  [ -f "$RELEASES/$ID/.verified" ] || die "release $ID is not verified: run verify first"
}

do_activate_api() {
  take_lock; need_verified
  local rel="$RELEASES/$ID/api" ts rb pend
  [ -d "$rel" ] || die "no api in release $ID"
  [ -f "$PRIV/config/prod.php" ] || die "private config is missing"
  [ -d "$APP" ] || mkdir -p "$APP"
  ts="$(date -u +%Y%m%d-%H%M%S)"; rb="$ROLLBACK/api-$ts"
  mkdir -p "$rb"
  local d
  for d in src bin migrations public; do [ -d "$APP/$d" ] && cp -a "$APP/$d" "$rb/$d"; done
  [ -f "$APP/RELEASE" ] && cp -a "$APP/RELEASE" "$rb/RELEASE"
  say "rollback copy: api-$ts"

  pend="$(VITALCARE_PRIVATE_ROOT="$PRIV" "$PHP" "$rel/bin/migrate.php" --pending)" || die "could not read the pending migrations"
  if [ -n "$pend" ]; then
    say "pending migrations: $(printf '%s' "$pend" | tr '\n' ' ')"
    [ -x "$APP/bin/backup.sh" ] || die "migrations are pending but the installed backup script is missing"
    VITALCARE_PRIVATE_ROOT="$PRIV" "$APP/bin/backup.sh" --pin || die "the pinned pre-migration backup failed; nothing was changed"
    say "pinned backup: ok"
    VITALCARE_PRIVATE_ROOT="$PRIV" "$PHP" "$rel/bin/migrate.php" || die "migration failed; code was NOT switched. Restore only with the owner's approval from the pinned backup"
  else
    say "pending migrations: none"
  fi

  app_sync "$rel"
  printf '%s\n' "$ID" > "$APP/RELEASE"
  ensure_shim
  sleep "$SETTLE"
  if wait_for_release "$ID"; then
    prune "$ROLLBACK" "$KEEP_ROLLBACKS" '^api-[0-9]{8}-[0-9]{6}$'
    log "activate-api $ID ok (rollback api-$ts)"
    say "API LIVE $ID"
  else
    say "health check failed after the switch; restoring the previous code" >&2
    app_sync "$rb"
    if [ -f "$rb/RELEASE" ]; then cp -a "$rb/RELEASE" "$APP/RELEASE"; else rm -f "$APP/RELEASE"; fi
    sleep "$SETTLE"
    die "the new API did not report ok for release ${ID:0:12}; the previous code was restored (database changes, if any, stay)"
  fi
}

do_rollback_api() {
  take_lock
  local rb="$ROLLBACK/api-$ID"
  [ -d "$rb" ] || die "no rollback copy api-$ID"
  [ -f "$rb/src/Autoload.php" ] || die "rollback copy api-$ID is incomplete"
  app_sync "$rb"
  if [ -f "$rb/RELEASE" ]; then cp -a "$rb/RELEASE" "$APP/RELEASE"; else rm -f "$APP/RELEASE"; fi
  local want; want="$(cat "$APP/RELEASE" 2>/dev/null || true)"
  sleep "$SETTLE"
  if [ -n "$want" ] && ! wait_for_release "$want"; then die "restored api-$ID but the health check did not pass"; fi
  log "rollback-api $ID ok"
  say "API RESTORED from api-$ID"
}

do_activate_site() {
  take_lock; need_verified
  local site="$RELEASES/$ID/site" ts rb f
  [ -d "$site" ] || die "no site in release $ID"
  [ -d "$DOCROOT" ] || die "the web root is missing"
  ts="$(date -u +%Y%m%d-%H%M%S)"; rb="$ROLLBACK/site-$ts"
  mkdir -p "$rb/files"
  : > "$rb/NEW-FILES"
  while IFS= read -r -d '' f; do
    f="${f#"$site/"}"
    if [ -e "$DOCROOT/$f" ]; then mkdir -p "$rb/files/$(dirname "$f")"; cp -a "$DOCROOT/$f" "$rb/files/$f"; else printf '%s\n' "$f" >> "$rb/NEW-FILES"; fi
  done < <(find "$site" -type f -print0)
  say "rollback copy: site-$ts"
  local common=(-rlt --checksum --exclude '/.htaccess' --exclude '/api/') last=(index.html sw.js manifest.webmanifest) ex=() args=()
  for f in "${last[@]}"; do ex+=(--exclude "/$f"); done
  # The web server must be able to read site files: a subshell with the usual umask, not the gate's 027.
  (
    umask 022
    rsync "${common[@]}" "${ex[@]}" "$site/" "$DOCROOT/"
    for f in "${last[@]}"; do [ -f "$site/$f" ] && args+=("$site/$f"); done
    rsync -lt --checksum "${args[@]}" "$DOCROOT/"
  )
  local i got=""
  for i in 1 2 3 4 5; do
    got="$(curl -fsS --max-time 20 "$SITE_URL/build-id.txt?r=$ID" 2>/dev/null | head -c 40 || true)"
    [ "$got" = "$ID" ] && break
    sleep "$HEALTH_WAIT"
  done
  if [ "$got" = "$ID" ]; then
    prune "$ROLLBACK" "$KEEP_ROLLBACKS" '^site-[0-9]{8}-[0-9]{6}$'
    log "activate-site $ID ok (rollback site-$ts)"
    say "SITE LIVE $ID"
  else
    die "the public site does not show build ${ID:0:12}; rollback copy site-$ts holds the previous files"
  fi
}

do_activate_htaccess() {
  take_lock; need_verified
  local tool="$RELEASES/$ID/api/tools/merge-htaccess.py" live="$DOCROOT/.htaccess" ts rb new
  [ -f "$tool" ] || die "this release has no tools/merge-htaccess.py"
  [ -f "$live" ] || die "the live .htaccess is missing; refusing to create one"
  ts="$(date -u +%Y%m%d-%H%M%S)"; rb="$ROLLBACK/htaccess-$ts"
  mkdir -p "$rb"; cp -a "$live" "$rb/.htaccess"
  new="$rb/merged"
  "$PYTHON" "$tool" "$live" > "$new" || { rm -f "$new"; die "the merge refused the live .htaccess (origin lock not found?); nothing was changed"; }
  if cmp -s "$live" "$new"; then
    rm -f "$new"; say ".htaccess: already up to date"; log "activate-htaccess $ID unchanged"; return 0
  fi
  cat "$new" > "$live.tmp" && mv "$live.tmp" "$live"
  chmod 644 "$live" 2>/dev/null || true
  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$HEALTH_URL" || true)"
  if [ "$code" != "200" ]; then
    cat "$rb/.htaccess" > "$live.tmp" && mv "$live.tmp" "$live"; chmod 644 "$live" 2>/dev/null || true
    die "the API answered $code after the .htaccess change; the previous .htaccess was restored"
  fi
  prune "$ROLLBACK" "$KEEP_ROLLBACKS" '^htaccess-[0-9]{8}-[0-9]{6}$'
  log "activate-htaccess $ID ok (rollback htaccess-$ts)"
  say ".htaccess: merged (rollback copy htaccess-$ts)"
}

log "request: $VERB ${ID:-}"
case "$VERB" in
  status) do_status ;;
  verify) do_verify ;;
  activate-api) do_activate_api ;;
  activate-site) do_activate_site ;;
  activate-htaccess) do_activate_htaccess ;;
  rollback-api) do_rollback_api ;;
esac
