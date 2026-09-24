#!/bin/bash
# Saved replies, and posting somebody their own conversation.
#
# Two things migration 045 left room for and nothing ever filled. The
# saved replies could be read into the desk's dropdown and put there by
# nobody — there was no screen — so the feature was off unless somebody
# opened MySQL. And transcript_sent_at sat unused, which meant a chat
# window closed and took the price the customer was quoted with it.
#
# The property everything here is really protecting: a private note is
# staff talking to each other about the customer, in the same thread as
# the conversation. Losing a transcript is a disappointment. Posting one
# that carries a note is a different kind of problem entirely.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

api_post() { local a="$1"; shift; curl -s -X POST "$BASE/api/chat/$a" "$@"; }
token_of() { sed 's/.*"token":"\([^"]*\)".*/\1/'; }
newest()   { q "SELECT id FROM live_conversations ORDER BY id DESC LIMIT 1;"; }

start_chat() {
  api_post start --data-urlencode "message=$1" \
                 --data-urlencode "name=${2:-Reply Tester}" \
                 --data-urlencode "email=${3:-}" \
                 --data-urlencode "department=sales"
}

# The transcript queued for a conversation, if any.
transcript_body() {
  q "SELECT body FROM notifications
      WHERE event='livechat_transcript' AND entity_id=$1 LIMIT 1;"
}
transcripts_for() {
  q "SELECT COUNT(*) FROM notifications WHERE event='livechat_transcript' AND entity_id=$1;"
}

# ---------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------
BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value) SEPARATOR ';') FROM settings
             WHERE setting_key IN ('livechat_enabled','livechat_transcript_email',
                                   'livechat_hours_from','livechat_hours_to','livechat_hours_days',
                                   'smtp_enabled');")

restore() {
  local pair key val
  while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    key="${pair%%=*}"; val="${pair#*=}"
    q "UPDATE settings SET setting_value='$(printf '%s' "$val" | sed "s/'/''/g")' WHERE setting_key='$key';"
  done <<EOF
$(printf '%s' "$BEFORE" | tr ';' '\n')
EOF

  q "DELETE FROM notifications WHERE event='livechat_transcript';"
  q "DELETE FROM live_conversations WHERE visitor_name LIKE '%Tester%';"
  q "DELETE FROM live_canned WHERE title LIKE 'TEST %';"
  q "DELETE FROM live_departments WHERE slug='replies-dept';"
  q "DELETE FROM users WHERE email='replyagent@shanfix.co.ke';"
}
trap restore EXIT

q "DELETE FROM bulk_rate_counters WHERE bucket LIKE 'livechat:%';"
q "UPDATE settings SET setting_value='1' WHERE setting_key IN
     ('livechat_enabled','livechat_transcript_email','smtp_enabled');"
q "UPDATE settings SET setting_value='00:00' WHERE setting_key='livechat_hours_from';"
q "UPDATE settings SET setting_value='23:59' WHERE setting_key='livechat_hours_to';"
q "UPDATE settings SET setting_value='0,1,2,3,4,5,6' WHERE setting_key='livechat_hours_days';"

signin_admin

echo ""
echo "=== 1. The replies have a screen at last ==="

PAGE=$(page /livechat/canned)
eq  "it opens"                     "$(code /livechat/canned)" "200"
has "and lists what came with the system" "$PAGE" "Ask for the artwork"
has "with what each one actually says"    "$PAGE" "3mm bleed"
has "and a box to write another"          "$PAGE" 'name="body"'

# The desk is where somebody realises a reply is wrong, so the way in is
# from there and not only from a menu.
has "the desk links to it" "$(page /livechat)" "/livechat/canned"

echo ""
echo "=== 2. Writing, changing and removing one ==="

T=$(tok /livechat/canned)
eq "a new reply is accepted" \
   "$(post /livechat/canned --data-urlencode "_token=$T" \
        --data-urlencode "title=TEST Delivery" \
        --data-urlencode "body=We deliver within Nakuru town for 300 bob.")" "302"

CID=$(q "SELECT id FROM live_canned WHERE title='TEST Delivery';")
ne "it was written down"  "$CID" ""
eq "with no department, so every queue gets it" \
   "$(q "SELECT COUNT(*) FROM live_canned WHERE id=$CID AND department_id IS NULL;")" "1"
eq "and it knows who wrote it" \
   "$(q "SELECT COUNT(*) FROM live_canned WHERE id=$CID AND created_by IS NOT NULL;")" "1"

T=$(tok /livechat/canned)
eq "changing it is accepted" \
   "$(post /livechat/canned --data-urlencode "_token=$T" --data-urlencode "id=$CID" \
        --data-urlencode "title=TEST Delivery" \
        --data-urlencode "body=We deliver within Nakuru town for 350 bob.")" "302"
has "the new wording is kept" "$(q "SELECT body FROM live_canned WHERE id=$CID;")" "350"
eq "and who changed it, which is the question asked when one quotes last year's price" \
   "$(q "SELECT COUNT(*) FROM live_canned WHERE id=$CID AND updated_by IS NOT NULL;")" "1"

