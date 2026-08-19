#!/bin/bash
# Roles and permissions: what each role may reach, what several roles
# together add up to, and the guards that stop the last administrator
# being removed.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
D="$(dirname "$0")"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }

# Sign in and leave the cookie jar at $JAR for the caller.
signin() {
  JAR="$D/role_$1.txt"; rm -f "$JAR"
  local t
  t=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$t&email=$1@shanfix.co.ke&password=$2"
}
code() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE$1"; }
q()    { $MYSQL -N -e "$1"; }
tok()  { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

# Fresh accounts every run, so the suite is repeatable.
HASH=$(php -r 'echo password_hash("Role@2026", PASSWORD_DEFAULT);')
for r in manager finance sales production reception staff; do
  $MYSQL -e "DELETE FROM users WHERE email='$r@shanfix.co.ke';
             INSERT INTO users (name,email,password_hash,role,is_active)
             VALUES ('Test $r','$r@shanfix.co.ke','$HASH','$r',1);
             INSERT INTO user_roles (user_id, role)
             SELECT id,'$r' FROM users WHERE email='$r@shanfix.co.ke';"
done
$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';"

echo ""
echo "=== 1. Reception can do front-desk work ==="
signin reception Role@2026
eq "signs in"                  "$(code /dashboard)"        "200"
eq "registers a walk-in client" "$(code /clients/create)"   "200"
eq "logs an enquiry"            "$(code /leads/create)"     "200"
eq "raises a quotation"         "$(code /quotations/create)" "200"
eq "sees what is owed"          "$(code /payments)"         "200"
eq "checks a job is ready"      "$(code /jobs)"             "200"

echo ""
echo "=== 2. Reception stops at the books ==="
eq "no expenses"        "$(code /expenses)" "403"
eq "no reports"         "$(code /reports)"  "403"
eq "no settings"        "$(code /settings)" "403"
eq "no user accounts"   "$(code /users)"    "403"
eq "cannot open a job card" "$(code /jobs/create)" "403"

echo ""
echo "=== 3. The company's margin is not shown to everyone ==="
signin staff Role@2026
eq "staff: no revenue tile" "$(curl -s -b "$JAR" "$BASE/dashboard" | grep -c 'Collected this month')" "0"
eq "staff: no margin tile"  "$(curl -s -b "$JAR" "$BASE/dashboard" | grep -c 'Net this month')"       "0"
signin production Role@2026
eq "production: no revenue"  "$(curl -s -b "$JAR" "$BASE/dashboard" | grep -c 'Collected this month')" "0"
signin reception Role@2026
eq "reception: sees revenue" "$(curl -s -b "$JAR" "$BASE/dashboard" | grep -c 'Collected this month')" "1"
eq "reception: NOT the margin" "$(curl -s -b "$JAR" "$BASE/dashboard" | grep -c 'Net this month')"     "0"
signin finance Role@2026
eq "finance: sees the margin" "$(curl -s -b "$JAR" "$BASE/dashboard" | grep -c 'Net this month')"      "1"

echo ""
echo "=== 4. Two roles grant the union of both ==="
$MYSQL -e "DELETE FROM users WHERE email='both@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('Front Desk Plus','both@shanfix.co.ke','$HASH','reception',1);
           INSERT INTO user_roles (user_id, role)
             SELECT id,'reception' FROM users WHERE email='both@shanfix.co.ke';
           INSERT INTO user_roles (user_id, role)
             SELECT id,'finance' FROM users WHERE email='both@shanfix.co.ke';"
signin both Role@2026
eq "keeps reception's client registration" "$(code /clients/create)" "200"
eq "gains finance's expenses"              "$(code /expenses)"       "200"
eq "gains finance's reports"               "$(code /reports)"        "200"
eq "still no settings (neither role has it)" "$(code /settings)"     "403"

echo ""
echo "=== 5. A role that does not exist cannot be granted ==="
signin admin Shanfix@2026
MID=$($MYSQL -N -e "SELECT id FROM users WHERE email='both@shanfix.co.ke';")
T=$(tok "/users/$MID/edit")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/users/$MID" \
  --data "_token=$T&name=Front+Desk+Plus&email=both@shanfix.co.ke&role=reception&roles[]=reception&roles[]=superuser&is_active=1"
eq "invented role dropped" "$($MYSQL -N -e "SELECT COUNT(*) FROM user_roles WHERE user_id=$MID AND role='superuser';")" "0"
eq "real role kept"        "$($MYSQL -N -e "SELECT COUNT(*) FROM user_roles WHERE user_id=$MID AND role='reception';")" "1"

echo ""
echo "=== 6. The last administrator cannot be removed ==="
AID=$($MYSQL -N -e "SELECT id FROM users WHERE email='admin@shanfix.co.ke';")
ADMINS=$($MYSQL -N -e "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE ur.role='admin' AND u.is_active=1;")
eq "exactly one admin to start" "$ADMINS" "1"
T=$(tok "/users/$AID/edit")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/users/$AID" \
  --data "_token=$T&name=System+Administrator&email=admin@shanfix.co.ke&role=sales&roles[]=sales&is_active=1"
eq "cannot demote themselves" "$($MYSQL -N -e "SELECT role FROM users WHERE id=$AID;")" "admin"
T=$(tok "/users")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/users/$AID/toggle" --data "_token=$T"
eq "cannot deactivate themselves" "$($MYSQL -N -e "SELECT is_active FROM users WHERE id=$AID;")" "1"

echo ""
echo "=== 7. Admin as a second role still counts as an admin ==="
T=$(tok "/users/$MID/edit")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/users/$MID" \
  --data "_token=$T&name=Front+Desk+Plus&email=both@shanfix.co.ke&role=reception&roles[]=reception&roles[]=admin&is_active=1"
eq "second admin exists now" \
  "$($MYSQL -N -e "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE ur.role='admin' AND u.is_active=1;")" "2"
eq "and they can reach user administration" "$(signin both Role@2026; code /users)" "200"


echo ""
echo "=== 8. Reception can be given a lead, and can work it ==="
signin admin Shanfix@2026 >/dev/null
GRACE=$(q "SELECT id FROM users WHERE email='reception@shanfix.co.ke';")

# Anyone who may act on leads belongs in the box that hands one out.
FORM=$(curl -s -b "$JAR" "$BASE/leads/create")
eq "reception offered as an owner" "$(printf '%s' "$FORM" | grep -c "option value=\"$GRACE\"")" "1"

