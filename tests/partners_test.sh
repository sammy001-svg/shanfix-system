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
# A service with its own rate, and one without, so the fallback is
# exercised. Both have to be ACTIVE: the partner catalogue lists only
# active services, and creating them switched off is how this suite
# managed to assert "20% is shown" against a leftover row somebody else
# had left lying about, and pass for the wrong reason.
$MYSQL -e "INSERT INTO services (code,name,pricing_type,price,commission_rate,is_active)
             VALUES ('PTEST-HI','PTEST design','fixed',100000,20.00,1),
                    ('PTEST-LO','PTEST print','fixed',100000,NULL,1);
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
has "the priced service is listed"           "$CAT" "PTEST design"
# 100,000 at its own 20% is 20,000; the one with no rate of its own falls
# back to the partner's 10% and pays 10,000. Asserted on the money rather
# than on the percentage, because a stray "20%" anywhere on the page would
# satisfy the looser check without the rate having been applied at all.
has "its own rate is what pays"              "$CAT" "20,000.00"
has "and the fallback pays the partner rate" "$CAT" "10,000.00"

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
echo "=== 15. Registering one ourselves ==="
# Some partners are signed up over a table, not through the website.
signin_admin > /dev/null
REG="ptestreg@example.co.ke"
$MYSQL -e "DELETE FROM partners WHERE email='$REG';"

NTOK=$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/new" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
SALESID=$(q "SELECT id FROM users WHERE role='sales' AND is_active=1 ORDER BY id LIMIT 1;")
curl -s -o /dev/null -b "$D/jar_admin.txt" -X POST "$BASE/partners-admin/new" \
  --data-urlencode "_token=$NTOK" \
  --data-urlencode "name=Registered Direct" \
  --data-urlencode "company=Direct Ltd" \
  --data-urlencode "email=$REG" \
  --data-urlencode "phone=0700111333" \
  --data-urlencode "default_rate=12.5" \
  --data-urlencode "account_manager_id=$SALESID"

eq "the partner exists"        "$(q "SELECT COUNT(*) FROM partners WHERE email='$REG';")" "1"
# Registered by us, so there is nothing to decide.
eq "and is active at once"     "$(q "SELECT status FROM partners WHERE email='$REG';")" "active"
eq "with a code"               "$(q "SELECT IF(partner_code IS NULL,'none','set') FROM partners WHERE email='$REG';")" "set"
eq "at the rate we gave them"  "$(q "SELECT default_rate FROM partners WHERE email='$REG';")" "12.50"
eq "and somebody looking after them" \
   "$(q "SELECT account_manager_id FROM partners WHERE email='$REG';")" "$SALESID"
# Registering never sets a password: they still set their own from a code.
eq "but no password is set for them" \
   "$(q "SELECT IF(password_hash IS NULL,'none','set') FROM partners WHERE email='$REG';")" "none"

REGID=$(q "SELECT id FROM partners WHERE email='$REG';")

echo ""
echo "=== 16. An account manager is a relationship, not an authority ==="
# The picker is not taken on trust. A partner assigned to somebody who is
# not on the sales team has no contact point at all while looking as though
# it has one, so the id is checked against who may actually be one.
#
# The suite makes its own outsider rather than looking for one: on a
# database with only an admin and a salesperson there is nobody to try, and
# the section then silently asserts nothing at all.
OHASH=$($PHP -r 'echo password_hash("PtestOut@2026", PASSWORD_DEFAULT);')
$MYSQL -e "DELETE FROM users WHERE email='ptestout@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('PTEST Outsider','ptestout@shanfix.co.ke','$OHASH','production',1);"
OUTSIDER=$(q "SELECT id FROM users WHERE email='ptestout@shanfix.co.ke';")

ATOK2=$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/$REGID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$D/jar_admin.txt" -X POST "$BASE/partners-admin/$REGID/assign"   --data "_token=$ATOK2&account_manager_id=$OUTSIDER"
ne "somebody off the sales team is refused"    "$(q "SELECT COALESCE(account_manager_id,0) FROM partners WHERE id=$REGID;")" "$OUTSIDER"

