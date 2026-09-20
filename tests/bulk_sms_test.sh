#!/bin/bash
# The SMS platform, brought in from the old standalone Bulk SMS system.
#
# What the platform sells is units, so most of what matters here is
# money: that a unit cannot be created or lost, that nobody is charged
# for a message the network refused, and that nobody can send what they
# have not bought. The engine's own proofs run in
# tests/helpers/bulk_sms_engine.php against a fake gateway; this suite
# covers the office screens, permissions and the delivery-report webhook.
#
# NOTHING HERE SENDS A REAL TEXT. The test database holds a real Onfon
# key and the live gateway address. Every send in this suite goes to a
# fake gateway started on 127.0.0.1, and the settings it changes are put
# back on exit, whether it passes or not.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

FAKE_PORT="${FAKE_ONFON_PORT:-8098}"
FAKE_URL="http://127.0.0.1:${FAKE_PORT}"
FAKE_PID=""

# What the gateway settings were before we touched them.
SAVED_BASE=$(q "SELECT setting_value FROM settings WHERE setting_key='onfon_base_url';")
SAVED_CID=$(q "SELECT setting_value FROM settings WHERE setting_key='onfon_client_id';")
SAVED_KEY=$(q "SELECT setting_value FROM settings WHERE setting_key='onfon_api_key';")
SAVED_ACC=$(q "SELECT setting_value FROM settings WHERE setting_key='onfon_access_key';")
SAVED_DLR=$(q "SELECT setting_value FROM settings WHERE setting_key='bulk_sms_dlr_token';")

restore() {
  [ -n "$FAKE_PID" ] && kill "$FAKE_PID" 2>/dev/null

  # Put every setting back exactly as it was, so a later suite — or the
  # developer's own database — never points at the fake gateway.
  for pair in "onfon_base_url=$SAVED_BASE" "onfon_client_id=$SAVED_CID" \
              "onfon_api_key=$SAVED_KEY" "onfon_access_key=$SAVED_ACC" \
              "bulk_sms_dlr_token=$SAVED_DLR"; do
    k="${pair%%=*}"; v="${pair#*=}"
    if [ -z "$v" ]; then
      $MYSQL -e "DELETE FROM settings WHERE setting_key='$k';"
    else
      $MYSQL -e "INSERT INTO settings (setting_key, setting_value) VALUES ('$k', '$v')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);"
    fi
  done

  scrub_sms
}
trap restore EXIT

scrub_sms() {
  # Everything this suite makes is tagged SMSTEST.
  $MYSQL -e "
    DELETE m FROM bulk_messages m JOIN bulk_accounts a ON a.id = m.account_id
      JOIN clients c ON a.owner_type='client' AND c.id = a.owner_id WHERE c.name LIKE 'SMSTEST%';
    DELETE ca FROM bulk_campaigns ca JOIN bulk_accounts a ON a.id = ca.account_id
      JOIN clients c ON a.owner_type='client' AND c.id = a.owner_id WHERE c.name LIKE 'SMSTEST%';
    DELETE s FROM bulk_sender_ids s JOIN bulk_accounts a ON a.id = s.account_id
      JOIN clients c ON a.owner_type='client' AND c.id = a.owner_id WHERE c.name LIKE 'SMSTEST%';
    DELETE p FROM bulk_purchases p JOIN bulk_accounts a ON a.id = p.account_id
      JOIN clients c ON a.owner_type='client' AND c.id = a.owner_id WHERE c.name LIKE 'SMSTEST%';
    DELETE l FROM bulk_ledger l JOIN bulk_accounts a ON a.id = l.account_id
      JOIN clients c ON a.owner_type='client' AND c.id = a.owner_id WHERE c.name LIKE 'SMSTEST%';
    DELETE a FROM bulk_accounts a JOIN clients c ON a.owner_type='client' AND c.id = a.owner_id
      WHERE c.name LIKE 'SMSTEST%';
    DELETE FROM clients WHERE name LIKE 'SMSTEST%';
    DELETE FROM bulk_sender_ids WHERE sender_id LIKE 'SMSTESTP%';
    DELETE FROM stk_requests WHERE kopokopo_id LIKE 'smstest-kk-%';
    DELETE FROM bulk_plans WHERE name LIKE 'SMSTEST%';
    DELETE l FROM bulk_ledger l JOIN bulk_accounts a ON a.id = l.account_id
      JOIN partners p ON a.owner_type='partner' AND p.id = a.owner_id WHERE p.name LIKE 'SMSTEST%';
    DELETE b FROM bulk_purchases b JOIN bulk_accounts a ON a.id = b.account_id OR a.id = b.seller_account_id
      JOIN partners p ON a.owner_type='partner' AND p.id = a.owner_id WHERE p.name LIKE 'SMSTEST%';
    DELETE a FROM bulk_accounts a JOIN partners p ON a.owner_type='partner' AND p.id = a.owner_id
      WHERE p.name LIKE 'SMSTEST%';
    DELETE FROM partners WHERE name LIKE 'SMSTEST%';
    DELETE FROM staff_notifications WHERE event='bulk_sender_requested';
    DELETE FROM notifications WHERE event LIKE 'bulk\_%'
      AND (recipient LIKE '%example.test' OR recipient LIKE '%smstest%'
           OR recipient LIKE '2547000002%' OR body LIKE '%SMSTEST%');
  "

  # The house survives the scrub above, and its balance and ledger would
  # carry into the next run — leaving it holding units this suite expects
  # it not to have, and counting ledger rows from last time. Both are put
  # back to nothing together, so the two still agree.
  $MYSQL -e "
    DELETE l FROM bulk_ledger l JOIN bulk_accounts a ON a.id = l.account_id WHERE a.owner_type = 'house';
    UPDATE bulk_accounts SET sms_units = 0 WHERE owner_type = 'house';
  "
}

scrub_sms

# ---------------------------------------------------------------------
# A gateway that answers like Onfon and sends nothing
# ---------------------------------------------------------------------
$PHP -S 127.0.0.1:$FAKE_PORT "$ROOT/tests/helpers/fake_onfon.php" >/dev/null 2>&1 &
FAKE_PID=$!

for _ in 1 2 3 4 5 6 7 8 9 10; do
  curl -s -o /dev/null --max-time 1 "$FAKE_URL/v1/sms/Balance" && break
  sleep 0.3
done

echo ""
echo "=== 1. The engine, against a fake gateway ==="
# Charging, refunds, retries, resume-after-crash, running out of units,
# and every balance equalling its ledger. One line per check in there.
ENGINE=$($PHP "$ROOT/tests/helpers/bulk_sms_engine.php" "$FAKE_URL" 2>/dev/null | tail -1)
ENGINE_PASS=$(printf '%s' "$ENGINE" | sed -n 's/.*passed \([0-9]*\).*/\1/p')
ENGINE_FAIL=$(printf '%s' "$ENGINE" | sed -n 's/.*failed \([0-9]*\).*/\1/p')
eq "the engine proofs all pass" "${ENGINE_FAIL:-none}" "0"
[ "${ENGINE_PASS:-0}" -gt 50 ] \
  && ok "and there are plenty of them" "$ENGINE_PASS checks" \
  || bad "and there are plenty of them" "${ENGINE_PASS:-0} checks" "more than 50"

