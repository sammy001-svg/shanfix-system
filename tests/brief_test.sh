#!/bin/bash
# Job detail requests: asking a client what they want, and getting it back.
#
# The thing worth guarding is the round trip. Staff raise a request and
# choose the brief; the client opens a link with no login and answers in
# their own words; those answers land on the client's profile. Every step
# of that has to hold, and the token has to be the only thing standing
# between a stranger and somebody else's brief.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

W="${LOCALAPPDATA:-$HOME}/Temp"
mkdir -p "$W" 2>/dev/null

# curl on Windows cannot read a Git Bash /tmp path, so the sample file has
# to live somewhere the Windows binary can see.
SAMPLE="$W/brief_sample.png"
$PHP "$(dirname "${BASH_SOURCE[0]}")/make_png.php" "$SAMPLE" > /dev/null 2>&1

$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key IN ('smtp_enabled','sms_enabled');
           DELETE FROM activity_log WHERE action='login_failed';"

CLIENT=$(q "SELECT id FROM clients WHERE email <> '' AND phone <> '' ORDER BY id LIMIT 1;")

echo ""
echo "=== 1. Raising one from a client's profile ==="
signin_admin
PROFILE=$(page "/clients/$CLIENT")
has "the profile offers it"     "$PROFILE" "Ask for job details"
has "all three briefs offered"  "$PROFILE" 'name="brief_type"'

BEFORE=$(q "SELECT COUNT(*) FROM job_requests;")
eq "raise a website brief" \
   "$(post /requests --data "_token=$(tok "/clients/$CLIENT")&client_id=$CLIENT&brief_type=website&title=Suite website&note=Internal only")" \
   "302"
eq "it exists" "$(( $(q "SELECT COUNT(*) FROM job_requests;") - BEFORE ))" "1"

RID=$(q "SELECT id FROM job_requests ORDER BY id DESC LIMIT 1;")
TOKEN=$(q "SELECT public_token FROM job_requests WHERE id=$RID;")
eq "it starts as a draft"   "$(q "SELECT status FROM job_requests WHERE id=$RID;")" "draft"
eq "it has a 48-char token" "${#TOKEN}" "48"
eq "it is numbered"         "$(q "SELECT reference REGEXP '^JDR-[0-9]{4}-[0-9]{4}$' FROM job_requests WHERE id=$RID;")" "1"

# A rubbish type must not create anything.
BEFORE=$(q "SELECT COUNT(*) FROM job_requests;")
post /requests --data "_token=$(tok "/clients/$CLIENT")&client_id=$CLIENT&brief_type=nonsense" > /dev/null
eq "an unknown brief type is refused" "$(q "SELECT COUNT(*) FROM job_requests;")" "$BEFORE"

echo ""
echo "=== 2. Sending it to the client ==="
$MYSQL -e "DELETE FROM notifications WHERE event='job_request';"
eq "send by email and text" \
   "$(post "/requests/$RID/send" --data "_token=$(tok "/requests/$RID")&channels[]=email&channels[]=sms")" "302"
eq "an email is queued"  "$(q "SELECT COUNT(*) FROM notifications WHERE event='job_request' AND channel='email';")" "1"
eq "a text is queued"    "$(q "SELECT COUNT(*) FROM notifications WHERE event='job_request' AND channel='sms';")" "1"
eq "it is marked sent"   "$(q "SELECT status FROM job_requests WHERE id=$RID;")" "sent"

has "the email carries the link" \
    "$(q "SELECT body FROM notifications WHERE event='job_request' AND channel='email' LIMIT 1;")" "/brief/$TOKEN"

# The full link alone pushes a routine text over one billable part.
has "the text uses the short link" \
    "$(q "SELECT body FROM notifications WHERE event='job_request' AND channel='sms' LIMIT 1;")" "/b/${TOKEN:0:10}"
eq  "and fits one message" \
    "$(q "SELECT IF(CHAR_LENGTH(body)<=160,'yes','no') FROM notifications WHERE event='job_request' AND channel='sms' LIMIT 1;")" "yes"

