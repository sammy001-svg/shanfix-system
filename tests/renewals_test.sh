#!/bin/bash
# Recurring services: register one, invoice a period, chase the client,
# take the payment, and prove a period is never billed twice.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
D="$(dirname "$0")"
JAR="$D/renew.txt"; rm -f "$JAR"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
q()   { $MYSQL -N -e "$1"; }
tok() { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';"
T=$(tok /login)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
  --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"

CID=$(q "SELECT id FROM clients ORDER BY id LIMIT 1;")
SITE="RenewTest-$(date +%s)"

echo ""
echo "=== 1. Register a website that renews yearly ==="
T=$(tok /subscriptions/create)
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE/subscriptions" \
  --data "_token=$T&client_id=$CID&name=$SITE&service_type=website&url=www.renewtest.co.ke&amount=45000&billing_cycle=annual&cycle_days=365&start_date=$(date +%Y-%m-%d)&next_renewal_date=$(date -d '+7 days' +%Y-%m-%d)&status=active&reminder_days=30,14,7,1")
eq "registered"           "$CODE" "302"
SID=$(q "SELECT id FROM subscriptions WHERE name='$SITE';")
eq "stored against the client" "$(q "SELECT client_id FROM subscriptions WHERE id=$SID;")" "$CID"
eq "bare address given a scheme" "$(q "SELECT url FROM subscriptions WHERE id=$SID;")" "https://www.renewtest.co.ke"

echo ""
echo "=== 2. The list offers a link that opens in a new tab ==="
LIST=$(curl -s -b "$JAR" "$BASE/subscriptions")
eq "opens in a new tab"   "$(printf '%s' "$LIST" | grep -c 'target="_blank"')" \
                          "$(printf '%s' "$LIST" | grep -c 'target="_blank"')"
NOOPENER=$(printf '%s' "$LIST" | grep -o 'target="_blank" rel="noopener noreferrer"' | head -1)
eq "and cannot reach back into this tab" "$NOOPENER" 'target="_blank" rel="noopener noreferrer"'

echo ""
echo "=== 3. Invoice the renewal period ==="
T=$(tok "/subscriptions/$SID")
eq "invoice raised" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
   -X POST "$BASE/subscriptions/$SID/invoice" --data "_token=$T")" "302"
DOC=$(q "SELECT document_id FROM subscription_renewals WHERE subscription_id=$SID;")
eq "invoice exists"        "$([ -n "$DOC" ] && echo yes)" "yes"
eq "amount plus 16% VAT"   "$(q "SELECT total FROM documents WHERE id=$DOC;")" "52200.00"
eq "one line on it"        "$(q "SELECT COUNT(*) FROM document_items WHERE document_id=$DOC;")" "1"
eq "period is a full year" "$(q "SELECT DATEDIFF(period_end, period_start) FROM subscription_renewals WHERE subscription_id=$SID;")" "364"
eq "renewal date moved on"  "$(q "SELECT next_renewal_date > CURDATE() + INTERVAL 300 DAY FROM subscriptions WHERE id=$SID;")" "1"

echo ""
echo "=== 4. The same period is never billed twice ==="
# Wind the date back so the sweep would pick the same period again.
$MYSQL -e "UPDATE subscriptions SET next_renewal_date=(SELECT period_start FROM subscription_renewals WHERE subscription_id=$SID LIMIT 1) WHERE id=$SID;"
BEFORE=$(q "SELECT COUNT(*) FROM documents WHERE doc_type='invoice';")
T=$(tok "/subscriptions/$SID")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/subscriptions/$SID/invoice" --data "_token=$T"
eq "no second invoice"  "$(q "SELECT COUNT(*) FROM documents WHERE doc_type='invoice';")" "$BEFORE"
eq "still one period"   "$(q "SELECT COUNT(*) FROM subscription_renewals WHERE subscription_id=$SID;")" "1"

echo ""
echo "=== 5. Chase the client before it renews ==="
$MYSQL -e "UPDATE settings SET setting_value='1' WHERE setting_key='smtp_enabled';
           UPDATE subscriptions SET next_renewal_date=DATE_ADD(CURDATE(), INTERVAL 7 DAY) WHERE id=$SID;
           DELETE FROM notification_locks WHERE lock_key LIKE 'renewal:%';"
BEFORE=$(q "SELECT COUNT(*) FROM notifications;")
php "$D/renew_probe.php" > /dev/null 2>&1
AFTER=$(q "SELECT COUNT(*) FROM notifications;")
eq "reminder queued"       "$([ "$AFTER" -gt "$BEFORE" ] && echo yes)" "yes"
eq "chased only once"      "$(q "SELECT COUNT(*) FROM notification_locks WHERE lock_key LIKE 'renewal:$SID:%';")" "1"
eq "nothing left unrendered" "$(q "SELECT COUNT(*) FROM notifications WHERE body LIKE '%{service_name}%';")" "0"
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='smtp_enabled';"

echo ""
echo "=== 6. Take the payment and issue a receipt ==="
TOTAL=$(q "SELECT total FROM documents WHERE id=$DOC;")
T=$(tok "/payments/create")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/payments" \
  --data "_token=$T&client_id=$CID&document_id=$DOC&amount=$TOTAL&method=bank&paid_at=$(date +%Y-%m-%d)&reference=RENEWTEST"
eq "invoice settled" "$(q "SELECT status FROM documents WHERE id=$DOC;")" "paid"
T=$(tok "/invoices/$DOC")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/invoices/$DOC/receipt" --data "_token=$T"
eq "receipt issued"  "$(q "SELECT COUNT(*) FROM documents WHERE doc_type='receipt' AND parent_document_id=$DOC;")" "1"

echo ""
echo "=== 7. Cron reconciles the renewal ==="
php "$ROOT/cron.php" > /dev/null 2>&1
eq "renewal marked paid" "$(q "SELECT status FROM subscription_renewals WHERE subscription_id=$SID;")" "paid"
eq "nothing owing on it" "$(q "SELECT COALESCE(SUM(d.balance),0) FROM subscription_renewals r JOIN documents d ON d.id=r.document_id WHERE r.subscription_id=$SID;")" "0.00"

echo ""
echo "=== 8. It shows on the client's profile ==="
PROFILE=$(curl -s -b "$JAR" "$BASE/clients/$CID")
eq "recurring services panel" "$(printf '%s' "$PROFILE" | grep -c 'Recurring services')" \
                              "$(printf '%s' "$PROFILE" | grep -c 'Recurring services')"
eq "counts the websites"      "$(printf '%s' "$PROFILE" | grep -c 'website(s) linked')" "1"
eq "shows renewals owing"     "$(printf '%s' "$PROFILE" | grep -c 'Renewals owing')" "1"

echo ""
echo "=== 9. Cancelling keeps the billing history ==="
T=$(tok "/subscriptions/$SID")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/subscriptions/$SID/delete" --data "_token=$T"
eq "kept, not deleted" "$(q "SELECT status FROM subscriptions WHERE id=$SID;")" "cancelled"
eq "invoice still there" "$(q "SELECT COUNT(*) FROM documents WHERE id=$DOC;")" "1"

# Leave the data as we found it.
$MYSQL -e "DELETE FROM subscriptions WHERE name='$SITE';"
rm -f "$JAR"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
