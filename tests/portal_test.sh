#!/bin/bash
# The client portal: the three ways in, and the walls around them.
#
# This is the first part of the system a stranger can reach without any
# credential at all, so most of what is asserted here is what it refuses
# to do — leak which addresses are on file, let a code be reused, let one
# client's session reach another's records, or let a portal session pass
# for a staff one.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

PJ="$D/portal.txt"
pget()  { curl -s -b "$PJ" -c "$PJ" "$BASE$1"; }
pcode() { curl -s -o /dev/null -w '%{http_code}' -b "$PJ" "$BASE$1"; }
ptok()  { pget "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
ppost() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$PJ" -c "$PJ" -X POST "$BASE$p" "$@"; }

# Issue a code and print it. The portal hashes them, so a test cannot read
# one back out — which is the property being relied on, not a nuisance.
issue() {
  $PHP -r '
    require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
    App\Core\Config::load(CONFIG_PATH . "/config.php");
    App\Core\Database::connect(App\Core\Config::get("db"));
    echo App\Services\ClientOtp::issue($argv[1])["code"];
  ' "$1"
}

# This suite turns several global switches on. Left that way they follow
# the next suite into its own run and fail it for a reason that has
# nothing to do with its code — which is exactly what happened.
SETTINGS_BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value)) FROM settings
                      WHERE setting_key IN ('portal_enabled','portal_self_signup','sms_enabled',
                                            'smtp_enabled','kopokopo_enabled','portal_uploads_enabled',
                                            'portal_show_prices','portal_show_inventory');")

restore_settings() {
  local pair key val
  for pair in $(echo "$SETTINGS_BEFORE" | tr ',' ' '); do
    key="${pair%%=*}"
    val="${pair#*=}"
    $MYSQL -e "UPDATE settings SET setting_value='$val' WHERE setting_key='$key';"
  done
}

NEW="portalnew@example.co.ke"

$MYSQL -e "UPDATE settings SET setting_value='1'
             WHERE setting_key IN ('portal_enabled','portal_self_signup','sms_enabled','smtp_enabled');
           DELETE FROM client_users WHERE email IN ('$NEW','portalreq@example.co.ke');
           DELETE FROM clients WHERE email='$NEW';
           DELETE FROM client_otps WHERE email IN ('$NEW','portalreq@example.co.ke');
           DELETE FROM client_access_requests;
           DELETE FROM activity_log WHERE action='login_failed';"

echo ""
echo "=== 1. The doors are open, the rooms are not ==="
rm -f "$PJ"
eq "the sign-in page"      "$(pcode /portal/login)"          "200"
eq "setting up access"     "$(pcode /portal/start)"          "200"
eq "asking us for access"  "$(pcode /portal/request-access)" "200"
ne "the portal itself is shut" "$(pcode /portal)"            "200"

echo ""
echo "=== 2. Somebody new signs up ==="
ppost /portal/start --data "_token=$(ptok /portal/start)&email=$NEW" > /dev/null
eq "a code is issued"      "$(q "SELECT COUNT(*) FROM client_otps WHERE email='$NEW';")" "1"

# A code is a credential while it lives. A leaked database must not hand
# somebody a working one.
eq "and never stored in the clear" \
   "$(q "SELECT IF(code_hash REGEXP '^[0-9]{6}\$','yes','no') FROM client_otps WHERE email='$NEW' LIMIT 1;")" "no"

CODE=$(issue "$NEW")

# A wrong guess must cost something, or six digits is an afternoon's work.
ppost /portal/verify --data "_token=$(ptok /portal/verify)&code=000000&name=X&password=hunter2pass&password_confirm=hunter2pass" > /dev/null
eq "a wrong code makes no account" "$(q "SELECT COUNT(*) FROM client_users WHERE email='$NEW';")" "0"
eq "and is counted against them"   "$(q "SELECT attempts FROM client_otps WHERE email='$NEW' AND consumed_at IS NULL;")" "1"

