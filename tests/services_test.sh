#!/bin/bash
# Service cards, and linking past jobs to a service as examples of it.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
D="$(dirname "$0")"
JAR="$D/svc.txt"; rm -f "$JAR"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
q()   { $MYSQL -N -e "$1"; }
tok() { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';"
T=$(tok /login)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
  --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"

SID=$(q "SELECT id FROM services ORDER BY id LIMIT 1;")
$MYSQL -e "DELETE FROM service_jobs WHERE service_id=$SID;"

echo ""
echo "=== 1. Services show as cards, with a list still available ==="
CARDS=$(curl -s -b "$JAR" "$BASE/services")
eq "cards by default"  "$(printf '%s' "$CARDS" | grep -c 'class=\"card service\"')" "$(q 'SELECT COUNT(*) FROM services;')"
eq "list on request"   "$(curl -s -b "$JAR" "$BASE/services?view=table" | grep -c 'class=\"card service\"')" "0"
eq "both views reachable" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/services?view=table")" "200"

echo ""
echo "=== 2. Link a finished job as an example ==="
JOB=$(q "SELECT id FROM jobs WHERE stage IN ('ready','delivered') ORDER BY id LIMIT 1;")
JOBNO=$(q "SELECT job_number FROM jobs WHERE id=$JOB;")
T=$(tok "/services/$SID")
eq "link accepted" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE/services/$SID/examples" \
   --data "_token=$T&job_id=$JOB&note=Good+example")" "302"
eq "stored"        "$(q "SELECT COUNT(*) FROM service_jobs WHERE service_id=$SID AND job_id=$JOB;")" "1"
eq "note kept"     "$(q "SELECT note FROM service_jobs WHERE service_id=$SID AND job_id=$JOB;")" "Good example"
eq "who linked it recorded" "$(q "SELECT IF(linked_by IS NULL,'no','yes') FROM service_jobs WHERE service_id=$SID AND job_id=$JOB;")" "yes"

echo ""
echo "=== 3. The same job is not linked twice ==="
T=$(tok "/services/$SID")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/services/$SID/examples" --data "_token=$T&job_id=$JOB"
eq "still one link" "$(q "SELECT COUNT(*) FROM service_jobs WHERE service_id=$SID;")" "1"

echo ""
echo "=== 4. It shows on the service and on the card ==="
SHOW=$(curl -s -b "$JAR" "$BASE/services/$SID")
eq "past work panel"   "$(printf '%s' "$SHOW" | grep -c 'Work we have done')" "1"
eq "exactly one example listed" "$(printf '%s' "$SHOW" | grep -c 'class=\"workitem\"')" "1"
eq "and it points at that job" "$(printf '%s' "$SHOW" | grep -q "/jobs/$JOB\"" && echo yes || echo no)" "yes"
eq "already-linked job not suggested again" "$(printf '%s' "$SHOW" | grep -c "option value=\"$JOB\"")" "0"
eq "card counts the example" "$(curl -s -b "$JAR" "$BASE/services" | grep -c 'past job')" "1"

echo ""
echo "=== 5. Artwork that is not a picture is labelled, not shown broken ==="
eq "no PDF inside an img tag" "$(printf '%s' "$SHOW" | grep -c 'img src=\"/files/[^\"]*\.pdf\"')" "0"

echo ""
echo "=== 6. Only finished jobs can be offered ==="
UNFINISHED=$(q "SELECT COUNT(*) FROM jobs WHERE stage NOT IN ('ready','delivered');")
OFFERED=0
for id in $(q "SELECT id FROM jobs WHERE stage NOT IN ('ready','delivered') LIMIT 5;"); do
  if printf '%s' "$SHOW" | grep -q "option value=\"$id\""; then OFFERED=$((OFFERED+1)); fi
done
eq "unfinished jobs never suggested" "$OFFERED" "0"

echo ""
echo "=== 7. Only people who manage services can link ==="
HASH=$(php -r 'echo password_hash("Role@2026", PASSWORD_DEFAULT);')
$MYSQL -e "DELETE FROM users WHERE email='svcstaff@shanfix.co.ke';
  INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('Svc Staff','svcstaff@shanfix.co.ke','$HASH','staff',1);
  INSERT INTO user_roles (user_id,role) SELECT id,'staff' FROM users WHERE email='svcstaff@shanfix.co.ke';"
SJAR="$D/svcstaff.txt"; rm -f "$SJAR"
T2=$(curl -s -c "$SJAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$SJAR" -c "$SJAR" -X POST "$BASE/login" --data "_token=$T2&email=svcstaff@shanfix.co.ke&password=Role@2026"
JOB2=$(q "SELECT id FROM jobs WHERE stage IN ('ready','delivered') AND id <> $JOB ORDER BY id LIMIT 1;")
T2=$(curl -s -b "$SJAR" -c "$SJAR" "$BASE/services/$SID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
eq "staff refused" "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJAR" -c "$SJAR" -X POST "$BASE/services/$SID/examples" \
   --data "_token=$T2&job_id=$JOB2")" "403"
eq "nothing linked by them" "$(q "SELECT COUNT(*) FROM service_jobs WHERE service_id=$SID;")" "1"
eq "but they can still see the service" "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJAR" "$BASE/services/$SID")" "200"

echo ""
echo "=== 8. Unlinking leaves the job alone ==="
T=$(tok "/services/$SID")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/services/$SID/examples/$JOB/remove" --data "_token=$T"
eq "example removed" "$(q "SELECT COUNT(*) FROM service_jobs WHERE service_id=$SID;")" "0"
eq "job card untouched" "$(q "SELECT COUNT(*) FROM jobs WHERE id=$JOB;")" "1"

echo ""
echo "=== 9. Deleting a job takes its examples with it ==="
# A throwaway job of its own: this section deletes what it links, and a
# suite has no business destroying real production records to prove a
# foreign key works.
CLI=$(q "SELECT id FROM clients ORDER BY id LIMIT 1;")
$MYSQL -e "INSERT INTO jobs (job_number,client_id,title,stage,priority,created_by)
           VALUES (CONCAT('JOB-TMP-',UNIX_TIMESTAMP()),$CLI,'Cascade check','delivered','normal',1);"
TMPJOB=$(q "SELECT id FROM jobs ORDER BY id DESC LIMIT 1;")
$MYSQL -e "INSERT INTO service_jobs (service_id, job_id) VALUES ($SID, $TMPJOB);"
eq "example linked to the throwaway job" "$(q "SELECT COUNT(*) FROM service_jobs WHERE job_id=$TMPJOB;")" "1"
$MYSQL -e "DELETE FROM jobs WHERE id=$TMPJOB;"
eq "deleting the job removed its example" "$(q "SELECT COUNT(*) FROM service_jobs WHERE job_id=$TMPJOB;")" "0"

$MYSQL -e "DELETE FROM service_jobs WHERE service_id=$SID;
           DELETE FROM users WHERE email='svcstaff@shanfix.co.ke';"
rm -f "$JAR" "$SJAR"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