echo ""
echo "=== 3. The client opens it, with no login ==="
CJ="$D/brief_client.txt"; rm -f "$CJ"
eq "the link opens"        "$(curl -s -o /dev/null -w '%{http_code}' -c "$CJ" "$BASE/brief/$TOKEN")" "200"
eq "the short link too"    "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/b/${TOKEN:0:10}")" "200"
eq "we know they saw it"   "$(q "SELECT IF(opened_at IS NULL,'no','yes') FROM job_requests WHERE id=$RID;")" "yes"
eq "and it says so"        "$(q "SELECT status FROM job_requests WHERE id=$RID;")" "opened"

FORM=$(curl -s -b "$CJ" "$BASE/brief/$TOKEN")
has "they get the website questions" "$FORM" "About the website you need"
has "and can attach files"           "$FORM" 'name="attachments[]"'

# The token is the only credential, so a wrong one must give nothing away.
for bad in deadbeef 000000000000000000000000000000000000000000000000 "../../config/config"; do
  ne "a wrong token gets nothing: $bad" \
     "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/brief/$bad")" "200"
done

echo ""
echo "=== 4. They answer it ==="
CT=$(curl -s -b "$CJ" -c "$CJ" "$BASE/brief/$TOKEN" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')

# Skipping a required question must not go through, and must not throw
# away everything else they typed — on a phone that is where people give up.
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/brief/$TOKEN" \
     --data "_token=$CT&answers[domain]=keepthisvalue.co.ke"
eq "an incomplete form is refused" "$(q "SELECT IFNULL(submitted_at,'no') FROM job_requests WHERE id=$RID;")" "no"
BACK=$(curl -s -b "$CJ" -c "$CJ" "$BASE/brief/$TOKEN")
has "it says what is missing"      "$BACK" "Please answer:"
has "and keeps what they typed"    "$BACK" "keepthisvalue.co.ke"

CT=$(echo "$BACK" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
eq "a complete one goes through" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE/brief/$TOKEN" \
      -F "_token=$CT" \
      -F "answers[site_type]=Company website" \
      -F "answers[business_name]=Acme Holdings Ltd" \
      -F "answers[what_you_do]=We import building materials." \
      -F "answers[features][]=Contact form" \
      -F "answers[features][]=M-Pesa payments" \
      -F "answers[budget]=150,000" \
      -F "attachments[]=@$SAMPLE")" "302"

eq "it is marked answered" "$(q "SELECT status FROM job_requests WHERE id=$RID;")" "submitted"
# Five rows, not six: the two ticked features are one answer, which the
# next assertion proves.
eq "the answers are stored" \
   "$(q "SELECT COUNT(*) FROM job_request_answers WHERE request_id=$RID AND answer IS NOT NULL;")" "5"

# A group of tickboxes has to read back as one answer, not several rows.
eq "tickboxes read back as one answer" \
   "$(q "SELECT answer FROM job_request_answers WHERE request_id=$RID AND field_key='features';")" \
   "Contact form, M-Pesa payments"

eq "the attachment is kept"  "$(q "SELECT COUNT(*) FROM job_request_files WHERE request_id=$RID;")" "1"
eq "with its own name"       "$(q "SELECT original_name FROM job_request_files WHERE request_id=$RID;")" "brief_sample.png"

# The question is stored with the answer, so an old brief still reads
# correctly after the wording of a question has been changed.
has "the question is kept with the answer" \
    "$(q "SELECT field_label FROM job_request_answers WHERE request_id=$RID AND field_key='business_name';")" \
    "Business name"

eq "somebody is told it arrived" \
   "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='job_request_submitted' AND entity_id=$RID;")" "1"

echo ""
echo "=== 5. It comes back to us ==="
signin_admin
SHOW=$(page "/requests/$RID")
has "the answers are on the request"  "$SHOW" "We import building materials."
has "so are the tickboxes"            "$SHOW" "M-Pesa payments"
has "and the attachment"              "$SHOW" "brief_sample.png"

FID=$(q "SELECT id FROM job_request_files WHERE request_id=$RID LIMIT 1;")
curl -s -b "$JAR" -o "$D/dl.png" "$BASE/requests/$RID/files/$FID"
eq "the file downloads whole" "$(stat -c%s "$D/dl.png")" "$(stat -c%s "$SAMPLE")"
rm -f "$D/dl.png"

has "it shows on the client's profile" "$(page "/clients/$CLIENT")" "Job details asked for"
has "and in the list"                  "$(page /requests)" "$(q "SELECT reference FROM job_requests WHERE id=$RID;")"

# The internal note is for us. It must never reach the client's page.
NOTE=$(q "SELECT note FROM job_requests WHERE id=$RID;")
has "the internal note is on our side"  "$SHOW" "$NOTE"
case "$(curl -s "$BASE/brief/$TOKEN")" in
  *"$NOTE"*) bad "the client never sees the internal note" "leaked" "absent" ;;
  *)         ok  "the client never sees the internal note" "absent" ;;