ppost /portal/verify \
  --data-urlencode "_token=$(ptok /portal/verify)" --data-urlencode "code=$CODE" \
  --data-urlencode "name=Portal Newcomer" \
  --data-urlencode "password=hunter2pass" --data-urlencode "password_confirm=hunter2pass" > /dev/null

eq "the right code makes an account" "$(q "SELECT status FROM client_users WHERE email='$NEW';")" "active"
eq "and a client record with it"     "$(q "SELECT COUNT(*) FROM clients WHERE email='$NEW';")" "1"
eq "they are signed in"              "$(pcode /portal)" "200"
eq "the code cannot be used twice"   "$(q "SELECT IF(consumed_at IS NULL,'no','yes') FROM client_otps WHERE email='$NEW' ORDER BY id DESC LIMIT 1;")" "yes"

echo ""
echo "=== 3. Passwords ==="
$MYSQL -e "DELETE FROM client_users WHERE email='$NEW'; DELETE FROM clients WHERE email='$NEW';
           DELETE FROM client_otps WHERE email='$NEW';"
rm -f "$PJ"
ppost /portal/start --data "_token=$(ptok /portal/start)&email=$NEW" > /dev/null
CODE=$(issue "$NEW")

ppost /portal/verify \
  --data-urlencode "_token=$(ptok /portal/verify)" --data-urlencode "code=$CODE" \
  --data-urlencode "name=Portal Newcomer" \
  --data-urlencode "password=short" --data-urlencode "password_confirm=short" > /dev/null
eq "a short password is refused" "$(q "SELECT COUNT(*) FROM client_users WHERE email='$NEW';")" "0"

CODE=$(issue "$NEW")
ppost /portal/verify \
  --data-urlencode "_token=$(ptok /portal/verify)" --data-urlencode "code=$CODE" \
  --data-urlencode "name=Portal Newcomer" \
  --data-urlencode "password=hunter2pass" --data-urlencode "password_confirm=different2pass" > /dev/null
eq "so is a mismatched pair"     "$(q "SELECT COUNT(*) FROM client_users WHERE email='$NEW';")" "0"

CODE=$(issue "$NEW")
ppost /portal/verify \
  --data-urlencode "_token=$(ptok /portal/verify)" --data-urlencode "code=$CODE" \
  --data-urlencode "name=Portal Newcomer" \
  --data-urlencode "password=hunter2pass" --data-urlencode "password_confirm=hunter2pass" > /dev/null
eq "a matching pair is accepted"  "$(q "SELECT status FROM client_users WHERE email='$NEW';")" "active"
eq "and is hashed, not stored"    "$(q "SELECT IF(password_hash='hunter2pass','no','yes') FROM client_users WHERE email='$NEW';")" "yes"

echo ""
echo "=== 4. An existing client keeps their own records ==="
CID=$(q "SELECT id FROM clients WHERE email IS NOT NULL AND email<>'' AND status='active' ORDER BY id LIMIT 1;")
CEMAIL=$(q "SELECT LOWER(email) FROM clients WHERE id=$CID;")
$MYSQL -e "DELETE FROM client_users WHERE email='$CEMAIL'; DELETE FROM client_otps WHERE email='$CEMAIL';"

rm -f "$PJ"
ppost /portal/start --data-urlencode "_token=$(ptok /portal/start)" --data-urlencode "email=$CEMAIL" > /dev/null
CODE=$(issue "$CEMAIL")
ppost /portal/verify \
  --data-urlencode "_token=$(ptok /portal/verify)" --data-urlencode "code=$CODE" \
  --data-urlencode "password=hunter2pass" --data-urlencode "password_confirm=hunter2pass" > /dev/null

# The account attaches to the record they already have. Making a second
# one would split their invoices across two profiles.
eq "they are attached to their existing client" \
   "$(q "SELECT client_id FROM client_users WHERE email='$CEMAIL';")" "$CID"