# Held as a second role, not the primary one — the list must still find them.
$MYSQL -e "DELETE FROM users WHERE email='dual@shanfix.co.ke';
  INSERT INTO users (name,email,password_hash,role,is_active)
  SELECT 'Dual Role', 'dual@shanfix.co.ke', password_hash, 'staff', 1 FROM users WHERE email='reception@shanfix.co.ke';
  INSERT INTO user_roles (user_id,role) SELECT id,'staff' FROM users WHERE email='dual@shanfix.co.ke';
  INSERT INTO user_roles (user_id,role) SELECT id,'sales' FROM users WHERE email='dual@shanfix.co.ke';"
DUAL=$(q "SELECT id FROM users WHERE email='dual@shanfix.co.ke';")
eq "sales held as a second role still counts" \
   "$(curl -s -b "$JAR" "$BASE/leads/create" | grep -c "option value=\"$DUAL\"")" "1"

T=$(tok /leads/create)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/leads" \
  --data-urlencode "_token=$T" --data-urlencode "name=Walk-in test $(date +%s)" \
  --data-urlencode "phone=0733112233" --data-urlencode "source=walk_in" \
  --data-urlencode "stage=new" --data-urlencode "assigned_to=$GRACE" \
  --data-urlencode "assignees[]=$GRACE"
LID=$(q "SELECT id FROM leads ORDER BY id DESC LIMIT 1;")
eq "the lead is hers"  "$(q "SELECT assigned_to FROM leads WHERE id=$LID;")" "$GRACE"

signin reception Role@2026 >/dev/null
eq "she can open it"   "$(code /leads/$LID)" "200"

rtok() { curl -s -b "$JAR" -c "$JAR" "$BASE/leads/$LID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
T=$(rtok)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/leads/$LID/activity" \
  --data-urlencode "_token=$T" --data-urlencode "activity_type=call" --data-urlencode "notes=Follow-up call"
eq "she can log a call"    "$(q "SELECT COUNT(*) FROM lead_activities WHERE lead_id=$LID AND activity_type='call';")" "1"

T=$(rtok)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/leads/$LID/reminder" \
  --data-urlencode "_token=$T" --data-urlencode "title=Chase this" \
  --data-urlencode "remind_at=$(date -d '+2 days' '+%Y-%m-%dT09:00')"
eq "she can set a reminder" "$(q "SELECT COUNT(*) FROM reminders WHERE lead_id=$LID;")" "1"

T=$(rtok)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/leads/$LID/stage" \
  --data-urlencode "_token=$T" --data-urlencode "stage=contacted"
eq "she can move the stage" "$(q "SELECT stage FROM leads WHERE id=$LID;")" "contacted"

# Production has no business in the sales pipeline.
signin production Role@2026 >/dev/null
eq "production still shut out" "$(code /leads)" "403"

signin admin Shanfix@2026 >/dev/null
$MYSQL -e "DELETE FROM reminders WHERE lead_id=$LID; DELETE FROM lead_activities WHERE lead_id=$LID;
           DELETE FROM lead_assignees WHERE lead_id=$LID; DELETE FROM leads WHERE id=$LID;
           DELETE FROM users WHERE email='dual@shanfix.co.ke';"
# Tidy up so a second run starts clean.
signin admin Shanfix@2026 >/dev/null
$MYSQL -e "DELETE FROM users WHERE email IN ('both@shanfix.co.ke','manager@shanfix.co.ke','finance@shanfix.co.ke',
           'sales@shanfix.co.ke','production@shanfix.co.ke','reception@shanfix.co.ke','staff@shanfix.co.ke');"
rm -f "$D"/role_*.txt

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
