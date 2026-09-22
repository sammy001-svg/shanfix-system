#!/bin/bash
# Live chat with people on the website.
#
# This replaced tawk.to, which means two things worth testing hard. The
# first is that the endpoint is genuinely public — no session, no CSRF —
# so it is the widest door in the system and most of what is asserted
# here is what it refuses. The second is that departments decide who
# reads what: a conversation for Sales must not appear in the inbox of
# somebody who only answers Support.
#
# The one property everything else rests on: a private note is for
# colleagues and must never reach the visitor.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

CJ="$D/livechat.txt"
LJ="$D/livechat_agent.txt"

# The visitor has no cookie jar at all — that is the point of the token.
api_post() { local a="$1"; shift; curl -s -X POST "$BASE/api/chat/$a" "$@"; }
api_get()  { local a="$1"; shift; curl -s "$BASE/api/chat/$a" "$@"; }

# Start a conversation and print its token.
start_chat() {
  api_post start --data-urlencode "message=$1" \
                 --data-urlencode "name=${2:-Tester}" \
                 --data-urlencode "department=${3:-sales}" \
    | sed 's/.*"token":"\([^"]*\)".*/\1/'
}

# What the visitor holding $1 can see, one body per line.
visitor_sees() {
  api_get "poll?token=$1&after=0" \
    | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true);
               foreach ($d["messages"] ?? [] as $m) echo $m["body"], "\n";'
}

# What staff see on the same conversation, notes included.
staff_sees() {
  curl -s -b "$JAR" "$BASE/livechat/poll?conversation=$1&after=0" \
    | $PHP -r '$d=json_decode(stream_get_contents(STDIN),true);
               foreach ($d["messages"] ?? [] as $m) echo ($m["note"] ? "NOTE " : ""), $m["body"], "\n";'
}

newest_id() { q "SELECT id FROM live_conversations ORDER BY id DESC LIMIT 1;"; }

# Chat has to be on for any of this, and the hours setting decides
# whether the visitor is told we are away. Both are global, so they are
# put back at the end — a suite that leaves a switch flipped fails the
# next one for reasons that have nothing to do with its code.
# Joined with ';', not the default ','. livechat_hours_days is itself a
# comma list (1,2,3,4,5,6); splitting on commas used to restore it as
# just "1", which left the office "open" on Mondays only.
BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value) SEPARATOR ';') FROM settings
             WHERE setting_key IN ('livechat_enabled','livechat_ask_department',
                                   'livechat_hours_from','livechat_hours_to','livechat_hours_days');")

restore() {
  local pair key val
  for pair in $(echo "$BEFORE" | tr ';' ' '); do
    key="${pair%%=*}"; val="${pair#*=}"
    q "UPDATE settings SET setting_value='$val' WHERE setting_key='$key';"
  done
  q "DELETE FROM live_conversations WHERE ref LIKE 'C%' AND visitor_name IN
       ('Tester','Queue Tester','Sales Only','Support Only','Rater','Longwinded');"
  q "DELETE FROM live_departments WHERE slug LIKE 'test-%';"
  # The stand-in agent built in section 5, so the next run starts from
  # the same place this one did.
  q "DELETE FROM users WHERE email='salesonly@shanfix.co.ke';"
}
trap restore EXIT

# This suite starts a good many conversations in one minute, which is
# exactly what the rate limit exists to stop. Clearing the counters keeps
# a second run in the same minute from failing on the first run's
# arithmetic rather than on the code.
q "DELETE FROM bulk_rate_counters WHERE bucket LIKE 'livechat:%';"

q "UPDATE settings SET setting_value='1' WHERE setting_key='livechat_enabled';"
q "UPDATE settings SET setting_value='1' WHERE setting_key='livechat_ask_department';"

signin_admin

echo ""
echo "=== 1. The door a stranger knocks on ==="

HELLO=$(api_get hello)
has "chat says it is on"          "$HELLO" '"enabled":true'
has "and offers a greeting"       "$HELLO" '"greeting"'
has "and lists its departments"   "$HELLO" '"departments"'
has "Sales is one of them"        "$HELLO" 'Sales'

# No session and no CSRF token anywhere in this section: a visitor on a
# marketing page has neither, and demanding one would mean nobody could
# ever start a chat.
TOKEN=$(start_chat "What does an A2 poster cost?" "Tester" "sales")
eq "a stranger can start one, with no session at all" "${#TOKEN}" "64"

CID=$(newest_id)
eq "it lands in the department they chose" \
   "$(q "SELECT d.slug FROM live_conversations c JOIN live_departments d ON d.id=c.department_id WHERE c.id=$CID;")" \
   "sales"
eq "and starts off waiting for a human" \
   "$(q "SELECT status FROM live_conversations WHERE id=$CID;")" "waiting"

echo ""
echo "=== 2. The token is the only key ==="

# Only the hash is stored, so a leaked database does not open anybody's
# conversation.
eq "the raw token is nowhere in the table" \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE token_hash='$TOKEN';")" "0"
eq "only its hash is"  \
   "$(q "SELECT COUNT(*) FROM live_conversations WHERE token_hash=SHA2('$TOKEN',256);")" "1"