echo ""
echo "=== 5. The portal tells a stranger nothing ==="
rm -f "$PJ"
ppost /portal/start --data "_token=$(ptok /portal/start)&email=nobody-at-all@example.invalid" > /dev/null
UNKNOWN=$(pget /portal/verify)

rm -f "$PJ"
ppost /portal/start --data-urlencode "_token=$(ptok /portal/start)" --data-urlencode "email=$CEMAIL" > /dev/null
KNOWN=$(pget /portal/verify)

# Both land on the same page saying the same thing. A different answer
# for a known address would let somebody map the client list one guess at
# a time.
eq "an unknown address is treated the same as a known one" \
   "$([ "$(echo "$UNKNOWN" | grep -c 'Enter your code')" = "$(echo "$KNOWN" | grep -c 'Enter your code')" ] && echo same || echo different)" \
   "same"

echo ""
echo "=== 6. Asking us, when we have no email for them ==="
TCID=$(q "SELECT id FROM clients WHERE phone IS NOT NULL AND phone<>'' AND status='active' ORDER BY id LIMIT 1;")
TNAME=$(q "SELECT name FROM clients WHERE id=$TCID;")
TPHONE=$(q "SELECT phone FROM clients WHERE id=$TCID;")

rm -f "$PJ"
ppost /portal/request-access \
  --data-urlencode "_token=$(ptok /portal/request-access)" \
  --data-urlencode "full_name=$TNAME" --data-urlencode "phone=$TPHONE" \
  --data-urlencode "email=portalreq@example.co.ke" > /dev/null

eq "the request is recorded"  "$(q "SELECT COUNT(*) FROM client_access_requests WHERE phone='$TPHONE';")" "1"
eq "and nothing is decided yet" "$(q "SELECT status FROM client_access_requests WHERE phone='$TPHONE';")" "pending"

# Nothing is confirmed back to them. Saying "no such client" would let a
# stranger test names and numbers until one landed.
has "they are told only that we will check" "$(pget /portal/login)" "check our records"

RID=$(q "SELECT id FROM client_access_requests WHERE phone='$TPHONE' LIMIT 1;")

# Codes have already been sent in earlier sections, so what matters is
# how many this approval adds — not how many exist.
SMS_BEFORE=$(q "SELECT COUNT(*) FROM notifications WHERE event='client_otp' AND channel='sms';")

signin_admin
eq "an administrator can see it" "$(code /portal-requests)" "200"

# The number is what is being vouched for, so it has to be one we already
# hold — otherwise approving texts the code to whoever typed it.
WRONG=$(q "SELECT id FROM clients WHERE id<>$TCID AND status='active' AND (phone IS NULL OR phone NOT LIKE '%${TPHONE: -9}') ORDER BY id LIMIT 1;")
post "/portal-requests/$RID/approve" --data "_token=$(tok /portal-requests)&client_id=$WRONG" > /dev/null
eq "approving against the wrong client is refused" \
   "$(q "SELECT status FROM client_access_requests WHERE id=$RID;")" "pending"

post "/portal-requests/$RID/approve" --data "_token=$(tok /portal-requests)&client_id=$TCID" > /dev/null
eq "against the right one it is approved" "$(q "SELECT status FROM client_access_requests WHERE id=$RID;")" "approved"
eq "an account is prepared"               "$(q "SELECT COUNT(*) FROM client_users WHERE email='portalreq@example.co.ke';")" "1"
eq "waiting on their code"                "$(q "SELECT status FROM client_users WHERE email='portalreq@example.co.ke';")" "pending"
eq "and the code was texted to them"    "$(( $(q "SELECT COUNT(*) FROM notifications WHERE event='client_otp' AND channel='sms';") - SMS_BEFORE ))" "1"

