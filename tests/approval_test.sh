#!/bin/bash
# Two rules that narrow what people can do:
#
#   1. Only an administrator may delete anything.
#   2. A quotation or invoice raised or changed by anybody else waits for
#      an administrator before it can be printed, sent, or opened on the
#      client's own link.
#
# Both are about what leaves the company, so what is asserted here is the
# ways out — every one of them, because a rule with one gap is not a rule.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

HASH=$($PHP -r 'echo password_hash("Ap@2026", PASSWORD_DEFAULT);')

$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';
           UPDATE settings SET setting_value='1' WHERE setting_key IN ('approval_required','sms_enabled','smtp_enabled');
           DELETE FROM users WHERE email IN ('apsales@shanfix.co.ke','apmgr@shanfix.co.ke');
           INSERT INTO users (name,email,phone,password_hash,role,is_active)
           VALUES ('Ann Sales','apsales@shanfix.co.ke','0712000111','$HASH','sales',1),
                  ('Moses Manager','apmgr@shanfix.co.ke','0712000222','$HASH','manager',1);
           INSERT INTO user_roles (user_id,role)
           SELECT id,'sales' FROM users WHERE email='apsales@shanfix.co.ke';
           INSERT INTO user_roles (user_id,role)
           SELECT id,'manager' FROM users WHERE email='apmgr@shanfix.co.ke';"

CID=$(q "SELECT id FROM clients ORDER BY id LIMIT 1;")

# What was already waiting before this suite ran. Asserting an absolute
# zero further down would fail on anything another suite, or somebody
# testing by hand, happened to leave behind — and that failure would
# point at innocent code.
PENDING_BEFORE=$(q "SELECT COUNT(*) FROM documents WHERE approval_status='pending';")

echo ""
echo "=== 1. Only an administrator deletes ==="
signin apmgr Ap@2026

# A manager keeps every other permission; only deleting is taken away.
eq "a manager can still open a lead to edit" \
   "$(code "/leads/$(q "SELECT id FROM leads ORDER BY id LIMIT 1;")/edit")" "200"
eq "and still raise an invoice" "$(code /invoices/create)" "200"

for spec in "leads:/leads/%s/delete" "jobs:/jobs/%s/delete" "expenses:/expenses/%s/delete" "clients:/clients/%s/delete"; do
  TBL="${spec%%:*}"
  PAT="${spec#*:}"
  ID=$(q "SELECT id FROM $TBL ORDER BY id LIMIT 1;")
  [ -z "$ID" ] && continue
  URL=$(printf "$PAT" "$ID")
  post "$URL" --data "_token=$(tok /dashboard)" > /dev/null
  eq "a manager cannot delete a $TBL row" "$(q "SELECT COUNT(*) FROM $TBL WHERE id=$ID;")" "1"
done

# Retracting your own chat message is deliberately still allowed: it is
# unsending something typed a moment ago, it soft-deletes, and the thread
# keeps the record.
has "the rule is written down" "$(cat "$ROOT/app/Core/Auth.php")" "records.delete"

echo ""
echo "=== 2. A quotation raised by sales waits ==="
signin apsales Ap@2026
$MYSQL -e "DELETE FROM staff_notifications WHERE event='document_approval';
           DELETE FROM notifications WHERE event='document_approval';"

post /quotations \
  --data-urlencode "_token=$(tok /quotations/create)" \
  --data-urlencode "client_id=$CID" --data-urlencode "title=Approval suite quotation" \
  --data-urlencode "issue_date=2026-08-27" --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][description]=Illuminated sign" \
  --data-urlencode "items[0][quantity]=1" --data-urlencode "items[0][unit_price]=85000" > /dev/null

QID=$(q "SELECT id FROM documents WHERE doc_type='quotation' ORDER BY id DESC LIMIT 1;")
eq "it is held"              "$(q "SELECT approval_status FROM documents WHERE id=$QID;")" "pending"
eq "the administrators are told" "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='document_approval';")" "1"

# SMS because the person who raised it is usually stood in front of the
# client waiting to hand it over.
eq "by text"  "$(q "SELECT COUNT(*) FROM notifications WHERE event='document_approval' AND channel='sms';")" "1"
eq "and email" "$(q "SELECT COUNT(*) FROM notifications WHERE event='document_approval' AND channel='email';")" "1"

echo ""
echo "=== 3. Every way out is shut while it waits ==="
ne "printing is refused"  "$(code "/quotations/$QID/print")" "200"
has "and says why"        "$(page "/quotations/$QID")" "waiting for an administrator"
has "the page says so"    "$(page "/quotations/$QID")" "Waiting for approval"

BEFORE=$(q "SELECT COUNT(*) FROM notifications WHERE entity_type='document' AND entity_id=$QID;")
post "/documents/$QID/send" --data "_token=$(tok "/quotations/$QID")&channels[]=email" > /dev/null
eq "nothing is sent to the client" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE entity_type='document' AND entity_id=$QID;")" "$BEFORE"

