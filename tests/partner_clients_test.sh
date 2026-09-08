#!/bin/bash
# A partner's customers: registering them, watching them, and the fence.
#
# The arrangement this suite defends: a partner introduces and earns, we
# quote, invoice and collect. So a partner may put a customer on our books
# and say what they want, and may see everything we then do for them — and
# may not raise a quotation, an invoice or a payment, ever.
#
# The other half is being told. Because none of the billing happens where
# a partner can see it, the messages are not a nicety: without them the
# only way they would learn their customer had paid is by signing in and
# looking.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

PJ="$D/pcl_partner.txt"
pget()  { curl -s -b "$PJ" -c "$PJ" "$BASE$1"; }
pcode() { curl -s -o /dev/null -w '%{http_code}' -b "$PJ" "$BASE$1"; }
ptok()  { pget "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
ppost() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$PJ" -c "$PJ" -X POST "$BASE$p" "$@"; }

PEMAIL="pcl@example.co.ke"
PPASS="pclpartner2026"
OTHER="pclother@example.co.ke"

# Both channels have to be ON, because a channel that is switched off is
# never written to the queue at all and there would be nothing to assert.
# What must not happen is anything actually leaving this machine: the test
# database carries a real SMS key and the live gateway URL, and the
# numbers below would be texted for real. So the senders are pointed at
# dead local ports for the duration and put back at the end.
SETTINGS_KEYS="partners_enabled sms_enabled smtp_enabled smtp_host smtp_port sms_base_url"

declare -A SETTINGS_BEFORE
for k in $SETTINGS_KEYS; do
  SETTINGS_BEFORE[$k]=$(q "SELECT setting_value FROM settings WHERE setting_key='$k';")
done

restore_settings() {
  local k
  for k in $SETTINGS_KEYS; do
    $MYSQL -e "UPDATE settings SET setting_value='${SETTINGS_BEFORE[$k]}' WHERE setting_key='$k';"
  done
}

# Put them back even if the suite dies part way, so a failed run cannot
# leave the machine pointing at a live SMS gateway with sending enabled.
trap restore_settings EXIT

scrub() {
  $MYSQL -e "
    DELETE FROM notifications
      WHERE event IN ('partner_client_registered','partner_document','partner_payment')
        AND recipient IN ('$PEMAIL','$OTHER','254722555444','254722555445');
    DELETE FROM notification_locks WHERE lock_key LIKE 'partner_doc:%' OR lock_key LIKE 'partner_pay:%';
    DELETE FROM commissions WHERE document_id IN (SELECT id FROM documents WHERE doc_number LIKE 'PCLD-%');
    DELETE FROM payments WHERE document_id IN (SELECT id FROM documents WHERE doc_number LIKE 'PCLD-%');
    DELETE FROM document_items WHERE document_id IN (SELECT id FROM documents WHERE doc_number LIKE 'PCLD-%');
    DELETE FROM documents WHERE doc_number LIKE 'PCLD-%';
    DELETE FROM leads WHERE name LIKE 'PCL %' OR name LIKE 'PCL%';
    UPDATE clients SET partner_id = NULL WHERE client_code LIKE 'PCLC%' OR name LIKE 'PCL %';
    DELETE FROM clients WHERE client_code LIKE 'PCLC%' OR name LIKE 'PCL %';
    DELETE FROM partners WHERE email IN ('$PEMAIL','$OTHER');
    DELETE FROM services WHERE code LIKE 'PCL-%';"
}

scrub
$MYSQL -e "
  UPDATE settings SET setting_value='1' WHERE setting_key IN ('partners_enabled','smtp_enabled','sms_enabled');
  UPDATE settings SET setting_value='127.0.0.1'            WHERE setting_key='smtp_host';
  UPDATE settings SET setting_value='59998'                WHERE setting_key='smtp_port';
  UPDATE settings SET setting_value='http://127.0.0.1:59999' WHERE setting_key='sms_base_url';"

HASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PPASS")
$MYSQL -e "
  INSERT INTO partners (partner_code,name,first_name,last_name,email,phone,default_rate,status,password_hash)
    VALUES ('PCLP1','PCL Partner','PCL','Partner','$PEMAIL','0722555444',10.00,'active','$HASH');
  INSERT INTO partners (partner_code,name,first_name,last_name,email,phone,default_rate,status,password_hash)
    VALUES ('PCLP2','PCL Other','PCL','Other','$OTHER','0722555445',10.00,'active','$HASH');"

PID=$(q "SELECT id FROM partners WHERE email='$PEMAIL';")
OID=$(q "SELECT id FROM partners WHERE email='$OTHER';")

rm -f "$PJ"
curl -s -o /dev/null -b "$PJ" -c "$PJ" -X POST "$BASE/partners/login" \
  --data-urlencode "_token=$(ptok /partners/login)" \
  --data-urlencode "email=$PEMAIL" --data-urlencode "password=$PPASS"

echo ""
echo "=== 1. The partner is in ==="
eq "signed in"                "$(pcode /partners)" "200"
eq "the register page opens"  "$(pcode /partners/clients/new)" "200"
has "and the portal links to it" "$(pget /partners/customers)" "/partners/clients/new"

echo ""
echo "=== 2. Registering a customer ==="
NOTE_BEFORE=$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_client_registered';")
STAFF_BEFORE=$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='partner_client';")

eq "the form is accepted" "$(ppost /partners/clients/new \
  --data-urlencode "_token=$(ptok /partners/clients/new)" \
  --data-urlencode "client_type=company" \
  --data-urlencode "name=PCL Hotel Group" \
  --data-urlencode "contact_person=Mary Wanjiru" \
  --data-urlencode "phone=0733222111" \
  --data-urlencode "email=pclhotel@example.co.ke" \
  --data-urlencode "city=Naivasha" \
  --data-urlencode "industry=Hospitality" \
  --data-urlencode "brief=Signage for two new lodges, needed before December.")" "302"

CID=$(q "SELECT id FROM clients WHERE name='PCL Hotel Group';")
ne "the customer exists"       "$CID" ""
eq "and is tagged to them"     "$(q "SELECT partner_id FROM clients WHERE id=$CID;")" "$PID"
eq "with the moment recorded"  "$(q "SELECT IF(partner_linked_at IS NULL,'none','set') FROM clients WHERE id=$CID;")" "set"
eq "and the details they gave" "$(q "SELECT CONCAT_WS('|',contact_person,city,industry,phone) FROM clients WHERE id=$CID;")" \
   "Mary Wanjiru|Naivasha|Hospitality|0733222111"

# The brief is the one thing only they can tell us, so it becomes work
# rather than a paragraph in a notes field nobody opens.
eq "the brief became a lead" \
   "$(q "SELECT COUNT(*) FROM leads WHERE converted_client_id=$CID AND partner_id=$PID;")" "1"
has "carrying what they said" \
   "$(q "SELECT requirement FROM leads WHERE converted_client_id=$CID;")" "two new lodges"

echo ""
echo "=== 3. They are told, and so are we ==="
eq "the partner is emailed" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_client_registered' AND channel='email' AND recipient='$PEMAIL';")" "1"
eq "and texted" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_client_registered' AND channel='sms' AND recipient='254722555444';")" "1"
has "the message names the customer" \
   "$(q "SELECT body FROM notifications WHERE event='partner_client_registered' AND channel='sms' AND recipient='254722555444' ORDER BY id DESC LIMIT 1;")" \
   "PCL Hotel Group"
# An unregistered event sends the raw event name as its subject and no
# SMS at all, so a real subject is the proof a template exists.
ne "with a written subject, not the event name" \
   "$(q "SELECT subject FROM notifications WHERE event='partner_client_registered' AND channel='email' ORDER BY id DESC LIMIT 1;")" \
   "Partner_client_registered"
# One row per member of staff who handles partner work, so the count is
# however many that is — what matters is that somebody was told.
eq "somebody here is told too" \
   "$(( $(q "SELECT COUNT(*) FROM staff_notifications WHERE event='partner_client';") > STAFF_BEFORE ? 1 : 0 ))" "1"

echo ""
echo "=== 4. The form insists on enough to act on ==="
BEFORE=$(q "SELECT COUNT(*) FROM clients;")
eq "no brief, no registration" "$(ppost /partners/clients/new \
  --data-urlencode "_token=$(ptok /partners/clients/new)" \
  --data-urlencode "name=PCL Nobrief" --data-urlencode "phone=0700111000" \
  --data-urlencode "brief=")" "302"
eq "and nothing was written"   "$(q "SELECT COUNT(*) FROM clients;")" "$BEFORE"

eq "no way to reach them, no registration" "$(ppost /partners/clients/new \
  --data-urlencode "_token=$(ptok /partners/clients/new)" \
  --data-urlencode "name=PCL Nocontact" --data-urlencode "brief=They want signage.")" "302"
eq "and nothing was written either" "$(q "SELECT COUNT(*) FROM clients;")" "$BEFORE"

echo ""
echo "=== 5. Somebody already ours is not theirs to claim ==="
# Commission follows the customer, so re-tagging on a second registration
# would hand a partner a share of business that was already ours — or
# somebody else's — for the price of guessing an email address.
$MYSQL -e "INSERT INTO clients (client_code,name,status,email)
             VALUES ('PCLCEXIST','PCL Existing Ltd','active','pclexisting@example.co.ke');"
EXIST=$(q "SELECT id FROM clients WHERE client_code='PCLCEXIST';")
BEFORE=$(q "SELECT COUNT(*) FROM clients;")

eq "the partner is not refused outright" "$(ppost /partners/clients/new \
  --data-urlencode "_token=$(ptok /partners/clients/new)" \
  --data-urlencode "name=PCL Existing Ltd" \
  --data-urlencode "email=pclexisting@example.co.ke" \
  --data-urlencode "brief=They want branded uniforms.")" "302"

eq "but no second customer is made" "$(q "SELECT COUNT(*) FROM clients;")" "$BEFORE"
eq "and the existing one is untouched" \
   "$(q "SELECT IFNULL(partner_id,'none') FROM clients WHERE id=$EXIST;")" "none"
eq "it is raised with a person instead" \
   "$(q "SELECT COUNT(*) FROM leads WHERE partner_id=$PID AND requirement LIKE '%Already on our books%';")" "1"

echo ""
echo "=== 6. Their customer's page ==="
eq "it opens"                "$(pcode "/partners/customers/$CID")" "200"
PAGE=$(pget "/partners/customers/$CID")
has "and names them"         "$PAGE" "PCL Hotel Group"
has "and shows the brief"    "$PAGE" "two new lodges"
has "and says who bills"     "$PAGE" "ours to raise"

echo ""
echo "=== 7. Somebody else's customer is not visible ==="
$MYSQL -e "INSERT INTO clients (client_code,name,status,partner_id,partner_linked_at)
             VALUES ('PCLCOTH','PCL Other Customer','active',$OID,NOW());"
OCID=$(q "SELECT id FROM clients WHERE client_code='PCLCOTH';")
eq "another partner's customer is a 404" "$(pcode "/partners/customers/$OCID")" "404"
eq "and so is one with no partner"       "$(pcode "/partners/customers/$EXIST")" "404"

echo ""
echo "=== 8. A quotation we raise reaches them ==="
signin_admin > /dev/null
$MYSQL -e "INSERT INTO services (code,name,pricing_type,price,commission_rate,is_active)
             VALUES ('PCL-SVC','PCL signage','fixed',100000,20.00,1);"
SVC=$(q "SELECT id FROM services WHERE code='PCL-SVC';")

$MYSQL -e "INSERT INTO documents
             (doc_type,doc_number,client_id,issue_date,due_date,status,approval_status,
              currency,subtotal,discount_amount,vat_mode,vat_rate,vat_amount,total,amount_paid,balance)
           VALUES ('quotation','PCLD-Q1',$CID,CURDATE(),CURDATE(),'sent','approved',
                   'KES',100000,0,'exclusive',16,16000,116000,0,116000);"
QID=$(q "SELECT id FROM documents WHERE doc_number='PCLD-Q1';")

$PHP -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH . "/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Services\PartnerAlerts::documentRaised((int) $argv[1]);
' "$QID" > /dev/null

eq "the partner is emailed about it" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_document' AND recipient='$PEMAIL' AND channel='email';")" "1"
has "and it names the quotation" \
   "$(q "SELECT body FROM notifications WHERE event='partner_document' AND channel='email' ORDER BY id DESC LIMIT 1;")" "PCLD-Q1"
# A text per quotation would be noise, and costs us per part.
eq "but not texted about a quotation" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_document' AND channel='sms';")" "0"

# Announced once, ever. Documents that need approval pass this way twice.
$PHP -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH . "/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Services\PartnerAlerts::documentRaised((int) $argv[1]);
' "$QID" > /dev/null
eq "and never announced twice" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_document' AND recipient='$PEMAIL' AND channel='email';")" "1"

echo ""
echo "=== 9. A draft is nobody's business yet ==="
# A quotation still being written is not something to relay to a customer,
# and a figure that moves before it is sent only causes an argument.
$MYSQL -e "INSERT INTO documents
             (doc_type,doc_number,client_id,issue_date,due_date,status,approval_status,
              currency,subtotal,vat_mode,vat_rate,vat_amount,total,amount_paid,balance)
           VALUES ('invoice','PCLD-DRAFT',$CID,CURDATE(),CURDATE(),'draft','pending',
                   'KES',50000,'exclusive',16,8000,58000,0,58000);"
DRAFT=$(q "SELECT id FROM documents WHERE doc_number='PCLD-DRAFT';")
$PHP -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH . "/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Services\PartnerAlerts::documentRaised((int) $argv[1]);
' "$DRAFT" > /dev/null

eq "a draft tells nobody" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_document' AND recipient='$PEMAIL';")" "1"
case "$(pget "/partners/customers/$CID")" in
  *PCLD-DRAFT*) bad "and does not appear on their page" "found" "absent";;
  *)            ok  "and does not appear on their page" "absent";;
