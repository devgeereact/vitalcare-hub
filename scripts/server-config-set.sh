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
  # Clean up what a paste can smuggle in, then say WHY a value is unusable (by category and length only,
  # never the value). Stripped: bracketed-paste markers, and whitespace or line ends around the value.
  JSON="$(KEY="$KEY" VALUE="$VALUE" python3 - <<'PY'
import json, os, re, sys
v = os.environ["VALUE"]
v = re.sub(r"\x1b\[20[01]~", "", v)          # bracketed-paste start and end markers
v = v.strip(" \t\r\n")
key = os.environ["KEY"]
if v == "":
    sys.stderr.write("Nothing was entered (or only spaces and line breaks). Nothing was sent.\n"); sys.exit(64)
problems = []
if len(v) < 8:
    problems.append("it is only %d characters long (at least 8 are needed)" % len(v))
if len(v) > 200:
    problems.append("it is %d characters long (at most 200)" % len(v))
spaces = v.count(" ")
ctrl = sum(1 for c in v if ord(c) < 32 or ord(c) == 127)
nonascii = sum(1 for c in v if ord(c) > 126)
if spaces: problems.append("it contains %d space(s) inside it" % spaces)
if ctrl: problems.append("it contains %d hidden control character(s)" % ctrl)
if nonascii: problems.append("it contains %d non-ASCII character(s) (accents, curly quotes, emoji)" % nonascii)
if problems:
    sys.stderr.write("Not sent. For %s, %s. (%d characters after trimming; the value itself is never shown.)\n" % (key, "; and ".join(problems), len(v)))
    sys.exit(65)
print(json.dumps({"set": {key: v}}))
PY
)" || exit $?
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
