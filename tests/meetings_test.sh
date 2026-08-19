#!/bin/bash
# Meetings: scheduling, the room, guests joining on a link, minutes, and
# the reminders that go out before it starts.
BASE="http://127.0.0.1:8000"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root shanfix_test"
D="$(dirname "$0")"
JAR="$D/mtg.txt"; GJAR="$D/mtg_guest.txt"
rm -f "$JAR" "$GJAR"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-54s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-54s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
q()   { $MYSQL -N -e "$1"; }
tok() { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';"
T=$(tok /login)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
  --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"

TITLE="Meeting test $(date +%s)"
WHEN=$(date -d '+90 minutes' '+%Y-%m-%dT%H:%M')

echo ""
echo "=== 1. Schedule one, with a colleague and an outside guest ==="
T=$(tok /meetings/create)
eq "scheduled" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE/meetings" \
   --data "_token=$T&title=$(printf '%s' "$TITLE" | sed 's/ /+/g')&scheduled_at=$WHEN&duration_mins=45&allow_guests=1&reminder_mins=60,30&guest_name[]=Test+Guest&guest_email[]=guest@example.com&guest_phone[]=0722000111")" "302"
MID=$(q "SELECT id FROM meetings WHERE title='$TITLE';")
TOKEN=$(q "SELECT public_token FROM meetings WHERE id=$MID;")
eq "the person calling it is the host" "$(q "SELECT invite_role FROM meeting_participants WHERE meeting_id=$MID AND user_id IS NOT NULL LIMIT 1;")" "host"
eq "the guest was invited"  "$(q "SELECT COUNT(*) FROM meeting_participants WHERE meeting_id=$MID AND email='guest@example.com';")" "1"
eq "guest phone normalised" "$(q "SELECT phone FROM meeting_participants WHERE meeting_id=$MID AND email='guest@example.com';")" "254722000111"
eq "share token is long"    "$(q "SELECT LENGTH(public_token) FROM meetings WHERE id=$MID;")" "48"

echo ""
echo "=== 2. The staff room ==="
ROOM=$(curl -s -b "$JAR" "$BASE/meetings/$MID/room")
eq "room opens"          "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/meetings/$MID/room")" "200"
eq "offers screen share" "$(printf '%s' "$ROOM" | grep -c 'data-share-screen')" "1"
eq "offers a microphone" "$(printf '%s' "$ROOM" | grep -c 'data-toggle-mic')" "1"
eq "has somewhere to take minutes" "$(printf '%s' "$ROOM" | grep -c 'data-note-form')" "1"
eq "carries a STUN server" "$(printf '%s' "$ROOM" | grep -c 'stun:')" "1"

