#!/bin/bash
# End-to-end: configure messaging, send an invoice, verify the queue,
# the captured email, and the client-facing public link.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
DIR="$(dirname "$0")"
JAR="$DIR/notify.txt"; rm -f "$JAR"
EML="$DIR/queue.eml"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
has() { if echo "$2" | grep -q "$3"; then ok "$1" "found"; else bad "$1" "missing" "$3"; fi; }

tok()  { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code() { curl -s -o /tmp/n.html -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE$1"; }
post() { curl -s -o /tmp/n.html -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE$1" --data "$2"; }

echo ""
echo "=== Setup ==="
T=$(tok /login)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"
eq "signed in" "$(code /dashboard)" "200"

# Point SMTP at the local capture server, enable both channels.
$MYSQL -e "
UPDATE settings SET setting_value='1'           WHERE setting_key='smtp_enabled';
UPDATE settings SET setting_value='127.0.0.1'   WHERE setting_key='smtp_host';
UPDATE settings SET setting_value='2530'        WHERE setting_key='smtp_port';
UPDATE settings SET setting_value='none'        WHERE setting_key='smtp_encryption';
UPDATE settings SET setting_value=''            WHERE setting_key='smtp_username';
UPDATE settings SET setting_value=''            WHERE setting_key='smtp_password';
UPDATE settings SET setting_value='invoices@shanfix.co.ke' WHERE setting_key='smtp_from_email';
UPDATE settings SET setting_value='Shanfix Technology'     WHERE setting_key='smtp_from_name';
UPDATE settings SET setting_value='0'           WHERE setting_key='sms_enabled';
"
eq "messaging settings page" "$(code '/settings?tab=messaging')" "200"

# A client with an email, and an invoice to send.
T=$(tok /clients/create)
post "/clients" "_token=$T&client_type=company&name=Riverside+Hotel&contact_person=Grace+Njeri&email=grace@riverside.co.ke&phone=0722814500&city=Nairobi&status=active&credit_limit=0" > /dev/null
CID=$($MYSQL -N -e "SELECT id FROM clients WHERE name='Riverside Hotel' LIMIT 1;")

T=$(tok "/invoices/create")
post "/invoices" "_token=$T&client_id=$CID&issue_date=$(date +%Y-%m-%d)&due_date=$(date -d '+14 days' +%Y-%m-%d)&title=Reception+signage&status=unpaid&discount_type=none&discount_value=0&vat_mode=exclusive&vat_rate=16&items[0][item_type]=custom&items[0][description]=Illuminated+facia+sign&items[0][quantity]=1&items[0][unit_price]=85000" > /dev/null
INV=$($MYSQL -N -e "SELECT id FROM documents WHERE client_id=$CID AND doc_type='invoice' ORDER BY id DESC LIMIT 1;")
eq "invoice created" "$([ -n "$INV" ] && echo yes)" "yes"

echo ""
echo "=== 1. Public share token ==="
code "/invoices/$INV" > /dev/null
TOKEN=$($MYSQL -N -e "SELECT public_token FROM documents WHERE id=$INV;")
eq "token minted on view" "$(echo -n "$TOKEN" | grep -cE '^[a-f0-9]{48}$')" "1"
eq "token is unique"      "$($MYSQL -N -e "SELECT COUNT(DISTINCT public_token) FROM documents WHERE public_token IS NOT NULL;")" "$($MYSQL -N -e "SELECT COUNT(public_token) FROM documents WHERE public_token IS NOT NULL;")"

echo ""
echo "=== 2. Client link works without a login ==="
NOAUTH=$(curl -s -o /tmp/pub.html -w "%{http_code}" "$BASE/view/$TOKEN")
eq "public view loads logged out" "$NOAUTH" "200"
BODY=$(cat /tmp/pub.html)
has "shows the invoice number" "$BODY" "INV-"
has "shows the client name"    "$BODY" "Riverside Hotel"
has "shows the amount"         "$BODY" "98,600"
has "offers print/save as PDF" "$BODY" "Save as PDF"
if echo "$BODY" | grep -qi "sidebar__nav\|Audit Trail\|Job Board"; then
  bad "no internal navigation leaked" "leaked" "clean"; else ok "no internal navigation leaked" "clean"; fi

echo ""
echo "=== 3. First open is recorded ==="
eq "viewed_at stamped" "$($MYSQL -N -e "SELECT IF(viewed_at IS NOT NULL,'yes','no') FROM documents WHERE id=$INV;")" "yes"

echo ""
echo "=== 4. Bad tokens rejected ==="
eq "wrong length -> 404"  "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/view/abc")" "404"
eq "valid shape, unknown -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/view/$(printf 'a%.0s' {1..48})")" "404"
eq "sql injection attempt -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/view/%27%20OR%201%3D1--")" "404"

