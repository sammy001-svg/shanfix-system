#!/bin/bash
# Partners: the third door, and the money behind it.
#
# The commission arithmetic itself is asserted here end to end rather than
# in isolation, because the rule that matters is not "this function
# multiplies correctly" — it is that a customer paying an invoice moves
# money into a partner's account and a reversal takes it back out again.
#
# The other half of this suite is about the three guards. A partner
# session must not open the staff system or the client portal, and vice
# versa. That is the whole reason there are three of them.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

TJ="$D/partner.txt"
tget()  { curl -s -b "$TJ" -c "$TJ" "$BASE$1"; }
tcode() { curl -s -o /dev/null -w '%{http_code}' -b "$TJ" "$BASE$1"; }
ttok()  { tget "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
tpost() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$TJ" -c "$TJ" -X POST "$BASE$p" "$@"; }

PEMAIL="ptest@example.co.ke"
PPASS="partner2026pass"

# A code cannot be read back out of the database — they are hashed, which
# is the property being relied on — so it is issued through the same
# service the application uses.
issue_code() {
  $PHP -r '
    require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
    App\Core\Config::load(CONFIG_PATH . "/config.php");
    App\Core\Database::connect(App\Core\Config::get("db"));
    echo App\Services\PartnerOtp::issue($argv[1])["code"];
  ' "$1"
}

SETTINGS_BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value)) FROM settings
                      WHERE setting_key IN ('partners_enabled','partner_signup_enabled','sms_enabled','smtp_enabled');")

restore_settings() {
  local pair key val
  for pair in $(echo "$SETTINGS_BEFORE" | tr ',' ' '); do
    key="${pair%%=*}"; val="${pair#*=}"
    $MYSQL -e "UPDATE settings SET setting_value='$val' WHERE setting_key='$key';"
  done
}

scrub() {
  $MYSQL -e "DELETE FROM commissions WHERE document_id IN (SELECT id FROM documents WHERE doc_number LIKE 'PTEST-%');
             DELETE FROM payments WHERE reference LIKE 'PTEST-%';
             DELETE FROM document_items WHERE document_id IN (SELECT id FROM documents WHERE doc_number LIKE 'PTEST-%');
             DELETE FROM documents WHERE doc_number LIKE 'PTEST-%';
             UPDATE clients SET partner_id = NULL WHERE name = 'PTEST Customer';
             DELETE FROM clients WHERE name = 'PTEST Customer';
             DELETE FROM partner_otps WHERE email = '$PEMAIL';
             DELETE FROM partners WHERE email = '$PEMAIL';
             DELETE FROM services WHERE code LIKE 'PTEST-%';"
}

scrub
$MYSQL -e "UPDATE settings SET setting_value='1'
             WHERE setting_key IN ('partners_enabled','partner_signup_enabled');
           UPDATE settings SET setting_value='0'
             WHERE setting_key IN ('sms_enabled','smtp_enabled');"

echo ""
echo "=== 1. The three doors ==="
eq "the front door asks which you are" "$(code /)"                "200"
has "and offers all three"             "$(curl -s "$BASE/")"      "/partners/login"
eq "the partner door is open"          "$(code /partners/login)"  "200"
eq "so is applying"                    "$(code /partners/apply)"  "200"
ne "the portal itself is shut"         "$(code /partners)"        "200"

echo ""
echo "=== 2. Applying ==="
rm -f "$TJ"
tpost /partners/apply \
  --data-urlencode "_token=$(ttok /partners/apply)" \
  --data-urlencode "name=Partner Tester" \
  --data-urlencode "company=Tester Agencies" \
  --data-urlencode "email=$PEMAIL" \
  --data-urlencode "phone=0733111222" \
  --data-urlencode "pitch=Hotels and schools in Westlands." > /dev/null

eq "the application is recorded" "$(q "SELECT COUNT(*) FROM partners WHERE email='$PEMAIL';")" "1"
eq "and it is not approved yet"  "$(q "SELECT status FROM partners WHERE email='$PEMAIL';")"  "pending"
eq "with no password on it"      "$(q "SELECT IF(password_hash IS NULL,'none','set') FROM partners WHERE email='$PEMAIL';")" "none"
eq "and no code burned"          "$(q "SELECT IF(partner_code IS NULL,'none','set') FROM partners WHERE email='$PEMAIL';")"  "none"

PID=$(q "SELECT id FROM partners WHERE email='$PEMAIL';")

# An application form that says "you already applied" is a way to find out
# who our partners are, one address at a time.
BEFORE=$(q "SELECT COUNT(*) FROM partners WHERE email='$PEMAIL';")
tpost /partners/apply \
  --data-urlencode "_token=$(ttok /partners/apply)" \
  --data-urlencode "name=Someone Else" --data-urlencode "email=$PEMAIL" \
  --data-urlencode "phone=0700000000" --data-urlencode "pitch=Guessing." > /dev/null