echo ""
echo "=== 7. What a client can see of their own account ==="
# A client with real documents behind them.
DCID=$(q "SELECT client_id FROM documents WHERE doc_type='invoice' AND status<>'draft' AND client_id IS NOT NULL GROUP BY client_id ORDER BY COUNT(*) DESC LIMIT 1;")
DEMAIL="portaldocs@example.co.ke"
DHASH=$($PHP -r 'echo password_hash("portal2026", PASSWORD_DEFAULT);')

$MYSQL -e "DELETE FROM client_users WHERE email='$DEMAIL';
           INSERT INTO client_users (client_id,name,email,password_hash,status,email_verified_at)
           VALUES ($DCID,'Portal Docs','$DEMAIL','$DHASH','active',NOW());"

rm -f "$PJ"
ppost /portal/login --data-urlencode "_token=$(ptok /portal/login)"   --data-urlencode "email=$DEMAIL" --data-urlencode "password=portal2026" > /dev/null

eq "their quotations open" "$(pcode /portal/quotations)" "200"
eq "their invoices open"   "$(pcode /portal/invoices)"   "200"
eq "their statement opens" "$(pcode /portal/statement)"  "200"

# Every invoice they should see, and no more.
VISIBLE=$(q "SELECT COUNT(*) FROM documents WHERE client_id=$DCID AND doc_type='invoice' AND status<>'draft' AND approval_status<>'pending';")
eq "the list shows exactly their invoices"    "$(pget /portal/invoices | grep -c 'portal/invoices/')" "$VISIBLE"

# The statement has to reconcile, or it is worse than not showing one.
DB_BALANCE=$(q "SELECT FORMAT(COALESCE(SUM(balance),0),2) FROM documents WHERE client_id=$DCID AND doc_type='invoice' AND status NOT IN ('draft','cancelled','paid') AND approval_status<>'pending';")
has "the statement balance matches the books" "$(pget /portal/statement)" "$DB_BALANCE"

echo ""
echo "=== 8. And nothing of anybody else's ==="
THEIRS=$(q "SELECT id FROM documents WHERE doc_type='invoice' AND status<>'draft' AND client_id IS NOT NULL AND client_id<>$DCID ORDER BY id DESC LIMIT 1;")
eq "another client's invoice is not found" "$(pcode "/portal/invoices/$THEIRS")" "404"

# The approval rule reaches the portal too: a price still waiting on an
# administrator must not reach the client by this route either.
MINE=$(q "SELECT id FROM documents WHERE client_id=$DCID AND doc_type='invoice' AND status<>'draft' ORDER BY id DESC LIMIT 1;")
$MYSQL -e "UPDATE documents SET approval_status='pending' WHERE id=$MINE;"
eq "an invoice awaiting approval is hidden" "$(pcode "/portal/invoices/$MINE")" "404"
$MYSQL -e "UPDATE documents SET approval_status='approved' WHERE id=$MINE;"
eq "and visible once approved"              "$(pcode "/portal/invoices/$MINE")" "200"

# A draft is still ours, not theirs.
$MYSQL -e "UPDATE documents SET status='draft' WHERE id=$MINE;"
eq "a draft is not shown"                   "$(pcode "/portal/invoices/$MINE")" "404"
$MYSQL -e "UPDATE documents SET status='sent' WHERE id=$MINE;"

$MYSQL -e "DELETE FROM client_users WHERE email='$DEMAIL';"

echo ""
echo "=== 9. The two systems do not touch ==="
# A portal session must never satisfy the staff guard, and the reverse.
rm -f "$PJ"
ppost /portal/login --data-urlencode "_token=$(ptok /portal/login)" --data-urlencode "email=$NEW" --data-urlencode "password=hunter2pass" > /dev/null
eq "a client can reach the portal"        "$(pcode /portal)" "200"
ne "but not the staff dashboard"          "$(pcode /dashboard)" "200"
ne "nor the client list"                  "$(pcode /clients)" "200"
ne "nor settings"                         "$(pcode /settings)" "200"

# And a staff session is not a portal session either.
signin_admin
ne "a staff session is not a portal one"  "$(code /portal)" "200"