# And a real one is accepted, so the check is not simply refusing everyone.
curl -s -o /dev/null -b "$D/jar_admin.txt" -X POST "$BASE/partners-admin/$REGID/assign"   --data "_token=$ATOK2&account_manager_id=$SALESID"
eq "but a salesperson is accepted"    "$(q "SELECT account_manager_id FROM partners WHERE id=$REGID;")" "$SALESID"

$MYSQL -e "DELETE FROM users WHERE email='ptestout@shanfix.co.ke';"

echo ""
echo "=== 17. Sales look after partners; they do not create or pay them ==="
# Its own salesperson. Borrowing another suite's fixture means this one
# passes or fails on whether that suite ran first, and a sign-in that
# quietly fails turns every assertion below into "302, so it must be
# denied" — which is exactly what it looked like the first time.
SALESPASS='PtestSales@2026'
SHASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$SALESPASS")
$MYSQL -e "DELETE FROM users WHERE email='ptestsales@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('PTEST Sales','ptestsales@shanfix.co.ke','$SHASH','sales',1);"

signin ptestsales "$SALESPASS" > /dev/null
SJ="$D/jar_ptestsales.txt"

# If this is not 200 the rest of the section proves nothing: everything
# below would be refused for want of a session rather than for want of
# permission.
eq "the salesperson is signed in"    "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/dashboard")" "200"

# They are the contact point, so they must be able to see the account.
eq "sales can see the list"    "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/partners-admin")" "200"
eq "and one partner's page"    "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/partners-admin/$REGID")" "200"
eq "and the monthly run"       "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/partners-admin/runs")" "200"

# But bringing a partner into existence, or committing us to paying one,
# is not a relationship job.
ne "sales cannot open the register form" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ" "$BASE/partners-admin/new")" "200"

STOK=$(curl -s -b "$SJ" "$BASE/partners-admin/$REGID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')

BEFORE_STATUS=$(q "SELECT status FROM partners WHERE id=$REGID;")
curl -s -o /dev/null -b "$SJ" -X POST "$BASE/partners-admin/$REGID/decide" \
  --data "_token=$STOK&decision=suspend" > /dev/null
eq "sales cannot suspend one"  "$(q "SELECT status FROM partners WHERE id=$REGID;")" "$BEFORE_STATUS"

BEFORE_RATE=$(q "SELECT default_rate FROM partners WHERE id=$REGID;")
curl -s -o /dev/null -b "$SJ" -X POST "$BASE/partners-admin/$REGID" \
  --data "_token=$STOK&name=Hijacked&email=$REG&phone=0700111333&default_rate=90" > /dev/null
eq "nor move the rate"         "$(q "SELECT default_rate FROM partners WHERE id=$REGID;")" "$BEFORE_RATE"
eq "nor rename them"           "$(q "SELECT name FROM partners WHERE id=$REGID;")" "Registered Direct"

BEFORE_EARNED=$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned';")
curl -s -o /dev/null -b "$SJ" -X POST "$BASE/partners-admin/$PID/payout" \
  --data "_token=$STOK&payout_ref=SALES-SHOULD-NOT" > /dev/null
eq "and cannot pay a commission" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned';")" "$BEFORE_EARNED"
eq "no payout reference was written" \
   "$(q "SELECT COUNT(*) FROM commissions WHERE payout_ref='SALES-SHOULD-NOT';")" "0"

echo ""
echo "=== 18. Commission is paid a month at a time ==="
signin_admin > /dev/null

# Everything earned so far belongs to the month it was earned in.
eq "every entry carries its month" \
   "$(q "SELECT COUNT(*) FROM commissions WHERE partner_id=$PID AND period IS NULL;")" "0"
eq "and it is the month it was earned" \
   "$(q "SELECT IF(period = DATE_FORMAT(created_at,'%Y-%m'),'yes','no') FROM commissions WHERE partner_id=$PID ORDER BY id LIMIT 1;")" "yes"

THISMONTH=$(q "SELECT DATE_FORMAT(NOW(),'%Y-%m');")
LASTMONTH=$(q "SELECT DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH),'%Y-%m');")

# Move half of it into last month, so paying one month must leave the other.
$MYSQL -e "UPDATE commissions SET period='$LASTMONTH'
            WHERE partner_id=$PID AND status='earned' ORDER BY id LIMIT 1;"

DUE_LAST=$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned' AND period='$LASTMONTH';")
DUE_THIS=$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned' AND period='$THISMONTH';")

MTOK=$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/$PID" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$D/jar_admin.txt" -X POST "$BASE/partners-admin/$PID/payout" \
  --data "_token=$MTOK&period=$LASTMONTH&payout_ref=PTEST-MONTH-1"

eq "that month is settled" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned' AND period='$LASTMONTH';")" "0.00"
eq "and the other month is untouched" \
   "$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned' AND period='$THISMONTH';")" "$DUE_THIS"
