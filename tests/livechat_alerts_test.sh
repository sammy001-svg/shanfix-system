#!/bin/bash
# Being told that somebody is waiting.
#
# The chat itself is covered by livechat_test.sh. This is the part that
# taps somebody on the shoulder — which, until now, did not exist: a
# stranger who started a chat reached a queue nobody was told about, and
# Departments::escalateTo() had been written and called from nowhere
# since the day it was added.
#
# Two moments, and they must not behave alike. The bell rings for the
# whole department the instant a chat arrives and nothing is e-mailed.
# Minutes later, if nobody has picked it up, the leads are written to —
# once, not once per cron run. Most of what is asserted here is about
# that "once", because an alarm that repeats is an alarm people learn to
# ignore, and then the one that mattered goes unread too.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

api_post() { local a="$1"; shift; curl -s -X POST "$BASE/api/chat/$a" "$@"; }
api_get()  { local a="$1"; shift; curl -s "$BASE/api/chat/$a" "$@"; }

start_chat() {
  api_post start --data-urlencode "message=$1" \
                 --data-urlencode "name=${2:-Waiting Tester}" \
                 --data-urlencode "department=${3:-alerts-dept}" \
                 --data-urlencode "email=${4:-}"
}

token_of() { sed 's/.*"token":"\([^"]*\)".*/\1/'; }
newest_id() { q "SELECT id FROM live_conversations ORDER BY id DESC LIMIT 1;"; }

# How many bells are ringing for one person about one conversation.
bells_for() {
  q "SELECT COUNT(*) FROM staff_notifications
      WHERE user_id=$1 AND entity_type='live_conversation' AND entity_id=$2;"
}

# Queued outbound messages about a conversation — the e-mail and SMS the
# escalation puts on the same queue clients' messages go on.
queued_for() {
  q "SELECT COUNT(*) FROM notifications
      WHERE entity_type='live_conversation' AND entity_id=$1
        AND channel='${2:-email}';"
}

# Push a conversation's clock back, so the sweep sees a long wait without
# the suite having to sit through one.
age_by() { q "UPDATE live_conversations SET created_at = DATE_SUB(NOW(), INTERVAL $2 MINUTE) WHERE id=$1;"; }

sweep() { $PHP "$ROOT/tests/helpers/livechat_sweep.php"; }

# ---------------------------------------------------------------------
# Setup
#
# Everything global is put back on the way out. A suite that leaves the
# office hours flipped, or SMTP switched on, fails the next suite for
# reasons that have nothing to do with its code.
#
# GROUP_CONCAT joins with ';' rather than the default ',': one of these
# values is itself a comma list, and splitting on commas restores it as
# its own first element.
# ---------------------------------------------------------------------
BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value) SEPARATOR ';') FROM settings
             WHERE setting_key IN ('livechat_enabled','livechat_ask_department',
                                   'livechat_hours_from','livechat_hours_to','livechat_hours_days',
                                   'livechat_alert_after','livechat_alert_email','livechat_alert_sms',
                                   'livechat_alert_sound','livechat_greeting','livechat_offline_message',
                                   'smtp_enabled','sms_enabled');")