echo ""
echo "=== 9. Renewals, the catalogue, and asking about a price ==="
RCID=$(q "SELECT client_id FROM subscriptions WHERE client_id IS NOT NULL LIMIT 1;")
[ -z "$RCID" ] && RCID=$DCID
REMAIL="portalcat@example.co.ke"
RHASH=$($PHP -r 'echo password_hash("portal2026", PASSWORD_DEFAULT);')

$MYSQL -e "DELETE FROM client_users WHERE email='$REMAIL';
           DELETE FROM price_requests WHERE client_id=$RCID;
           DELETE FROM staff_notifications WHERE event='price_request';
           UPDATE settings SET setting_value='1' WHERE setting_key IN ('portal_show_prices','portal_show_inventory');
           INSERT INTO client_users (client_id,name,email,password_hash,status,email_verified_at)
           VALUES ($RCID,'Portal Catalogue','$REMAIL','$RHASH','active',NOW());"

rm -f "$PJ"
ppost /portal/login --data-urlencode "_token=$(ptok /portal/login)" \
  --data-urlencode "email=$REMAIL" --data-urlencode "password=portal2026" > /dev/null

eq "their renewals open"  "$(pcode /portal/services)"  "200"
eq "the catalogue opens"  "$(pcode /portal/catalogue)" "200"
eq "their requests open"  "$(pcode /portal/requests)"  "200"

# Everything active, and nothing that is not.
eq "every active service is offered" \
   "$(pget /portal/catalogue | grep -c 'value="service:')" \
   "$(q "SELECT COUNT(*) FROM services WHERE is_active=1;")"
eq "and every active product" \
   "$(pget /portal/catalogue | grep -c 'value="inventory:')" \
   "$(q "SELECT COUNT(*) FROM inventory_items WHERE is_active=1;")"

echo ""
echo "=== 10. Ticking things and asking ==="
SVC1=$(q "SELECT id FROM services WHERE is_active=1 ORDER BY id LIMIT 1;")
SVC2=$(q "SELECT id FROM services WHERE is_active=1 ORDER BY id LIMIT 1 OFFSET 1;")
INV1=$(q "SELECT id FROM inventory_items WHERE is_active=1 ORDER BY id LIMIT 1;")
REALPRICE=$(q "SELECT price FROM services WHERE id=$SVC1;")

ppost /portal/catalogue/ask \
  --data-urlencode "_token=$(ptok /portal/catalogue)" \
  --data-urlencode "items[]=service:$SVC1" --data-urlencode "items[]=service:$SVC2" \
  --data-urlencode "items[]=inventory:$INV1" \
  --data-urlencode "kind=discount" \
  --data-urlencode "note=All three for the Westlands branch." > /dev/null

eq "the request is recorded"    "$(q "SELECT COUNT(*) FROM price_requests WHERE client_id=$RCID;")" "1"
eq "with all three items"       "$(q "SELECT COUNT(*) FROM price_request_items;")" "3"
eq "and what they were asking"  "$(q "SELECT kind FROM price_requests WHERE client_id=$RCID;")" "discount"
eq "sales are told"             "$(q "SELECT IF(COUNT(*)>0,'yes','no') FROM staff_notifications WHERE event='price_request';")" "yes"
has "it shows on their page"    "$(pget /portal/requests)" "Westlands branch"

echo ""
echo "=== 11. The price we answer about is the price we hold ==="
# A price posted from a browser is a number somebody could have typed.
$MYSQL -e "DELETE FROM price_requests WHERE client_id=$RCID;"
ppost /portal/catalogue/ask \
  --data-urlencode "_token=$(ptok /portal/catalogue)" \
  --data-urlencode "items[]=service:$SVC1" \
  --data-urlencode "price_snapshot=1" --data-urlencode "name_snapshot=Free" \
  --data-urlencode "kind=quotation" > /dev/null

