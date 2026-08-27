#!/bin/bash
# Run every suite and report the total.
#
#   ./tests/run.sh              everything
#   ./tests/run.sh chat backup  only those
#
# The system has to be running and reachable at $BASE first. See README.md.
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1
source ./config.sh

# Nothing runs if the system is not up. Every assertion would fail with
# the same connection error, which buries whatever the real problem is.
if ! curl -s -o /dev/null --max-time 5 "$BASE/login"; then
  echo ""
  echo "  Nothing is answering at $BASE."
  echo "  Start it with:  php -S 127.0.0.1:8000 -t public dev-server.php"
  echo ""
  exit 1
fi

if [ $# -gt 0 ]; then
  SUITES=("$@")
else
  SUITES=(smoke roles approval leads letters jobs meetings services images renewals remember
          paylink webhook notify whatsapp chat backup brief)
fi

TOTAL_PASS=0
TOTAL_FAIL=0
BROKEN=()
STARTED=$(date +%s)

for name in "${SUITES[@]}"; do
  file="./${name}_test.sh"

  if [ ! -f "$file" ]; then
    printf "  %-12s \033[33mno such suite\033[0m\n" "$name"
    continue
  fi

  out=$(bash "$file" 2>&1)
  line=$(echo "$out" | sed 's/\x1b\[[0-9;]*m//g' | grep -oE 'PASSED: [0-9]+   FAILED: [0-9]+' | tail -1)
  p=$(echo "$line" | grep -oE 'PASSED: [0-9]+' | grep -oE '[0-9]+')
  f=$(echo "$line" | grep -oE 'FAILED: [0-9]+' | grep -oE '[0-9]+')

  p=${p:-0}
  f=${f:-0}

  TOTAL_PASS=$((TOTAL_PASS + p))
  TOTAL_FAIL=$((TOTAL_FAIL + f))

  if [ "$f" -gt 0 ] || [ -z "$line" ]; then
    BROKEN+=("$name")
    printf "  %-12s \033[31m%s\033[0m\n" "$name" "${line:-produced no result}"
    # Only the failures, so the reason is on screen without scrolling.
    echo "$out" | sed 's/\x1b\[[0-9;]*m//g' | grep -E '^\s+FAIL' | sed 's/^/      /'
  else
    printf "  %-12s \033[32m%d passed\033[0m\n" "$name" "$p"
  fi
done

echo ""
echo "==================================================="
printf "  \033[32m%d passed\033[0m   \033[31m%d failed\033[0m   in %ds\n" \
       "$TOTAL_PASS" "$TOTAL_FAIL" "$(( $(date +%s) - STARTED ))"

if [ ${#BROKEN[@]} -gt 0 ]; then
  printf "  suites with failures: %s\n" "${BROKEN[*]}"
fi

echo "==================================================="

[ "$TOTAL_FAIL" -eq 0 ] && [ ${#BROKEN[@]} -eq 0 ]