eq "applying twice makes no second row" "$(q "SELECT COUNT(*) FROM partners WHERE email='$PEMAIL';")" "$BEFORE"
eq "and does not overwrite the first"   "$(q "SELECT name FROM partners WHERE email='$PEMAIL';")" "Partner Tester"

echo ""
echo "=== 3. Waiting on a decision ==="
eq "a pending partner cannot sign in" \
   "$(tpost /partners/login --data "_token=$(ttok /partners/login)&email=$PEMAIL&password=$PPASS")" "302"
ne "and is not let into the portal"  "$(tcode /partners)" "200"

echo ""
echo "=== 4. Approving ==="
signin_admin > /dev/null
ATOK=$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/$PID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$D/jar_admin.txt" -X POST "$BASE/partners-admin/$PID/decide" \
  --data "_token=$ATOK&decision=approve&default_rate=10"

eq "they are active"        "$(q "SELECT status FROM partners WHERE id=$PID;")" "active"
eq "and now have a code"    "$(q "SELECT IF(partner_code IS NULL,'none','set') FROM partners WHERE id=$PID;")" "set"
eq "at the rate we set"     "$(q "SELECT default_rate FROM partners WHERE id=$PID;")" "10.00"
eq "approving sets no password" \
   "$(q "SELECT IF(password_hash IS NULL,'none','set') FROM partners WHERE id=$PID;")" "none"

echo ""
echo "=== 5. Setting their own password ==="
rm -f "$TJ"
tpost /partners/start --data-urlencode "_token=$(ttok /partners/start)" --data-urlencode "email=$PEMAIL" > /dev/null
eq "a code is issued" "$(q "SELECT COUNT(*) FROM partner_otps WHERE email='$PEMAIL' AND consumed_at IS NULL;")" "1"
eq "and never stored in the clear" \
   "$(q "SELECT IF(code_hash REGEXP '^[0-9]{6}\$','yes','no') FROM partner_otps WHERE email='$PEMAIL' LIMIT 1;")" "no"

# A wrong guess must cost something, or six digits is an afternoon's work.
tpost /partners/verify --data "_token=$(ttok /partners/verify)&code=000000&password=$PPASS&password_confirm=$PPASS" > /dev/null
eq "a wrong code sets no password" "$(q "SELECT IF(password_hash IS NULL,'none','set') FROM partners WHERE id=$PID;")" "none"
eq "and is counted against them"   "$(q "SELECT attempts FROM partner_otps WHERE email='$PEMAIL' AND consumed_at IS NULL;")" "1"

CODE=$(issue_code "$PEMAIL")
tpost /partners/verify \
  --data-urlencode "_token=$(ttok /partners/verify)" --data-urlencode "code=$CODE" \
  --data-urlencode "password=$PPASS" --data-urlencode "password_confirm=$PPASS" > /dev/null

eq "the right code sets one"      "$(q "SELECT IF(password_hash IS NULL,'none','set') FROM partners WHERE id=$PID;")" "set"
eq "and it is hashed"             "$(q "SELECT IF(password_hash='$PPASS','no','yes') FROM partners WHERE id=$PID;")" "yes"
eq "the code cannot be used twice" "$(q "SELECT IF(consumed_at IS NULL,'no','yes') FROM partner_otps WHERE email='$PEMAIL' ORDER BY id DESC LIMIT 1;")" "yes"
eq "they are signed in"           "$(tcode /partners)" "200"

echo ""
echo "=== 6. A customer, and what they earn on it ==="
# A service with its own rate, and one without, so the fallback is exercised.
$MYSQL -e "INSERT INTO services (code,name,pricing_type,price,commission_rate,is_active)
             VALUES ('PTEST-HI','PTEST design','fixed',100000,20.00,0),
                    ('PTEST-LO','PTEST print','fixed',100000,NULL,0);
           INSERT INTO clients (client_code,name,status,partner_id,partner_linked_at)
             VALUES ('PTESTC','PTEST Customer','active',$PID,NOW());"

CID=$(q "SELECT id FROM clients WHERE name='PTEST Customer';")
SHI=$(q "SELECT id FROM services WHERE code='PTEST-HI';")
SLO=$(q "SELECT id FROM services WHERE code='PTEST-LO';")

# 200,000 net, 16% VAT, 232,000 gross. Design 20% + print 10% = 30,000.
$MYSQL -e "INSERT INTO documents
             (doc_type,doc_number,client_id,issue_date,due_date,status,approval_status,
              currency,subtotal,discount_amount,vat_mode,vat_rate,vat_amount,total,amount_paid,balance)
           VALUES ('invoice','PTEST-INV-1',$CID,CURDATE(),CURDATE(),'sent','approved',
                   'KES',200000,0,'exclusive',16,32000,232000,0,232000);"