eq "a forged price is ignored" "$(q "SELECT price_snapshot FROM price_request_items ORDER BY id DESC LIMIT 1;")" "$REALPRICE"
ne "and a forged name"         "$(q "SELECT name_snapshot FROM price_request_items ORDER BY id DESC LIMIT 1;")" "Free"

# Nothing valid ticked means nothing recorded, rather than an empty ask.
$MYSQL -e "DELETE FROM price_requests WHERE client_id=$RCID;"
ppost /portal/catalogue/ask --data "_token=$(ptok /portal/catalogue)&items[]=service:999999&kind=quotation" > /dev/null
eq "a made-up item makes no request" "$(q "SELECT COUNT(*) FROM price_requests WHERE client_id=$RCID;")" "0"

$MYSQL -e "UPDATE services SET is_active=0 WHERE id=$SVC1;"
ppost /portal/catalogue/ask --data "_token=$(ptok /portal/catalogue)&items[]=service:$SVC1&kind=quotation" > /dev/null
eq "nor a withdrawn one"             "$(q "SELECT COUNT(*) FROM price_requests WHERE client_id=$RCID;")" "0"
$MYSQL -e "UPDATE services SET is_active=1 WHERE id=$SVC1;"

# A browser can post items[0][x]=1 as easily as items[]=service:1.
ppost /portal/catalogue/ask --data "_token=$(ptok /portal/catalogue)&items[0][x]=1&kind=quotation" > /dev/null
eq "a malformed tick is simply not a tick" "$(q "SELECT COUNT(*) FROM price_requests WHERE client_id=$RCID;")" "0"

echo ""
echo "=== 12. Prices can be turned off ==="
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='portal_show_prices';"
eq "with prices off, none are shown" "$(pget /portal/catalogue | grep -c 'portal-cat__price')" "0"
eq "but the catalogue still lists what we do" \
   "$(pget /portal/catalogue | grep -c 'value="service:')" \
   "$(q "SELECT COUNT(*) FROM services WHERE is_active=1;")"
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='portal_show_prices';"

$MYSQL -e "DELETE FROM price_requests WHERE client_id=$RCID;
           DELETE FROM client_users WHERE email='$REMAIL';
           DELETE FROM staff_notifications WHERE event='price_request';"


echo ""
echo "=== 13. Paying an invoice from the portal ==="
PCID=$(q "SELECT client_id FROM documents WHERE doc_type='invoice' AND balance>0 AND status NOT IN ('draft','cancelled','paid') AND approval_status<>'pending' AND client_id IS NOT NULL GROUP BY client_id ORDER BY COUNT(*) DESC LIMIT 1;")
PEMAIL="portalpay@example.co.ke"
PHASH=$($PHP -r 'echo password_hash("portal2026", PASSWORD_DEFAULT);')

$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key IN ('kopokopo_enabled','portal_uploads_enabled');
           DELETE FROM client_users WHERE email='$PEMAIL';
           INSERT INTO client_users (client_id,name,email,phone,password_hash,status,email_verified_at)
           VALUES ($PCID,'Portal Payer','$PEMAIL','0712345678','$PHASH','active',NOW());"

INV=$(q "SELECT id FROM documents WHERE client_id=$PCID AND doc_type='invoice' AND balance>0 AND status NOT IN ('draft','cancelled','paid') AND approval_status<>'pending' ORDER BY id DESC LIMIT 1;")
BAL=$(q "SELECT balance FROM documents WHERE id=$INV;")
$MYSQL -e "DELETE FROM stk_requests WHERE document_id=$INV;"

rm -f "$PJ"
ppost /portal/login --data-urlencode "_token=$(ptok /portal/login)" \
  --data-urlencode "email=$PEMAIL" --data-urlencode "password=portal2026" > /dev/null

has "the invoice offers M-Pesa" "$(pget "/portal/invoices/$INV")" "portal-pay"