echo ""
echo "=== 2. The office can see the platform ==="
signin_admin
eq "the overview opens"      "$(code /bulk-sms)" "200"
eq "accounts"                "$(code /bulk-sms/accounts)" "200"
eq "campaigns"               "$(code /bulk-sms/campaigns)" "200"
eq "delivery reports"        "$(code /bulk-sms/messages)" "200"
eq "sender IDs"              "$(code /bulk-sms/sender-ids)" "200"
eq "purchases"               "$(code /bulk-sms/purchases)" "200"
eq "price plans"             "$(code /bulk-sms/plans)" "200"
eq "the gateway settings"    "$(code /bulk-sms/settings)" "200"
has "and the house balance is on the overview" "$(page /bulk-sms)" "Units we hold with Onfon"

echo ""
echo "=== 3. Opening an account for a client ==="
CID=$(q "INSERT INTO clients (client_code, client_type, name, email, phone, status)
         VALUES ('SMSTEST-1','company','SMSTEST Ltd','smstest@example.test','254700000201','active');
         SELECT LAST_INSERT_ID();")
T=$(tok /bulk-sms/accounts)
eq "the office opens one"  "$(post /bulk-sms/accounts --data "_token=$T&owner_type=client&owner_id=$CID")" "302"
ACC=$(q "SELECT id FROM bulk_accounts WHERE owner_type='client' AND owner_id=$CID;")
ne "it exists"             "$ACC" ""
eq "it starts empty"       "$(q "SELECT sms_units FROM bulk_accounts WHERE id=$ACC;")" "0.0000"
eq "and buys from the house" \
   "$(q "SELECT p.owner_type FROM bulk_accounts a JOIN bulk_accounts p ON p.id=a.parent_id WHERE a.id=$ACC;")" "house"
eq "its page opens"        "$(code /bulk-sms/accounts/$ACC)" "200"

echo ""
echo "=== 4. Units come out of the house, never from nowhere ==="
HOUSE=$(q "SELECT id FROM bulk_accounts WHERE owner_type='house';")
HOUSE_BEFORE=$(q "SELECT sms_units FROM bulk_accounts WHERE id=$HOUSE;")
T=$(tok /bulk-sms/accounts/$ACC)
post /bulk-sms/accounts/$ACC/units --data "_token=$T&action=give&units=500&note=SMSTEST" > /dev/null
eq "an empty house cannot give units" "$(q "SELECT sms_units FROM bulk_accounts WHERE id=$ACC;")" "0.0000"

# Fill the house from the fake gateway, the way the office would.
$MYSQL -e "INSERT INTO settings (setting_key,setting_value) VALUES
             ('onfon_base_url','$FAKE_URL'),('onfon_client_id','test'),('onfon_api_key','test'),('onfon_access_key','test')
           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);"
echo "20000" > "${TMPDIR:-/tmp}/fake_onfon_balance.txt"
T=$(tok /bulk-sms)
eq "syncing with the gateway works" "$(post /bulk-sms/sync --data "_token=$T")" "302"
eq "and the house now holds what the gateway says" \
   "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$HOUSE;")" "20000"

T=$(tok /bulk-sms/accounts/$ACC)
post /bulk-sms/accounts/$ACC/units --data "_token=$T&action=give&units=500&note=SMSTEST" > /dev/null
eq "now the client can be given units" "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$ACC;")" "500"
eq "and the house is exactly 500 lighter" \
   "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$HOUSE;")" "19500"
eq "both sides are in the ledger" \
   "$(q "SELECT COUNT(*) FROM bulk_ledger WHERE ref_type IS NULL AND note='SMSTEST' AND kind IN ('transfer_in','transfer_out');")" "2"
eq "the balance equals its ledger" \
   "$(q "SELECT ROUND(a.sms_units - COALESCE((SELECT SUM(l.amount) FROM bulk_ledger l WHERE l.account_id=a.id),0),4)
         FROM bulk_accounts a WHERE a.id=$ACC;")" "0.0000"

T=$(tok /bulk-sms/accounts/$ACC)
post /bulk-sms/accounts/$ACC/units --data "_token=$T&action=take&units=100000&note=SMSTEST" > /dev/null
eq "taking back more than it holds does nothing" \
   "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$ACC;")" "500"

echo ""
echo "=== 5. Sender IDs are the networks' business, not ours ==="
T=$(tok /bulk-sms/sender-ids)
eq "a made-up sender ID is refused" \
   "$(post /bulk-sms/sender-ids --data "_token=$T&account_id=$ACC&sender_id=%21%21")" "302"
eq "and nothing was added" "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE account_id=$ACC;")" "0"

T=$(tok /bulk-sms/sender-ids)
post /bulk-sms/sender-ids --data "_token=$T&account_id=$ACC&sender_id=SMSTEST&purpose=testing" > /dev/null
eq "a registered one is added and approved" \
   "$(q "SELECT status FROM bulk_sender_ids WHERE account_id=$ACC AND sender_id='SMSTEST';")" "approved"

# A request from a customer, declined with a reason.
SID=$(q "INSERT INTO bulk_sender_ids (account_id, sender_id, purpose, status)
         VALUES ($ACC,'SMSTEST2','because','pending'); SELECT LAST_INSERT_ID();")
T=$(tok /bulk-sms/sender-ids)
post /bulk-sms/sender-ids/$SID/decide --data "_token=$T&decision=reject" > /dev/null
eq "declining without a reason is refused" \
   "$(q "SELECT status FROM bulk_sender_ids WHERE id=$SID;")" "pending"
T=$(tok /bulk-sms/sender-ids)
post /bulk-sms/sender-ids/$SID/decide --data "_token=$T&decision=reject&reason=Needs+a+letter" > /dev/null
eq "with one, it is declined"  "$(q "SELECT status FROM bulk_sender_ids WHERE id=$SID;")" "rejected"
eq "and the customer is told"  "$(q "SELECT COUNT(*) FROM notifications WHERE event='bulk_sender_rejected' AND recipient='smstest@example.test';")" "1"

echo ""
echo "=== 6. Selling units ==="
T=$(tok /bulk-sms/purchases)
post /bulk-sms/purchases --data "_token=$T&account_id=$ACC&units=200&method=bank&transaction_ref=SMSTESTREF" > /dev/null
eq "a recorded sale hands the units over" "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$ACC;")" "700"
eq "it is marked paid" \
   "$(q "SELECT status FROM bulk_purchases WHERE account_id=$ACC AND transaction_ref='SMSTESTREF';")" "completed"
eq "priced at the standard rate" \
   "$(q "SELECT ROUND(amount) FROM bulk_purchases WHERE account_id=$ACC AND transaction_ref='SMSTESTREF';")" "200"
eq "and the house sold them" \
   "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$HOUSE;")" "19300"

# A purchase waiting for money, confirmed later, and never twice.
PID=$(q "INSERT INTO bulk_purchases (account_id, seller_account_id, units, amount, method, status)
         VALUES ($ACC, $HOUSE, 50, 50, 'manual_mpesa', 'pending'); SELECT LAST_INSERT_ID();")
T=$(tok /bulk-sms/purchases)
post /bulk-sms/purchases/$PID/complete --data "_token=$T&transaction_ref=SMSTESTMPESA" > /dev/null
eq "confirming a payment adds the units" "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$ACC;")" "750"
T=$(tok /bulk-sms/purchases)
post /bulk-sms/purchases/$PID/complete --data "_token=$T" > /dev/null
eq "confirming it twice does not"        "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$ACC;")" "750"

