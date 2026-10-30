#!/usr/bin/env bash
# Runner side of a deploy: upload a packaged release over SFTP, then ask the server's gate to verify and
# activate it. Used by .github/workflows/deploy.yml; it is the ONLY way code reaches the server.
#
#   scripts/deploy-sftp.sh COMMIT_ID --api [--site] [--htaccess]
#   scripts/deploy-sftp.sh status
#
# Needs a built release (scripts/build-release.sh). Environment, supplied by the workflow from GitHub
# environment secrets (never written to the repository or printed):
#   DEPLOY_HOST  DEPLOY_PORT  DEPLOY_USER   the account
#   DEPLOY_KEY                               path to the private key file (mode 0600)
#   DEPLOY_KNOWN_HOSTS                       path to a known_hosts file with the server's host key (checked strictly)
# Test mode: DEPLOY_TRANSPORT=local with DEPLOY_LOCAL_GATE=<path to deploy-gate.sh> and VITALCARE_* set
# runs the same sequence against a local folder instead of a server.
set -euo pipefail
cd "$(dirname "$0")/.."

die() { echo "FAIL: $*" >&2; exit 1; }
ACTION="${1:-}"
[ -n "$ACTION" ] || die "usage: scripts/deploy-sftp.sh COMMIT_ID --api [--site] [--htaccess] | status"

TRANSPORT="${DEPLOY_TRANSPORT:-ssh}"
if [ "$TRANSPORT" = "ssh" ]; then
  for v in DEPLOY_HOST DEPLOY_PORT DEPLOY_USER DEPLOY_KEY DEPLOY_KNOWN_HOSTS; do
    [ -n "${!v:-}" ] || die "$v is not set"
  done
  [[ "$DEPLOY_PORT" =~ ^[0-9]{1,5}$ ]] || die "DEPLOY_PORT must be a number"
  [[ "$DEPLOY_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || die "DEPLOY_HOST has unexpected characters"
  [[ "$DEPLOY_USER" =~ ^[A-Za-z0-9_.-]+$ ]] || die "DEPLOY_USER has unexpected characters"
  [ -f "$DEPLOY_KEY" ] && [ -f "$DEPLOY_KNOWN_HOSTS" ] || die "key or known_hosts file is missing"
  # Explicit options only: no ssh config, only this key, only the pinned host key, never a prompt.
  SSHOPTS=(-F /dev/null -i "$DEPLOY_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o "UserKnownHostsFile=$DEPLOY_KNOWN_HOSTS" -o StrictHostKeyChecking=yes -o ConnectTimeout=20)
  gate() { ssh "${SSHOPTS[@]}" -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" "$1"; }
  upload() {
    # $1 = local release folder, $2 = id. Plain put only: the server refuses rename, delete and chmod.
    local batch; batch="$(mktemp)"; trap 'rm -f "$batch"' RETURN
    {
      echo "-mkdir vitalcare-private/incoming"
      echo "-mkdir vitalcare-private/incoming/$2"
      for f in api.tar site.tar; do [ -f "$1/$f" ] && echo "put $1/$f vitalcare-private/incoming/$2/$f"; done
      echo "put $1/SHA256SUMS vitalcare-private/incoming/$2/SHA256SUMS"
    } > "$batch"
    sftp "${SSHOPTS[@]}" -P "$DEPLOY_PORT" -b "$batch" "$DEPLOY_USER@$DEPLOY_HOST" >/dev/null
  }
elif [ "$TRANSPORT" = "local" ]; then
  : "${DEPLOY_LOCAL_GATE:?}" "${VITALCARE_HOME:?}"
  gate() { SSH_ORIGINAL_COMMAND="$1" bash "$DEPLOY_LOCAL_GATE"; }
  upload() {
    local d="$VITALCARE_HOME/vitalcare-private/incoming/$2"; mkdir -p "$d"
    for f in api.tar site.tar; do [ -f "$1/$f" ] && cp "$1/$f" "$d/$f"; done
    cp "$1/SHA256SUMS" "$d/SHA256SUMS"
  }
else
  die "unknown DEPLOY_TRANSPORT"
fi

if [ "$ACTION" = "status" ]; then gate status; exit $?; fi

ID="$ACTION"; shift
[[ "$ID" =~ ^[0-9a-f]{40}$ ]] || die "the first argument must be a 40-character commit id"
API=0; SITE=0; HTA=0
for a in "$@"; do
  case "$a" in --api) API=1 ;; --site) SITE=1 ;; --htaccess) HTA=1 ;; *) die "unknown option: $a" ;; esac
done
[ $((API + SITE + HTA)) -gt 0 ] || die "nothing to deploy: pass --api, --site or --htaccess"
[ "$SITE" = 0 ] || [ "$API" = 1 ] || true
REL="${RELEASE_OUT:-.release}/$ID"
[ -f "$REL/SHA256SUMS" ] && [ -f "$REL/api.tar" ] || die "$REL is not a built release"
if [ "$SITE" = 1 ] && [ ! -f "$REL/site.tar" ]; then die "--site was asked for but $REL has no site.tar"; fi

echo "== upload"
upload "$REL" "$ID"
echo "== verify"
gate "verify $ID"
if [ "$API" = 1 ]; then echo "== activate api"; gate "activate-api $ID"; fi
if [ "$HTA" = 1 ]; then echo "== activate .htaccess"; gate "activate-htaccess $ID"; fi
if [ "$SITE" = 1 ]; then echo "== activate site"; gate "activate-site $ID"; fi
echo "== done"