esac

echo ""
echo "=== 10. Their customer pays ==="
$MYSQL -e "INSERT INTO documents
             (doc_type,doc_number,client_id,issue_date,due_date,status,approval_status,
              currency,subtotal,discount_amount,vat_mode,vat_rate,vat_amount,total,amount_paid,balance)
           VALUES ('invoice','PCLD-INV1',$CID,CURDATE(),CURDATE(),'sent','approved',
                   'KES',100000,0,'exclusive',16,16000,116000,0,116000);"
INV=$(q "SELECT id FROM documents WHERE doc_number='PCLD-INV1';")
$MYSQL -e "INSERT INTO document_items (document_id,item_type,ref_id,description,quantity,unit_price,line_total,sort_order)
             VALUES ($INV,'service',$SVC,'signage',1,100000,100000,1);"

# The whole chain, as it runs in production: post, refresh, sync, tell.
$PHP -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH . "/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  $r = App\Services\PaymentPoster::post((int)$argv[1], 116000.00, "cash", (int)$argv[2], "PCL-PAY1");
  App\Services\PaymentPoster::notifyClient($r["payment_id"]);
' "$CID" "$INV" > /dev/null

eq "the invoice is settled" "$(q "SELECT status FROM documents WHERE id=$INV;")" "paid"
eq "the partner earned 20% of it" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE document_id=$INV AND partner_id=$PID AND status<>'void';")" "20000.00"

