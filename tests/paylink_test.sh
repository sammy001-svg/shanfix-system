#!/bin/bash
# Paying an invoice from the share link sent by SMS or email.
# The endpoint has no login, so most of this is about what it refuses.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
D="$(dirname "$0")"
JAR="$D/pay.txt"; rm -f "$JAR"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-54s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-54s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
q()   { $MYSQL -N -e "$1"; }
tok() { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

# A fresh unpaid invoice with its own share token.
CID=$(q "SELECT id FROM clients ORDER BY id LIMIT 1;")
$MYSQL -e "INSERT INTO documents (doc_type,doc_number,public_token,client_id,issue_date,due_date,status,currency,
             subtotal,discount_type,discount_value,discount_amount,vat_mode,vat_rate,vat_amount,total,amount_paid,balance,created_by)
           VALUES ('invoice',CONCAT('INV-PAY-',UNIX_TIMESTAMP()),SHA2(CONCAT(RAND(),'paylink'),256),$CID,CURDATE(),CURDATE(),
             'unpaid','KES',10000,'none',0,0,'exclusive',16,1600,11600,0,11600,1);"
DOC=$(q "SELECT id FROM documents ORDER BY id DESC LIMIT 1;")
TOKEN=$(q "SELECT public_token FROM documents WHERE id=$DOC;")
$MYSQL -e "INSERT INTO settings (setting_key,setting_value) VALUES ('kopokopo_enabled','1')
           ON DUPLICATE KEY UPDATE setting_value='1';"

echo ""
echo "=== 1. The client is offered payment ==="
PAGE=$(curl -s -b "$JAR" -c "$JAR" "$BASE/view/$TOKEN")
eq "payment panel shown"   "$(printf '%s' "$PAGE" | grep -c 'class=\"paybox no-print\"')" "1"
eq "amount owed shown"     "$(printf '%s' "$PAGE" | grep -o 'paybox__amount-value\">[^<]*' | sed 's/.*>//')" "KES 11,600.00"
eq "their number filled in" "$(printf '%s' "$PAGE" | grep -A2 'id=\"phone\"' | grep -o 'value=\"[0-9]*\"' | head -1)" 'value="0712345678"'

echo ""
echo "=== 2. Switched off, nothing is offered ==="
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='kopokopo_enabled';"
eq "no panel when M-Pesa is off" "$(curl -s "$BASE/view/$TOKEN" | grep -c 'class=\"paybox no-print\"')" "0"
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='kopokopo_enabled';"

echo ""
echo "=== 3. What the endpoint refuses ==="
eq "POST without a CSRF token" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/view/$TOKEN/pay" --data 'phone=0712345678')" "419"
T=$(tok "/view/$TOKEN")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/view/$TOKEN/pay" --data "_token=$T&phone=notaphone"
eq "a phone number that is not one" "$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$DOC;")" "0"

echo ""
echo "=== 4. The amount comes from the invoice, not the form ==="
T=$(tok "/view/$TOKEN")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/view/$TOKEN/pay" --data "_token=$T&phone=0712345678&amount=5"
eq "posted amount ignored" "$(q "SELECT amount FROM stk_requests WHERE document_id=$DOC ORDER BY id DESC LIMIT 1;")" "11600.00"
eq "recorded as the client's own" "$(q "SELECT IFNULL(initiated_by,'client') FROM stk_requests WHERE document_id=$DOC ORDER BY id DESC LIMIT 1;")" "client"
eq "number normalised"     "$(q "SELECT phone FROM stk_requests WHERE document_id=$DOC ORDER BY id DESC LIMIT 1;")" "254712345678"

echo ""
echo "=== 5. It cannot be used to pester a phone ==="
$MYSQL -e "DELETE FROM stk_requests WHERE document_id=$DOC;"
for i in 1 2 3 4 5 6 7 8; do
  T=$(tok "/view/$TOKEN")
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/view/$TOKEN/pay" --data "_token=$T&phone=0712345678"
  # Age the row so this exercises the hourly cap, not the one-at-a-time guard.
  $MYSQL -e "UPDATE stk_requests SET created_at=DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE document_id=$DOC;"
done
eq "capped at six an hour" "$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$DOC;")" "6"

echo ""
echo "=== 6. One prompt at a time ==="
$MYSQL -e "DELETE FROM stk_requests WHERE document_id=$DOC;
  INSERT INTO stk_requests (document_id,client_id,phone,amount,kopokopo_id,location_url,status,initiated_by)
  VALUES ($DOC,$CID,'254712345678',11600,'kk-paylink-1','https://sandbox.test/x','pending',NULL);"
T=$(tok "/view/$TOKEN")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/view/$TOKEN/pay" --data "_token=$T&phone=0712345678"
eq "no second prompt stacked" "$(q "SELECT COUNT(*) FROM stk_requests WHERE document_id=$DOC;")" "1"
eq "page watches the request" "$(curl -s -b "$JAR" "$BASE/view/$TOKEN" | grep -c 'id=\"stk-poll\"')" "1"

echo ""
echo "=== 7. Status is only told to whoever started it ==="
MINE=$(curl -s -b "$JAR" "$BASE/view/$TOKEN/pay/status")
eq "their own request"  "$(printf '%s' "$MINE" | grep -c '\"status\":\"pending\"')" "1"
eq "a stranger is told nothing" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/view/$TOKEN/pay/status")" "404"

echo ""
echo "=== 8. Payment confirmed by the M-Pesa callback ==="
STK=$(q "SELECT id FROM stk_requests WHERE document_id=$DOC ORDER BY id DESC LIMIT 1;")
BODY="{\"topic\":\"buygoods_transaction_received\",\"id\":\"evt-paylink-$STK\",\"data\":{\"id\":\"kk-paylink-1\",\"type\":\"incoming_payment\",\"attributes\":{\"status\":\"Success\",\"event\":{\"type\":\"Incoming Payment Request\",\"resource\":{\"id\":\"res-paylink\",\"reference\":\"QZPAYLINK1\",\"sender_phone_number\":\"+254712345678\",\"amount\":\"11600.00\",\"currency\":\"KES\",\"status\":\"Received\"},\"errors\":null},\"metadata\":{\"stk_id\":\"$STK\",\"document_id\":\"$DOC\"}}}}"
SIG=$(php -r 'echo hash_hmac("sha256", $argv[1], "test_api_key_123");' "$BODY")
eq "callback accepted" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/kopokopo" \
   -H 'Content-Type: application/json' -H "X-KopoKopo-Signature: $SIG" --data "$BODY")" "200"
eq "invoice settled"   "$(q "SELECT status FROM documents WHERE id=$DOC;")" "paid"
eq "payment recorded"  "$(q "SELECT method FROM payments WHERE document_id=$DOC ORDER BY id DESC LIMIT 1;")" "mpesa_stk"
eq "client told it worked" "$(curl -s -b "$JAR" "$BASE/view/$TOKEN/pay/status" | grep -c 'Payment received')" "1"

echo ""
echo "=== 9. A settled invoice offers no more payment ==="
eq "panel gone" "$(curl -s "$BASE/view/$TOKEN" | grep -c 'class=\"paybox no-print\"')" "0"

# Tidy up.
$MYSQL -e "DELETE FROM stk_requests WHERE document_id=$DOC;
           DELETE FROM payments WHERE document_id=$DOC;
           DELETE FROM documents WHERE parent_document_id=$DOC;
           DELETE FROM document_items WHERE document_id=$DOC;
           DELETE FROM documents WHERE id=$DOC;
           UPDATE settings SET setting_value='0' WHERE setting_key='kopokopo_enabled';"
rm -f "$JAR"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