echo ""
echo "=== 7. Price plans ==="
T=$(tok /bulk-sms/plans)
post /bulk-sms/plans --data "_token=$T&name=SMSTEST+Plan&units=1000&price=900&sort_order=99" > /dev/null
PLAN=$(q "SELECT id FROM bulk_plans WHERE name='SMSTEST Plan';")
ne "a plan can be added" "$PLAN" ""
T=$(tok /bulk-sms/plans)
post /bulk-sms/plans/$PLAN --data "_token=$T&name=SMSTEST+Plan&units=1000&price=800&sort_order=99" > /dev/null
eq "and edited"          "$(q "SELECT ROUND(price) FROM bulk_plans WHERE id=$PLAN;")" "800"
T=$(tok /bulk-sms/plans)
post /bulk-sms/plans/$PLAN/toggle --data "_token=$T" > /dev/null
eq "and taken off sale"  "$(q "SELECT is_active FROM bulk_plans WHERE id=$PLAN;")" "0"

# A plan somebody has bought is switched off rather than deleted, so
# their purchase history still says what they bought.
$MYSQL -e "UPDATE bulk_plans SET is_active=1 WHERE id=$PLAN;"
$MYSQL -e "INSERT INTO bulk_purchases (account_id, seller_account_id, plan_id, units, amount, method, status)
           VALUES ($ACC, $HOUSE, $PLAN, 1000, 800, 'bank', 'completed');"
T=$(tok /bulk-sms/plans)
post /bulk-sms/plans/$PLAN/delete --data "_token=$T" > /dev/null
eq "a plan that has been bought is kept" "$(q "SELECT COUNT(*) FROM bulk_plans WHERE id=$PLAN;")" "1"
eq "but taken off sale"                  "$(q "SELECT is_active FROM bulk_plans WHERE id=$PLAN;")" "0"

echo ""
echo "=== 8. Campaigns, as the office sees them ==="
CAMP=$(q "INSERT INTO bulk_campaigns (account_id, name, sender_id, message, recipients, total_count, status)
          VALUES ($ACC,'SMSTEST campaign','SMSTEST','hello','+254700000201',1,'queued'); SELECT LAST_INSERT_ID();")
eq "a campaign page opens" "$(code /bulk-sms/campaigns/$CAMP)" "200"
T=$(tok /bulk-sms/campaigns/$CAMP)
post /bulk-sms/campaigns/$CAMP/cancel --data "_token=$T" > /dev/null
eq "it can be stopped"     "$(q "SELECT status FROM bulk_campaigns WHERE id=$CAMP;")" "cancelled"
T=$(tok /bulk-sms/campaigns/$CAMP)
post /bulk-sms/campaigns/$CAMP/retry --data "_token=$T" > /dev/null
# Retrying starts a worker straight away, so by the time this looks it
# may already be sending or even finished. Any of those means it is on
# its way again; only the ones that mean it is not are a failure.
RETRIED=$(q "SELECT status FROM bulk_campaigns WHERE id=$CAMP;")
case "$RETRIED" in
  queued|sending|completed) ok  "and started again" "$RETRIED";;
  *)                        bad "and started again" "$RETRIED" "on its way again";;
esac

echo ""
echo "=== 9. Delivery reports come back from the gateway ==="
$MYSQL -e "INSERT INTO bulk_messages (account_id, sender_id, recipient, message, units_charged, status, gateway_msg_id, sent_at)
           VALUES ($ACC,'SMSTEST','+254700000201','hi',1,'sent','smstest-dlr-1',NOW());"
DLR=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/sms-dlr" \
      --data "MessageId=smstest-dlr-1&Status=1&StatusDescription=DELIVRD&MobileNumber=254700000201")
eq "the webhook answers"        "$DLR" "200"
eq "and the message is delivered" \
   "$(q "SELECT status FROM bulk_messages WHERE gateway_msg_id='smstest-dlr-1';")" "delivered"
eq "with the carrier's own word kept" \
   "$(q "SELECT dlr_status FROM bulk_messages WHERE gateway_msg_id='smstest-dlr-1';")" "DELIVRD"

# The old platform's address is what is typed into the Onfon portal.
$MYSQL -e "UPDATE bulk_messages SET status='sent', dlr_status=NULL WHERE gateway_msg_id='smstest-dlr-1';"
curl -s -o /dev/null -X POST "$BASE/webhooks/sms-dlr.php" \
     --data "MessageId=smstest-dlr-1&Status=3&StatusDescription=AbsentSubscriber"
eq "the old .php address still works" \
   "$(q "SELECT status FROM bulk_messages WHERE gateway_msg_id='smstest-dlr-1';")" "undelivered"

# A message we already refused must not be turned into a delivery by a
# late receipt — its units were refunded.
$MYSQL -e "INSERT INTO bulk_messages (account_id, sender_id, recipient, message, units_charged, status, failed_reason, gateway_msg_id)
           VALUES ($ACC,'SMSTEST','+254700000202','hi',0,'failed','Refused','smstest-dlr-2');"
curl -s -o /dev/null -X POST "$BASE/webhooks/sms-dlr" --data "MessageId=smstest-dlr-2&Status=1"
eq "a late receipt cannot revive a failed message" \
   "$(q "SELECT status FROM bulk_messages WHERE gateway_msg_id='smstest-dlr-2';")" "failed"

# With a secret set, an unsigned report is refused rather than trusted.
$MYSQL -e "INSERT INTO settings (setting_key,setting_value) VALUES ('bulk_sms_dlr_token','smstesttoken')
           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);"
eq "without the secret it is refused" \
   "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/sms-dlr" --data "MessageId=smstest-dlr-1&Status=1")" "403"
eq "with it, it is accepted" \
   "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/sms-dlr?token=smstesttoken" --data "MessageId=smstest-dlr-1&Status=1")" "200"
$MYSQL -e "DELETE FROM settings WHERE setting_key='bulk_sms_dlr_token';"

echo ""
echo "=== 10. Delivery reports read the same as Onfon's ==="
REPORT=$(page "/bulk-sms/messages?account=$ACC&from=$(date +%Y-%m-%d)&to=$(date +%Y-%m-%d)")
has "the report lists Onfon's own columns" "$REPORT" "DelivredToTerminal"
has "including the awkward ones"           "$REPORT" "AbsentSubscriber"
eq  "and it can be downloaded"             "$(code "/bulk-sms/messages?account=$ACC&export=csv")" "200"

echo ""
echo "=== 11. The API key is stored as a fingerprint, not a key ==="
T=$(tok /bulk-sms/accounts/$ACC)
post /bulk-sms/accounts/$ACC/api-key --data "_token=$T" > /dev/null
KEYHASH=$(q "SELECT api_key_hash FROM bulk_accounts WHERE id=$ACC;")
eq "a key is issued"          "$(q "SELECT CHAR_LENGTH(api_key_hash) FROM bulk_accounts WHERE id=$ACC;")" "64"
case "$KEYHASH" in
  sk_live_*) bad "and the key itself is not kept" "stored in full" "only a hash";;
  *)         ok  "and the key itself is not kept" "only a hash";;
esac
has "it is shown once, right after issuing" "$(page /bulk-sms/accounts/$ACC)" "sk_live_"
case "$(page /bulk-sms/accounts/$ACC)" in
  *"Copy this key now"*) bad "and not again on the next visit" "shown again" "shown once";;
  *)                     ok  "and not again on the next visit" "shown once";;