restore() {
  local pair key val
  # Read one per line rather than splitting $BEFORE into words: two of
  # these values are sentences, and word-splitting restored the greeting
  # as its own first word and threw the rest away. IFS is left alone
  # outside the read, because q() relies on $MYSQL splitting into a
  # command and its arguments.
  while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    key="${pair%%=*}"; val="${pair#*=}"
    q "UPDATE settings SET setting_value='$(printf '%s' "$val" | sed "s/'/''/g")' WHERE setting_key='$key';"
  done <<EOF
$(printf '%s' "$BEFORE" | tr ';' '
')
EOF

  q "DELETE FROM notifications WHERE entity_type='live_conversation';"
  q "DELETE FROM staff_notifications WHERE entity_type='live_conversation';"
  q "DELETE FROM live_conversations WHERE visitor_name LIKE '%Tester%'
       OR visitor_name IN ('Midnight Caller','Orphan Caller');"
  q "DELETE FROM live_departments WHERE slug IN ('alerts-dept','alerts-empty');"
  q "DELETE FROM users WHERE email IN ('alertlead@shanfix.co.ke','alertmate@shanfix.co.ke');"
}
trap restore EXIT

q "DELETE FROM bulk_rate_counters WHERE bucket LIKE 'livechat:%';"
q "UPDATE settings SET setting_value='1' WHERE setting_key IN ('livechat_enabled','livechat_ask_department');"
q "UPDATE settings SET setting_value='5' WHERE setting_key='livechat_alert_after';"
q "UPDATE settings SET setting_value='1' WHERE setting_key='livechat_alert_email';"
q "UPDATE settings SET setting_value='0' WHERE setting_key='livechat_alert_sms';"

# Open every day, all day, so "are we at the desk" is not a coin toss
# decided by when somebody runs the suite. Section 5 turns it off again
# on purpose.
q "UPDATE settings SET setting_value='00:00' WHERE setting_key='livechat_hours_from';"
q "UPDATE settings SET setting_value='23:59' WHERE setting_key='livechat_hours_to';"
q "UPDATE settings SET setting_value='0,1,2,3,4,5,6' WHERE setting_key='livechat_hours_days';"

# A department with two people in it: one lead, one not. The difference
# between them is the whole point of escalateTo().
HASH=$($PHP -r 'echo password_hash("AlertPass1", PASSWORD_DEFAULT);')
q "DELETE FROM users WHERE email IN ('alertlead@shanfix.co.ke','alertmate@shanfix.co.ke');"
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Alert Lead','alertlead@shanfix.co.ke','$HASH','sales',1),
          ('Alert Mate','alertmate@shanfix.co.ke','$HASH','sales',1);"
LEAD=$(q "SELECT id FROM users WHERE email='alertlead@shanfix.co.ke';")
MATE=$(q "SELECT id FROM users WHERE email='alertmate@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id, role) VALUES ($LEAD,'sales'),($MATE,'sales');"

q "DELETE FROM live_departments WHERE slug IN ('alerts-dept','alerts-empty');"
q "INSERT INTO live_departments (name,slug,blurb,is_default,position,status)
   VALUES ('Alerts Test','alerts-dept','For the alerts suite',0,90,'active');"
DEPT=$(q "SELECT id FROM live_departments WHERE slug='alerts-dept';")
q "INSERT INTO live_department_staff (department_id,user_id,is_lead)
   VALUES ($DEPT,$LEAD,1),($DEPT,$MATE,0);"

signin_admin

echo ""
echo "=== 1. The bell rings the moment somebody arrives ==="

START=$(start_chat "My banner has the wrong logo on it" "Bell Tester")
CID=$(newest_id)

has "the visitor is told their message arrived" "$START" '"ok":true'
eq  "the lead is told"      "$(bells_for "$LEAD" "$CID")" "1"
eq  "and so is everybody else in the department" "$(bells_for "$MATE" "$CID")" "1"

eq  "the notice carries what they actually said" \
    "$(q "SELECT body FROM staff_notifications WHERE user_id=$LEAD AND entity_id=$CID;")" \
    "My banner has the wrong logo on it"

eq  "and a link straight to the conversation" \
    "$(q "SELECT link FROM staff_notifications WHERE user_id=$LEAD AND entity_id=$CID;")" \
    "/livechat/$CID"

# The distinction the whole design rests on: arriving is a bell, not a
# mailbox full of post.
eq  "nothing is e-mailed for a chat four seconds old" "$(queued_for "$CID" email)" "0"
eq  "and nothing is texted"                           "$(queued_for "$CID" sms)"   "0"

echo ""
echo "=== 2. Somebody in another department hears nothing ==="

OTHER=$(q "SELECT id FROM users WHERE email='admin@shanfix.co.ke';")
eq "an administrator not in the department is not rung" \
   "$(bells_for "$OTHER" "$CID")" "0"

echo ""
echo "=== 3. Nobody picks it up ==="

# Not yet old enough. The sweep must leave it alone, or the second-line
# alarm is indistinguishable from the first.
sweep >/dev/null
eq "a fresh chat is not escalated"   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID AND escalated_at IS NOT NULL;")" "0"
eq "and still nothing is e-mailed"   "$(queued_for "$CID" email)" "0"

age_by "$CID" 30
q "UPDATE settings SET setting_value='1' WHERE setting_key='smtp_enabled';"
sweep >/dev/null

eq "once it has waited, it is escalated" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID AND escalated_at IS NOT NULL;")" "1"
eq "the lead gets an e-mail"  "$(queued_for "$CID" email)" "1"

# escalateTo() prefers leads. Alert Mate is in the department but is not
# one, so they got the bell when it arrived and nothing now.
eq "it goes to the lead, not to everybody" \
   "$(q "SELECT COUNT(*) FROM notifications n JOIN users u ON u.email=n.recipient
          WHERE n.entity_id=$CID AND n.channel='email' AND u.id=$MATE;")" "0"

eq "the e-mail says how long they have been waiting" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE entity_id=$CID AND channel='email'
          AND subject LIKE '%waited%minutes%';")" "1"

echo ""
echo "=== 4. Once, not once per cron run ==="

sweep >/dev/null
sweep >/dev/null
eq "three sweeps, one e-mail"   "$(queued_for "$CID" email)" "1"
eq "and one extra bell for the lead, not three" "$(bells_for "$LEAD" "$CID")" "2"

# A reply stops the clock. Nothing that is being handled should ever
# reach the leads.
AGENT=$(start_chat "Second question, answered quickly" "Answered Tester" | token_of)
CID2=$(newest_id)
age_by "$CID2" 30
q "UPDATE live_conversations SET status='open', first_reply_at=NOW() WHERE id=$CID2;"
sweep >/dev/null
eq "a conversation somebody is handling is never escalated" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID2 AND escalated_at IS NOT NULL;")" "0"

# A chat from last week is a mess for the inbox, not an emergency for
# somebody's phone at two in the morning.
start_chat "Ancient and forgotten" "Ancient Tester" >/dev/null
CID3=$(newest_id)
q "UPDATE live_conversations SET created_at = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE id=$CID3;"
sweep >/dev/null
eq "and neither is one from last week" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID3 AND escalated_at IS NOT NULL;")" "0"

echo ""
echo "=== 5. Written to again after it was closed ==="

REOPEN=$(start_chat "Is my order ready?" "Return Tester" | token_of)
CID4=$(newest_id)
age_by "$CID4" 30
sweep >/dev/null
eq "the first wait escalates" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID4 AND escalated_at IS NOT NULL;")" "1"

q "UPDATE live_conversations SET status='closed', closed_at=NOW() WHERE id=$CID4;"
api_post send --data-urlencode "token=$REOPEN" --data-urlencode "message=Actually, one more thing" >/dev/null

eq "writing again puts it back in the queue" \
   "$(q "SELECT status FROM live_conversations WHERE id=$CID4;")" "waiting"
eq "and clears the mark, because this is a new wait" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID4 AND escalated_at IS NULL;")" "1"
# Three by now: when it arrived, when it escalated, and now that it is
# back. Each one is a separate moment somebody needed to know about.
eq "the department is rung about it again" "$(bells_for "$LEAD" "$CID4")" "3"

eq "and closing it left nothing stale behind"    "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID4 AND closed_at IS NULL;")" "1"

eq "the bell carries the new message, not the old one" \
   "$(q "SELECT body FROM staff_notifications WHERE user_id=$LEAD AND entity_id=$CID4 ORDER BY id DESC LIMIT 1;")" \
   "Actually, one more thing"

echo ""
echo "=== 6. Out of hours, we ask how to reply ==="

q "UPDATE settings SET setting_value='1,2' WHERE setting_key='livechat_hours_days';"
q "UPDATE settings SET setting_value='03:00' WHERE setting_key='livechat_hours_from';"
q "UPDATE settings SET setting_value='03:01' WHERE setting_key='livechat_hours_to';"

NIGHT=$(start_chat "Are you open tomorrow?" "Midnight Caller")
NTOKEN=$(echo "$NIGHT" | token_of)
CID5=$(newest_id)

has "the widget is told the desk is shut"        "$NIGHT" '"open":false'
has "and that we have no way of reaching them"   "$NIGHT" '"knows":false'

CONTACT=$(api_post contact --data-urlencode "token=$NTOKEN" --data-urlencode "email=night@example.com")
has "an address can be left afterwards" "$CONTACT" '"ok":true'
eq  "and is kept" "$(q "SELECT visitor_email FROM live_conversations WHERE id=$CID5;")" "night@example.com"

eq  "the thread says where the reply will go" \
    "$(q "SELECT COUNT(*) FROM live_messages WHERE conversation_id=$CID5
           AND sender='system' AND body LIKE '%night@example.com%';")" "1"

# The token lives in a browser we do not control, so it must not be able
# to redirect where our answer goes.
api_post contact --data-urlencode "token=$NTOKEN" --data-urlencode "email=thief@example.com" >/dev/null
eq "a second address cannot overwrite the first" \
   "$(q "SELECT visitor_email FROM live_conversations WHERE id=$CID5;")" "night@example.com"

BADMAIL=$(api_post contact --data-urlencode "token=$(printf 'b%.0s' {1..64})" --data-urlencode "email=x@example.com")
has "and a made-up token leaves no address anywhere" "$BADMAIL" '"ok":false'

# Somebody who did give an address is not asked for one again.
KNOWN=$(start_chat "I left my email" "Known Tester" "alerts-dept" "known@example.com")
has "somebody who already said how to reach them is not asked" "$KNOWN" '"knows":true'

# A bad address is refused rather than stored as a lie.
LATE=$(start_chat "No address" "Anon Tester")
LTOKEN=$(echo "$LATE" | token_of)
REFUSED=$(api_post contact --data-urlencode "token=$LTOKEN" --data-urlencode "email=not-an-address")
has "an address that is not one is refused" "$REFUSED" '"ok":false'
eq  "and nothing is written" \
    "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$(newest_id) AND visitor_email IS NULL;")" "1"

