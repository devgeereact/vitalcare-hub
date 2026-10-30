#!/usr/bin/env bash
# Change allowlisted values in the server's private config (backend/bin/config-set.php) safely.
#
#   scripts/server-config-set.sh set smtp.host=mail.example.com smtp.port=465 clerk.authorized_parties=https://a,https://b
#   scripts/server-config-set.sh secret smtp.password        # prompts silently; the value is never echoed
#   pbpaste | scripts/server-config-set.sh secret turnstile.secret --stdin   # secret from a pipe (clipboard)
#
# Values travel over ssh on STDIN as JSON: never on a command line, never printed, never in shell history.
# Environment: DEPLOY_HOST (ssh alias, default "cpanel"); SERVER_CONFIG_LOCAL_ROOT (a local private root, for tests).
set -euo pipefail
CMD="${1:-}"; shift || true
HOST="${DEPLOY_HOST:-cpanel}"
PHPBIN="${SERVER_PHP:-/opt/alt/php84/usr/bin/php}"

case "$CMD" in
  set) [ "$#" -ge 1 ] || { echo "usage: $0 set key=value ..." >&2; exit 64; } ;;
  secret) [ "$#" -ge 1 ] || { echo "usage: $0 secret key [--stdin]" >&2; exit 64; } ;;
  *) echo "usage: $0 set key=value ... | secret key [--stdin]" >&2; exit 64 ;;
esac

if [ "$CMD" = "secret" ]; then
  KEY="$1"; FROM_STDIN="${2:-}"
  if [ "$FROM_STDIN" = "--stdin" ]; then
    IFS= read -r VALUE || true
  else
    [ -t 0 ] || { echo "no terminal: pipe the value and add --stdin" >&2; exit 64; }
    printf 'Value for %s (input is hidden): ' "$KEY" >&2
    IFS= read -r -s VALUE || true
    printf '\n' >&2
  fi
  [ -n "$VALUE" ] || { echo "empty value; nothing changed" >&2; exit 64; }
  JSON="$(KEY="$KEY" VALUE="$VALUE" python3 -c 'import json,os; print(json.dumps({"set": {os.environ["KEY"]: os.environ["VALUE"]}}))')"
  unset VALUE
else
  JSON="$(python3 - "$@" <<'PY'
import json, sys
out = {}
for arg in sys.argv[1:]:
    key, sep, raw = arg.partition("=")
    if not sep:
        sys.exit("expected key=value, got: " + key)
    if key == "clerk.authorized_parties":
        val = [v for v in raw.split(",") if v]
    elif key == "smtp.port":
        val = int(raw)
    else:
        val = raw
    out[key] = val
print(json.dumps({"set": out}))
PY
)"
fi

if [ -n "${SERVER_CONFIG_LOCAL_ROOT:-}" ]; then
  printf '%s' "$JSON" | VITALCARE_PRIVATE_ROOT="$SERVER_CONFIG_LOCAL_ROOT" php "$(cd "$(dirname "$0")/.." && pwd)/backend/bin/config-set.php"
else
  printf '%s' "$JSON" | ssh -o BatchMode=yes "$HOST" "VITALCARE_PRIVATE_ROOT=\"\$HOME/vitalcare-private\" $PHPBIN \"\$HOME/vitalcare-private/app/bin/config-set.php\""
fi