INV=$(q "SELECT id FROM documents WHERE doc_number='PTEST-INV-1';")
$MYSQL -e "INSERT INTO document_items (document_id,item_type,ref_id,description,quantity,unit_price,line_total,sort_order)
             VALUES ($INV,'service',$SHI,'design',1,100000,100000,1),
                    ($INV,'service',$SLO,'print',1,100000,100000,2);"

eq "an unpaid invoice earns nothing" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE document_id=$INV AND status<>'void';")" "0.00"

echo ""
echo "=== 7. Commission is earned when the customer pays ==="
$PHP -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH . "/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Services\PaymentPoster::post((int)$argv[1], 58000.00, "cash", (int)$argv[2], "PTEST-P1");
' "$CID" "$INV" > /dev/null

# A quarter of 232,000 paid earns a quarter of 30,000.
eq "a quarter paid earns a quarter" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE document_id=$INV AND status<>'void';")" "7500.00"

$PHP -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH . "/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Services\PaymentPoster::post((int)$argv[1], 174000.00, "cash", (int)$argv[2], "PTEST-P2");
' "$CID" "$INV" > /dev/null

eq "paid in full earns it all, exactly" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE document_id=$INV AND status<>'void';")" "30000.00"

# Each line took its own rate, not one blended guess.
eq "and the per-service rate was used" \
   "$(q "SELECT ROUND(base_amount) FROM commissions WHERE document_id=$INV ORDER BY id LIMIT 1;")" "200000"

echo ""
echo "=== 8. A reversed payment takes its commission with it ==="
PAY2=$(q "SELECT id FROM payments WHERE reference='PTEST-P2';")
$PHP -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH . "/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Services\PaymentPoster::reverse((int)$argv[1]);
' "$PAY2" > /dev/null

eq "only the deposit is left earned" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE document_id=$INV AND status<>'void';")" "7500.00"

echo ""
echo "=== 9. What the partner sees is what we owe ==="
eq "their overview opens"  "$(tcode /partners)"           "200"
eq "their earnings open"   "$(tcode /partners/earnings)"  "200"
eq "their customers open"  "$(tcode /partners/customers)" "200"
eq "the catalogue opens"   "$(tcode /partners/services)"  "200"

has "the figure they see is the ledger" "$(tget /partners)" "7,500.00"
has "and their customer is named"       "$(tget /partners/customers)" "PTEST Customer"

# The catalogue is the point of the portal: their cut against every line.
CAT=$(tget /partners/services)
has "the service with its own rate shows it" "$CAT" "20%"
has "and the rest fall back to theirs"       "$CAT" "10%"

echo ""
echo "=== 10. A partner sees nothing of anybody else's ==="
OTHER=$(q "SELECT name FROM clients WHERE partner_id IS NULL AND name <> 'PTEST Customer' ORDER BY id LIMIT 1;")
CUST=$(tget /partners/customers)
eq "somebody else's customer is not listed" \
   "$(echo "$CUST" | grep -c "$OTHER")" "0"

echo ""
echo "=== 11. The three guards do not touch ==="
# A partner session must not open the staff system or the client portal.
ne "a partner cannot reach the dashboard" "$(tcode /dashboard)"     "200"
ne "nor the client portal"                "$(tcode /portal)"        "200"
ne "nor the partner admin"                "$(tcode /partners-admin)" "200"

# And a staff session is not a partner one.
eq "staff cannot enter the partner portal" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$D/jar_admin.txt" "$BASE/partners")" "302"

echo ""
echo "=== 12. Paying them out ==="
PTOK=$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/$PID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$D/jar_admin.txt" -X POST "$BASE/partners-admin/$PID/payout" \
  --data "_token=$PTOK&payout_ref=PTEST-MPESA-1"

eq "everything owed is marked paid" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned';")" "0.00"
eq "and carries the reference" \
   "$(q "SELECT payout_ref FROM commissions WHERE partner_id=$PID AND status='paid' LIMIT 1;")" "PTEST-MPESA-1"

# Paying out must not invent money that was never earned.
eq "the paid total is what was earned" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='paid';")" "7500.00"

echo ""
echo "=== 13. Suspending closes the door ==="
STOK=$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/$PID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$D/jar_admin.txt" -X POST "$BASE/partners-admin/$PID/decide" \
  --data "_token=$STOK&decision=suspend"

eq "they are suspended"            "$(q "SELECT status FROM partners WHERE id=$PID;")" "suspended"
# The guard reloads the row on every request, so a live session dies at
# the next click rather than at the next sign-in.
ne "and the open session is closed" "$(tcode /partners)" "200"

echo ""
echo "=== 14. Tidy up ==="
scrub
restore_settings
eq "the test partner is gone" "$(q "SELECT COUNT(*) FROM partners WHERE email='$PEMAIL';")" "0"
eq "the settings are put back" "$(q "SELECT setting_value FROM settings WHERE setting_key='partners_enabled';")" \
   "$(echo "$SETTINGS_BEFORE" | tr ',' '\n' | grep '^partners_enabled=' | cut -d= -f2)"

report