q "UPDATE settings SET setting_value='00:00' WHERE setting_key='livechat_hours_from';"
q "UPDATE settings SET setting_value='23:59' WHERE setting_key='livechat_hours_to';"
q "UPDATE settings SET setting_value='0,1,2,3,4,5,6' WHERE setting_key='livechat_hours_days';"

echo ""
echo "=== 7. A department with nobody in it ==="

# The one setup mistake that silently loses customers. It must be loud.
q "INSERT INTO live_departments (name,slug,blurb,fallback_email,is_default,position,status)
   VALUES ('Alerts Empty','alerts-empty','Nobody answers this one','nobody@shanfix.test',0,91,'active');"

start_chat "Anybody there?" "Orphan Caller" "alerts-empty" >/dev/null
CID6=$(newest_id)

eq "nobody is rung, because there is nobody" \
   "$(q "SELECT COUNT(*) FROM staff_notifications WHERE entity_id=$CID6;")" "0"

age_by "$CID6" 30
OUT=$(sweep)
eq "but the sweep still marks it dealt with" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$CID6 AND escalated_at IS NOT NULL;")" "1"
has "and says out loud that the department is empty" "$OUT" "no staff in the department"

echo ""
echo "=== 8. The switches actually switch ==="

start_chat "Quiet please" "Quiet Tester" >/dev/null
CID7=$(newest_id)
age_by "$CID7" 30