esac

echo ""
echo "=== 6. Taking it down over the phone ==="
post /requests --data "_token=$(tok "/clients/$CLIENT")&client_id=$CLIENT&brief_type=design" > /dev/null
R2=$(q "SELECT id FROM job_requests ORDER BY id DESC LIMIT 1;")
eq "the fill-in form opens" "$(code "/requests/$R2/fill")" "200"
eq "and saves" \
   "$(post "/requests/$R2/fill" --data "_token=$(tok "/requests/$R2/fill")&answers[what][]=Logo&answers[business_name]=Acme&answers[what_you_do]=Importer")" \
   "302"
eq "it counts as answered"  "$(q "SELECT status FROM job_requests WHERE id=$R2;")" "submitted"

# Worth recording who typed it: an answer from us and an answer from the
# client do not carry the same weight if there is a disagreement later.
eq "and records who took it down" \
   "$(q "SELECT IF(filled_by_staff IS NULL,'no','yes') FROM job_requests WHERE id=$R2;")" "yes"
has "the page says so" "$(page "/requests/$R2")" "Taken down by"

echo ""
echo "=== 7. Who may do what ==="
HASH=$($PHP -r 'echo password_hash("Brief@2026", PASSWORD_DEFAULT);')
for r in finance production; do
  $MYSQL -e "DELETE FROM users WHERE email='b$r@shanfix.co.ke';
             INSERT INTO users (name,email,password_hash,role,is_active)
             VALUES ('Brief $r','b$r@shanfix.co.ke','$HASH','$r',1);
             INSERT INTO user_roles (user_id,role) SELECT id,'$r' FROM users WHERE email='b$r@shanfix.co.ke';"
done

signin bfinance Brief@2026
ne "finance cannot see the list"  "$(code /requests)" "200"
ne "nor one request"              "$(code "/requests/$RID")" "200"

signin bproduction Brief@2026
eq "production can read them"     "$(code /requests)" "200"
ne "but cannot fill one in"       "$(code "/requests/$RID/fill")" "200"
BEFORE=$(q "SELECT COUNT(*) FROM job_requests;")
post /requests --data "_token=$(tok /dashboard)&client_id=$CLIENT&brief_type=design" > /dev/null
eq "nor raise one"                "$(q "SELECT COUNT(*) FROM job_requests;")" "$BEFORE"

$MYSQL -e "DELETE FROM users WHERE email IN ('bfinance@shanfix.co.ke','bproduction@shanfix.co.ke');
           DELETE FROM activity_log WHERE action='login_failed';"

echo ""
echo "=== 8. Cancelling stops the link ==="
signin_admin
eq "cancel it" "$(post "/requests/$R2/status" --data "_token=$(tok "/requests/$R2")&status=cancelled")" "302"
T2=$(q "SELECT public_token FROM job_requests WHERE id=$R2;")
ne "the client's link stops working" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/brief/$T2")" "200"

echo ""
echo "=== 9. Tidy up ==="
$MYSQL -e "DELETE FROM job_requests WHERE id IN ($RID, $R2);
           DELETE FROM staff_notifications WHERE event='job_request_submitted';
           DELETE FROM notifications WHERE event='job_request';
           DELETE FROM activity_log WHERE action LIKE 'job_request%';
           UPDATE settings SET setting_value='0' WHERE setting_key='sms_enabled';"
eq "the answers go with the request" "$(q "SELECT COUNT(*) FROM job_request_answers WHERE request_id=$RID;")" "0"
eq "and so do the files"             "$(q "SELECT COUNT(*) FROM job_request_files WHERE request_id=$RID;")" "0"
rm -f "$SAMPLE" "$D"/brief_*.txt

report