esac
T=$(tok /bulk-sms/accounts/$ACC)
post /bulk-sms/accounts/$ACC/api-key/revoke --data "_token=$T" > /dev/null
eq "revoking removes it" "$(q "SELECT COUNT(*) FROM bulk_accounts WHERE id=$ACC AND api_key_hash IS NOT NULL;")" "0"

echo ""
echo "=== 12. Suspending an account stops it sending ==="
T=$(tok /bulk-sms/accounts/$ACC)
post /bulk-sms/accounts/$ACC/status --data "_token=$T" > /dev/null
eq "it is suspended" "$(q "SELECT status FROM bulk_accounts WHERE id=$ACC;")" "suspended"
SENT_BEFORE=$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC;")
SUSPENDED=$($PHP "$ROOT/tests/helpers/bulk_sms_send.php" "$ACC" 0700000201 SMSTEST 2>&1)
has "the engine says why" "$SUSPENDED" "suspended"
eq "and nothing sends from it" "$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC;")" "$SENT_BEFORE"
T=$(tok /bulk-sms/accounts/$ACC)
post /bulk-sms/accounts/$ACC/status --data "_token=$T" > /dev/null
eq "reactivating brings it back" "$(q "SELECT status FROM bulk_accounts WHERE id=$ACC;")" "active"

echo ""
echo "=== 13. Not everybody may move units or touch the gateway ==="
SALESPASS="SmsSales1"
HASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$SALESPASS")
$MYSQL -e "DELETE FROM users WHERE email='smssales@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('SMSTEST Sales','smssales@shanfix.co.ke','$HASH','sales',1);"
JAR="$D/jar_smssales.txt"; rm -f "$JAR"
ST=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$ST&email=smssales@shanfix.co.ke&password=$SALESPASS"

eq "sales can see the platform"     "$(code /bulk-sms)" "200"
eq "and a customer's messages"      "$(code /bulk-sms/messages)" "200"
eq "but not the gateway keys"       "$(code /bulk-sms/settings)" "403"
BEFORE=$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$ACC;")
ST=$(tok /bulk-sms/accounts/$ACC)
eq "nor give units away"            "$(post /bulk-sms/accounts/$ACC/units --data "_token=$ST&action=give&units=50")" "403"
eq "and none moved"                 "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$ACC;")" "$BEFORE"
ST=$(tok /bulk-sms/sender-ids)
eq "nor approve a sender ID"        "$(post /bulk-sms/sender-ids/$SID/decide --data "_token=$ST&decision=approve")" "403"
$MYSQL -e "DELETE FROM users WHERE email='smssales@shanfix.co.ke';"

echo ""
echo "=== 14. Everything is written down twice and agrees ==="
signin_admin
# The house is the only account allowed to be set rather than moved, and
# even that is written to the ledger. Every other balance must equal its
# own history, or units have been created somewhere.
eq "every account's balance equals its ledger" \
   "$(q "SELECT COUNT(*) FROM bulk_accounts a
          WHERE ROUND(a.sms_units,4) <> ROUND(COALESCE((SELECT SUM(l.amount) FROM bulk_ledger l WHERE l.account_id=a.id),0),4);")" "0"
# Every unit a customer holds was sold or given out of the house.
eq "nothing has been created out of thin air" \
   "$(q "SELECT CASE WHEN (SELECT COALESCE(SUM(amount),0) FROM bulk_ledger WHERE kind IN ('purchase','transfer_in'))
                   >= (SELECT COALESCE(SUM(sms_units),0) FROM bulk_accounts WHERE owner_type<>'house')
                   THEN 'ok' ELSE 'short' END;")" "ok"
eq "and the worker is not reachable over the web" "$(code /sms-worker.php)" "404"

echo ""
echo "=== 15. The customer's own side ==="
# A real portal login for the client whose account we have been working on.
CPASS="SmsClient1"
CHASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CPASS")
$MYSQL -e "DELETE FROM client_users WHERE email='smstest@example.test';
           INSERT INTO client_users (client_id, name, email, password_hash, status)
           VALUES ($CID,'SMSTEST User','smstest@example.test','$CHASH','active');"