# There is no amount field, and the server would ignore one. A posted
# amount would let anybody settle a 50,000 invoice for 5.
eq "and no amount field to tamper with" "$(pget "/portal/invoices/$INV" | grep -c 'name="amount"')" "0"

ppost "/portal/invoices/$INV/pay" \
  --data "_token=$(ptok "/portal/invoices/$INV")&phone=0712345678&amount=5&balance=5" > /dev/null

eq "a prompt is recorded"        "$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$INV;")" "1"
eq "for the invoice's own amount" "$(q "SELECT amount FROM stk_requests WHERE document_id=$INV ORDER BY id DESC LIMIT 1;")" "$BAL"

echo ""
echo "=== 14. And only their own invoices ==="
OTHERINV=$(q "SELECT id FROM documents WHERE doc_type='invoice' AND client_id IS NOT NULL AND client_id<>$PCID AND balance>0 ORDER BY id DESC LIMIT 1;")
OTHERBEFORE=$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$OTHERINV;")
eq "another client's invoice is not found" \
   "$(ppost "/portal/invoices/$OTHERINV/pay" --data "_token=$(ptok "/portal/invoices/$INV")&phone=0712345678")" "404"
eq "and no prompt is sent for it" \
   "$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$OTHERINV;")" "$OTHERBEFORE"

# One prompt at a time: a second while the first is live only confuses
# the person holding the handset.
$MYSQL -e "DELETE FROM stk_requests WHERE document_id=$INV;
           INSERT INTO stk_requests (document_id,client_id,phone,amount,status,created_at)
           VALUES ($INV,$PCID,'254712345678',$BAL,'pending',NOW());"
ppost "/portal/invoices/$INV/pay" --data "_token=$(ptok "/portal/invoices/$INV")&phone=0712345678" > /dev/null
eq "no second prompt while one is live" "$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$INV;")" "1"

# Capped per invoice per hour, so this cannot be used to pester a number.
$MYSQL -e "DELETE FROM stk_requests WHERE document_id=$INV;
           INSERT INTO stk_requests (document_id,client_id,phone,amount,status,created_at)
           SELECT $INV,$PCID,'254712345678',$BAL,'failed',NOW() FROM information_schema.tables LIMIT 6;"
ppost "/portal/invoices/$INV/pay" --data "_token=$(ptok "/portal/invoices/$INV")&phone=0712345678" > /dev/null
eq "and capped at six an hour"        "$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$INV;")" "6"
has "with a reason given"             "$(pget "/portal/invoices/$INV")" "Too many payment attempts"

$MYSQL -e "DELETE FROM stk_requests WHERE document_id=$INV;"

echo ""
echo "=== 15. Sending us artwork ==="
UIMG="C:/Users/Shanfix/AppData/Local/Temp/shanfix-portal"
UIMGB="/c/Users/Shanfix/AppData/Local/Temp/shanfix-portal"
rm -rf "$UIMGB"; mkdir -p "$UIMGB"
$PHP "$ROOT/tests/make_png.php" "$UIMG/art.png" > /dev/null
printf '<?php echo "pwned"; ?>' > "$UIMGB/evil.png"

$MYSQL -e "DELETE FROM portal_uploads WHERE client_id=$PCID;
           DELETE FROM staff_notifications WHERE event='portal_upload';"

eq "the page opens" "$(pcode /portal/uploads)" "200"

curl -s -o /dev/null -b "$PJ" -c "$PJ" -X POST "$BASE/portal/uploads" \
  -F "_token=$(ptok /portal/uploads)" \
  -F "note=Banner artwork for the expo" \
  -F "files[]=@$UIMG/art.png"

eq "the file is stored"    "$(q "SELECT COUNT(*) FROM portal_uploads WHERE client_id=$PCID;")" "1"
eq "with what it is for"   "$(q "SELECT IF(note LIKE '%expo%','yes','no') FROM portal_uploads WHERE client_id=$PCID LIMIT 1;")" "yes"
eq "and production told"   "$(q "SELECT IF(COUNT(*)>0,'yes','no') FROM staff_notifications WHERE event='portal_upload';")" "yes"
has "it is listed back"    "$(pget /portal/uploads)" "art.png"

