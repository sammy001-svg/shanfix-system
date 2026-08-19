#!/bin/bash
# Verifies the "keep me signed in" token: it works, it rotates, it cannot be
# forged, and it dies when it should.
B="http://127.0.0.1:8000"
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
D="$(dirname "$0")"
P=0; F=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; P=$((P+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; F=$((F+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }

tok() { curl -s -b "$1" -c "$1" "$B/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code(){ curl -s -o /dev/null -w "%{http_code}" -b "$1" -c "$1" "$B$2"; }
# Read the remember cookie value out of a jar
rc()  { grep SHANFIX_REMEMBER "$1" 2>/dev/null | awk '{print $7}' | sed 's/%3A/:/gI'; }

echo ""
echo "=== 1. Sign in WITHOUT remember ==="
J="$D/j1.txt"; rm -f "$J"
T=$(tok "$J")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"
eq "signed in"                  "$(code "$J" /dashboard)" "200"
eq "no remember cookie issued"  "$([ -z "$(rc "$J")" ] && echo none || echo present)" "none"
eq "no token row created"       "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "0"

echo ""
echo "=== 2. Sign in WITH remember ==="
J="$D/j2.txt"; rm -f "$J"
T=$(tok "$J")
curl -s -o /dev/null -b "$J" -c "$J" -X POST "$B/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026&remember=1"
COOKIE=$(rc "$J")
eq "remember cookie issued"     "$([ -n "$COOKIE" ] && echo present || echo none)" "present"
eq "token row created"          "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "1"
eq "cookie is selector:validator" "$(echo "$COOKIE" | grep -cE '^[a-f0-9]{24}:[a-f0-9]{64}$')" "1"

SEL=$(echo "$COOKIE" | cut -d: -f1)
VAL=$(echo "$COOKIE" | cut -d: -f2)
STORED=$($MYSQL -N -e "SELECT validator_hash FROM remember_tokens WHERE selector='$SEL';")
eq "validator NOT stored in clear" "$([ "$STORED" = "$VAL" ] && echo leaked || echo hashed)" "hashed"
eq "stored hash matches sha256"    "$(php -r "echo hash('sha256','$VAL') === '$STORED' ? 'yes':'no';")" "yes"
eq "expiry ~30 days out"           "$($MYSQL -N -e "SELECT IF(DATEDIFF(expires_at,NOW()) BETWEEN 28 AND 30,'yes','no') FROM remember_tokens WHERE selector='$SEL';")" "yes"

echo ""
echo "=== 3. Session dies, cookie signs us back in ==="
# Keep ONLY the remember cookie — drop the session cookie entirely.
J3="$D/j3.txt"
printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tSHANFIX_REMEMBER\t%s\n' "$COOKIE" > "$J3"
eq "auto signed in from cookie"  "$(code "$J3" /dashboard)" "200"
eq "reached a real signed-in page" "$(curl -s -b "$J3" -c "$J3" "$B/dashboard" | grep -c 'Sign out')" "1"

echo ""
echo "=== 4. Validator rotates on use ==="
NEW=$(rc "$J3")
NEWVAL=$(echo "$NEW" | cut -d: -f2)
eq "selector unchanged"          "$(echo "$NEW" | cut -d: -f1)" "$SEL"
eq "validator changed"           "$([ "$NEWVAL" != "$VAL" ] && echo rotated || echo same)" "rotated"
eq "old validator no longer valid" "$(php -r "echo hash('sha256','$VAL') === '$($MYSQL -N -e "SELECT validator_hash FROM remember_tokens WHERE selector='$SEL';")' ? 'still-valid':'dead';")" "dead"
eq "last_used_at stamped"        "$($MYSQL -N -e "SELECT IF(last_used_at IS NOT NULL,'yes','no') FROM remember_tokens WHERE selector='$SEL';")" "yes"

echo ""
echo "=== 5. Replaying the OLD cookie is rejected and burns the token ==="
J5="$D/j5.txt"
printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tSHANFIX_REMEMBER\t%s\n' "$COOKIE" > "$J5"
eq "stolen old cookie refused"   "$(code "$J5" /dashboard)" "302"
eq "all tokens for user purged"  "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "0"
# J3 also holds a live session, which the purge deliberately does not touch.
# Isolate just its remember cookie to prove THAT no longer grants access.
J3R="$D/j3r.txt"
grep SHANFIX_REMEMBER "$J3" > "$J3R" 2>/dev/null
eq "rotated cookie alone now dead"  "$(code "$J3R" /dashboard)" "302"
eq "existing session still valid"   "$(code "$J3" /dashboard)" "200"

echo ""
echo "=== 6. Forged cookies ==="
for LABEL in "unknown selector" "malformed" "sql injection"; do
  case "$LABEL" in
    "unknown selector") C="aaaaaaaaaaaaaaaaaaaaaaaa:$(printf 'b%.0s' {1..64})" ;;
    "malformed")        C="not-a-valid-cookie" ;;
    "sql injection")    C="' OR '1'='1:x" ;;
  esac
  JF="$D/jf.txt"
  printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tSHANFIX_REMEMBER\t%s\n' "$C" > "$JF"
  eq "rejected: $LABEL" "$(code "$JF" /dashboard)" "302"
done

echo ""
echo "=== 7. Sign out clears the token ==="
J7="$D/j7.txt"; rm -f "$J7"
T=$(tok "$J7")
curl -s -o /dev/null -b "$J7" -c "$J7" -X POST "$B/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026&remember=1"
eq "token exists after login"    "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "1"
T=$(curl -s -b "$J7" -c "$J7" "$B/dashboard" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J7" -c "$J7" -X POST "$B/logout" --data "_token=$T"
eq "token removed on sign out"   "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "0"
eq "cannot reach dashboard"      "$(code "$J7" /dashboard)" "302"

echo ""
echo "=== 8. Expired token is refused ==="
J8="$D/j8.txt"; rm -f "$J8"
T=$(tok "$J8")
curl -s -o /dev/null -b "$J8" -c "$J8" -X POST "$B/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026&remember=1"
C8=$(rc "$J8")
$MYSQL -e "UPDATE remember_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY);"
J8B="$D/j8b.txt"
printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tSHANFIX_REMEMBER\t%s\n' "$C8" > "$J8B"
eq "expired cookie refused"      "$(code "$J8B" /dashboard)" "302"
eq "expired row deleted"         "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "0"

echo ""
echo "=== 9. Changing the password kills every remembered device ==="
J9="$D/j9.txt"; rm -f "$J9"
T=$(tok "$J9")
curl -s -o /dev/null -b "$J9" -c "$J9" -X POST "$B/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026&remember=1"
# A second "device"
J9B="$D/j9b.txt"; rm -f "$J9B"
T2=$(tok "$J9B")
curl -s -o /dev/null -b "$J9B" -c "$J9B" -X POST "$B/login" --data "_token=$T2&email=admin@shanfix.co.ke&password=Shanfix@2026&remember=1"
eq "two devices remembered"      "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "2"
T=$(curl -s -b "$J9" -c "$J9" "$B/profile" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J9" -c "$J9" -X POST "$B/profile/password" \
  --data "_token=$T&current_password=Shanfix@2026&new_password=NewPass2026!&new_password_confirm=NewPass2026!"
eq "all devices signed out"      "$($MYSQL -N -e "SELECT COUNT(*) FROM remember_tokens;")" "0"
# put it back
T=$(curl -s -b "$J9" -c "$J9" "$B/profile" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J9" -c "$J9" -X POST "$B/profile/password" \
  --data "_token=$T&current_password=NewPass2026!&new_password=Shanfix@2026&new_password_confirm=Shanfix@2026"

echo ""
echo "=== 10. Cookie flags ==="
J10="$D/j10.txt"; rm -f "$J10"
T=$(tok "$J10")
H=$(curl -s -D - -o /dev/null -b "$J10" -c "$J10" -X POST "$B/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026&remember=1" | grep -i "set-cookie: SHANFIX_REMEMBER")
eq "HttpOnly set"  "$(echo "$H" | grep -ci httponly)" "1"
eq "SameSite=Lax"  "$(echo "$H" | grep -ci 'samesite=lax')" "1"

$MYSQL -e "DELETE FROM remember_tokens;" 2>/dev/null
rm -f "$D"/j*.txt

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$P" "$F"
echo "==================================================="
exit $F
