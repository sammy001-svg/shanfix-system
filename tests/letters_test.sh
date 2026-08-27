#!/bin/bash
# Letters: writing one, and what comes out on the letterhead.
#
# The letterhead is the point. A letter that prints without the logo, the
# address or the vision is not a company letter, it is a page of text —
# so most of what is checked here is what actually reaches the paper.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';"

signin_admin

echo ""
echo "=== 1. The pages open ==="
eq "the list"    "$(code /letters)"        "200"
eq "the form"    "$(code /letters/create)" "200"

echo ""
echo "=== 2. Writing one ==="
BEFORE=$(q "SELECT COUNT(*) FROM letters;")

post /letters \
  --data-urlencode "_token=$(tok /letters/create)" \
  --data-urlencode "recipient_title=The Branch Manager" \
  --data-urlencode "recipient_name=Grace Njeri" \
  --data-urlencode "recipient_org=Equity Bank, Westlands" \
  --data-urlencode "recipient_address=P.O. Box 75104
Nairobi" \
  --data-urlencode "letter_date=2026-08-25" \
  --data-urlencode "subject=Request for a business account statement" \
  --data-urlencode "salutation=Dear Madam" \
  --data-urlencode "body=First paragraph of the letter.

Second paragraph, after a blank line.

Third and last." \
  --data-urlencode "closing=Yours sincerely" \
  --data-urlencode "signatory_name=Samuel Opiyo" \
  --data-urlencode "signatory_title=Managing Director" > /dev/null

eq "it is saved"      "$(( $(q "SELECT COUNT(*) FROM letters;") - BEFORE ))" "1"

LID=$(q "SELECT id FROM letters ORDER BY id DESC LIMIT 1;")
eq "it is numbered"   "$(q "SELECT reference REGEXP '^LTR-[0-9]{4}-[0-9]{4}$' FROM letters WHERE id=$LID;")" "1"
eq "it starts a draft" "$(q "SELECT status FROM letters WHERE id=$LID;")" "draft"
eq "it opens"         "$(code "/letters/$LID")" "200"

echo ""
echo "=== 3. What reaches the paper ==="
eq "the print view opens" "$(code "/letters/$LID/print")" "200"
SHEET=$(page "/letters/$LID/print")

# The letterhead is drawn from the company settings at print time, so
# correcting a phone number corrects every letter ever written.
has "the company name"  "$SHEET" "$(q "SELECT setting_value FROM settings WHERE setting_key='company_name';")"
has "the address"       "$SHEET" "$(q "SELECT setting_value FROM settings WHERE setting_key='company_address';")"
has "the phone number"  "$SHEET" "$(q "SELECT setting_value FROM settings WHERE setting_key='company_phone';")"
has "the email"         "$SHEET" "$(q "SELECT setting_value FROM settings WHERE setting_key='company_email';")"
has "the website"       "$SHEET" "$(q "SELECT setting_value FROM settings WHERE setting_key='company_website';")"
has "the vision at the foot" "$SHEET" "letter__foot"

has "the reference"     "$SHEET" "$(q "SELECT reference FROM letters WHERE id=$LID;")"
has "the date in full"  "$SHEET" "25 August 2026"
has "who it is to"      "$SHEET" "The Branch Manager"
has "the greeting"      "$SHEET" "Dear Madam"
has "the subject"       "$SHEET" "Request for a business account statement"
has "the closing"       "$SHEET" "Yours sincerely"
has "who signed it"     "$SHEET" "Samuel Opiyo"

# A blank line between paragraphs is the writer's own paragraphing and
# has to survive to the page, or the letter arrives as one block.
#
# Every other paragraph in the template carries a class, so a bare <p> is
# a paragraph of the letter itself and nothing else.
eq "blank lines became paragraphs" \
   "$(echo "$SHEET" | grep -o '<p>' | wc -l | tr -d ' ')" "3"