eq "the payment carries that month's reference" \
   "$(q "SELECT COUNT(*) FROM commissions WHERE partner_id=$PID AND payout_ref='PTEST-MONTH-1' AND period='$LASTMONTH';")" \
   "$(q "SELECT COUNT(*) FROM commissions WHERE partner_id=$PID AND status='paid' AND period='$LASTMONTH';")"

# The run screen groups by the same month the payout does, or the two
# would disagree about what is owed.
has "the run names that month" \
    "$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/runs?period=$THISMONTH")" "$(q "SELECT DATE_FORMAT(NOW(),'%M %Y');")"

echo ""
echo "=== 19. What they resell, and when it renews ==="
PROF=$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/$PID")
has "the profile lists what they resell" "$PROF" "What they are reselling"
has "and what falls due"                 "$PROF" "Recurring, and when it falls due"
has "and who looks after them"           "$PROF" "Looked after by"

# The months moved behind their own tab when the page was split up. Read
# from where they live now rather than deleting the check: the assertion
# was never about which page it was on.
has "and the months are a tab away"     "$(curl -s -b "$D/jar_admin.txt" "$BASE/partners-admin/$PID?tab=money")" "Month by month"

$MYSQL -e "DELETE FROM partners WHERE email='$REG';
           DELETE FROM users WHERE email='ptestsales@shanfix.co.ke';"

echo ""
echo "=== 20. A partner can supply what we need to pay them ==="
# The commission run refuses to pay a partner with no KRA PIN, and until
# now the only person who could supply one was a member of staff typing it
# in off a phone call. The person who actually knows it had no way to say.
# Section 13 suspended them to prove the door shuts, and a suspended
# partner cannot sign in — which is the point of it. Let them back in, or
# everything below fails for want of a session rather than for want of the
# thing it is meant to be testing.
$MYSQL -e "UPDATE partners SET status='active', kra_pin=NULL WHERE id = $PID;"

rm -f "$TJ"
tpost /partners/login --data-urlencode "_token=$(ttok /partners/login)" \
  --data-urlencode "email=$PEMAIL" --data-urlencode "password=$PPASS" > /dev/null

eq "their own details open" "$(tcode /partners/account)" "200"
has "and say what is missing" "$(tget /partners/account)" "before we can pay you"

tpost /partners/account \
  --data-urlencode "_token=$(ttok /partners/account)" \
  --data-urlencode "name=Partner Tester" \
  --data-urlencode "company=Tester Agencies" \
  --data-urlencode "phone=0733111222" \
  --data-urlencode "kra_pin=a012345678z" > /dev/null

eq "the PIN is stored"     "$(q "SELECT kra_pin FROM partners WHERE id=$PID;")" "A012345678Z"
eq "and it is upper-cased" "$(q "SELECT IF(kra_pin = UPPER(kra_pin),'yes','no') FROM partners WHERE id=$PID;")" "yes"

# What they may not change from in here: the address they sign in with and
# the rate we agreed to pay. Posting them anyway must do nothing.
tpost /partners/account \
  --data-urlencode "_token=$(ttok /partners/account)" \
  --data-urlencode "name=Partner Tester" --data-urlencode "phone=0733111222" \
  --data-urlencode "email=hijack@example.co.ke" \
  --data-urlencode "default_rate=95" > /dev/null

eq "they cannot move their own rate"  "$(q "SELECT default_rate FROM partners WHERE id=$PID;")" "10.00"
eq "nor change what they sign in as"  "$(q "SELECT email FROM partners WHERE id=$PID;")" "$PEMAIL"

