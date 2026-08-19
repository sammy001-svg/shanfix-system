#!/bin/bash
# Team chat: who may change a channel's membership, and the rule that
# decides whether a message is worth an email and a text message.
#
# The alerting half is the part worth guarding. A chat is a back and
# forth, and the naive version of this feature texts somebody once per
# line. Each of those costs money and tells the recipient nothing the
# first one did not, so most of these assertions are about the alerts
# that must NOT be sent.
BASE="http://127.0.0.1:8000"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root shanfix_test"
D="$(dirname "$0")"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
has() { case "$2" in *"$3"*) ok "$1" "found";; *) bad "$1" "$2" "contains $3";; esac; }

q()   { $MYSQL -N -e "$1"; }
signin() {
  JAR="$D/chat_$1.txt"; rm -f "$JAR"
  local t
  t=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$t&email=$1@shanfix.co.ke&password=$2"
}
tok()  { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
page() { curl -s -b "$JAR" -c "$JAR" "$BASE$1"; }
post() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE$p" "$@"; }

# Two colleagues, made fresh so the suite is repeatable.
HASH=$(php -r 'echo password_hash("Chat@2026", PASSWORD_DEFAULT);')
for u in chatty quiet; do
  $MYSQL -e "DELETE FROM users WHERE email='$u@shanfix.co.ke';
             INSERT INTO users (name,email,phone,password_hash,role,is_active)
             VALUES ('Test $u','$u@shanfix.co.ke','0722000${#u}01','$HASH','sales',1);
             INSERT INTO user_roles (user_id, role)
             SELECT id,'sales' FROM users WHERE email='$u@shanfix.co.ke';"
done
CHATTY=$(q "SELECT id FROM users WHERE email='chatty@shanfix.co.ke';")
QUIET=$(q "SELECT id FROM users WHERE email='quiet@shanfix.co.ke';")
ADMIN=$(q "SELECT id FROM users WHERE email='admin@shanfix.co.ke';")

# Alerts on, both channels available, and a clean slate.
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key IN
             ('chat_alerts_enabled','notify_chat_message_email','notify_chat_message_sms',
              'smtp_enabled','sms_enabled');
           UPDATE settings SET setting_value='15' WHERE setting_key='chat_alert_cooldown';
           UPDATE settings SET setting_value='3'  WHERE setting_key='chat_alert_active_mins';
           DELETE FROM staff_notifications;
           DELETE FROM notifications WHERE event LIKE 'chat%';
           DELETE FROM activity_log WHERE action='login_failed';"

# Counting only what this suite provoked, so a backlog left by another
# suite cannot make these numbers drift upward run after run.
alerts() { q "SELECT COUNT(*) FROM staff_notifications WHERE event='chat_message';"; }
queued() { q "SELECT COUNT(*) FROM notifications WHERE event='chat_message' AND channel='$1';"; }
reset()  { $MYSQL -e "DELETE FROM staff_notifications; DELETE FROM notifications WHERE event LIKE 'chat%';"; }
# Pretend somebody wandered off, so they qualify for an alert.
idle()   { $MYSQL -e "UPDATE chat_participants SET last_read_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE conversation_id=$1 AND user_id=$2;"; }
say()    { curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/chat/send" --data-urlencode "_token=$T" --data-urlencode "conversation_id=$1" --data-urlencode "body=$2"; }

NAME="suite-channel-$$"

echo ""
echo "=== 1. An admin opens a channel ==="
signin admin Shanfix@2026
T=$(tok /chat)
eq "creates it" "$(post /chat/channels --data-urlencode "_token=$T" --data-urlencode "name=$NAME" --data-urlencode "description=A test channel" --data-urlencode "members[]=$CHATTY")" "302"
CH=$(q "SELECT id FROM chat_conversations WHERE name='$NAME' ORDER BY id DESC LIMIT 1;")
eq "the channel exists"        "$([ -n "$CH" ] && echo yes)"  "yes"
eq "creator and invitee in it" "$(q "SELECT COUNT(*) FROM chat_participants WHERE conversation_id=$CH;")" "2"

echo ""
echo "=== 2. Membership can be changed after the fact ==="
P=$(page "/chat?c=$CH")
has "the add-people control is offered" "$P" 'id="add-members"'
has "a member can be removed"           "$P" "/members/"
T=$(tok "/chat?c=$CH")
eq "adds somebody"  "$(post "/chat/$CH/members" --data "_token=$T&user_ids[]=$QUIET")" "302"
eq "they are in it" "$(q "SELECT COUNT(*) FROM chat_participants WHERE conversation_id=$CH AND user_id=$QUIET;")" "1"
eq "the addition is logged" "$(q "SELECT COUNT(*) FROM activity_log WHERE action='chat_member_added' AND entity_id=$CH;")" "1"
eq "removes them again" "$(post "/chat/$CH/members/$QUIET/remove" --data "_token=$T")" "302"
eq "they are out"       "$(q "SELECT COUNT(*) FROM chat_participants WHERE conversation_id=$CH AND user_id=$QUIET;")" "0"

# Ticking nothing and pressing the button used to log "0 person(s) added"
# and congratulate you on it.
eq "an empty pick is not called a success" "$(post "/chat/$CH/members" --data "_token=$T")" "302"
eq "and nothing is logged for it" "$(q "SELECT COUNT(*) FROM activity_log WHERE action='chat_member_added' AND entity_id=$CH;")" "1"

# Removing the person who opened the channel would leave it with nobody
# answerable for it, so it is refused however senior the remover.
eq "the creator cannot be removed" "$(post "/chat/$CH/members/$ADMIN/remove" --data "_token=$T")" "302"
eq "the creator is still in it"    "$(q "SELECT COUNT(*) FROM chat_participants WHERE conversation_id=$CH AND user_id=$ADMIN;")" "1"

echo ""
echo "=== 3. An ordinary member cannot change the membership ==="
signin chatty Chat@2026
P=$(page "/chat?c=$CH")
eq "no add-people control" "$(echo "$P" | grep -c 'id="add-members"')" "0"
eq "no remove buttons"     "$(echo "$P" | grep -c '/members/.*/remove')" "0"
T=$(tok "/chat?c=$CH")
post "/chat/$CH/members" --data "_token=$T&user_ids[]=$QUIET" > /dev/null
eq "posting it directly changes nothing" "$(q "SELECT COUNT(*) FROM chat_participants WHERE conversation_id=$CH AND user_id=$QUIET;")" "0"

echo ""
echo "=== 4. A message reaches somebody who is away ==="
reset
signin admin Shanfix@2026
T=$(tok "/chat?c=$CH")
idle "$CH" "$CHATTY"
say "$CH" "Karanja needs the 500 branded mugs proofed before 4pm, is the artwork signed off?"
eq "one person is told" "$(alerts)"       "1"
eq "an email is queued" "$(queued email)" "1"
eq "a text is queued"   "$(queued sms)"   "1"
eq "not the sender"     "$(q "SELECT COUNT(*) FROM staff_notifications WHERE user_id=$ADMIN;")" "0"

# The point of the alert is that the reader can judge from it whether to
# stop what they are doing. A bare "you have a message" cannot do that.
has "the email carries the message" "$(q "SELECT body FROM notifications WHERE event='chat_message' AND channel='email' LIMIT 1;")" "500 branded mugs"
has "the text carries the message"  "$(q "SELECT body FROM notifications WHERE event='chat_message' AND channel='sms' LIMIT 1;")" "500 branded mugs"
has "the text carries a way back"   "$(q "SELECT body FROM notifications WHERE event='chat_message' AND channel='sms' LIMIT 1;")" "/chat/$CH"
eq  "the text fits the 300 the gateway allows" "$(q "SELECT IF(CHAR_LENGTH(body)<=300,'yes','no') FROM notifications WHERE event='chat_message' AND channel='sms' LIMIT 1;")" "yes"

echo ""
echo "=== 5. The alerts that must not be sent ==="
# Somebody with the conversation open has already read it.
reset
$MYSQL -e "UPDATE chat_participants SET last_read_at=NOW() WHERE conversation_id=$CH AND user_id=$CHATTY;"
say "$CH" "A second thought while they are watching."
eq "nobody reading is emailed" "$(alerts)" "0"

# Two messages in a row is the ordinary case, and it is one alert.
reset
idle "$CH" "$CHATTY"
say "$CH" "First line of a burst."
idle "$CH" "$CHATTY"
say "$CH" "Second line of the same burst."
eq "a burst is one alert, not two" "$(alerts)" "1"

# Being named already produced a mention alert; a second one for the same
# message would be the same news twice.
reset
idle "$CH" "$CHATTY"
say "$CH" "@Test chatty can you chase the Naivas artwork today?"
eq "a mention is not alerted twice"    "$(alerts)" "0"
eq "the mention alert itself was sent" "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='chat_mention';")" "1"

# The master switch has to actually switch it off.
reset
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='chat_alerts_enabled';"
idle "$CH" "$CHATTY"
say "$CH" "Nothing should come of this one."
eq "the master switch stops everything" "$(alerts)" "0"
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='chat_alerts_enabled';"

echo ""
echo "=== 6. Direct messages alert the same way ==="
reset
DM=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$BASE/chat/with/$QUIET")
CID=$(q "SELECT c.id FROM chat_conversations c JOIN chat_participants a ON a.conversation_id=c.id AND a.user_id=$ADMIN JOIN chat_participants b ON b.conversation_id=c.id AND b.user_id=$QUIET WHERE c.type='dm' ORDER BY c.id DESC LIMIT 1;")
eq "the conversation opens" "$DM" "302"
T=$(tok "/chat?c=$CID")
idle "$CID" "$QUIET"
say "$CID" "Are you free to look at the Tuskys banner proof?"
eq "the other person is told" "$(alerts)" "1"
has "and it says where from"  "$(q "SELECT title FROM staff_notifications WHERE event='chat_message' LIMIT 1;")" "direct message"

echo ""
echo "=== 7. Tidy up ==="
$MYSQL -e "DELETE FROM chat_messages WHERE conversation_id IN ($CH, $CID);
           DELETE FROM chat_participants WHERE conversation_id IN ($CH, $CID);
           DELETE FROM chat_conversations WHERE id IN ($CH, $CID);
           DELETE FROM staff_notifications;
           DELETE FROM notifications WHERE event LIKE 'chat%';
           DELETE FROM activity_log WHERE action LIKE 'chat_member%';
           DELETE FROM users WHERE email IN ('chatty@shanfix.co.ke','quiet@shanfix.co.ke');
           UPDATE settings SET setting_value='0' WHERE setting_key='sms_enabled';"
eq "the test channel is gone" "$(q "SELECT COUNT(*) FROM chat_conversations WHERE id=$CH;")" "0"
rm -f "$D"/chat_*.txt

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
[ "$FAIL" -eq 0 ]