q "UPDATE settings SET setting_value='0' WHERE setting_key='livechat_alert_email';"
sweep >/dev/null
eq "with e-mail off, nothing is queued" "$(queued_for "$CID7" email)" "0"
eq "the bell still rings"               "$(bells_for "$LEAD" "$CID7")" "2"

q "UPDATE settings SET setting_value='1' WHERE setting_key='livechat_alert_email';"

# SMS is the expensive one and the one that wakes people up, so it stays
# off unless the office has said otherwise — and it must also respect the
# global sms_enabled, or a suite like this could text a real phone.
start_chat "Noisy please" "Noisy Tester" >/dev/null
CID8=$(newest_id)
age_by "$CID8" 30
q "UPDATE settings SET setting_value='1' WHERE setting_key='livechat_alert_sms';"
q "UPDATE settings SET setting_value='0' WHERE setting_key='sms_enabled';"
sweep >/dev/null
eq "asking for texts while SMS is off sends none" "$(queued_for "$CID8" sms)" "0"

echo ""
echo "=== 9. The office can change all this without a MySQL client ==="

# These settings were seeded by migration 045 and, until now, reachable
# from nowhere in the system.
PAGE=$(page /livechat/departments)
has "the settings are on the departments page" "$PAGE" 'livechat_alert_after'
has "including whether to e-mail"              "$PAGE" 'livechat_alert_email'
has "and whether the desk makes a sound"       "$PAGE" 'livechat_alert_sound'
has "and the office hours"                     "$PAGE" 'livechat_hours_from'