# Blocking the send button achieves nothing if the link still opens, so
# the link is checked with a real token on a document that is not a draft.
TOKEN=$($PHP -r 'echo bin2hex(random_bytes(24));')
$MYSQL -e "UPDATE documents SET public_token='$TOKEN', status='sent' WHERE id=$QID;"
eq "the client's link will not open"  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/view/$TOKEN")" "404"
eq "nor the short one from an SMS"    "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/v/${TOKEN:0:10}")" "404"
has "and the client is told plainly"  "$(curl -s "$BASE/view/$TOKEN")" "not ready yet"

eq "sales cannot approve their own"   "$(page "/quotations/$QID" | grep -c 'Approve it')" "0"

echo ""
echo "=== 4. An administrator decides ==="
signin_admin
eq "the administrator is offered it" "$(page "/quotations/$QID" | grep -c 'Approve it')" "1"

post "/quotations/$QID/send-back" \
  --data-urlencode "_token=$(tok "/quotations/$QID")" \
  --data-urlencode "approval_note=The installation cost is missing." > /dev/null
eq "it can be sent back"        "$(q "SELECT approval_status FROM documents WHERE id=$QID;")" "pending"
has "with what to change"       "$(q "SELECT approval_note FROM documents WHERE id=$QID;")" "installation cost"
eq "and the author is told"     "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='document_sent_back';")" "1"

post "/quotations/$QID/approve" --data "_token=$(tok "/quotations/$QID")" > /dev/null
eq "it can be approved"         "$(q "SELECT approval_status FROM documents WHERE id=$QID;")" "approved"
eq "with who and when recorded" "$(q "SELECT IF(approved_by IS NULL OR approved_at IS NULL,'no','yes') FROM documents WHERE id=$QID;")" "yes"

eq "printing works now"         "$(code "/quotations/$QID/print")" "200"
eq "and the client's link opens" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/view/$TOKEN")" "200"

echo ""
echo "=== 5. An administrator's own work is not held ==="
post /invoices \
  --data-urlencode "_token=$(tok /invoices/create)" \
  --data-urlencode "client_id=$CID" --data-urlencode "title=Approval suite invoice" \
  --data-urlencode "issue_date=2026-08-27" --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][description]=Banner" \
  --data-urlencode "items[0][quantity]=2" --data-urlencode "items[0][unit_price]=5000" > /dev/null

AID=$(q "SELECT id FROM documents WHERE doc_type='invoice' ORDER BY id DESC LIMIT 1;")
eq "it needs no approval"  "$(q "SELECT approval_status FROM documents WHERE id=$AID;")" "approved"
eq "and prints at once"    "$(code "/invoices/$AID/print")" "200"

echo ""
echo "=== 6. Changing a price is the same commitment as setting one ==="
$MYSQL -e "DELETE FROM staff_notifications WHERE event='document_approval';"
signin apsales Ap@2026

post "/invoices/$AID" \
  --data-urlencode "_token=$(tok "/invoices/$AID/edit")" \
  --data-urlencode "client_id=$CID" --data-urlencode "title=Edited by sales" \
  --data-urlencode "issue_date=2026-08-27" --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][description]=Banner" \
  --data-urlencode "items[0][quantity]=2" --data-urlencode "items[0][unit_price]=9000" > /dev/null

eq "an approved invoice goes back on hold" "$(q "SELECT approval_status FROM documents WHERE id=$AID;")" "pending"
eq "and the administrators are told again" "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='document_approval';")" "1"
ne "it cannot be printed again"            "$(code "/invoices/$AID/print")" "200"

echo ""
echo "=== 7. Only prices are governed ==="
# A receipt records money already taken; a proposal or agreement is prose.
# Holding those would stop work without protecting anything.
eq "receipts are never held" \
   "$(q "SELECT COUNT(*) FROM documents WHERE doc_type='receipt' AND approval_status='pending';")" "0"
eq "nor proposals and agreements" \
   "$(q "SELECT COUNT(*) FROM documents WHERE doc_type IN ('proposal','agreement') AND approval_status='pending';")" "0"

# Nothing that existed before this rule was introduced may be frozen by
# it. Measured against what was already waiting when this suite started,
# not against zero: another suite, or somebody testing by hand, can leave
# a document pending, and failing on that would point at innocent code.
eq "nothing else was frozen by this rule" \
   "$(q "SELECT COUNT(*) FROM documents WHERE id NOT IN ($QID,$AID) AND approval_status='pending';")" \
   "$PENDING_BEFORE"

echo ""
echo "=== 8. Tidy up ==="
signin_admin
$MYSQL -e "DELETE FROM document_items WHERE document_id IN ($QID,$AID);
           DELETE FROM documents WHERE id IN ($QID,$AID);
           DELETE FROM users WHERE email IN ('apsales@shanfix.co.ke','apmgr@shanfix.co.ke');
           DELETE FROM staff_notifications WHERE event IN ('document_approval','document_approved','document_sent_back');
           DELETE FROM notifications WHERE event='document_approval';
           DELETE FROM activity_log WHERE action IN ('login_failed','document_approved','document_sent_back');"
eq "the test documents are gone" "$(q "SELECT COUNT(*) FROM documents WHERE id IN ($QID,$AID);")" "0"

report