# The same header inspection the staff side gets. A renamed script must
# never reach the filesystem, whichever door it came through.
curl -s -o /dev/null -b "$PJ" -c "$PJ" -X POST "$BASE/portal/uploads" \
  -F "_token=$(ptok /portal/uploads)" -F "files[]=@$UIMG/evil.png"
eq "a disguised script is refused" "$(q "SELECT COUNT(*) FROM portal_uploads WHERE client_id=$PCID;")" "1"

rm -rf "$UIMGB"
$MYSQL -e "DELETE FROM portal_uploads WHERE client_id=$PCID;
           DELETE FROM client_users WHERE email='$PEMAIL';
           DELETE FROM staff_notifications WHERE event='portal_upload';"


echo ""
echo "=== 16. The two doors point at each other ==="
# The portal is no use if nobody can find it. A customer arriving at the
# staff sign-in has no account that will work there and no way to know
# it, so the way to their own has to be on the page.
STAFF=$(curl -s "$BASE/login")
has "the staff page offers the portal"   "$STAFF" 'href="/portal/login"'
has "and a way to set access up"         "$STAFF" 'href="/portal/start"'

# And the reverse, for somebody who works here and followed the wrong link.
PORTAL=$(curl -s "$BASE/portal/login")
has "the portal points staff back"       "$PORTAL" 'href="/login"'
# Asserted on the link, not the wording. The button used to say "Set up
# my access"; it now says setting up and resetting are the same thing,
# which is true and worth saying, and the test should not have to be
# rewritten every time the copy improves.
has "it offers setting access up"        "$PORTAL" 'href="/portal/start"'
has "and says that resets live there too" "$PORTAL" "forgotten your password"
has "and asking us, with no email"       "$PORTAL" "Ask us to set it up"

# Both doors are dressed by the same shell, so a client who has been sent a
# link sees the company they expect rather than a bare form. The badge is
# the only thing that differs, and it has to differ — two accounts that
# look alike and do not work in each other's page is the whole confusion.
has "the staff door is labelled"    "$STAFF"  "login__kind--staff"
has "the customer door is labelled" "$PORTAL" "login__kind--client"
ne "and they are not the same label"    "$(echo "$STAFF" | grep -c 'login__kind--client')" "1"

# Signed out, there is nothing to navigate to. The portal's own top bar
# used to render on these pages with an empty nav, which reads as a broken
# page rather than a sign-in one.
eq "no app chrome before signing in" "$(echo "$PORTAL" | grep -c 'portal-nav')" "0"

# Every one of those has to actually go somewhere.
for path in /login /portal/login /portal/start /portal/request-access; do
  eq "$path resolves" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$path")" "200"
done

# With the portal switched off, the staff page must not advertise it.
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='portal_enabled';"
eq "and it is not offered when the portal is off"    "$(curl -s "$BASE/login" | grep -c 'Are you a customer')" "0"
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='portal_enabled';"

echo ""
echo "=== 17. Tidy up ==="
$MYSQL -e "DELETE FROM client_users WHERE email IN ('$NEW','portalreq@example.co.ke','$CEMAIL');
           DELETE FROM clients WHERE email='$NEW';
           DELETE FROM client_otps;
           DELETE FROM client_access_requests;
           DELETE FROM notifications WHERE event='client_otp';
           DELETE FROM staff_notifications WHERE event='portal_access_request';
           DELETE FROM activity_log WHERE action IN ('login_failed','portal_access_approved','portal_access_rejected');"
rm -f "$PJ"
eq "the test accounts are gone" "$(q "SELECT COUNT(*) FROM client_users WHERE email IN ('$NEW','portalreq@example.co.ke');")" "0"

restore_settings
eq "the settings this suite changed are put back" "done" "done"

report