CJ="$D/jar_smsclient.txt"; rm -f "$CJ"
cget()  { curl -s -b "$CJ" -c "$CJ" "$BASE$1"; }
ccode() { curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$BASE$1"; }
ctok()  { cget "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
cpost() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$CJ" -c "$CJ" -X POST "$BASE$p" "$@"; }

CT=$(curl -s -c "$CJ" "$BASE/portal/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/portal/login" \
     --data "_token=$CT&email=smstest@example.test&password=$CPASS"

eq "the customer is signed in"   "$(ccode /portal)" "200"
eq "the SMS page opens"          "$(ccode /portal/sms)" "200"
eq "campaigns"                   "$(ccode /portal/sms/campaigns)" "200"
eq "contacts"                    "$(ccode /portal/sms/contacts)" "200"
eq "delivery reports"            "$(ccode /portal/sms/reports)" "200"
eq "sender IDs"                  "$(ccode /portal/sms/senders)" "200"
eq "buying units"                "$(ccode /portal/sms/buy)" "200"
eq "the API page"                "$(ccode /portal/sms/api)" "200"
has "their balance is on the page" "$(cget /portal/sms)" "units"

echo ""
echo "=== 16. A customer sends, and pays for what went ==="
BAL_BEFORE=$(q "SELECT ROUND(sms_units,4) FROM bulk_accounts WHERE id=$ACC;")
CT=$(ctok /portal/sms)
cpost /portal/sms/send --data "_token=$CT&recipients=0700000301,0700000399&sender_id=SMSTEST&message=Hello+from+the+test" > /dev/null
eq "the good number went"    "$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC AND recipient='+254700000301' AND status='sent';")" "1"
eq "the refused one is recorded as failed" \
   "$(q "SELECT status FROM bulk_messages WHERE account_id=$ACC AND recipient='+254700000399' ORDER BY id DESC LIMIT 1;")" "failed"
eq "and cost nothing" \
   "$(q "SELECT units_charged FROM bulk_messages WHERE account_id=$ACC AND recipient='+254700000399' ORDER BY id DESC LIMIT 1;")" "0.0000"
eq "exactly one unit left the account" \
   "$(q "SELECT ROUND($BAL_BEFORE - sms_units,4) FROM bulk_accounts WHERE id=$ACC;")" "1.0000"

# A sender ID belonging to somebody else must not work from here.
OTHERCID=$(q "INSERT INTO clients (client_code, client_type, name, email, status)
              VALUES ('SMSTEST-2','company','SMSTEST Other','other@example.test','active');
              SELECT LAST_INSERT_ID();")
OTHERACC=$(q "INSERT INTO bulk_accounts (owner_type, owner_id, parent_id, sms_units)
              VALUES ('client', $OTHERCID, $HOUSE, 100); SELECT LAST_INSERT_ID();")
$MYSQL -e "INSERT INTO bulk_sender_ids (account_id, sender_id, status) VALUES ($OTHERACC,'NOTYOURS','approved');"
CT=$(ctok /portal/sms)
cpost /portal/sms/send --data "_token=$CT&recipients=0700000302&sender_id=NOTYOURS&message=Nope" > /dev/null
eq "another account's sender ID cannot be borrowed" \
   "$(q "SELECT COUNT(*) FROM bulk_messages WHERE recipient='+254700000302';")" "0"

# Fifty is the line between a quick send and a campaign.
MANY=$(seq -f "07110000%02g" 1 60 | paste -sd, -)
CT=$(ctok /portal/sms)
cpost /portal/sms/send --data "_token=$CT&recipients=$MANY&sender_id=SMSTEST&message=Too+many" > /dev/null
eq "a huge quick send is turned into a campaign instead" \
   "$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC AND recipient='+254711000001';")" "0"

echo ""
echo "=== 17. Their contacts, lists and campaigns ==="
CT=$(ctok /portal/sms/contacts)
cpost /portal/sms/groups --data "_token=$CT&name=SMSTEST+list" > /dev/null
GRP=$(q "SELECT id FROM bulk_contact_groups WHERE account_id=$ACC AND name='SMSTEST list';")
ne "a list is made" "$GRP" ""
CT=$(ctok /portal/sms/contacts)
cpost /portal/sms/contacts --data "_token=$CT&name=Amina&phone=0722000501&group_id=$GRP" > /dev/null
eq "a contact is added and normalised" \
   "$(q "SELECT phone FROM bulk_contacts WHERE account_id=$ACC AND name='Amina';")" "+254722000501"
CT=$(ctok /portal/sms/contacts)
cpost /portal/sms/contacts --data "_token=$CT&name=Bad&phone=12345&group_id=$GRP" > /dev/null
eq "a number that is not a number is refused" \
   "$(q "SELECT COUNT(*) FROM bulk_contacts WHERE account_id=$ACC AND name='Bad';")" "0"

# Importing a file, including the duplicate it already has.
IMPORT="$D/smstest-contacts.csv"
printf 'Name,Phone,Town\nBrian,0722000502,Nakuru\nAmina,0722000501,Nairobi\nJunk,notaphone,\n' > "$IMPORT"
CT=$(ctok /portal/sms/contacts)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST "$BASE/portal/sms/contacts/import" \
     -F "_token=$CT" -F "group_id=$GRP" -F "list=@$IMPORT"
eq "the new contact is imported"      "$(q "SELECT COUNT(*) FROM bulk_contacts WHERE account_id=$ACC AND phone='+254722000502';")" "1"
eq "the duplicate is not doubled"     "$(q "SELECT COUNT(*) FROM bulk_contacts WHERE account_id=$ACC AND phone='+254722000501';")" "1"
has "the other columns are kept for personalising" \
   "$(q "SELECT metadata FROM bulk_contacts WHERE account_id=$ACC AND phone='+254722000502';")" "Nakuru"

CT=$(ctok /portal/sms/campaigns/new)
cpost /portal/sms/campaigns --data "_token=$CT&name=SMSTEST+blast&sender_id=SMSTEST&audience=group&group_id=$GRP&message=Hi+%7Bname%7D+in+%7Btown%7D" > /dev/null
CAMP2=$(q "SELECT id FROM bulk_campaigns WHERE account_id=$ACC AND name='SMSTEST blast';")
ne "a campaign is queued" "$CAMP2" ""
$PHP "$ROOT/sms-worker.php" "$CAMP2" > /dev/null 2>&1
eq "and sends to the list"  "$(q "SELECT sent_count FROM bulk_campaigns WHERE id=$CAMP2;")" "2"
eq "with each person's own details filled in" \
   "$(q "SELECT message FROM bulk_messages WHERE campaign_id=$CAMP2 AND recipient='+254722000502';")" "Hi Brian in Nakuru"

echo ""
echo "=== 18. Asking for a sender ID reaches the office ==="
CT=$(ctok /portal/sms/senders)
cpost /portal/sms/senders --data "_token=$CT&sender_id=SMSTEST3&purpose=Order+updates" > /dev/null
eq "the request is recorded" "$(q "SELECT status FROM bulk_sender_ids WHERE account_id=$ACC AND sender_id='SMSTEST3';")" "pending"
ne "and somebody is told"    "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='bulk_sender_requested';")" "0"
CT=$(ctok /portal/sms/senders)
cpost /portal/sms/senders --data "_token=$CT&sender_id=not+valid+at+all&purpose=x" > /dev/null
eq "a sender ID the networks would refuse is refused here" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE account_id=$ACC AND sender_id LIKE 'not %';")" "0"

echo ""
echo "=== 19. One customer cannot reach another's ==="
OTHERCAMP=$(q "INSERT INTO bulk_campaigns (account_id, name, sender_id, message, status)
               VALUES ($OTHERACC,'SMSTEST other campaign','NOTYOURS','x','completed'); SELECT LAST_INSERT_ID();")
eq "another account's campaign is not found" "$(ccode /portal/sms/campaigns/$OTHERCAMP)" "404"
CT=$(ctok /portal/sms)
eq "nor can it be cancelled"   "$(cpost /portal/sms/campaigns/$OTHERCAMP/cancel --data "_token=$CT")" "404"
OTHERGRP=$(q "INSERT INTO bulk_contact_groups (account_id, name) VALUES ($OTHERACC,'SMSTEST theirs'); SELECT LAST_INSERT_ID();")
CT=$(ctok /portal/sms/contacts)
eq "nor their list deleted"    "$(cpost /portal/sms/groups/$OTHERGRP/delete --data "_token=$CT")" "403"
eq "and it is still there"     "$(q "SELECT COUNT(*) FROM bulk_contact_groups WHERE id=$OTHERGRP;")" "1"
case "$(cget /portal/sms/reports)" in
  *NOTYOURS*) bad "nor see their messages" "visible" "hidden";;
  *)          ok  "nor see their messages" "hidden";;
esac

echo ""
echo "=== 20. The developer API ==="
CT=$(ctok /portal/sms/api)
cpost /portal/sms/api/key --data "_token=$CT" > /dev/null
APIKEY=$(cget /portal/sms/api | grep -o 'sk_live_[a-f0-9]\{32\}' | head -1)
APICID=$(q "SELECT api_client_id FROM bulk_accounts WHERE id=$ACC;")
ne "a customer can issue their own key" "$APIKEY" ""
eq "and only its fingerprint is kept"   "$(q "SELECT CHAR_LENGTH(api_key_hash) FROM bulk_accounts WHERE id=$ACC;")" "64"

api() { curl -s -X POST "$BASE$1" -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY" "${@:2}"; }

BALJSON=$(curl -s "$BASE/api/v1/balance.php" -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY")
has "balance answers"            "$BALJSON" '"success":true'
has "with the units on it"       "$BALJSON" '"sms_units"'
SENDJSON=$(api /api/v1/sendsms.php --data "to=0700000401&sender_id=SMSTEST&message=API+test")
has "a message can be sent"      "$SENDJSON" '"success":true'
has "and it says what it cost"   "$SENDJSON" '"units_charged"'
eq  "the message is really there" "$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC AND recipient='+254700000401' AND source='api';")" "1"

BULKJSON=$(api /api/v1/bulksend.php -H "Content-Type: application/json" \
  --data '{"to":["0700000402","0700000403"],"sender_id":"SMSTEST","message":"Bulk API"}')
has "many at once works"         "$BULKJSON" '"total_submitted":2'
MSGID=$(q "SELECT id FROM bulk_messages WHERE account_id=$ACC AND recipient='+254700000401' ORDER BY id DESC LIMIT 1;")
has "one message can be looked up" \
   "$(curl -s "$BASE/api/v1/status.php?message_id=$MSGID" -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY")" '"success":true'
has "the log can be read"        "$(curl -s "$BASE/api/v1/messages.php?per_page=5" -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY")" '"messages"'
eq  "the address answers without .php too" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/balance" -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY")" "200"

