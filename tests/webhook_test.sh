#!/bin/bash
# Simulates the KopoKopo STK Push callback against a real pending request,
# and checks the payment posts and the invoice reconciles.
BASE="http://127.0.0.1:8000"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root shanfix_test"
API_KEY="test_api_key_123"   # matches what unit.php stored

PASS=0; FAIL=0
RUN=$(date +%s)          # fixtures must be unique: doc_number has a UNIQUE index
INV1="INV-TEST-$RUN-1"; INV2="INV-TEST-$RUN-2"
KK1="kk-$RUN-1";        KK2="kk-$RUN-2"
EVT="evt-$RUN"
assert() {
  if [ "$2" = "$3" ]; then printf "  \033[32mPASS\033[0m %-48s %s\n" "$1" "$2"; PASS=$((PASS+1));
  else printf "  \033[31mFAIL\033[0m %-48s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

echo ""
echo "=== Setup: fresh unpaid invoice + pending STK request ==="

$MYSQL -e "
INSERT INTO documents (doc_type, doc_number, client_id, issue_date, due_date, status, currency,
                       subtotal, vat_mode, vat_rate, vat_amount, total, amount_paid, balance, created_by)
VALUES ('invoice','$INV1',1,CURDATE(),CURDATE(),'unpaid','KES',
        10000,'exclusive',16,1600,11600,0,11600,1);
SET @doc := LAST_INSERT_ID();
INSERT INTO document_items (document_id,item_type,description,quantity,unit_price,line_total,sort_order)
VALUES (@doc,'custom','Test banner job',1,10000,10000,0);
INSERT INTO stk_requests (document_id, client_id, phone, amount, kopokopo_id, status, initiated_by)
VALUES (@doc, 1, '254712345678', 11600, '$KK1', 'pending', 1);
" 2>&1

DOC_ID=$($MYSQL -N -e "SELECT id FROM documents WHERE doc_number='$INV1' ORDER BY id DESC LIMIT 1;")
STK_ID=$($MYSQL -N -e "SELECT id FROM stk_requests WHERE kopokopo_id='$KK1' ORDER BY id DESC LIMIT 1;")
echo "  invoice id=$DOC_ID  stk request id=$STK_ID"

BODY=$(cat <<JSON
{"topic":"buygoods_transaction_received","id":"$EVT","data":{"id":"$KK1","type":"incoming_payment","attributes":{"status":"Success","event":{"type":"Incoming Payment Request","resource":{"id":"res-1","reference":"SGH4KL9MNP","sender_phone_number":"+254712345678","amount":"11600.0","currency":"KES","status":"Received"},"errors":null},"metadata":{"stk_id":"$STK_ID","document_id":"$DOC_ID"}}}}
JSON
)

echo ""
echo "=== 1. Callback with a forged signature is rejected ==="
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/webhooks/kopokopo" \
  -H "Content-Type: application/json" -H "X-KopoKopo-Signature: 0000bad0000" --data "$BODY")
assert "forged signature -> 401" "$CODE" "401"
assert "no payment created" "$($MYSQL -N -e "SELECT COUNT(*) FROM payments WHERE document_id=$DOC_ID;")" "0"
assert "stk still pending" "$($MYSQL -N -e "SELECT status FROM stk_requests WHERE id=$STK_ID;")" "pending"

echo ""
echo "=== 2. Correctly signed callback is processed ==="
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$API_KEY" | sed 's/^.* //')
CODE=$(curl -s -o /tmp/wh.json -w "%{http_code}" -X POST "$BASE/webhooks/kopokopo" \
  -H "Content-Type: application/json" -H "X-KopoKopo-Signature: $SIG" --data "$BODY")
assert "valid signature -> 200" "$CODE" "200"
assert "stk marked success" "$($MYSQL -N -e "SELECT status FROM stk_requests WHERE id=$STK_ID;")" "success"
assert "M-Pesa receipt stored" "$($MYSQL -N -e "SELECT mpesa_receipt FROM stk_requests WHERE id=$STK_ID;")" "SGH4KL9MNP"
assert "payment created" "$($MYSQL -N -e "SELECT COUNT(*) FROM payments WHERE document_id=$DOC_ID AND status='completed';")" "1"
assert "payment amount" "$($MYSQL -N -e "SELECT amount FROM payments WHERE document_id=$DOC_ID;")" "11600.00"
assert "payment method mpesa_stk" "$($MYSQL -N -e "SELECT method FROM payments WHERE document_id=$DOC_ID;")" "mpesa_stk"
assert "invoice amount_paid" "$($MYSQL -N -e "SELECT amount_paid FROM documents WHERE id=$DOC_ID;")" "11600.00"
assert "invoice balance cleared" "$($MYSQL -N -e "SELECT balance FROM documents WHERE id=$DOC_ID;")" "0.00"
assert "invoice status paid" "$($MYSQL -N -e "SELECT status FROM documents WHERE id=$DOC_ID;")" "paid"
assert "payment linked to stk row" "$($MYSQL -N -e "SELECT IF(payment_id IS NOT NULL,'yes','no') FROM stk_requests WHERE id=$STK_ID;")" "yes"

echo ""
echo "=== 3. Duplicate delivery is ignored (no double-charge) ==="
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/webhooks/kopokopo" \
  -H "Content-Type: application/json" -H "X-KopoKopo-Signature: $SIG" --data "$BODY")
assert "replay -> 200 (acknowledged)" "$CODE" "200"
assert "still exactly one payment" "$($MYSQL -N -e "SELECT COUNT(*) FROM payments WHERE document_id=$DOC_ID;")" "1"
assert "amount_paid unchanged" "$($MYSQL -N -e "SELECT amount_paid FROM documents WHERE id=$DOC_ID;")" "11600.00"

echo ""
echo "=== 4. Declined payment marks the request failed, posts nothing ==="
$MYSQL -e "
INSERT INTO documents (doc_type, doc_number, client_id, issue_date, due_date, status, currency,
                       subtotal, vat_mode, vat_rate, vat_amount, total, amount_paid, balance, created_by)
VALUES ('invoice','$INV2',1,CURDATE(),CURDATE(),'unpaid','KES',5000,'exempt',0,0,5000,0,5000,1);
SET @d2 := LAST_INSERT_ID();
INSERT INTO stk_requests (document_id, client_id, phone, amount, kopokopo_id, status, initiated_by)
VALUES (@d2, 1, '254712345678', 5000, '$KK2', 'pending', 1);" 2>&1

DOC2=$($MYSQL -N -e "SELECT id FROM documents WHERE doc_number='$INV2' ORDER BY id DESC LIMIT 1;")
STK2=$($MYSQL -N -e "SELECT id FROM stk_requests WHERE kopokopo_id='$KK2' ORDER BY id DESC LIMIT 1;")

BODY2="{\"data\":{\"id\":\"$KK2\",\"attributes\":{\"status\":\"Failed\",\"event\":{\"resource\":{\"status\":\"Failed\"},\"errors\":[\"Request cancelled by user\"]},\"metadata\":{\"stk_id\":\"$STK2\"}}}}"
SIG2=$(printf '%s' "$BODY2" | openssl dgst -sha256 -hmac "$API_KEY" | sed 's/^.* //')
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/webhooks/kopokopo" \
  -H "Content-Type: application/json" -H "X-KopoKopo-Signature: $SIG2" --data "$BODY2")
assert "declined callback -> 200" "$CODE" "200"
assert "stk marked failed" "$($MYSQL -N -e "SELECT status FROM stk_requests WHERE id=$STK2;")" "failed"
assert "reason recorded" "$($MYSQL -N -e "SELECT result_desc FROM stk_requests WHERE id=$STK2;")" "Request cancelled by user"
assert "no payment posted" "$($MYSQL -N -e "SELECT COUNT(*) FROM payments WHERE document_id=$DOC2;")" "0"
assert "invoice still unpaid" "$($MYSQL -N -e "SELECT status FROM documents WHERE id=$DOC2;")" "unpaid"
assert "balance untouched" "$($MYSQL -N -e "SELECT balance FROM documents WHERE id=$DOC2;")" "5000.00"

echo ""
echo "=== 5. Payment reversal restores the balance ==="
PID=$($MYSQL -N -e "SELECT id FROM payments WHERE document_id=$DOC_ID LIMIT 1;")
JAR=$(dirname "$0")/rev.txt; rm -f "$JAR"
TOK=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$TOK&email=admin@shanfix.co.ke&password=Shanfix@2026"
TOK=$(curl -s -b "$JAR" -c "$JAR" "$BASE/payments" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/payments/$PID/reverse" --data "_token=$TOK"
assert "payment cancelled" "$($MYSQL -N -e "SELECT status FROM payments WHERE id=$PID;")" "cancelled"
assert "invoice balance restored" "$($MYSQL -N -e "SELECT balance FROM documents WHERE id=$DOC_ID;")" "11600.00"
assert "invoice back to unpaid/overdue" "$($MYSQL -N -e "SELECT IF(status IN ('unpaid','overdue'),'yes','no') FROM documents WHERE id=$DOC_ID;")" "yes"
assert "amount_paid reset" "$($MYSQL -N -e "SELECT amount_paid FROM documents WHERE id=$DOC_ID;")" "0.00"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