echo ""
echo "=== 5. Draft documents are not shareable ==="
$MYSQL -e "INSERT INTO documents (doc_type,doc_number,public_token,client_id,issue_date,status,currency,subtotal,vat_mode,vat_rate,vat_amount,total,balance,created_by)
 VALUES ('quotation','QTN-DRAFT-1',REPEAT('b',48),$CID,CURDATE(),'draft','KES',1000,'exempt',0,0,1000,1000,1);"
eq "draft link -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/view/$(printf 'b%.0s' {1..48})")" "404"

echo ""
echo "=== 6. Send the invoice by email ==="
rm -f "$EML" "$EML.log" "$EML.ready"
php "$DIR/fake_smtp.php" 2530 "$EML" > /dev/null 2>&1 &
SMTP_PID=$!
for i in $(seq 1 40); do [ -f "$EML.ready" ] && break; sleep 0.1; done

T=$(tok "/invoices/$INV")
SEND=$(post "/documents/$INV/send" "_token=$T&channels[]=email")
eq "send endpoint" "$SEND" "302"

for i in $(seq 1 40); do [ -f "$EML" ] && break; sleep 0.1; done
wait $SMTP_PID 2>/dev/null

eq "notification row created" "$($MYSQL -N -e "SELECT COUNT(*) FROM notifications WHERE entity_id=$INV AND channel='email';")" "1"
eq "marked sent"              "$($MYSQL -N -e "SELECT status FROM notifications WHERE entity_id=$INV ORDER BY id DESC LIMIT 1;")" "sent"
eq "sent_at stamped"          "$($MYSQL -N -e "SELECT IF(sent_at IS NOT NULL,'yes','no') FROM notifications WHERE entity_id=$INV ORDER BY id DESC LIMIT 1;")" "yes"
eq "document marked sent"     "$($MYSQL -N -e "SELECT IF(sent_at IS NOT NULL,'yes','no') FROM documents WHERE id=$INV;")" "yes"

echo ""
echo "=== 7. What actually landed in the mailbox ==="
RAW=$(cat "$EML" 2>/dev/null)
has "addressed to the client"    "$RAW" "grace@riverside.co.ke"
has "from our address"           "$RAW" "invoices@shanfix.co.ke"
has "subject has invoice number" "$RAW" "Subject:"

# Decode the HTML part
php -r '
$raw = file_get_contents($argv[1]);
preg_match_all("/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/", $raw, $m);
foreach ($m[1] as $b) {
  $d = base64_decode(preg_replace("/\s+/", "", $b));
  if (str_contains($d, "<html") || str_contains($d, "<table")) { echo $d; break; }
}' "$EML" > /tmp/email.html 2>/dev/null

HTML=$(cat /tmp/email.html 2>/dev/null)
has "greets the contact by name" "$HTML" "Grace"
has "company name in header"     "$HTML" "Shanfix Technology"
has "invoice number in body"     "$HTML" "INV-"
has "line item description"      "$HTML" "Illuminated facia sign"
has "total amount"               "$HTML" "98,600"
has "brand navy used"            "$HTML" "#0C2B4A"
has "brand green used"           "$HTML" "#14874E"
has "view button links to token" "$HTML" "$TOKEN"
if echo "$HTML" | grep -qi "gradient"; then bad "no gradients in email" "found" "none"; else ok "no gradients in email" "clean"; fi

echo ""
echo "=== 8. Message log UI ==="
eq "log page"        "$(code /notifications)" "200"
NID=$($MYSQL -N -e "SELECT id FROM notifications ORDER BY id DESC LIMIT 1;")
eq "message detail"  "$(code /notifications/$NID)" "200"