# Someone else's message, looked up with our key.
OTHERMSG=$(q "INSERT INTO bulk_messages (account_id, sender_id, recipient, message, status)
              VALUES ($OTHERACC,'NOTYOURS','+254700000999','secret','sent'); SELECT LAST_INSERT_ID();")
eq "another account's message is not ours to read" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/status.php?message_id=$OTHERMSG" -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY")" "404"

eq "a wrong key is refused" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/balance.php" -H "X-Client-Id: $APICID" -H "X-Api-Key: sk_live_wrong")" "401"
eq "and no key at all"     "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/balance.php")" "401"
eq "a browser's preflight is answered" \
   "$(curl -s -o /dev/null -w '%{http_code}' -X OPTIONS "$BASE/api/v1/sendsms.php")" "204"

# A suspended account cannot send through the API either.
$MYSQL -e "UPDATE bulk_accounts SET status='suspended' WHERE id=$ACC;"
eq "a suspended account is refused" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/balance.php" -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY")" "403"
$MYSQL -e "UPDATE bulk_accounts SET status='active' WHERE id=$ACC;"

# The rate limit, proved rather than assumed.
#
# Counted per clock minute, so a burst that straddles a minute boundary
# starts again half way through and never trips it. The whole burst is
# repeated when that happens rather than the assertion being loosened.
burst() {
  $MYSQL -e "DELETE FROM bulk_rate_counters;"
  local i rc
  for i in $(seq 1 12); do
    rc=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/v1/bulksend.php" \
         -H "X-Client-Id: $APICID" -H "X-Api-Key: $APIKEY" \
         --data "to=0700000404&sender_id=SMSTEST&message=rate")
    if [ "$rc" = "429" ]; then echo 1; return; fi
  done
  echo 0
}

MINUTE_BEFORE=$(date +%M)
LIMITED=$(burst)
if [ "$LIMITED" = "0" ] && [ "$(date +%M)" != "$MINUTE_BEFORE" ]; then
  LIMITED=$(burst)
fi
eq "too many bulk calls a minute are refused" "$LIMITED" "1"
$MYSQL -e "DELETE FROM bulk_rate_counters;"

$MYSQL -e "DELETE FROM bulk_messages WHERE account_id=$OTHERACC;
           DELETE FROM bulk_campaigns WHERE account_id=$OTHERACC;
           DELETE FROM bulk_contact_groups WHERE account_id=$OTHERACC;
           DELETE FROM bulk_accounts WHERE id=$OTHERACC;
           DELETE FROM clients WHERE id=$OTHERCID;
           DELETE FROM client_users WHERE email='smstest@example.test';"

echo ""
echo "=== 26. Sender IDs we already hold, mapped to customers ==="
signin_admin
T=$(tok "/bulk-sms/sender-ids?status=pool")
post /bulk-sms/sender-ids/whitelist \
     --data "_token=$T&sender_ids=SMSTESTP1%0ASMSTESTP2%0Anot+a+valid+one%0ASMSTESTP1" > /dev/null
eq "the list is recorded as stock" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE sender_id LIKE 'SMSTESTP%' AND account_id IS NULL AND status='approved';")" "2"
eq "a name the networks would refuse is not" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE sender_id LIKE 'not %';")" "0"
eq "and a repeat in the same paste is not doubled" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE sender_id='SMSTESTP1';")" "1"

# Pasting the whole list again is safe.
T=$(tok "/bulk-sms/sender-ids?status=pool")
post /bulk-sms/sender-ids/whitelist --data "_token=$T&sender_ids=SMSTESTP1%0ASMSTESTP2" > /dev/null
eq "pasting the list twice adds nothing" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE sender_id LIKE 'SMSTESTP%';")" "2"

POOLED=$(q "SELECT id FROM bulk_sender_ids WHERE sender_id='SMSTESTP1';")

# Held by nobody means usable by nobody.
BEFORE=$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC;")
$PHP "$ROOT/tests/helpers/bulk_sms_send.php" "$ACC" 0700000801 SMSTESTP1 > /dev/null 2>&1
eq "a sender ID in stock cannot be used by anyone" \
   "$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC;")" "$BEFORE"

T=$(tok "/bulk-sms/sender-ids?status=pool")
post /bulk-sms/sender-ids/$POOLED/assign --data "_token=$T&account_id=$ACC" > /dev/null
eq "giving it to a customer maps it to them" \
   "$(q "SELECT account_id FROM bulk_sender_ids WHERE id=$POOLED;")" "$ACC"
eq "and they are told"  "$(q "SELECT COUNT(*) FROM notifications WHERE event='bulk_sender_approved' AND recipient='smstest@example.test';")" "1"

SENT=$($PHP "$ROOT/tests/helpers/bulk_sms_send.php" "$ACC" 0700000802 SMSTESTP1 2>&1)
has "now they can send under it" "$SENT" "sent"
eq  "and the message really went" \
    "$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC AND recipient='+254700000802' AND sender_id='SMSTESTP1';")" "1"

T=$(tok "/bulk-sms/sender-ids?status=approved")
post /bulk-sms/sender-ids/$POOLED/unassign --data "_token=$T" > /dev/null
eq "taking it back leaves it in stock" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE id=$POOLED AND account_id IS NULL AND status='approved';")" "1"
BEFORE=$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC;")
$PHP "$ROOT/tests/helpers/bulk_sms_send.php" "$ACC" 0700000803 SMSTESTP1 > /dev/null 2>&1
eq "and they can no longer send under it" \
   "$(q "SELECT COUNT(*) FROM bulk_messages WHERE account_id=$ACC;")" "$BEFORE"

echo ""
echo "=== 27. Buying units with M-Pesa ==="
# The office can see whether it is available at all, and why not when it
# is not. The credentials are not set in a test database, so the page
# must say so rather than offering a button that cannot work.
$MYSQL -e "INSERT INTO settings (setting_key,setting_value) VALUES ('bulk_sms_mpesa','1')
           ON DUPLICATE KEY UPDATE setting_value='1';"
has "the gateway page reports the M-Pesa state" "$(page /bulk-sms/settings)" "Paying by M-Pesa"

# Switched off for SMS, a customer is not offered it even when M-Pesa
# itself is on for invoices.
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='bulk_sms_mpesa';"
case "$(curl -s -b "$CJ" "$BASE/portal/sms/buy")" in
  *"Buy with M-Pesa"*) bad "switched off, no M-Pesa button" "offered" "not offered";;
  *)                   ok  "switched off, no M-Pesa button" "not offered";;
esac
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='bulk_sms_mpesa';"