echo ""
echo "=== 21. And change their own password ==="
NEWPASS="partner2026changed"

# The wrong current password must not be enough.
tpost /partners/account/password \
  --data-urlencode "_token=$(ttok /partners/account)" \
  --data-urlencode "current_password=not-the-one" \
  --data-urlencode "new_password=$NEWPASS" \
  --data-urlencode "new_password_confirm=$NEWPASS" > /dev/null

rm -f "$TJ"
eq "the old password still works" \
   "$(tpost /partners/login --data-urlencode "_token=$(ttok /partners/login)" \
        --data-urlencode "email=$PEMAIL" --data-urlencode "password=$PPASS")" "302"
eq "and they are in"  "$(tcode /partners)" "200"

tpost /partners/account/password \
  --data-urlencode "_token=$(ttok /partners/account)" \
  --data-urlencode "current_password=$PPASS" \
  --data-urlencode "new_password=$NEWPASS" \
  --data-urlencode "new_password_confirm=$NEWPASS" > /dev/null

rm -f "$TJ"
eq "the new password works" \
   "$(tpost /partners/login --data-urlencode "_token=$(ttok /partners/login)" \
        --data-urlencode "email=$PEMAIL" --data-urlencode "password=$NEWPASS")" "302"
eq "and lets them in" "$(tcode /partners)" "200"

rm -f "$TJ"
tpost /partners/login --data-urlencode "_token=$(ttok /partners/login)" \
  --data-urlencode "email=$PEMAIL" --data-urlencode "password=$PPASS" > /dev/null
ne "the old one no longer does" "$(tcode /partners)" "200"

# Back in, on the new password, for the sections below.
rm -f "$TJ"
tpost /partners/login --data-urlencode "_token=$(ttok /partners/login)" \
  --data-urlencode "email=$PEMAIL" --data-urlencode "password=$NEWPASS" > /dev/null

echo ""
echo "=== 22. A month they can invoice us against ==="
# The terms a partner agrees to say they are paid monthly against an
# invoice from them, so they need something to invoice against.
PERIOD=$(q "SELECT period FROM commissions WHERE partner_id=$PID AND status<>'void' ORDER BY id LIMIT 1;")
eq "the month opens"  "$(tcode "/partners/statement/$PERIOD")" "200"

STMT=$(tget "/partners/statement/$PERIOD")
has "it names the month"        "$STMT" "$(q "SELECT DATE_FORMAT(CONCAT('$PERIOD','-01'),'%M %Y');")"
has "and carries our letterhead" "$STMT" "$(q "SELECT setting_value FROM settings WHERE setting_key='company_name';")"
has "and their reference"        "$STMT" "$(q "SELECT partner_code FROM partners WHERE id=$PID;")"
has "and totals what is due"     "$STMT" "Due to you"