echo ""
echo "=== 9. Failure is captured, not swallowed ==="
$MYSQL -e "UPDATE settings SET setting_value='59998' WHERE setting_key='smtp_port';"
T=$(tok "/invoices/$INV")
post "/documents/$INV/send" "_token=$T&channels[]=email" > /dev/null
sleep 1
LAST=$($MYSQL -N -e "SELECT status FROM notifications ORDER BY id DESC LIMIT 1;")
ERRTXT=$($MYSQL -N -e "SELECT last_error FROM notifications ORDER BY id DESC LIMIT 1;")
if [ "$LAST" = "queued" ] || [ "$LAST" = "failed" ]; then ok "failed send not marked sent" "$LAST"; else bad "failed send not marked sent" "$LAST" "queued|failed"; fi
has "error message recorded" "$ERRTXT" "connect"
eq "attempts incremented" "$($MYSQL -N -e "SELECT IF(attempts>0,'yes','no') FROM notifications ORDER BY id DESC LIMIT 1;")" "yes"

echo ""
echo "=== 10. Overdue reminders are queued once only ==="
$MYSQL -e "UPDATE documents SET due_date = DATE_SUB(CURDATE(), INTERVAL 7 DAY), status='overdue' WHERE id=$INV;
           DELETE FROM notifications; DELETE FROM notification_locks;
           UPDATE settings SET setting_value='127.0.0.1' WHERE setting_key='smtp_host';
           UPDATE settings SET setting_value='59998' WHERE setting_key='smtp_port';"
php -r '
require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
\App\Core\Config::load(CONFIG_PATH."/config.php");
\App\Core\Database::connect(\App\Core\Config::get("db"));
$r = \App\Services\Notifier::queueOverdueReminders();
echo "queued=".$r["queued"]."\n";
' > /tmp/rem1.txt 2>&1
# Scoped to this suite's own invoice. Counting every overdue reminder in the
# database made the result depend on what other suites had left behind, so it
# climbed by one on every run.
eq "first run queues one" "$($MYSQL -N -e "SELECT COUNT(*) FROM notifications WHERE event='invoice_overdue' AND entity_id=$INV;")" "1"

php -r '
require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
\App\Core\Config::load(CONFIG_PATH."/config.php");
\App\Core\Database::connect(\App\Core\Config::get("db"));
\App\Services\Notifier::queueOverdueReminders();
' > /dev/null 2>&1
eq "second run adds nothing" "$($MYSQL -N -e "SELECT COUNT(*) FROM notifications WHERE event='invoice_overdue' AND entity_id=$INV;")" "1"
eq "lock recorded" "$($MYSQL -N -e "SELECT COUNT(*) FROM notification_locks WHERE lock_key LIKE 'overdue:$INV:%';")" "1"

echo ""
echo "=== 11. Cron runs cleanly ==="
CRON=$(php "$ROOT/cron.php" --verbose 2>&1)
if echo "$CRON" | grep -qi "error\|fatal\|exception"; then bad "cron ran without errors" "errors" "clean"; else ok "cron ran without errors" "clean"; fi
has "cron reports done" "$CRON" "Done in"

echo ""
echo "=== 12. Access control ==="
$MYSQL -e "DELETE FROM users WHERE email='sales2@shanfix.co.ke';"
HASH=$(php -r "echo password_hash('SalesPass1', PASSWORD_DEFAULT);")
$MYSQL -e "INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('Sales Two','sales2@shanfix.co.ke','$HASH','sales',1);"
SJ=/tmp/sales2.txt; rm -f $SJ
T=$(curl -s -c $SJ "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b $SJ -c $SJ -X POST "$BASE/login" --data "_token=$T&email=sales2@shanfix.co.ke&password=SalesPass1"
eq "sales can see the log"        "$(curl -s -o /dev/null -w '%{http_code}' -b $SJ "$BASE/notifications")" "200"
eq "sales BLOCKED from settings"  "$(curl -s -o /dev/null -w '%{http_code}' -b $SJ "$BASE/settings?tab=messaging")" "403"
T=$(curl -s -b $SJ -c $SJ "$BASE/notifications" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
eq "sales BLOCKED from retry"     "$(curl -s -o /dev/null -w '%{http_code}' -b $SJ -X POST "$BASE/notifications/1/retry" --data "_token=$T")" "403"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
rm -f "$EML" "$EML.log" "$EML.ready"
exit $FAIL