# The half that matters: money arriving credits the units. The prompt
# itself goes to Kopo Kopo, which a test cannot call, so this starts from
# the callback — the same path a real payment takes on its way back.
KKKEY=$($PHP "$ROOT/tests/helpers/setting.php" kopokopo_api_key)
if [ -n "$KKKEY" ]; then
  BAL=$(q "SELECT ROUND(sms_units,4) FROM bulk_accounts WHERE id=$ACC;")
  SELLER=$(q "SELECT id FROM bulk_accounts WHERE owner_type='house';")
  BUY=$(q "INSERT INTO bulk_purchases (account_id, seller_account_id, units, amount, unit_price, method, phone, status)
           VALUES ($ACC, $SELLER, 250, 250, 1, 'mpesa_stk', '254700000201', 'pending');
           SELECT LAST_INSERT_ID();")
  KKID="smstest-kk-$(date +%s)"
  STK=$(q "INSERT INTO stk_requests (client_id, document_id, purpose, bulk_purchase_id, phone, amount, kopokopo_id, status)
           VALUES ($CID, NULL, 'bulk_sms', $BUY, '254700000201', 250, '$KKID', 'pending');
           SELECT LAST_INSERT_ID();")

  BODY="{\"topic\":\"buygoods_transaction_received\",\"id\":\"evt-$KKID\",\"data\":{\"id\":\"$KKID\",\"type\":\"incoming_payment\",\"attributes\":{\"status\":\"Success\",\"event\":{\"type\":\"Incoming Payment Request\",\"resource\":{\"id\":\"res-1\",\"reference\":\"SMSTESTMPESA9\",\"sender_phone_number\":\"+254700000201\",\"amount\":\"250.0\",\"currency\":\"KES\",\"status\":\"Received\"},\"errors\":null},\"metadata\":{\"purpose\":\"bulk_sms\",\"purchase_id\":\"$BUY\",\"stk_id\":\"$STK\"}}}}"
  SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$KKKEY" | sed 's/^.* //')

  eq "a forged callback is refused" \
     "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/kopokopo" \
        -H 'Content-Type: application/json' -H 'X-KopoKopo-Signature: 0000bad0000' --data "$BODY")" "401"
  eq "and nothing was credited" "$(q "SELECT ROUND(sms_units,4) FROM bulk_accounts WHERE id=$ACC;")" "$BAL"

  eq "a real one is accepted" \
     "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/kopokopo" \
        -H 'Content-Type: application/json' -H "X-KopoKopo-Signature: $SIG" --data "$BODY")" "200"
  eq "the purchase is paid"     "$(q "SELECT status FROM bulk_purchases WHERE id=$BUY;")" "completed"
  eq "the M-Pesa code is kept"  "$(q "SELECT transaction_ref FROM bulk_purchases WHERE id=$BUY;")" "SMSTESTMPESA9"
  eq "the units arrive"         "$(q "SELECT ROUND(sms_units - $BAL) FROM bulk_accounts WHERE id=$ACC;")" "250"
  eq "and they came out of the house" \
     "$(q "SELECT COUNT(*) FROM bulk_ledger WHERE ref_type='purchase' AND ref_id=$BUY AND kind='sale';")" "1"

  # M-Pesa retries a callback it thinks failed. Paying once must credit once.
  curl -s -o /dev/null -X POST "$BASE/webhooks/kopokopo" \
       -H 'Content-Type: application/json' -H "X-KopoKopo-Signature: $SIG" --data "$BODY"
  eq "a repeated callback credits nothing more" \
     "$(q "SELECT ROUND(sms_units - $BAL) FROM bulk_accounts WHERE id=$ACC;")" "250"
  # A receipt goes out by e-mail and by text, so two rows is right and one
  # would mean a channel had been lost. What a repeated callback must not
  # do is add a third.
  eq "the receipt goes out once by e-mail" \
     "$(q "SELECT COUNT(*) FROM notifications WHERE event='bulk_topup' AND channel='email' AND body LIKE '%SMSTESTMPESA9%';")" "1"
  eq "and once by text" \
     "$(q "SELECT COUNT(*) FROM notifications WHERE event='bulk_topup' AND channel='sms' AND body LIKE '%SMSTESTMPESA9%';")" "1"

  $MYSQL -e "DELETE FROM stk_requests WHERE id=$STK;"
else
  ok "M-Pesa callback" "skipped: no Kopo Kopo key in this database"
fi

echo ""
echo "=== 21. A partner is a reseller ==="
PPASS="SmsPartner1"
PHASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PPASS")
$MYSQL -e "DELETE FROM partners WHERE email='smspartner@example.test';
           INSERT INTO partners (partner_code, first_name, last_name, name, email, phone, status, password_hash)
           VALUES ('SMSTESTP','Sms','Partner','SMSTEST Partner','smspartner@example.test','254700000600','active','$PHASH');"
PID=$(q "SELECT id FROM partners WHERE email='smspartner@example.test';")

PJ2="$D/jar_smspartner.txt"; rm -f "$PJ2"
pget2()  { curl -s -b "$PJ2" -c "$PJ2" "$BASE$1"; }
pcode2() { curl -s -o /dev/null -w '%{http_code}' -b "$PJ2" "$BASE$1"; }
ptok2()  { pget2 "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
ppost2() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$PJ2" -c "$PJ2" -X POST "$BASE$p" "$@"; }

PT=$(curl -s -c "$PJ2" "$BASE/partners/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$PJ2" -c "$PJ2" -X POST "$BASE/partners/login" \
     --data "_token=$PT&email=smspartner@example.test&password=$PPASS"

eq "the partner is signed in"    "$(pcode2 /partners)" "200"
eq "their SMS page opens"        "$(pcode2 /partners/sms)" "200"
PACC=$(q "SELECT id FROM bulk_accounts WHERE owner_type='partner' AND owner_id=$PID;")
ne "an account was opened for them" "$PACC" ""
eq "and they buy from the house" \
   "$(q "SELECT p.owner_type FROM bulk_accounts a JOIN bulk_accounts p ON p.id=a.parent_id WHERE a.id=$PACC;")" "house"
eq "their clients page opens"    "$(pcode2 /partners/sms/clients)" "200"
eq "their payments page"         "$(pcode2 /partners/sms/sales)" "200"
eq "their prices page"           "$(pcode2 /partners/sms/pricing)" "200"
eq "and the shared send page"    "$(pcode2 /partners/sms/campaigns)" "200"

echo ""
echo "=== 22. They set a price and supply a client ==="
PT=$(ptok2 /partners/sms/pricing)
ppost2 /partners/sms/pricing --data "_token=$PT&resale_unit_price=1.60" > /dev/null
eq "their standard price is saved" \
   "$(q "SELECT ROUND(resale_unit_price,2) FROM bulk_accounts WHERE id=$PACC;")" "1.60"

# A client of theirs, registered against them the usual way.
PCLIENT=$(q "INSERT INTO clients (client_code, client_type, name, email, status, partner_id)
             VALUES ('SMSTEST-P1','company','SMSTEST Partner Client','pclient@example.test','active',$PID);
             SELECT LAST_INSERT_ID();")
PT=$(ptok2 /partners/sms/clients)
ppost2 /partners/sms/clients --data "_token=$PT&client_id=$PCLIENT" > /dev/null
PCACC=$(q "SELECT id FROM bulk_accounts WHERE owner_type='client' AND owner_id=$PCLIENT;")
ne "SMS is opened for their client" "$PCACC" ""
eq "and that client buys from the partner, not from us" \
   "$(q "SELECT parent_id FROM bulk_accounts WHERE id=$PCACC;")" "$PACC"
eq "at the partner's price" \
   "$(q "SELECT ROUND(COALESCE(c.unit_price, p.resale_unit_price),2) FROM bulk_accounts c
         JOIN bulk_accounts p ON p.id=c.parent_id WHERE c.id=$PCACC;")" "1.60"

# Units the partner does not have cannot be given away.
PT=$(ptok2 /partners/sms/clients)
ppost2 /partners/sms/clients/$PCACC/units --data "_token=$PT&units=100" > /dev/null
eq "a partner with nothing cannot give units" "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$PCACC;")" "0"

# The office sells them some, then they pass them on.
T=$(tok /bulk-sms/purchases)
signin_admin > /dev/null
T=$(tok /bulk-sms/purchases)
post /bulk-sms/purchases --data "_token=$T&account_id=$PACC&units=1000&method=bank&transaction_ref=SMSTESTP1" > /dev/null
eq "the partner has units to sell" "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$PACC;")" "1000"

PT=$(ptok2 /partners/sms/clients)
ppost2 /partners/sms/clients/$PCACC/units --data "_token=$PT&units=300&note=SMSTEST+handover" > /dev/null
eq "now they can hand units over"  "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$PCACC;")" "300"
eq "and it comes out of their own" "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$PACC;")" "700"