BAD=$(api_get "poll?token=$(printf 'a%.0s' {1..64})&after=0")
has "a made-up token opens nothing" "$BAD" '"ok":false'

# The same answer for a wrong token as for a missing conversation, so
# guessing cannot be used to learn which ones exist.
has "and is told nothing about what does exist" "$BAD" 'expired'

eq "a short token is refused outright" \
   "$(api_get 'poll?token=abc&after=0' | grep -c '"ok":false')" "1"

echo ""
echo "=== 3. Answering, and who may ==="

TOK=$(tok "/livechat/$CID")
post "/livechat/$CID/claim" --data "_token=$TOK" > /dev/null
eq "an agent can take a conversation" \
   "$(q "SELECT assigned_user_id IS NOT NULL FROM live_conversations WHERE id=$CID;")" "1"

TOK=$(tok "/livechat/$CID")
post "/livechat/$CID/reply" --data "_token=$TOK" \
     --data-urlencode "message=A2 posters are KES 350 each." > /dev/null

has "the reply reaches the visitor" "$(visitor_sees "$TOKEN")" "KES 350"
eq "answering opens the conversation" \
   "$(q "SELECT status FROM live_conversations WHERE id=$CID;")" "open"
eq "and the wait is timed, once" \
   "$(q "SELECT first_reply_at IS NOT NULL FROM live_conversations WHERE id=$CID;")" "1"

FIRST=$(q "SELECT first_reply_at FROM live_conversations WHERE id=$CID;")
TOK=$(tok "/livechat/$CID")
post "/livechat/$CID/reply" --data "_token=$TOK" --data-urlencode "message=Anything else?" > /dev/null
eq "a later reply does not rewrite how long they waited" \
   "$(q "SELECT first_reply_at FROM live_conversations WHERE id=$CID;")" "$FIRST"

# The desk was built without a script: replies needed a page load, Enter
# did not send, and nothing new appeared until a refresh. Typing and
# pressing Enter is only provable in a browser; this pins the hooks the
# script needs, and that a reply posted the way the script posts it
# answers JSON rather than redirecting.
has "the desk hands its script a poll address" "$(page "/livechat/$CID")" "data-desk"
TOK=$(tok "/livechat/$CID")
DESKREPLY=$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/livechat/$CID/reply" -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" --data "_token=$TOK" --data-urlencode "message=Sent by the desk script.")
has "a reply sent from the desk answers without a reload" "$DESKREPLY" '"ok":true'

echo ""
echo "=== 4. A private note is private ==="

TOK=$(tok "/livechat/$CID")
post "/livechat/$CID/reply" --data "_token=$TOK" --data "note=1" \
     --data-urlencode "message=Margin is thin on these, do not discount." > /dev/null

# The property the whole feature rests on. If this ever fails, somebody's
# internal remark about a customer has been shown to that customer.
eq "the visitor cannot see it" \
   "$(visitor_sees "$TOKEN" | grep -c 'Margin is thin')" "0"
eq "but colleagues can" \
   "$(staff_sees "$CID" | grep -c 'NOTE Margin is thin')" "1"

eq "and it does not count as answering them" \
   "$(q "SELECT first_reply_at FROM live_conversations WHERE id=$CID;")" "$FIRST"

echo ""
echo "=== 5. Departments decide who reads what ==="

q "INSERT INTO live_departments (name, slug, blurb, is_default, position, status)
   VALUES ('Test Support','test-support','Testing only',0,90,'active');"
TDEPT=$(q "SELECT id FROM live_departments WHERE slug='test-support';")

SUP_TOKEN=$(start_chat "My banner arrived torn." "Support Only" "test-support")
SUP_CID=$(newest_id)

eq "it lands in the right queue" \
   "$(q "SELECT department_id FROM live_conversations WHERE id=$SUP_CID;")" "$TDEPT"

# An administrator holds livechat.view_all and is meant to see every
# queue — that is how somebody watches the whole thing.
eq "an administrator sees every department" "$(code "/livechat/$SUP_CID")" "200"

# Somebody who answers only Sales must not reach it. Built by hand here
# rather than trusting a fixture to have the right shape.
q "INSERT IGNORE INTO users (name,email,password_hash,role,is_active,created_at)
   VALUES ('Sales Only Agent','salesonly@shanfix.co.ke','$(q "SELECT password_hash FROM users WHERE email='$ADMIN_EMAIL';")','sales',1,NOW());"
