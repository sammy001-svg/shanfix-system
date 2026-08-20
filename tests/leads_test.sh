#!/bin/bash
# Leads: the pipeline, and the actions that move one along it.
#
# Written after "Raise quotation" and "Raise proposal" both returned a 500
# in front of the user. Nothing here had been covered: the crawl only
# walks GET pages, and every one of these is a POST, so a broken button
# stayed broken until somebody clicked it.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';"

echo ""
echo "=== 1. Registering one ==="
signin_admin
eq "the pipeline opens"  "$(code /leads)"        "200"
eq "the list view"       "$(code '/leads?view=list')" "200"
eq "the form opens"      "$(code /leads/create)" "200"

BEFORE=$(q "SELECT COUNT(*) FROM leads;")
eq "register a lead" \
   "$(post /leads --data "_token=$(tok /leads/create)&name=Mwangi Kariuki&company=Kariuki Hardware&phone=0722333444&email=mwangi@kariuki.co.ke&source=walk_in&stage=new&estimated_value=180000&requirement=Shop front signage and a company profile")" \
   "302"
eq "it exists" "$(( $(q "SELECT COUNT(*) FROM leads;") - BEFORE ))" "1"

LID=$(q "SELECT id FROM leads ORDER BY id DESC LIMIT 1;")
eq "it is numbered"        "$(q "SELECT lead_number REGEXP '^LD-[0-9]{4}-[0-9]{4}$' FROM leads WHERE id=$LID;")" "1"
eq "it opens"              "$(code "/leads/$LID")" "200"
eq "creating it is logged" "$(q "SELECT COUNT(*) FROM lead_activities WHERE lead_id=$LID AND subject='Lead created';")" "1"

# Every activity row carries when it happened. The column is NOT NULL, so
# an insert that forgets it takes the whole page down with a 500 — which
# is exactly what used to happen further down this file.
eq "and carries a date"    "$(q "SELECT IF(activity_date IS NULL,'no','yes') FROM lead_activities WHERE lead_id=$LID LIMIT 1;")" "yes"

echo ""
echo "=== 2. Raising a document from it ==="
# The two buttons that were returning 500.
for type in quotation proposal; do
  BEFORE=$(q "SELECT COUNT(*) FROM documents;")
  eq "raise a $type" \
     "$(post "/leads/$LID/document" --data "_token=$(tok "/leads/$LID")&type=$type")" "302"
  eq "the $type exists" "$(( $(q "SELECT COUNT(*) FROM documents;") - BEFORE ))" "1"

  DID=$(q "SELECT id FROM documents ORDER BY id DESC LIMIT 1;")
  eq "it is a $type"            "$(q "SELECT doc_type FROM documents WHERE id=$DID;")" "$type"
  eq "it points back at the lead" "$(q "SELECT lead_id FROM documents WHERE id=$DID;")" "$LID"
  eq "it starts as a draft"     "$(q "SELECT status FROM documents WHERE id=$DID;")" "draft"

  # The point of the button is that nobody retypes the lead.
  eq "it carried the client"    "$(q "SELECT IF(client_id IS NULL,'no','yes') FROM documents WHERE id=$DID;")" "yes"
  eq "and what they asked for"  "$(q "SELECT IF(title IS NULL OR title='','no','yes') FROM documents WHERE id=$DID;")" "yes"

  # A document nobody can open is no better than the 500 it replaced.
  PATHSEG=$([ "$type" = "quotation" ] && echo quotations || echo proposals)
  eq "the $type page opens"     "$(code "/$PATHSEG/$DID")" "200"
  eq "and its edit form"        "$(code "/$PATHSEG/$DID/edit")" "200"

  eq "raising it is logged"     "$(q "SELECT COUNT(*) FROM lead_activities WHERE lead_id=$LID AND subject='$(echo "$type" | sed 's/^./\U&/') raised';")" "1"
  has "and shows on the lead"   "$(page "/leads/$LID")" "$(q "SELECT doc_number FROM documents WHERE id=$DID;")"
done

# A type we do not issue must not create anything.
BEFORE=$(q "SELECT COUNT(*) FROM documents;")
post "/leads/$LID/document" --data "_token=$(tok "/leads/$LID")&type=nonsense" > /dev/null
eq "an unknown document type is refused" "$(q "SELECT COUNT(*) FROM documents;")" "$BEFORE"

echo ""
echo "=== 3. Moving it along ==="
eq "move to qualified" \
   "$(post "/leads/$LID/stage" --data "_token=$(tok "/leads/$LID")&stage=qualified&stage_note=Budget confirmed")" "302"
