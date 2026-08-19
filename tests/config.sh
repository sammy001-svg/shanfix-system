#!/bin/bash
# Shared setup for the test suites. Every suite sources this.
#
# These are integration tests: they drive a running copy of the system
# over HTTP and check what landed in the database, because that is where
# the bugs in this system actually are — a permission that does not bite,
# a total that rounds the wrong way, a reminder sent twice. Nothing here
# mocks anything.
#
# Everything is overridable from the environment, so the same suites run
# on somebody else's machine without editing them:
#
#   SHANFIX_URL=http://localhost:8080 ./run.sh
#   SHANFIX_DB=shanfix_ci MYSQL_BIN=mysql ./run.sh

# The project root, worked out from this file rather than assumed.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Exported so the php -r blocks inside the suites can find the
# application. They are single-quoted, so a shell variable would not
# expand inside them.
export SHANFIX_ROOT="$ROOT"

# Where the system under test is being served.
BASE="${SHANFIX_URL:-http://127.0.0.1:8000}"

# The mysql client. On XAMPP it is not on PATH, so fall back to the usual
# Windows location before giving up.
if [ -n "$MYSQL_BIN" ]; then
  MYSQL_CLIENT="$MYSQL_BIN"
elif command -v mysql > /dev/null 2>&1; then
  MYSQL_CLIENT="mysql"
elif [ -x "/c/xampp/mysql/bin/mysql.exe" ]; then
  MYSQL_CLIENT="/c/xampp/mysql/bin/mysql.exe"
else
  echo "Cannot find the mysql client. Set MYSQL_BIN to its path." >&2
  exit 1
fi

DB="${SHANFIX_DB:-shanfix_test}"
DBUSER="${SHANFIX_DB_USER:-root}"
DBPASS="${SHANFIX_DB_PASS:-}"

if [ -n "$DBPASS" ]; then
  MYSQL="$MYSQL_CLIENT -u $DBUSER -p$DBPASS $DB"
  MYSQL_NODB="$MYSQL_CLIENT -u $DBUSER -p$DBPASS"
else
  MYSQL="$MYSQL_CLIENT -u $DBUSER $DB"
  MYSQL_NODB="$MYSQL_CLIENT -u $DBUSER"
fi

# The administrator the suites sign in as.
ADMIN_EMAIL="${SHANFIX_ADMIN:-admin@shanfix.co.ke}"
ADMIN_PASS="${SHANFIX_ADMIN_PASS:-Shanfix@2026}"

# PHP, for the handful of checks that call into the application directly.
PHP="${PHP_BIN:-php}"

# Somewhere for cookie jars and scratch files.
D="${TMPDIR:-/tmp}/shanfix-tests"
mkdir -p "$D"

# -- Shared assertions ------------------------------------------------

PASS=0
FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
ne()  { if [ "$2" != "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "anything but $3"; fi; }
has() { case "$2" in *"$3"*) ok "$1" "found";; *) bad "$1" "$2" "contains $3";; esac; }

# -- Talking to the system --------------------------------------------

q()    { $MYSQL -N -e "$1"; }
tok()  { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
page() { curl -s -b "$JAR" -c "$JAR" "$BASE$1"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE$1"; }
post() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE$p" "$@"; }

signin() {
  JAR="$D/jar_$1.txt"; rm -f "$JAR"
  local t
  t=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
       --data "_token=$t&email=$1@shanfix.co.ke&password=$2"
}

signin_admin() {
  JAR="$D/jar_admin.txt"; rm -f "$JAR"
  local t
  t=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
       --data "_token=$t&email=$ADMIN_EMAIL&password=$ADMIN_PASS"
}

# The tally every suite prints last.
report() {
  echo ""
  echo "==================================================="
  printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
  echo "==================================================="
  [ "$FAIL" -eq 0 ]
}

# Refuse to run against anything that is not a test database. These
# suites delete rows and truncate tables; pointed at production they
# would destroy the business's records.
case "$DB" in
  *test*|*ci*|*dev*) ;;
  *)
    echo "Refusing to run: '$DB' does not look like a test database." >&2
    echo "Set SHANFIX_DB to a disposable copy." >&2
    exit 1
    ;;
esac
