#!/bin/bash
# Asking to see a system, and being sent a login.
#
# The flow crosses both applications and ends by sending somebody
# outside the company a working login to a running system, so what is
# asserted here is mostly about that last part: who may do it, what
# actually reaches the client, and what is kept afterwards.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

site()  { curl -s --max-time 25 "$BASE$1"; }
scode() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$BASE$1"; }

api() {
  curl -s --max-time 25 -X POST "$BASE/api/demo-request.php" \
       -H 'Content-Type: application/json' -d "$1"
}

MYSQL_TECH="${MYSQL_CLIENT} -u ${DBUSER} ${SITE_DB:-shanfix_tech}"

scrub() {
  q "DELETE FROM demo_requests WHERE email LIKE '%@demotest.invalid';
     DELETE FROM leads         WHERE email LIKE '%@demotest.invalid';
     DELETE FROM notifications WHERE event = 'demo_ready'
            AND recipient LIKE '%demotest.invalid%';
     DELETE FROM notifications WHERE event = 'demo_ready' AND recipient = '254711000111';
     DELETE FROM staff_notifications WHERE event = 'demo_request';"
  $MYSQL_TECH -e "DELETE FROM demo_requests WHERE email LIKE '%@demotest.invalid';" 2>/dev/null
}

scrub

# Both channels on, so what is skipped is a real skip rather than a
# switch somebody left off. Put back at the end.
SMTP_WAS=$(q "SELECT setting_value FROM settings WHERE setting_key='smtp_enabled';")
SMS_WAS=$(q "SELECT setting_value FROM settings WHERE setting_key='sms_enabled';")
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key IN ('smtp_enabled','sms_enabled');"

signin_admin

echo ""
echo "=== 1. The page, and the button on it ==="

eq "the sacco page serves" "$(scode /sacco.php)" "200"

PAGE=$(site /sacco.php)
has "it is about Sacco Pro"        "$PAGE" "Sacco Pro"
has "there is a demo button"       "$PAGE" "data-demo-open"
has "and a form behind it"         "$PAGE" 'id="demoForm"'
has "loading the shared script"    "$PAGE" "demo-request.js"
has "which posts to the endpoint"  "$(cat "$ROOT/site/demo-request.js")" "api/demo-request.php"

# It says what it is going to do with the phone number, on the form, at
# the moment somebody is deciding whether to give it.
has "it says the login is texted" "$PAGE" "by email and by text"

# The menu used to point at an anchor that does not exist.
HEAD=$(cat "$ROOT/site/includes/header.php")
case "$HEAD" in
  *"software-solution.php#sacco"*) bad "the menu link is not a dead anchor" "still dead" "fixed" ;;
  *)                               ok  "the menu link is not a dead anchor" "fixed" ;;
esac
has "and points at the page" "$HEAD" 'href="sacco.php"'

echo ""
echo "=== 1b. Every system we sell can be asked for ==="

# One dialog, four pages. It was written into the Sacco page first;
# copying it would have meant four copies of the form, the validation
# and the script, and the first fix to any of them landing in one copy.
for spec in "sacco.php|sacco|Sacco Pro|members" \
            "school-management.php|school|School Management|pupils" \
            "pos-solution.php|pos|Point of Sale|tills" \
            "erp-solution.php|erp|Business ERP|people"; do
  PAGE="${spec%%|*}"; REST="${spec#*|}"
  KEY="${REST%%|*}";  REST="${REST#*|}"
  LABEL="${REST%%|*}"; UNIT="${REST#*|}"

  BODY=$(site "/$PAGE")

  eq  "$PAGE serves"              "$(scode "/$PAGE")" "200"
  has "  it has a demo button"    "$BODY" "data-demo-open"
  has "  and the shared dialog"   "$BODY" 'id="demoModal"'
  has "  asking for $KEY"         "$BODY" "name=\"product\" value=\"$KEY\""
  has "  named $LABEL"            "$BODY" "Request a $LABEL demo"

  # Each product asks the size question in its own units. A school
  # counts pupils and a shop counts tills; one list for all four was
  # how the sacco's wording ended up on every page.
  has "  counting $UNIT"          "$BODY" "$UNIT"
