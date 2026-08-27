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
echo "=== 7. The two systems do not touch ==="
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
echo "=== 8. Tidy up ==="
$MYSQL -e "DELETE FROM client_users WHERE email IN ('$NEW','portalreq@example.co.ke','$CEMAIL');
           DELETE FROM clients WHERE email='$NEW';
           DELETE FROM client_otps;
           DELETE FROM client_access_requests;
           DELETE FROM notifications WHERE event='client_otp';
           DELETE FROM staff_notifications WHERE event='portal_access_request';
           DELETE FROM activity_log WHERE action IN ('login_failed','portal_access_approved','portal_access_rejected');"
rm -f "$PJ"
eq "the test accounts are gone" "$(q "SELECT COUNT(*) FROM client_users WHERE email IN ('$NEW','portalreq@example.co.ke');")" "0"

report
