#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "$0")/.."
T="$PWD/.local-test/tmp/scs-$$"; mkdir -p "$T/config"; trap 'rm -rf "$T"' EXIT
printf "<?php return ['db'=>['dsn'=>'x','user'=>'u','password'=>'DB-PW-VALUE'],'office_email'=>'info@vitalcare.uk','turnstile'=>['secret'=>'','dev_bypass'=>false],'clerk'=>['issuer'=>'https://clerk.vitalcare.uk','authorized_parties'=>['https://vitalcare.uk']]];" > "$T/config/prod.php"; chmod 600 "$T/config/prod.php"
export SERVER_CONFIG_LOCAL_ROOT="$T"
PASS=0; FAILN=0; check() { if [ "$2" = "0" ]; then PASS=$((PASS+1)); else FAILN=$((FAILN+1)); echo "FAIL: $1"; fi; }
val() { php -r "\$c = require '$T/config/prod.php'; \$p = explode('.', \$argv[1]); foreach (\$p as \$k) { \$c = \$c[\$k]; } echo is_array(\$c) ? json_encode(\$c) : \$c;" "$1"; }

OUT="$(bash scripts/server-config-set.sh set smtp.host=mail.example.com smtp.port=465 clerk.authorized_parties=https://vitalcare.uk,https://local.vitalcare.uk 2>&1)"; check "set exits 0" $?
[ "$(val smtp.host)" = "mail.example.com" ] && [ "$(val smtp.port)" = "465" ]; check "plain values are applied (port as a number)" $?
[ "$(val clerk.authorized_parties)" = '["https:\/\/vitalcare.uk","https:\/\/local.vitalcare.uk"]' ]; check "a comma list becomes a list" $?

OUT="$(printf 'Pasted-Secret_Value-123' | bash scripts/server-config-set.sh secret smtp.password --stdin 2>&1)"; check "a secret from a pipe exits 0" $?
[ "$(val smtp.password)" = "Pasted-Secret_Value-123" ]; check "the secret is applied" $?
echo "$OUT" | grep -q "Pasted-Secret"; [ $? -ne 0 ]; check "the secret is not in the output" $?
echo "$OUT" | grep -q "secret, not shown"; check "the output says it was set without showing it" $?
bash scripts/server-config-set.sh secret smtp.password </dev/null >/dev/null 2>&1; [ $? -ne 0 ]; check "no terminal and no --stdin is refused (it never reads a secret from an arg)" $?

# Paste artefacts are cleaned, and an unusable value is explained without being echoed.
printf 'Pasted-Secret_Value-123\r\n' | bash scripts/server-config-set.sh secret smtp.password --stdin >/dev/null 2>&1; rc=$?
check "a trailing carriage return from a paste is removed and the value accepted" $rc
[ "$(val smtp.password)" = "Pasted-Secret_Value-123" ]; check "...and what is stored has no carriage return" $?
printf '\033[200~Bracketed-Paste_Value-1\033[201~\n' | bash scripts/server-config-set.sh secret turnstile.secret --stdin >/dev/null 2>&1
[ "$(val turnstile.secret)" = "Bracketed-Paste_Value-1" ]; check "terminal bracketed-paste markers are removed" $?
printf '   Spaces_Around_Value-9   \n' | bash scripts/server-config-set.sh secret smtp.password --stdin >/dev/null 2>&1
[ "$(val smtp.password)" = "Spaces_Around_Value-9" ]; check "spaces around the value are trimmed" $?
for bad in 'has a space inside' 'short' 'cafÃ©-with-accent-1' ; do
  ERR="$(printf "$bad\n" | bash scripts/server-config-set.sh secret turnstile.secret --stdin 2>&1 >/dev/null)"; rc=$?
  [ $rc -ne 0 ]; check "an unusable value is refused: $(printf "$bad" | wc -c | tr -d ' ') bytes" $?
  echo "$ERR" | grep -q "Not sent"; check "...with a reason, before anything is sent" $?
  echo "$ERR" | grep -qiE "has a space inside|caf|short"; [ $? -ne 0 ]; check "...and the reason never contains the value" $?
done
R1="$(printf 'has a space inside\n' | bash scripts/server-config-set.sh secret turnstile.secret --stdin 2>&1 || true)"; echo "$R1" | grep -q "space"; check "the reason for an inner space says so" $?
R2="$(printf 'short\n' | bash scripts/server-config-set.sh secret turnstile.secret --stdin 2>&1 || true)"; echo "$R2" | grep -q "only 5 characters"; check "the reason for a short value gives its length" $?
[ "$(val turnstile.secret)" = "Bracketed-Paste_Value-1" ]; check "a refused value leaves the stored one untouched" $?
printf '' | bash scripts/server-config-set.sh secret turnstile.secret --stdin >/dev/null 2>&1; [ $? -ne 0 ]; check "an empty secret is refused" $?
printf 'short' | bash scripts/server-config-set.sh secret turnstile.secret --stdin >/dev/null 2>&1; [ $? -ne 0 ]; check "a too-short secret is refused by the server-side tool" $?
bash scripts/server-config-set.sh set db.password=hacked12345 >/dev/null 2>&1; [ $? -ne 0 ]; check "a key outside the allowlist is refused" $?
[ "$(val db.password)" = "DB-PW-VALUE" ]; check "...and the database password is untouched" $?
bash scripts/server-config-set.sh set noequals >/dev/null 2>&1; [ $? -ne 0 ]; check "an argument without = is refused" $?
bash scripts/server-config-set.sh bogus >/dev/null 2>&1; [ $? -ne 0 ]; check "an unknown command is refused" $?
echo "server-config-set tests: $PASS passed, $FAILN failed"; [ "$FAILN" = "0" ]