# A reply pointing at a department that does not exist would be offered
# nowhere, which is worse than being offered everywhere.
T=$(tok /livechat/canned)
post /livechat/canned --data-urlencode "_token=$T" --data-urlencode "id=$CID" \
     --data-urlencode "title=TEST Delivery" --data-urlencode "body=Still here." \
     --data-urlencode "department_id=999999" >/dev/null
eq "a department that does not exist becomes 'everywhere'" \
   "$(q "SELECT COUNT(*) FROM live_canned WHERE id=$CID AND department_id IS NULL;")" "1"

T=$(tok /livechat/canned)
eq "an empty reply is refused" \
   "$(post /livechat/canned --data-urlencode "_token=$T" --data-urlencode "title=TEST Nothing" \
        --data-urlencode "body=")" "302"
eq "and nothing was saved" "$(q "SELECT COUNT(*) FROM live_canned WHERE title='TEST Nothing';")" "0"

echo ""
echo "=== 3. A reply for one department is not offered in another ==="

q "INSERT INTO live_departments (name,slug,blurb,is_default,position,status)
   VALUES ('Replies Test','replies-dept','For the replies suite',0,92,'active');"
DEPT=$(q "SELECT id FROM live_departments WHERE slug='replies-dept';")
ADMIN=$(q "SELECT id FROM users WHERE email='admin@shanfix.co.ke';")
q "INSERT IGNORE INTO live_department_staff (department_id,user_id,is_lead) VALUES ($DEPT,$ADMIN,1);"

T=$(tok /livechat/canned)
post /livechat/canned --data-urlencode "_token=$T" \
     --data-urlencode "title=TEST Only here" \
     --data-urlencode "body=This belongs to one queue." \
     --data-urlencode "department_id=$DEPT" >/dev/null

# Open a conversation in the OTHER department and look at its dropdown.
start_chat "Which replies do I see?" "Reply Tester" >/dev/null
OTHER=$(newest)
DESK=$(page "/livechat/$OTHER")

has "a reply for every queue is offered here"  "$DESK" "Ask for the artwork"
case "$DESK" in
  *"TEST Only here"*) bad "one belonging to another queue is not" "offered" "hidden";;
  *)                  ok  "one belonging to another queue is not" "hidden";;
esac

echo ""
echo "=== 4. The count is real now ==="

# The desk orders the list by how often each reply is used, and nothing
# had ever incremented it — so the ordering never changed.
USES=$(q "SELECT uses FROM live_canned WHERE id=$CID;")
eq "it starts at nothing" "$USES" "0"

T=$(tok "/livechat/$OTHER")
eq "a reply sent from a saved one goes" \
   "$(post "/livechat/$OTHER/reply" --data-urlencode "_token=$T" \
        --data-urlencode "message=Still here." --data-urlencode "canned_id=$CID")" "302"
eq "and is counted"  "$(q "SELECT uses FROM live_canned WHERE id=$CID;")" "1"

# Counted even when the wording was changed first: what the number
# answers is "which of these is worth keeping".
T=$(tok "/livechat/$OTHER")
post "/livechat/$OTHER/reply" --data-urlencode "_token=$T" \
     --data-urlencode "message=Still here, but I have reworded it." \
     --data-urlencode "canned_id=$CID" >/dev/null
eq "an edited one still counts" "$(q "SELECT uses FROM live_canned WHERE id=$CID;")" "2"

T=$(tok "/livechat/$OTHER")
post "/livechat/$OTHER/reply" --data-urlencode "_token=$T" \
     --data-urlencode "message=Typed from scratch." >/dev/null
eq "a reply typed from scratch counts for nothing" \
   "$(q "SELECT uses FROM live_canned WHERE id=$CID;")" "2"

echo ""
echo "=== 5. Only management may change them ==="

