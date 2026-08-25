#!/bin/bash
# End-to-end smoke test for the Shanfix BMS.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
JAR="$(dirname "$0")/cookies.txt"
rm -f "$JAR"

PASS=0; FAIL=0

# GET a URL and assert the status code.
check_any() {
  local path="$1" expect="$2" label="$3" code
  code=$(curl -s --path-as-is -o /tmp/body.html -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE$path")
  if grep -qE "app_key|db_pass" /tmp/body.html 2>/dev/null; then
    printf "  [31mFAIL[0m %-46s LEAKED SECRETS
" "$label"; FAIL=$((FAIL+1)); return
  fi
  for e in $expect; do
    if [ "$code" = "$e" ]; then printf "  [32mPASS[0m %-46s %s
" "$label" "$code"; PASS=$((PASS+1)); return; fi
  done
  printf "  [31mFAIL[0m %-46s got %s want one of %s
" "$label" "$code" "$expect"; FAIL=$((FAIL+1))
}

check() {
  local path="$1" expect="${2:-200}" label="${3:-$1}"
  local code
  code=$(curl -s -o /tmp/body.html -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE$path")
  if [ "$code" = "$expect" ]; then
    printf "  \033[32mPASS\033[0m %-46s %s\n" "$label" "$code"; PASS=$((PASS+1))
  else
    printf "  \033[31mFAIL\033[0m %-46s got %s want %s\n" "$label" "$code" "$expect"; FAIL=$((FAIL+1))
    grep -o 'Error [0-9]*.\{0,200\}' /tmp/body.html | head -2
    grep -o '<p class="empty__text">.\{0,240\}' /tmp/body.html | head -2
  fi
}

# Pull the CSRF token out of the last fetched page.
token() {
  curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

post() {
  local path="$1" data="$2" label="$3" expect="${4:-302}"
  local code
  code=$(curl -s -o /tmp/body.html -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE$path" --data "$data")
  if [ "$code" = "$expect" ]; then
    printf "  \033[32mPASS\033[0m %-46s %s\n" "$label" "$code"; PASS=$((PASS+1))
  else
    printf "  \033[31mFAIL\033[0m %-46s got %s want %s\n" "$label" "$code" "$expect"; FAIL=$((FAIL+1))
    head -c 500 /tmp/body.html; echo
  fi
}

echo ""
echo "=== 1. Auth ==="
check "/login" 200 "GET /login"
check "/dashboard" 302 "GET /dashboard (unauthenticated -> redirect)"

TOKEN=$(token "/login")
[ -z "$TOKEN" ] && { echo "  could not read CSRF token"; exit 1; }
post "/login" "_token=$TOKEN&email=admin@shanfix.co.ke&password=Shanfix@2026" "POST /login (correct password)"

check "/dashboard" 200 "GET /dashboard (authenticated)"

echo ""
echo "=== 2. Read-only pages ==="
for p in /dashboard /clients /clients/create /inventory /inventory/create /services /services/create \
         /leads "/leads?view=list" /leads/create /quotations /quotations/create /invoices /invoices/create \
         /receipts /payments /payments/create /expenses /expenses/create /reports /reminders /chat \
         /users /users/create /settings "/settings?tab=documents" "/settings?tab=payments" \
         "/settings?tab=categories" /audit /profile "/search?q=shanfix"; do
  check "$p" 200
done

echo ""
echo "=== 3. Create a client ==="
TOKEN=$(token "/clients/create")
post "/clients" "_token=$TOKEN&client_type=company&name=Acme+Holdings+Ltd&contact_person=Jane+Wanjiku&email=accounts@acme.co.ke&phone=0712345678&kra_pin=P051234567X&city=Nairobi&address=Kimathi+Street&credit_limit=0&status=active" "POST /clients"

CLIENT_ID=$(curl -s -b "$JAR" "$BASE/clients" | grep -o 'href="/clients/[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "  -> client id = $CLIENT_ID"
check "/clients/$CLIENT_ID" 200 "GET client profile"
check "/clients/$CLIENT_ID?tab=invoices" 200 "GET client invoices tab"
check "/clients/$CLIENT_ID?tab=payments" 200 "GET client payments tab"
check "/clients/$CLIENT_ID?tab=activity" 200 "GET client activity tab"
check "/clients/$CLIENT_ID/edit" 200 "GET client edit"

echo ""
echo "=== 4. Quotation with VAT ==="
TOKEN=$(token "/quotations/create")
# 2 banners @ 6500 + 1 website @ 45000 = 58000 net, +16% VAT = 67280
post "/quotations" "_token=$TOKEN&client_id=$CLIENT_ID&issue_date=$(date +%Y-%m-%d)&valid_until=$(date -d '+30 days' +%Y-%m-%d)&title=Corporate+branding+package&status=draft&discount_type=none&discount_value=0&vat_mode=exclusive&vat_rate=16&items[0][item_type]=custom&items[0][description]=Roll-up+Banner+800x2000mm&items[0][quantity]=2&items[0][unit_price]=6500&items[1][item_type]=custom&items[1][description]=Business+Website+(5+pages)&items[1][quantity]=1&items[1][unit_price]=45000&notes=Artwork+to+be+supplied&terms=60%25+deposit" "POST /quotations"

QUOTE_ID=$(curl -s -b "$JAR" "$BASE/quotations" | grep -o 'href="/quotations/[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "  -> quotation id = $QUOTE_ID"
check "/quotations/$QUOTE_ID" 200 "GET quotation"
check "/quotations/$QUOTE_ID/print" 200 "GET quotation print view"
check "/quotations/$QUOTE_ID/edit" 200 "GET quotation edit"

echo ""
echo "=== 5. Convert quotation -> invoice ==="
TOKEN=$(token "/quotations/$QUOTE_ID")
post "/quotations/$QUOTE_ID/convert" "_token=$TOKEN" "POST convert to invoice"

INVOICE_ID=$(curl -s -b "$JAR" "$BASE/invoices" | grep -o 'href="/invoices/[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "  -> invoice id = $INVOICE_ID"
check "/invoices/$INVOICE_ID" 200 "GET invoice"
check "/invoices/$INVOICE_ID/print" 200 "GET invoice print view"

echo ""
echo "=== 6. Record a part payment ==="
TOKEN=$(token "/payments/create?document_id=$INVOICE_ID")
post "/payments" "_token=$TOKEN&client_id=$CLIENT_ID&document_id=$INVOICE_ID&amount=30000&paid_at=$(date +%Y-%m-%d)&method=mpesa_manual&reference=SFX7HG12KL&notes=Deposit" "POST /payments (part payment)"
check "/payments" 200 "GET /payments"

echo ""
echo "=== 7. Settle and issue a receipt ==="
TOKEN=$(token "/payments/create?document_id=$INVOICE_ID")
post "/payments" "_token=$TOKEN&client_id=$CLIENT_ID&document_id=$INVOICE_ID&amount=37280&paid_at=$(date +%Y-%m-%d)&method=bank&reference=TRF99812" "POST /payments (settle balance)"

TOKEN=$(token "/invoices/$INVOICE_ID")
post "/invoices/$INVOICE_ID/receipt" "_token=$TOKEN" "POST issue receipt"
RECEIPT_ID=$(curl -s -b "$JAR" "$BASE/receipts" | grep -o 'href="/receipts/[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "  -> receipt id = $RECEIPT_ID"
check "/receipts/$RECEIPT_ID" 200 "GET receipt"
check "/receipts/$RECEIPT_ID/print" 200 "GET receipt print view"

echo ""
echo "=== 8. Lead -> activity -> convert ==="
TOKEN=$(token "/leads/create")
post "/leads" "_token=$TOKEN&name=Peter+Otieno&company=Sunrise+SACCO&email=peter@sunrise.co.ke&phone=0722334455&source=referral&estimated_value=120000&stage=new&requirement=Needs+full+rebrand+and+website&follow_up_at=$(date -d '+2 days' +%Y-%m-%dT09:00)" "POST /leads"

LEAD_ID=$(curl -s -b "$JAR" "$BASE/leads?view=list" | grep -o 'href="/leads/[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "  -> lead id = $LEAD_ID"
check "/leads/$LEAD_ID" 200 "GET lead"

TOKEN=$(token "/leads/$LEAD_ID")
post "/leads/$LEAD_ID/activity" "_token=$TOKEN&activity_type=call&subject=Intro+call&notes=Discussed+scope+and+budget&outcome=Wants+a+quotation&activity_date=$(date +%Y-%m-%dT%H:%M)&next_follow_up=$(date -d '+3 days' +%Y-%m-%dT09:00)" "POST log activity"

TOKEN=$(token "/leads/$LEAD_ID")
post "/leads/$LEAD_ID/stage" "_token=$TOKEN&stage=qualified&stage_note=Budget+confirmed" "POST move stage"

TOKEN=$(token "/leads/$LEAD_ID")
post "/leads/$LEAD_ID/convert" "_token=$TOKEN&confirm_duplicate=0" "POST convert lead to client"

echo ""
echo "=== 9. Inventory movement ==="
ITEM_ID=$($MYSQL -N -e "SELECT MIN(id) FROM inventory_items;" 2>/dev/null)
ITEM_ID=${ITEM_ID:-1}
TOKEN=$(token "/inventory/$ITEM_ID")
post "/inventory/$ITEM_ID/stock" "_token=$TOKEN&movement_type=in&quantity=50&note=Delivery+from+supplier" "POST stock in"
check "/inventory/$ITEM_ID" 200 "GET inventory item"

echo ""
echo "=== 10. Expense ==="
TOKEN=$(token "/expenses/create")
post "/expenses" "_token=$TOKEN&description=Vinyl+rolls+for+Acme+job&vendor=Zenith+Supplies&amount=18560&vat_amount=2560&expense_date=$(date +%Y-%m-%d)&payment_method=mpesa&reference=SFX99KK&is_billable=1&client_id=$CLIENT_ID" "POST /expenses"
check "/expenses" 200 "GET /expenses"

echo ""
echo "=== 11. Chat ==="
TOKEN=$(token "/chat")
post "/chat/channels" "_token=$TOKEN&name=production&description=Print+jobs+in+production" "POST create channel"
CONV_ID=$(curl -s -b "$JAR" "$BASE/chat" | grep -o 'href="/chat/[0-9]*"' | head -1 | grep -o '[0-9]*')
echo "  -> conversation id = $CONV_ID"
check "/chat/$CONV_ID" 200 "GET channel"
TOKEN=$(token "/chat/$CONV_ID")
post "/chat/send" "_token=$TOKEN&conversation_id=$CONV_ID&body=Acme+banners+are+on+the+press" "POST send message"
check "/chat/poll?conversation_id=$CONV_ID&after=0" 200 "GET chat poll"
check "/chat/unread-count" 200 "GET unread count"

echo ""
echo "=== 12. Reports + exports ==="
check "/reports" 200 "GET /reports"
check "/reports/statement" 200 "GET statement CSV"
check "/clients/export" 200 "GET clients CSV"
check "/inventory/export" 200 "GET inventory CSV"
check "/expenses/export" 200 "GET expenses CSV"

echo ""
echo "=== 13. Security checks ==="
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -X POST "$BASE/clients" --data "name=No+Token")
if [ "$code" = "419" ]; then printf "  \033[32mPASS\033[0m %-46s %s\n" "POST without CSRF token rejected" "$code"; PASS=$((PASS+1));
else printf "  \033[31mFAIL\033[0m %-46s got %s want 419\n" "POST without CSRF token rejected" "$code"; FAIL=$((FAIL+1)); fi

code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/webhooks/kopokopo" -H "Content-Type: application/json" --data '{"data":{"id":"x"}}')
if [ "$code" = "401" ]; then printf "  \033[32mPASS\033[0m %-46s %s\n" "Webhook without signature rejected" "$code"; PASS=$((PASS+1));
else printf "  \033[31mFAIL\033[0m %-46s got %s want 401\n" "Webhook without signature rejected" "$code"; FAIL=$((FAIL+1)); fi

check_any "/storage/../config/config.php" "400 404" "Path traversal on /storage blocked"
check "/nonexistent-page" 404 "Unknown route 404s"

echo ""
echo "=== 14. Sign out ==="
TOKEN=$(token "/dashboard")
post "/logout" "_token=$TOKEN" "POST /logout"
check "/dashboard" 302 "GET /dashboard after logout -> redirect"

echo ""
echo "=== The connectivity probe ==="
# The browser asks this before claiming there is no internet. It used to
# trust navigator.onLine, which on Windows is routinely stuck reporting
# offline on a machine whose connection is fine — so the system told
# people they had no internet all day, and quietly held their saved work
# on the device instead of submitting it.
eq "the probe answers"           "$(curl -s -o /dev/null -w '%{http_code}' -I "$BASE/up")" "204"
eq "it sends no body"            "$(curl -s -o /dev/null -w '%{size_download}' -I "$BASE/up")" "0"
has "and is never cached"        "$(curl -sI "$BASE/up")" "no-store"

# HEAD on purpose: the service worker only intercepts GET, and a probe
# answered out of a cache would report the network as up when it is not.
ne "GET is not the probe"        "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/up")" "204"
has "the worker ignores non-GET" "$(curl -s "$BASE/sw.js")" "request.method !== 'GET'"

# The banner must read the verified state, not the browser's guess.
has "the banner reads the probe" "$(curl -s "$BASE/assets/js/offline.js")" "lastKnown.online"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