done

# The dialog brings its own stylesheet and script, so a page gets a
# working one by including the partial and has nothing else to remember.
SACCO=$(site /sacco.php)
has "the dialog carries its styles" "$SACCO" "demo-request.css"
has "and its script"                "$SACCO" "demo-request.js"

# One copy of each, not four.
eq "the form is written once" \
   "$(grep -lc 'id=\"demoForm\"' "$ROOT"/site/*.php 2>/dev/null | wc -l)" "0"
has "and lives in the shared partial" \
   "$(cat "$ROOT/site/includes/demo_request.php")" 'id="demoForm"'

# The dialog does not borrow its buttons from whichever page included
# it — the Sacco page is dark and the next one may not be.
has "it has buttons of its own" \
   "$(cat "$ROOT/site/demo-request.css")" ".demo-btn"

echo ""
echo "=== 2. A request comes in ==="

OUT=$(api '{"product":"sacco","name":"DEMOTEST Grace","organisation":"DEMOTEST Sacco","role":"Treasurer","email":"grace@demotest.invalid","phone":"0711000111","members":"500 to 2,000 members","message":"DEMOTEST how check-off posts","source":"sacco.php"}')

has "the form accepts it"   "$OUT" '"success":true'
has "and quotes a reference" "$OUT" "DEMO-"

DID=$(q "SELECT id FROM demo_requests WHERE email='grace@demotest.invalid';")
ne "it is stored in the system" "$DID" ""
eq "and starts as waiting" "$(q "SELECT status FROM demo_requests WHERE id=$DID;")" "new"

# The band is kept as the words the page offered. A fixed list in the
# system would have to agree with four forms in four files, and the
# first time it did not the size would be silently dropped.
eq "the size is kept as it was asked" \
   "$(q "SELECT members FROM demo_requests WHERE id=$DID;")" "500 to 2,000 members"

# It becomes a lead as well, so it is on the board people actually work
# from rather than in a list somebody has to remember to open.
eq "a lead was raised beside it" \
   "$(q "SELECT COUNT(*) FROM leads WHERE email='grace@demotest.invalid' AND source='website';")" "1"
eq "and the two are joined up" \
   "$(q "SELECT COUNT(*) FROM demo_requests d JOIN leads l ON l.id = d.lead_id WHERE d.id=$DID;")" "1"

# Somebody is told, or it sits there.
eq "sales were told" \
   "$(q "SELECT COUNT(*) > 0 FROM staff_notifications WHERE event='demo_request' AND entity_id=$DID;")" "1"

# The site keeps its own copy, so a request is not lost if the system is
# down when it arrives.
eq "the site kept its own copy" \
   "$($MYSQL_TECH -N -e "SELECT COUNT(*) FROM demo_requests WHERE email='grace@demotest.invalid';" 2>/dev/null)" "1"

echo ""
echo "=== 3. What the form refuses ==="

has "no name is refused"  "$(api '{"name":"","email":"a@demotest.invalid","phone":"0711000111"}')" '"success":false'
has "no phone is refused" "$(api '{"name":"DEMOTEST X","email":"a@demotest.invalid","phone":""}')" '"success":false'
has "a bad email is refused" \
    "$(api '{"name":"DEMOTEST X","email":"not-an-email","phone":"0711000111"}')" '"success":false'
has "a number that cannot be texted is refused" \
    "$(api '{"name":"DEMOTEST X","email":"a@demotest.invalid","phone":"123"}')" '"success":false'

# A robot fills every field it finds. Accepted and dropped, because
# telling it that it failed only teaches it to try again.
BOT=$(api '{"name":"DEMOTEST Bot","email":"bot@demotest.invalid","phone":"0711000222","website":"http://spam.example"}')
has "the honeypot answers politely" "$BOT" '"success":true'
eq  "and nothing is stored" \
    "$(q "SELECT COUNT(*) FROM demo_requests WHERE email='bot@demotest.invalid';")" "0"

