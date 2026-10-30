#!/usr/bin/env bash
# Throwaway local MariaDB for the PHP tests. Never touches any other database.
#   bash scripts/test-db.sh up     start (initialises .local-test/db/ on first use), prints nothing secret
#   bash scripts/test-db.sh down   stop it
# Listens on 127.0.0.1:33175 only. The credentials below are local-only and grant nothing elsewhere.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
DB_DIR="$ROOT/.local-test/db"
SOCK="$DB_DIR/mariadb.sock"
PORT=33175
if [ -z "${TEST_DB_EXTERNAL:-}" ]; then
  MB="$(brew --prefix mariadb 2>/dev/null)/bin"
  [ -x "$MB/mariadbd" ] || { echo "MariaDB not found: brew install mariadb" >&2; exit 1; }
fi
if [ -z "${TEST_DB_EXTERNAL:-}" ]; then
  git check-ignore -q .local-test/probe || { echo "BLOCKED: .local-test/ must be git-ignored first" >&2; exit 1; }
  export TMPDIR="$ROOT/.local-test/tmp"; mkdir -p "$TMPDIR" "$DB_DIR"
fi

case "${1:-}" in
  up)
    # CI provides its own MariaDB service and sets TEST_DB_EXTERNAL=1.
    if [ -n "${TEST_DB_EXTERNAL:-}" ]; then echo "Using the external test database"; exit 0; fi
    if ! "$MB/mariadb" --no-defaults -uroot --socket="$SOCK" -e 'SELECT 1' >/dev/null 2>&1; then
      [ -d "$DB_DIR/data" ] || "$MB/mariadb-install-db" --no-defaults --datadir="$DB_DIR/data" --auth-root-authentication-method=normal >/dev/null
      nohup "$MB/mariadbd" --no-defaults --datadir="$DB_DIR/data" --socket="$SOCK" --port=$PORT --bind-address=127.0.0.1 \
        --pid-file="$DB_DIR/mariadb.pid" > "$DB_DIR/mariadb.log" 2>&1 &
      for _ in $(seq 1 40); do "$MB/mariadb" --no-defaults -uroot --socket="$SOCK" -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
    fi
    "$MB/mariadb" --no-defaults -uroot --socket="$SOCK" -e "CREATE USER IF NOT EXISTS 'vc_test'@'127.0.0.1' IDENTIFIED BY 'vc_test_local_only'; GRANT ALL ON \`vitalcare\\_test%\`.* TO 'vc_test'@'127.0.0.1'; FLUSH PRIVILEGES;"
    echo "Test MariaDB ready on 127.0.0.1:$PORT"
    ;;
  down)
    "$MB/mariadb-admin" --no-defaults -uroot --socket="$SOCK" shutdown 2>/dev/null && echo "stopped" || echo "was not running"
    ;;
  *) echo "usage: bash scripts/test-db.sh up|down" >&2; exit 64 ;;
esac
