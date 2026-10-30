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
printf '' | bash scripts/server-config-set.sh secret turnstile.secret --stdin >/dev/null 2>&1; [ $? -ne 0 ]; check "an empty secret is refused" $?
printf 'short' | bash scripts/server-config-set.sh secret turnstile.secret --stdin >/dev/null 2>&1; [ $? -ne 0 ]; check "a too-short secret is refused by the server-side tool" $?
bash scripts/server-config-set.sh set db.password=hacked12345 >/dev/null 2>&1; [ $? -ne 0 ]; check "a key outside the allowlist is refused" $?
[ "$(val db.password)" = "DB-PW-VALUE" ]; check "...and the database password is untouched" $?
bash scripts/server-config-set.sh set noequals >/dev/null 2>&1; [ $? -ne 0 ]; check "an argument without = is refused" $?
bash scripts/server-config-set.sh bogus >/dev/null 2>&1; [ $? -ne 0 ]; check "an unknown command is refused" $?
echo "server-config-set tests: $PASS passed, $FAILN failed"; [ "$FAILN" = "0" ]