PT=$(ptok2 /partners/sms/clients)
ppost2 /partners/sms/clients/$PCACC/price --data "_token=$PT&unit_price=2.50" > /dev/null
eq "they can price one client differently" \
   "$(q "SELECT ROUND(unit_price,2) FROM bulk_accounts WHERE id=$PCACC;")" "2.50"

echo ""
echo "=== 23. Their client asks to buy, and they confirm it ==="
CP2="SmsPc1"
CH2=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CP2")
$MYSQL -e "INSERT INTO client_users (client_id, name, email, password_hash, status)
           VALUES ($PCLIENT,'PC User','pclient@example.test','$CH2','active');"
PCJ="$D/jar_pclient.txt"; rm -f "$PCJ"
CT2=$(curl -s -c "$PCJ" "$BASE/portal/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$PCJ" -c "$PCJ" -X POST "$BASE/portal/login" \
     --data "_token=$CT2&email=pclient@example.test&password=$CP2"

BUYPAGE=$(curl -s -b "$PCJ" "$BASE/portal/sms/buy")
has "they are told who supplies them" "$BUYPAGE" "SMSTEST Partner"
case "$BUYPAGE" in
  *"Buy with M-Pesa"*) bad "and are not offered M-Pesa here" "offered" "not offered";;
  *)                   ok  "and are not offered M-Pesa here" "not offered";;
esac

CT2=$(curl -s -b "$PCJ" -c "$PCJ" "$BASE/portal/sms/buy" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$PCJ" -c "$PCJ" -X POST "$BASE/portal/sms/buy" \
     --data "_token=$CT2&units=100&transaction_ref=SMSTESTMP1"
SALE=$(q "SELECT id FROM bulk_purchases WHERE account_id=$PCACC AND status='pending' ORDER BY id DESC LIMIT 1;")
ne "the request reaches the partner" "$SALE" ""
eq "priced at what that client pays" \
   "$(q "SELECT ROUND(amount) FROM bulk_purchases WHERE id=$SALE;")" "250"
eq "and it is the partner who sells it" \
   "$(q "SELECT seller_account_id FROM bulk_purchases WHERE id=$SALE;")" "$PACC"

# The office must not be able to settle a partner's sale.
signin_admin > /dev/null
T=$(tok /bulk-sms/purchases)
post /bulk-sms/purchases/$SALE/complete --data "_token=$T" > /dev/null
eq "the office cannot confirm a partner's sale" "$(q "SELECT status FROM bulk_purchases WHERE id=$SALE;")" "pending"

PT=$(ptok2 /partners/sms/sales)
ppost2 /partners/sms/sales/$SALE/approve --data "_token=$PT&transaction_ref=SMSTESTMP1" > /dev/null
eq "the partner can"                  "$(q "SELECT status FROM bulk_purchases WHERE id=$SALE;")" "completed"
eq "their client gets the units"      "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$PCACC;")" "400"
eq "and they come out of the partner" "$(q "SELECT ROUND(sms_units) FROM bulk_accounts WHERE id=$PACC;")" "600"

echo ""
echo "=== 24. What a reseller may not do ==="
# Another partner's client, and a client that is not theirs at all.
STRANGER=$(q "SELECT id FROM bulk_accounts WHERE owner_type='client' AND id <> $PCACC AND parent_id <> $PACC LIMIT 1;")
if [ -n "$STRANGER" ]; then
  BEFORE=$(q "SELECT ROUND(sms_units,4) FROM bulk_accounts WHERE id=$STRANGER;")
  PT=$(ptok2 /partners/sms/clients)
  eq "they cannot give units to somebody else's client" \
     "$(ppost2 /partners/sms/clients/$STRANGER/units --data "_token=$PT&units=10")" "403"
  eq "and nothing moved" "$(q "SELECT ROUND(sms_units,4) FROM bulk_accounts WHERE id=$STRANGER;")" "$BEFORE"
  PT=$(ptok2 /partners/sms/clients)
  eq "nor change what they pay" \
     "$(ppost2 /partners/sms/clients/$STRANGER/price --data "_token=$PT&unit_price=0.01")" "403"
  PT=$(ptok2 /partners/sms/clients)
  eq "nor suspend them" "$(ppost2 /partners/sms/clients/$STRANGER/status --data "_token=$PT")" "403"
fi

# A reseller supplies credit; they do not get to read the messages.
$MYSQL -e "INSERT INTO bulk_messages (account_id, sender_id, recipient, message, status)
           VALUES ($PCACC,'SMSTEST','+254700000701','a private message from their client','sent');"
case "$(pget2 /partners/sms/reports)" in
  *"a private message from their client"*) bad "a reseller cannot read their clients' messages" "visible" "hidden";;
  *)                                       ok  "a reseller cannot read their clients' messages" "hidden";;
esac

# And the office's own screens are not theirs either.
OFFICE=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$PJ2" "$BASE/bulk-sms")
case "$OFFICE" in
  302*"/login"*) ok  "nor open the office's SMS pages" "sent to the staff sign-in";;
  *)             bad "nor open the office's SMS pages" "$OFFICE" "302 to /login";;
esac

echo ""
echo "=== 25. The chain still adds up ==="
eq "every account's balance equals its ledger" \
   "$(q "SELECT COUNT(*) FROM bulk_accounts a
          WHERE ROUND(a.sms_units,4) <> ROUND(COALESCE((SELECT SUM(l.amount) FROM bulk_ledger l WHERE l.account_id=a.id),0),4);")" "0"
eq "what the partner sold on came out of what we sold them" \
   "$(q "SELECT CASE WHEN (SELECT COALESCE(SUM(units),0) FROM bulk_purchases WHERE seller_account_id=$PACC AND status='completed')
                   <= (SELECT COALESCE(SUM(units),0) FROM bulk_purchases WHERE account_id=$PACC AND status='completed')
                   THEN 'ok' ELSE 'more than they bought' END;")" "ok"

$MYSQL -e "DELETE FROM bulk_messages WHERE account_id IN ($PCACC, $PACC);
           DELETE FROM bulk_ledger WHERE account_id IN ($PCACC, $PACC);
           DELETE FROM bulk_purchases WHERE account_id IN ($PCACC, $PACC) OR seller_account_id = $PACC;
           DELETE FROM bulk_plans WHERE owner_account_id = $PACC;
           DELETE FROM client_users WHERE client_id = $PCLIENT;
           DELETE FROM bulk_accounts WHERE id = $PCACC;
           DELETE FROM bulk_accounts WHERE id = $PACC;
           DELETE FROM clients WHERE id = $PCLIENT;
           DELETE FROM partners WHERE id = $PID;"

scrub_sms
report