# The total on the statement is the sum of that month, not of everything.
MONTHTOTAL=$(q "SELECT FORMAT(COALESCE(SUM(amount),0),2) FROM commissions
                 WHERE partner_id=$PID AND period='$PERIOD' AND status<>'void';")
has "and the figure is that month's" "$STMT" "$MONTHTOTAL"

# A period is read from the URL, so it has to be a period.
eq "a month that is not one is refused" "$(tcode /partners/statement/nonsense)" "404"
eq "and a month with nothing in it too" "$(tcode /partners/statement/1999-01)" "404"

# Two rows per entry would satisfy a "more than none" check while showing
# somebody else's money, so the count is asserted exactly. The row index
# cell appears once per line, plus once in the header.
MINE=$(q "SELECT COUNT(*) FROM commissions WHERE partner_id=$PID AND period='$PERIOD' AND status<>'void';")
eq "it lists exactly their own entries"    "$(tget "/partners/statement/$PERIOD" | grep -c 'doc-table__idx')" "$((MINE + 1))"

echo ""
echo "=== 23. What is coming, with a date on it ==="
eq "the renewals page opens" "$(tcode /partners/upcoming)" "200"

echo ""
echo "=== 24. Somebody is reminded to pay them ==="
# Commission paid monthly is commission paid by somebody remembering, and
# a debt that depends on somebody remembering is one a business forgets.
PAYDAY=$(q "SELECT setting_value FROM settings WHERE setting_key='partner_payout_day';")
ne "the payout day is configured" "$PAYDAY" ""
eq "and the cron reads it"        "$(grep -c 'partner_payout_day' "$SHANFIX_ROOT/cron.php")" "1"
eq "and only says so once a month" \
   "$(grep -c "partner:payout:" "$SHANFIX_ROOT/cron.php")" "1"

echo ""
echo "=== 25. What we do, with pictures ==="
# /files is behind the staff guard, which is right for receipts and wrong
# for photographs of what we sell: a partner and a client both saw nothing
# at all while the pictures sat in the database.
CAT=$(tget /partners/services)
has "services are shown as cards" "$CAT" "cat-card--service"
has "and so are products"        "$CAT" "cat-card--product"

# Both kinds, and all of each: a catalogue missing half of itself is worse
# than one that says it is empty. Counted on the class rather than the
# visible word, which CSS upper-cases and the markup wraps in newlines.
eq "every active service has a card"    "$(echo "$CAT" | grep -c 'cat-card--service')"    "$(q "SELECT COUNT(*) FROM services WHERE is_active=1;")"
eq "and every active product"    "$(echo "$CAT" | grep -c 'cat-card--product')"    "$(q "SELECT COUNT(*) FROM inventory_items WHERE is_active=1;")"

# The card has to say what it earns them, which is the whole reason a
# partner is reading this rather than the client-facing one.
has "each card says what it pays" "$CAT" "you earn"

echo ""
echo "=== 26. A catalogue picture is served, and only that ==="
IMGID=$(q "SELECT i.id FROM inventory_images i JOIN inventory_items o ON o.id=i.item_id
            WHERE o.is_active=1 ORDER BY i.id LIMIT 1;")

if [ -n "$IMGID" ]; then
  eq "the picture is served to them" \
     "$(curl -s -o /dev/null -w '%{http_code}' -b "$TJ" "$BASE/catalogue/image/product/$IMGID")" "200"
  eq "and it really is an image" \
     "$(curl -s -o /dev/null -w '%{content_type}' -b "$TJ" "$BASE/catalogue/image/product/$IMGID" | cut -d/ -f1)" "image"

  # Signed out it is nothing at all. The picture should be no more public
  # than the page that shows it.
  eq "a stranger gets nothing" \
     "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/catalogue/image/product/$IMGID")" "404"

  # The route takes an image row id, never a path. There is no way through
  # it to the rest of storage.
  eq "it cannot be pointed at another file" \
     "$(curl -s -o /dev/null -w '%{http_code}' -b "$TJ" "$BASE/catalogue/image/product/0")" "404"
  eq "nor at a kind that does not exist" \
     "$(curl -s -o /dev/null -w '%{http_code}' -b "$TJ" "$BASE/catalogue/image/receipts/$IMGID")" "404"

  # Switching a product off takes its picture with it.
  ITEM=$(q "SELECT item_id FROM inventory_images WHERE id=$IMGID;")
  $MYSQL -e "UPDATE inventory_items SET is_active=0 WHERE id=$ITEM;"
  eq "and not at something we no longer sell" \
     "$(curl -s -o /dev/null -w '%{http_code}' -b "$TJ" "$BASE/catalogue/image/product/$IMGID")" "404"
  $MYSQL -e "UPDATE inventory_items SET is_active=1 WHERE id=$ITEM;"
fi

# Staff keep their own route; this one does not replace it.
eq "the staff file route still needs staff" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/files/uploads/products/nothing.png")" "302"

echo ""
echo "=== 27. A partner's page is read one part at a time ==="
# Nine full-width cards down one page put an approval decision, a payment,
# an edit form and four tables at the same weight. Each part is now behind
# a tab, and the tab is in the URL so it can be sent to somebody.
signin_admin > /dev/null
AJ="$D/jar_admin.txt"

OVER=$(curl -s -b "$AJ" "$BASE/partners-admin/$PID")
# Read from the row rather than hard-coded: this suite makes its own
# partner, and a name borrowed from somebody's preview data passes
# or fails on whether that data happens to be lying about.
has "the header names them"        "$OVER" "$(q "SELECT COALESCE(company, name) FROM partners WHERE id=$PID;")"
has "and says what we owe"         "$OVER" "Owed to them"
has "and who looks after them"     "$OVER" "Looked after by"

# Overview is what the relationship is: what they sell and what renews.
has "overview shows what they sell" "$OVER" "What they are reselling"
has "and what falls due"            "$OVER" "Recurring, and when it falls due"

# And only that. The whole point is that the other parts are not also here.
eq "the edit form is not on it"     "$(echo "$OVER" | grep -c 'name="default_rate"')" "0"
eq "nor the month-by-month table"   "$(echo "$OVER" | grep -c 'Month by month')" "0"

MONEY=$(curl -s -b "$AJ" "$BASE/partners-admin/$PID?tab=money")
has "the money tab has the months"  "$MONEY" "Month by month"
# The way to settle everything only appears when there is something to
# settle — by this point the suite has already paid this partner out, so
# asserting it unconditionally asserts the wrong thing.
DUE_NOW=$(q "SELECT COALESCE(SUM(amount),0) FROM commissions WHERE partner_id=$PID AND status='earned';")
if [ "${DUE_NOW%%.*}" -gt 0 ] 2>/dev/null; then
  has "and the way to settle the lot" "$MONEY" "Settle everything outstanding"
else
  eq "and no way to settle nothing" "$(echo "$MONEY" | grep -c 'Settle everything outstanding')" "0"
fi
eq "and not the customers table"    "$(echo "$MONEY" | grep -c 'Their customers')" "0"

DET=$(curl -s -b "$AJ" "$BASE/partners-admin/$PID?tab=details")
has "the details tab can edit them" "$DET" 'name="default_rate"'
has "and assign somebody"           "$DET" 'name="account_manager_id"'

CUST=$(curl -s -b "$AJ" "$BASE/partners-admin/$PID?tab=customers")
has "the customers tab lists them"  "$CUST" "Their customers"

# A tab nobody defined falls back to the overview rather than to a blank
# page, because the value comes from the URL.
has "an unknown tab falls back" \
    "$(curl -s -b "$AJ" "$BASE/partners-admin/$PID?tab=nonsense")" "What they are reselling"

echo ""
echo "=== 28. Sales are not offered what they cannot do ==="
# Sales look after the relationship and may read all of it. Offering them
# an edit form that refuses on submit is worse than not offering it.
SP='PtestSales2@2026'
SH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$SP")
$MYSQL -e "DELETE FROM users WHERE email='ptabsales@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('PTEST Tabs Sales','ptabsales@shanfix.co.ke','$SH','sales',1);"
signin ptabsales "$SP" > /dev/null
SJ2="$D/jar_ptabsales.txt"

eq "they are signed in" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$SJ2" "$BASE/dashboard")" "200"

SOVER=$(curl -s -b "$SJ2" "$BASE/partners-admin/$PID")
has "sales can read the overview" "$SOVER" "What they are reselling"
eq "but are not offered a details tab" \
   "$(echo "$SOVER" | grep -c 'tab=details')" "0"

# And the tab is not simply hidden: asking for it directly shows them
# nothing they could not already see.
SDET=$(curl -s -b "$SJ2" "$BASE/partners-admin/$PID?tab=details")
eq "asking for it anyway shows no form" "$(echo "$SDET" | grep -c 'name="default_rate"')" "0"
eq "and no way to reassign"             "$(echo "$SDET" | grep -c 'name="account_manager_id"')" "0"

$MYSQL -e "DELETE FROM users WHERE email='ptabsales@shanfix.co.ke';"

echo ""
echo "=== 14. Tidy up ==="
scrub
restore_settings
eq "the test partner is gone" "$(q "SELECT COUNT(*) FROM partners WHERE email='$PEMAIL';")" "0"
eq "the settings are put back" "$(q "SELECT setting_value FROM settings WHERE setting_key='partners_enabled';")" \
   "$(echo "$SETTINGS_BEFORE" | tr ',' '\n' | grep '^partners_enabled=' | cut -d= -f2)"

report