eq "the stage changed"     "$(q "SELECT stage FROM leads WHERE id=$LID;")" "qualified"
eq "the move is logged"    "$(q "SELECT COUNT(*) FROM lead_activities WHERE lead_id=$LID AND activity_type='stage_change';")" "1"

# The probability follows the stage, so a pipeline total means something.
eq "probability follows it" "$(q "SELECT probability FROM leads WHERE id=$LID;")" "45"

eq "a stage that does not exist is refused" \
   "$(post "/leads/$LID/stage" --data "_token=$(tok "/leads/$LID")&stage=inventing_things" > /dev/null; q "SELECT stage FROM leads WHERE id=$LID;")" \
   "qualified"

echo ""
echo "=== 4. Logging what happened ==="
eq "log a call" \
   "$(post "/leads/$LID/activity" --data "_token=$(tok "/leads/$LID")&activity_type=call&subject=Rang about the signage&notes=Wants it before the expo&outcome=Sending a quote")" \
   "302"
eq "it is on the trail" "$(q "SELECT COUNT(*) FROM lead_activities WHERE lead_id=$LID AND activity_type='call';")" "1"
has "and on the page"   "$(page "/leads/$LID")" "Rang about the signage"

eq "set a follow-up" \
   "$(post "/leads/$LID/reminder" --data "_token=$(tok "/leads/$LID")&title=Chase the signage quote&remind_at=$(date -d '+2 days' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date '+%Y-%m-%d %H:%M:%S')")" \
   "302"
eq "the follow-up is set" "$(q "SELECT COUNT(*) FROM reminders WHERE lead_id=$LID AND is_done=0;")" "1"

echo ""
echo "=== 5. Winning it ==="
# Quoting this lead already created its client record, and that used to be
# what stopped it ever being converted: the guard asked whether a client
# was linked rather than whether the deal had actually been closed.
CBEFORE=$(q "SELECT COUNT(*) FROM clients;")
eq "a quoted lead can still be converted" \
   "$(post "/leads/$LID/convert" --data "_token=$(tok "/leads/$LID")")" "302"
eq "the lead is won"        "$(q "SELECT stage FROM leads WHERE id=$LID;")" "won"
eq "and stamped with when"  "$(q "SELECT IF(converted_at IS NULL,'no','yes') FROM leads WHERE id=$LID;")" "yes"

# The client the quotation created is the same person. A second record is
# how somebody's history ends up split across two profiles.
eq "it reuses that client rather than making another" \
   "$(( $(q "SELECT COUNT(*) FROM clients;") - CBEFORE ))" "0"

# Twice is still refused.
post "/leads/$LID/convert" --data "_token=$(tok "/leads/$LID")" > /dev/null
eq "converting twice creates nothing" \
   "$(( $(q "SELECT COUNT(*) FROM clients;") - CBEFORE ))" "0"

CLID=$(q "SELECT converted_client_id FROM leads WHERE id=$LID;")
eq "and the client opens"   "$(code "/clients/$CLID")" "200"

echo ""
echo "=== 6. Who may do what ==="
HASH=$($PHP -r 'echo password_hash("Lead@2026", PASSWORD_DEFAULT);')
$MYSQL -e "DELETE FROM users WHERE email='ldfinance@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('Lead Finance','ldfinance@shanfix.co.ke','$HASH','finance',1);
           INSERT INTO user_roles (user_id,role) SELECT id,'finance' FROM users WHERE email='ldfinance@shanfix.co.ke';"

signin ldfinance Lead@2026
BEFORE=$(q "SELECT COUNT(*) FROM documents;")
post "/leads/$LID/document" --data "_token=$(tok /dashboard)&type=quotation" > /dev/null
eq "finance cannot raise one off a lead" "$(q "SELECT COUNT(*) FROM documents;")" "$BEFORE"

$MYSQL -e "DELETE FROM users WHERE email='ldfinance@shanfix.co.ke';
           DELETE FROM activity_log WHERE action='login_failed';"

echo ""
echo "=== 7. Tidy up ==="
signin_admin
$MYSQL -e "DELETE FROM documents WHERE lead_id=$LID;
           DELETE FROM reminders WHERE lead_id=$LID;
           DELETE FROM clients WHERE id=$CLID;
           DELETE FROM leads WHERE id=$LID;
           DELETE FROM activity_log WHERE action LIKE 'lead_%';"
eq "the trail goes with the lead" "$(q "SELECT COUNT(*) FROM lead_activities WHERE lead_id=$LID;")" "0"

report