eq "and is emailed about the payment" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_payment' AND recipient='$PEMAIL' AND channel='email';")" "1"
eq "and texted, because this one is money" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='partner_payment' AND channel='sms' AND recipient='254722555444';")" "1"
has "the message says what they earned" \
   "$(q "SELECT body FROM notifications WHERE event='partner_payment' AND channel='sms' ORDER BY id DESC LIMIT 1;")" "20,000"
# The client's own receipt still goes, unchanged by any of this.
ne "and the customer is told as well" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event IN ('payment_received','payment_partial') AND created_at > NOW() - INTERVAL 2 MINUTE;")" "0"

echo ""
echo "=== 11. The page adds up ==="
PAGE=$(pget "/partners/customers/$CID")
has "what we invoiced"   "$PAGE" "116,000"
has "and what they earned" "$PAGE" "20,000"
has "the invoice is listed" "$PAGE" "PCLD-INV1"
has "and so is the quotation" "$PAGE" "PCLD-Q1"

echo ""
echo "=== 12. The fence ==="
# A partner introduces and earns; we quote, invoice and collect. None of
# the doing is reachable from a partner session, whatever they type.
for path in /invoices /quotations /payments /clients /dashboard; do
  eq "the staff $path is shut to them" "$(pcode $path)" "302"