HASH=$($PHP -r 'echo password_hash("ReplyPass1", PASSWORD_DEFAULT);')
q "DELETE FROM users WHERE email='replyagent@shanfix.co.ke';"
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Reply Agent','replyagent@shanfix.co.ke','$HASH','sales',1);"
AGENT=$(q "SELECT id FROM users WHERE email='replyagent@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($AGENT,'sales');"

AJ="$D/replies_agent.txt"
rm -f "$AJ"
JAR="$AJ" login_as "replyagent@shanfix.co.ke" "ReplyPass1" >/dev/null 2>&1

eq "somebody who answers chats cannot open the screen" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$AJ" "$BASE/livechat/canned")" "403"
eq "nor write a reply" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$AJ" -X POST "$BASE/livechat/canned" \
        --data-urlencode "title=TEST Sneaky" --data-urlencode "body=no")" "403"
eq "and nothing was written" "$(q "SELECT COUNT(*) FROM live_canned WHERE title='TEST Sneaky';")" "0"

echo ""
echo "=== 6. Closing a chat posts the customer their own conversation ==="

TOKEN=$(start_chat "What does an A1 poster cost?" "Transcript Tester" "customer@example.com" | token_of)
TC=$(newest)

T=$(tok "/livechat/$TC")
post "/livechat/$TC/reply" --data-urlencode "_token=$T" \
     --data-urlencode "message=An A1 poster is 1,200 shillings on 200gsm." >/dev/null

# A private note, in the same thread. This is the one that must not
# travel.
T=$(tok "/livechat/$TC")
post "/livechat/$TC/reply" --data-urlencode "_token=$T" \
     --data-urlencode "message=NOTE-DO-NOT-SEND they always haggle, hold the price" \
     --data-urlencode "note=1" >/dev/null

eq "nothing is sent while it is open" "$(transcripts_for "$TC")" "0"

T=$(tok "/livechat/$TC")
eq "closing it is accepted" \
   "$(post "/livechat/$TC/close" --data-urlencode "_token=$T" --data-urlencode "reason=Answered")" "302"

eq "a transcript is queued"  "$(transcripts_for "$TC")" "1"
eq "to the address they gave" \
   "$(q "SELECT recipient FROM notifications WHERE event='livechat_transcript' AND entity_id=$TC;")" \
   "customer@example.com"
eq "and the conversation is marked as sent" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$TC AND transcript_sent_at IS NOT NULL;")" "1"

BODY=$(transcript_body "$TC")
has "it carries what they asked"   "$BODY" "A1 poster cost"
has "and what we answered"         "$BODY" "1,200 shillings"
has "and the reference to quote"   "$BODY" "$(q "SELECT ref FROM live_conversations WHERE id=$TC;")"

echo ""
echo "=== 7. A private note is never in it ==="

# The whole reason this suite exists.
case "$BODY" in
  *NOTE-DO-NOT-SEND*) bad "the private note stayed behind" "sent" "not sent";;
  *)                  ok  "the private note stayed behind" "not sent";;
esac
case "$BODY" in
  *haggle*) bad "not a word of it travelled" "sent" "not sent";;
  *)        ok  "not a word of it travelled" "not sent";;
esac

echo ""
echo "=== 8. Once, and only when there is somewhere to send it ==="

# Closing again must not post a second copy.
T=$(tok "/livechat/$TC")
post "/livechat/$TC/reopen" --data-urlencode "_token=$T" >/dev/null
T=$(tok "/livechat/$TC")
post "/livechat/$TC/close" --data-urlencode "_token=$T" >/dev/null
eq "closing twice sends one transcript" "$(transcripts_for "$TC")" "1"

# A stranger who never said who they are is the ordinary case, not a
# fault: there is simply nowhere to write.
start_chat "No address from me" "Anon Tester" >/dev/null
AC=$(newest)
T=$(tok "/livechat/$AC")
post "/livechat/$AC/close" --data-urlencode "_token=$T" >/dev/null
eq "somebody who left no address gets nothing" "$(transcripts_for "$AC")" "0"
eq "and is not marked as sent to" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$AC AND transcript_sent_at IS NULL;")" "1"

echo ""
echo "=== 9. The visitor closing it works the same way ==="

VTOKEN=$(start_chat "I will close this myself" "Visitor Tester" "visitor@example.com" | token_of)
VC=$(newest)
T=$(tok "/livechat/$VC")
post "/livechat/$VC/reply" --data-urlencode "_token=$T" \
     --data-urlencode "message=Happy to help." >/dev/null

OUT=$(api_post close --data-urlencode "token=$VTOKEN")
has "the widget is told it was sent" "$OUT" '"transcript":true'
eq  "and it was"                     "$(transcripts_for "$VC")" "1"

echo ""
echo "=== 10. The office can switch it off ==="

q "UPDATE settings SET setting_value='0' WHERE setting_key='livechat_transcript_email';"

start_chat "Quiet close please" "Quiet Tester" "quiet@example.com" >/dev/null
QC=$(newest)
T=$(tok "/livechat/$QC")
post "/livechat/$QC/close" --data-urlencode "_token=$T" >/dev/null
eq "with it off, nothing is queued" "$(transcripts_for "$QC")" "0"

q "UPDATE settings SET setting_value='1' WHERE setting_key='livechat_transcript_email';"

# And it rides on the mail switch, like everything else that sends.
q "UPDATE settings SET setting_value='0' WHERE setting_key='smtp_enabled';"
start_chat "No mail server" "Mailless Tester" "nomail@example.com" >/dev/null
MC=$(newest)
T=$(tok "/livechat/$MC")
post "/livechat/$MC/close" --data-urlencode "_token=$T" >/dev/null
eq "with email switched off entirely, nothing is queued" "$(transcripts_for "$MC")" "0"
eq "and it is not marked sent, so it can go when email comes back" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE id=$MC AND transcript_sent_at IS NULL;")" "1"

q "UPDATE settings SET setting_value='1' WHERE setting_key='smtp_enabled';"

echo ""
echo "=== 11. The setting is on the screen that owns it ==="

PAGE=$(page /livechat/departments)
has "the transcript switch is there" "$PAGE" 'livechat_transcript_email'
has "and says notes are never included" "$PAGE" "Private notes are never included"

report