T=$(tok /livechat/departments)
eq "saving them works" \
   "$(post /livechat/settings --data-urlencode "_token=$T" \
        --data-urlencode "livechat_greeting=Hello there" \
        --data-urlencode "livechat_offline_message=Back tomorrow" \
        --data-urlencode "livechat_hours_from=08:30" \
        --data-urlencode "livechat_hours_to=17:00" \
        --data-urlencode "days[]=1" --data-urlencode "days[]=2" \
        --data-urlencode "livechat_alert_after=11" \
        --data-urlencode "livechat_alert_email=1" \
        --data-urlencode "livechat_enabled=1")" "302"

eq "the threshold is kept"  "$(q "SELECT setting_value FROM settings WHERE setting_key='livechat_alert_after';")" "11"
eq "so are the hours"       "$(q "SELECT setting_value FROM settings WHERE setting_key='livechat_hours_from';")" "08:30"
eq "and the days, in order" "$(q "SELECT setting_value FROM settings WHERE setting_key='livechat_hours_days';")" "1,2"

# An unticked box is a real answer, not a missing one.
eq "an unticked box switches the thing off" \
   "$(q "SELECT setting_value FROM settings WHERE setting_key='livechat_alert_sms';")" "0"

# Nought minutes would escalate a chat the instant it arrived, which
# would make the second-line alarm the same thing as the first.
T=$(tok /livechat/departments)
post /livechat/settings --data-urlencode "_token=$T" --data-urlencode "livechat_alert_after=0" \
     --data-urlencode "livechat_enabled=1" >/dev/null
eq "and nought minutes is clamped, not obeyed" \
   "$(q "SELECT setting_value FROM settings WHERE setting_key='livechat_alert_after';")" "1"

echo ""
echo "=== 10. Nobody but management may change them ==="

SJ="$D/alerts_staff.txt"
JAR="$SJ" login_as "alertmate@shanfix.co.ke" "AlertPass1" >/dev/null 2>&1

eq "somebody who only answers chats cannot open the settings" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/livechat/departments")" "403"

eq "nor post to them" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" -X POST "$BASE/livechat/settings" \
        --data-urlencode "livechat_alert_after=99")" "403"

eq "and the threshold is untouched" \
   "$(q "SELECT setting_value FROM settings WHERE setting_key='livechat_alert_after';")" "1"

echo ""
echo "=== 11. The desk can be told without being deafened ==="

JS="$ROOT/public/assets/js/app.js"
eq "the desk has a sound to make"         "$(grep -c 'createOscillator' "$JS")" "1"
eq "it can be silenced per person"        "$(grep -c "sf.livechat.sound" "$JS")" "1"
has "and the title flashes for a tab in the background" "$(cat "$JS")" "Somebody is waiting"

# Two voices announcing the same visitor is worse than one.
has "the sidebar keeps quiet while the desk is open" "$(cat "$JS")" "atTheDesk"

report