done

BEFORE_DOCS=$(q "SELECT COUNT(*) FROM documents;")
eq "they cannot raise an invoice" \
   "$(ppost /invoices --data "_token=$(ptok /partners/customers/$CID)&client_id=$CID&doc_type=invoice")" "302"
eq "and none was raised"  "$(q "SELECT COUNT(*) FROM documents;")" "$BEFORE_DOCS"

BEFORE_PAY=$(q "SELECT COUNT(*) FROM payments;")
eq "they cannot record a payment" \
   "$(ppost /payments --data "_token=$(ptok /partners/customers/$CID)&client_id=$CID&amount=1000")" "302"
eq "and none was recorded" "$(q "SELECT COUNT(*) FROM payments;")" "$BEFORE_PAY"

# Nor may they retag a customer to themselves by editing one.
eq "they cannot edit a customer" "$(ppost "/clients/$CID" --data "_token=x&name=Renamed")" "302"
eq "so the name stands"          "$(q "SELECT name FROM clients WHERE id=$CID;")" "PCL Hotel Group"

echo ""
echo "=== 13. Tidy up ==="
scrub
restore_settings
eq "the test partners are gone" "$(q "SELECT COUNT(*) FROM partners WHERE email IN ('$PEMAIL','$OTHER');")" "0"
eq "and their customers with them" "$(q "SELECT COUNT(*) FROM clients WHERE client_code LIKE 'PCLC%';")" "0"

report