echo ""
echo "=== 4. Only an administrator hands out a login ==="

# Reading them is sales work.
HASH=$($PHP -r 'echo password_hash("DemoPass1", PASSWORD_DEFAULT);')
q "DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE email='demosales@shanfix.co.ke');
   DELETE FROM users WHERE email='demosales@shanfix.co.ke';
   INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('DEMOTEST Sales','demosales@shanfix.co.ke','$HASH','sales',1);"
SUID=$(q "SELECT id FROM users WHERE email='demosales@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($SUID,'sales');"

SJ="$D/jar_demosales.txt"; rm -f "$SJ"
JAR="$SJ" login_as "demosales@shanfix.co.ke" "DemoPass1" > /dev/null 2>&1

eq "sales can read the list" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/demos")" "200"
eq "and open one" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/demos/$DID")" "200"

# But not send a login to it.
T=$(curl -s -b "$SJ" -c "$SJ" "$BASE/demos/$DID" \
    | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$SJ" -c "$SJ" -X POST "$BASE/demos/$DID/accept" \
     --data-urlencode "_token=$T" \
     --data-urlencode "demo_url=sneaky.example.com" \
     --data-urlencode "demo_username=sneak" \
     --data-urlencode "demo_password=Sneak123" > /dev/null

eq "sales cannot send one" "$(q "SELECT status FROM demo_requests WHERE id=$DID;")" "new"

echo ""
echo "=== 5. Accepting it sends the login, both ways ==="

signin_admin
T=$(tok "/demos/$DID")
post "/demos/$DID/accept" --data-urlencode "_token=$T" \
     --data-urlencode "demo_url=demo.saccopro.co.ke" \
     --data-urlencode "demo_username=demotest.user" \
     --data-urlencode "demo_password=DemoTest#2026" \
     --data-urlencode "days=14" > /dev/null

eq "it is marked as sent"   "$(q "SELECT status FROM demo_requests WHERE id=$DID;")" "accepted"
eq "with a send time on it" "$(q "SELECT sent_at IS NOT NULL FROM demo_requests WHERE id=$DID;")" "1"

# A bare domain is published as a link that opens. Without this it is a
# relative link to a page on our own site.
eq "a bare domain became a real link" \
   "$(q "SELECT demo_url FROM demo_requests WHERE id=$DID;")" "https://demo.saccopro.co.ke"

ne "and it has an expiry" "$(q "SELECT expires_on FROM demo_requests WHERE id=$DID;")" ""

# Both channels, which is the whole point.
eq "an email was queued" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='demo_ready' AND channel='email' AND entity_id=$DID;")" "1"
eq "and a text"  \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='demo_ready' AND channel='sms' AND entity_id=$DID;")" "1"

echo ""
echo "=== 6. What the client actually receives ==="

# The e-mail used to say "the details are below" and carry only the
# link: the generic template renders document details, and a demo login
# is not one.
for part in "demo.saccopro.co.ke" "demotest.user" "DemoTest#2026"; do
  eq "the email carries $part" \
     "$(q "SELECT COUNT(*) FROM notifications WHERE event='demo_ready' AND channel='email' AND entity_id=$DID AND body LIKE '%$part%';")" "1"
done

eq "and says the demo expires" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='demo_ready' AND channel='email' AND entity_id=$DID AND body LIKE '%Good until%';")" "1"
eq "and that it is a demo, not a real account" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='demo_ready' AND channel='email' AND entity_id=$DID AND body LIKE '%demonstration account%';")" "1"

# The text has to carry the same three things in far less room.
SMS=$(q "SELECT body FROM notifications WHERE event='demo_ready' AND channel='sms' AND entity_id=$DID;")
for part in "demo.saccopro.co.ke" "demotest.user" "DemoTest#2026"; do
  has "the text carries $part" "$SMS" "$part"
done

echo ""
echo "=== 7. What is kept, and what is not ==="

# A dump of this table must not be a list of live demo logins.
RAW=$(q "SELECT CAST(demo_password AS CHAR) FROM demo_requests WHERE id=$DID;")
case "$RAW" in
  *"DemoTest#2026"*) bad "the password is encrypted at rest" "plaintext" "ciphertext" ;;
  "")                bad "the password is encrypted at rest" "empty" "ciphertext" ;;
  *)                 ok  "the password is encrypted at rest" "not plaintext" ;;