echo ""
echo "=== 3. A guest joins on the link, with no account ==="
LOBBY=$(curl -s -c "$GJAR" "$BASE/join/$TOKEN")
eq "lobby opens"      "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/join/$TOKEN")" "200"
eq "asks who they are" "$(printf '%s' "$LOBBY" | grep -c 'id=\"name\"')" "1"
# Without -L, so the redirect itself is what gets asserted. Following it
# would land on the lobby and report 200 whether or not the room refused.
eq "room sends them back to give a name" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$GJAR" "$BASE/join/$TOKEN/room")" "302"
GT=$(printf '%s' "$LOBBY" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$GJAR" -c "$GJAR" -X POST "$BASE/join/$TOKEN" --data "_token=$GT&name=Test+Guest&email=guest@example.com"
eq "guest reaches the room" "$(curl -s -o /dev/null -w '%{http_code}' -b "$GJAR" "$BASE/join/$TOKEN/room")" "200"
eq "attendance recorded"    "$(q "SELECT IF(joined_at IS NULL,'no','yes') FROM meeting_participants WHERE meeting_id=$MID AND email='guest@example.com';")" "yes"

echo ""
echo "=== 4. Minutes, from both sides ==="
T=$(tok "/meetings/$MID/room")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/meetings/$MID/notes" --data "_token=$T&body=Staff+note&kind=note"
GT=$(curl -s -b "$GJAR" -c "$GJAR" "$BASE/join/$TOKEN/room" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$GJAR" -c "$GJAR" -X POST "$BASE/join/$TOKEN/notes" --data "_token=$GT&body=Guest+decision&kind=decision"
eq "both notes kept"        "$(q "SELECT COUNT(*) FROM meeting_notes WHERE meeting_id=$MID;")" "2"
eq "guest note attributed"  "$(q "SELECT author_name FROM meeting_notes WHERE meeting_id=$MID AND kind='decision';")" "Test Guest"
FIRST=$(q "SELECT MIN(id) FROM meeting_notes WHERE meeting_id=$MID;")
eq "polling returns only what is new" \
   "$(curl -s -b "$JAR" "$BASE/meetings/$MID/notes?since=$FIRST" | grep -c 'Guest decision')" "1"

echo ""
echo "=== 5. Screen-share signalling ==="
T=$(tok "/meetings/$MID/room")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/meetings/$MID/signal" --data "_token=$T&from=alpha&kind=hello&payload=%7B%7D"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/meetings/$MID/signal" --data "_token=$T&from=alpha&to=beta&kind=offer&payload=%7B%7D"
eq "beta gets both"        "$(curl -s -b "$GJAR" "$BASE/join/$TOKEN/signals?peer=beta&since=0" | grep -o '\"kind\":\"[a-z]*\"' | wc -l | tr -d ' ')" "2"
eq "alpha is not sent its own" "$(curl -s -b "$JAR" "$BASE/meetings/$MID/signals?peer=alpha&since=0" | grep -c '\"signals\":\[\]')" "1"
eq "a nonsense signal is refused" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE/meetings/$MID/signal" --data "_token=$(tok "/meetings/$MID/room")&from=alpha&kind=nonsense")" "400"

echo ""
echo "=== 6. Reminders before it starts ==="
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='smtp_enabled';
           UPDATE settings SET setting_value='0' WHERE setting_key='sms_enabled';
           UPDATE meetings SET scheduled_at=DATE_ADD(NOW(), INTERVAL 60 MINUTE) WHERE id=$MID;
           DELETE FROM notification_locks WHERE lock_key LIKE 'meeting:%';"
BEFORE=$(q "SELECT COUNT(*) FROM notifications;")
php "$D/meet_probe.php" > /dev/null 2>&1
AFTER=$(q "SELECT COUNT(*) FROM notifications;")
eq "everyone invited was told" "$((AFTER - BEFORE))" "2"
eq "nobody told twice"         "$(q "SELECT COUNT(*) FROM notification_locks WHERE lock_key LIKE 'meeting:$MID:%';")" "2"
eq "every reminder carries the join link" "$(q "SELECT COUNT(*) FROM notifications WHERE body NOT LIKE '%/join/%' AND event='meeting_reminder';")" "0"
eq "nothing left unrendered"   "$(q "SELECT COUNT(*) FROM notifications WHERE body LIKE '%{meeting_title}%';")" "0"
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='smtp_enabled';"

echo ""
echo "=== 7. A meeting that is off is not joinable ==="
T=$(tok "/meetings/$MID")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/meetings/$MID/cancel" --data "_token=$T"
eq "cancelled"            "$(q "SELECT status FROM meetings WHERE id=$MID;")" "cancelled"
eq "the link now refuses" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/join/$TOKEN")" "410"

echo ""
echo "=== 8. A staff-only meeting has no public door ==="
$MYSQL -e "UPDATE meetings SET status='scheduled', allow_guests=0 WHERE id=$MID;"
eq "guests refused" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/join/$TOKEN")" "403"
eq "staff unaffected" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/meetings/$MID/room")" "200"

echo ""
echo "=== 9. An invented link goes nowhere ==="
eq "made-up token" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/join/$(printf 'a%.0s' $(seq 48))")" "404"
eq "wrong shape"   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/join/short")" "404"

echo ""
echo "=== 10. Ending it clears the signalling ==="
$MYSQL -e "UPDATE meetings SET status='in_progress' WHERE id=$MID;"
T=$(tok "/meetings/$MID")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/meetings/$MID/end" --data "_token=$T"
eq "ended"             "$(q "SELECT status FROM meetings WHERE id=$MID;")" "ended"
eq "postbox emptied"   "$(q "SELECT COUNT(*) FROM meeting_signals WHERE meeting_id=$MID;")" "0"
eq "the minutes remain" "$(q "SELECT COUNT(*) FROM meeting_notes WHERE meeting_id=$MID;")" "2"

# Tidy up.
$MYSQL -e "DELETE FROM meetings WHERE id=$MID;
           DELETE FROM notification_locks WHERE lock_key LIKE 'meeting:%';"
rm -f "$JAR" "$GJAR"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