AGENT=$(q "SELECT id FROM users WHERE email='salesonly@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id, role) VALUES ($AGENT,'sales');"
SALES_DEPT=$(q "SELECT id FROM live_departments WHERE slug='sales';")
q "DELETE FROM live_department_staff WHERE user_id=$AGENT;"
q "INSERT INTO live_department_staff (department_id,user_id) VALUES ($SALES_DEPT,$AGENT);"

JAR="$LJ"; rm -f "$JAR"
T=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
     --data "_token=$T&email=salesonly@shanfix.co.ke&password=$ADMIN_PASS" > /dev/null

eq "a sales agent reaches the sales one"      "$(code "/livechat/$CID")"     "200"
ne "but not one belonging to another department" "$(code "/livechat/$SUP_CID")" "200"

# Refused with the same answer as something that does not exist, so the
# inbox cannot be used to count other departments' conversations.
eq "and is told it does not exist, not that it is forbidden" \
   "$(code "/livechat/$SUP_CID")" "404"

eq "their queue counts only their own departments" \
   "$(curl -s -b "$JAR" "$BASE/livechat/waiting" | grep -c '"ok":true')" "1"

JAR="$D/jar_admin.txt"

echo ""
echo "=== 6. Moving one to the right department ==="

TOK=$(tok "/livechat/$SUP_CID")
post "/livechat/$SUP_CID/transfer" --data "_token=$TOK" --data "department_id=$SALES_DEPT" > /dev/null

eq "it moves"  "$(q "SELECT department_id FROM live_conversations WHERE id=$SUP_CID;")" "$SALES_DEPT"
eq "and is released, since whoever had it is probably not in the new one" \
   "$(q "SELECT assigned_user_id IS NULL FROM live_conversations WHERE id=$SUP_CID;")" "1"
has "the visitor is told, rather than hearing a stranger" \
    "$(visitor_sees "$SUP_TOKEN")" "Passed to"

echo ""
echo "=== 7. What it will not accept ==="

EMPTY=$(api_post start --data-urlencode "message=   ")
has "an empty message starts nothing" "$EMPTY" '"ok":false'

# 4000 is the cap; this is well past it and must be trimmed rather than
# rejected, because a long question is still a question.
LONG=$(printf 'x%.0s' {1..5000})
LONGTOK=$(api_post start --data-urlencode "message=$LONG" --data-urlencode "name=Longwinded" \
          | sed 's/.*"token":"\([^"]*\)".*/\1/')
eq "an over-long one is cut to the cap, not thrown away" \
   "$(q "SELECT CHAR_LENGTH(body) FROM live_messages WHERE conversation_id=$(newest_id) ORDER BY id LIMIT 1;")" \
   "4000"

# The bug this catches: stripping control characters with the /u flag
# returns null on malformed input, which silently turned a visitor's
# whole message into an empty one and told them it was blank.
ODDTOK=$(api_post start --data-binary $'message=Torn \xc3\x28 banner&name=Tester&department=sales' \
         -H 'Content-Type: application/x-www-form-urlencoded' \
         | sed 's/.*"token":"\([^"]*\)".*/\1/')
eq "a malformed character does not swallow the message" \
   "$(q "SELECT COUNT(*) FROM live_messages WHERE conversation_id=$(newest_id) AND body LIKE '%banner%';")" "1"

# Nothing the visitor types is ever trusted as markup.
XSSTOK=$(start_chat '<script>alert(1)</script>' "Tester" "sales")
XSS_CID=$(newest_id)
eq "a script tag is stored as text, not as markup" \
   "$(q "SELECT COUNT(*) FROM live_messages WHERE conversation_id=$XSS_CID AND body LIKE '%<script>%';")" "1"
eq "and comes back escaped on the staff page" \
   "$(page "/livechat/$XSS_CID" | grep -c '<script>alert(1)</script>')" "0"

echo ""
echo "=== 8. Closing, and coming back ==="

TOK=$(tok "/livechat/$CID")
post "/livechat/$CID/close" --data "_token=$TOK" > /dev/null
eq "an agent can close it" "$(q "SELECT status FROM live_conversations WHERE id=$CID;")" "closed"

# Somebody who thinks of one more thing should not have to start again
# and re-explain themselves to a different person.
api_post send --data-urlencode "token=$TOKEN" --data-urlencode "message=Sorry, one more thing." > /dev/null
eq "a closed conversation reopens when they write again" \
   "$(q "SELECT status FROM live_conversations WHERE id=$CID;")" "waiting"

echo ""
echo "=== 9. Switched off means off ==="

q "UPDATE settings SET setting_value='0' WHERE setting_key='livechat_enabled';"

has "the widget is told not to draw itself" "$(api_get hello)" '"enabled":false'
has "and starting one is refused"           "$(api_post start --data-urlencode 'message=hello')" '"ok":false'

q "UPDATE settings SET setting_value='1' WHERE setting_key='livechat_enabled';"

echo ""
echo "=== 10. The website carries our widget, not somebody else's ==="

HOME=$(curl -s "$BASE/")
eq "tawk.to is gone from the site"   "$(echo "$HOME" | grep -ci 'tawk')" "0"
has "our widget is loaded instead"   "$HOME" "livechat.js"
has "with its own styles"            "$HOME" "livechat.css"

eq "the widget script is served"     "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/livechat.js")"  "200"
eq "and its stylesheet"              "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/livechat.css")" "200"

# The visitor's token must never travel in a URL a browser would keep in
# its history or hand to the next site in a referrer header.
eq "the widget never puts the token in a link" \
   "$(grep -c "location.*token" "$ROOT/site/livechat.js")" "0"

report