esac

# The log records that a demo went out, not what was in it.
eq "and never written to the activity log" \
   "$(q "SELECT COUNT(*) FROM activity_log WHERE description LIKE '%DemoTest#2026%';")" "0"
eq "though the log does say one was sent" \
   "$(q "SELECT COUNT(*) > 0 FROM activity_log WHERE action='demo_accepted' AND entity_id=$DID;")" "1"

# Nor shown back on the screen once it has gone.
case "$(page "/demos/$DID")" in
  *"DemoTest#2026"*) bad "nor shown again on the screen" "shown" "hidden" ;;
  *)                 ok  "nor shown again on the screen" "hidden" ;;
esac

echo ""
echo "=== 8. Sending it again, and turning one down ==="

# People lose e-mails. This is the only reason the password is kept.
q "DELETE FROM notifications WHERE event='demo_ready' AND entity_id=$DID;"
post "/demos/$DID/resend" --data "_token=$(tok "/demos/$DID")" > /dev/null

eq "the same details can be sent again" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='demo_ready' AND entity_id=$DID AND body LIKE '%DemoTest#2026%';")" "2"

# Already sent, so accepting again is refused rather than quietly
# issuing a second login for the same request.
T=$(tok "/demos/$DID")
post "/demos/$DID/accept" --data-urlencode "_token=$T" \
     --data-urlencode "demo_url=other.example.com" \
     --data-urlencode "demo_username=other" \
     --data-urlencode "demo_password=Other123" > /dev/null
eq "accepting a sent one does not change it" \
   "$(q "SELECT demo_username FROM demo_requests WHERE id=$DID;")" "demotest.user"

# Turning one down tells the client nothing. A demo refused is a phone
# call, not a form letter.
OUT2=$(api '{"product":"sacco","name":"DEMOTEST Turned Down","email":"no@demotest.invalid","phone":"0711000333"}')
NID=$(q "SELECT id FROM demo_requests WHERE email='no@demotest.invalid';")
post "/demos/$NID/decline" --data-urlencode "_token=$(tok "/demos/$NID")" \
     --data-urlencode "reason=DEMOTEST a competitor" > /dev/null

eq "it is marked declined" "$(q "SELECT status FROM demo_requests WHERE id=$NID;")" "declined"
eq "and nothing was sent"  "$(q "SELECT COUNT(*) FROM notifications WHERE event='demo_ready' AND entity_id=$NID;")" "0"

echo ""
echo "=== 9. A demo that has run out ==="

q "UPDATE demo_requests SET expires_on = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=$DID;"
page /demos > /dev/null
eq "an expired demo stops saying it is live" \
   "$(q "SELECT status FROM demo_requests WHERE id=$DID;")" "expired"

echo ""
echo "=== 10. It is in the menu, under Sales ==="

NAV=$(page /dashboard)
has "the sidebar offers it" "$NAV" "Demo requests"
has "pointing at the page"  "$NAV" '/demos'

echo ""
echo "=== 11. Tidy up ==="

scrub
q "DELETE FROM user_roles WHERE user_id = $SUID;
   DELETE FROM users WHERE id = $SUID;
   UPDATE settings SET setting_value='$SMTP_WAS' WHERE setting_key='smtp_enabled';
   UPDATE settings SET setting_value='$SMS_WAS'  WHERE setting_key='sms_enabled';"

eq "the test requests are gone" \
   "$(q "SELECT COUNT(*) FROM demo_requests WHERE email LIKE '%@demotest.invalid';")" "0"
eq "and the test account"  \
   "$(q "SELECT COUNT(*) FROM users WHERE email='demosales@shanfix.co.ke';")" "0"
eq "and the switches are back" \
   "$(q "SELECT setting_value FROM settings WHERE setting_key='smtp_enabled';")" "$SMTP_WAS"

report