echo ""
echo "=== 4. Changing the vision changes every letter ==="
OLD=$(q "SELECT setting_value FROM settings WHERE setting_key='company_vision';")
$MYSQL -e "UPDATE settings SET setting_value='A different vision entirely.' WHERE setting_key='company_vision';"
has "the letter carries the new one" "$(page "/letters/$LID/print")" "A different vision entirely."
$MYSQL -e "UPDATE settings SET setting_value='$(echo "$OLD" | sed "s/'/''/g")' WHERE setting_key='company_vision';"

echo ""
echo "=== 5. A sent letter is the record of what was sent ==="
post "/letters/$LID/status" --data "_token=$(tok "/letters/$LID")&status=final" > /dev/null
eq "it can be marked final" "$(q "SELECT status FROM letters WHERE id=$LID;")" "final"

post "/letters/$LID/delete" --data "_token=$(tok "/letters/$LID")" > /dev/null
eq "and then cannot be deleted" "$(q "SELECT COUNT(*) FROM letters WHERE id=$LID;")" "1"
has "with a reason given"       "$(page "/letters/$LID")" "has been sent"

echo ""
echo "=== 6. Starting another from it ==="
BEFORE=$(q "SELECT COUNT(*) FROM letters;")
post "/letters/$LID/duplicate" --data "_token=$(tok "/letters/$LID")" > /dev/null
eq "a copy is made"        "$(( $(q "SELECT COUNT(*) FROM letters;") - BEFORE ))" "1"
COPY=$(q "SELECT id FROM letters ORDER BY id DESC LIMIT 1;")
eq "the copy is a draft"   "$(q "SELECT status FROM letters WHERE id=$COPY;")" "draft"
eq "with its own number"   "$(q "SELECT COUNT(DISTINCT reference) FROM letters WHERE id IN ($LID,$COPY);")" "2"

echo ""
echo "=== 7. Addressing it to a client fills in the address ==="
CID=$(q "SELECT id FROM clients WHERE contact_person IS NOT NULL AND contact_person<>'' ORDER BY id LIMIT 1;")
FORM=$(page "/letters/create?client_id=$CID")
has "their contact person" "$FORM" "$(q "SELECT contact_person FROM clients WHERE id=$CID;")"
has "and their company"    "$FORM" "$(q "SELECT name FROM clients WHERE id=$CID;")"

echo ""
echo "=== 8. Who may write one ==="
HASH=$($PHP -r 'echo password_hash("Ltr@2026", PASSWORD_DEFAULT);')
$MYSQL -e "DELETE FROM users WHERE email='ltrfin@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('Letters Finance','ltrfin@shanfix.co.ke','$HASH','finance',1);
           INSERT INTO user_roles (user_id,role) SELECT id,'finance' FROM users WHERE email='ltrfin@shanfix.co.ke';"

signin ltrfin Ltr@2026
eq "finance may read a letter"   "$(code "/letters/$LID")" "200"
ne "but may not write one"       "$(code /letters/create)" "200"

BEFORE=$(q "SELECT COUNT(*) FROM letters;")
post /letters --data "_token=$(tok /dashboard)&recipient_name=X&subject=Y&body=Z&signatory_name=Q&letter_date=2026-08-25" > /dev/null
eq "nor post one past the form"  "$(q "SELECT COUNT(*) FROM letters;")" "$BEFORE"

$MYSQL -e "DELETE FROM users WHERE email='ltrfin@shanfix.co.ke';
           DELETE FROM activity_log WHERE action='login_failed';"

echo ""
echo "=== 9. Tidy up ==="
signin_admin
$MYSQL -e "DELETE FROM letters WHERE id IN ($LID, $COPY);
           DELETE FROM activity_log WHERE action LIKE 'letter_%';"
eq "the test letters are gone" "$(q "SELECT COUNT(*) FROM letters WHERE id IN ($LID,$COPY);")" "0"

report
