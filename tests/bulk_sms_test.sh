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
    DELETE FROM bulk_plans WHERE name LIKE 'SMSTEST%';
    DELETE FROM notifications WHERE event LIKE 'bulk\_%' AND recipient LIKE '%smstest%';
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
eq "and started again"     "$(q "SELECT status FROM bulk_campaigns WHERE id=$CAMP;")" "queued"

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

scrub_sms
report
