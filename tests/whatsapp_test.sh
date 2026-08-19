#!/bin/bash
# WhatsApp: the shared inbox, the webhook Meta calls, and the 24-hour rule
# that decides whether a reply can be typed at all.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
TMP="C:/Users/Shanfix/AppData/Local/Temp"
D="$(dirname "$0")"
JAR="$D/wa.txt"; rm -f "$JAR"
SECRET="test_app_secret_xyz"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-54s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-54s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
q()   { $MYSQL -N -e "$1"; }
tok() { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

# Post a webhook body, signing it byte-for-byte the way Meta does. Written
# to a file because the shell mangles UTF-8 between hashing and sending.
hook() {
  php -r '
    $json = $argv[1]; $dir = $argv[2]; $secret = $argv[3];
    file_put_contents($dir . "/wa_t_body.json", $json);
    file_put_contents($dir . "/wa_t_sig.txt", "sha256=" . hash_hmac("sha256", $json, $secret));
  ' "$1" "$TMP" "$SECRET"

  curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/whatsapp" \
    -H 'Content-Type: application/json' \
    -H "X-Hub-Signature-256: $(cat "$TMP/wa_t_sig.txt")" \
    --data-binary "@$TMP/wa_t_body.json"
}

$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';
           DELETE FROM whatsapp_conversations;"

# Connect with test credentials so the module behaves as it would live.
php -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH."/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Core\Settings::set("whatsapp_app_secret", "test_app_secret_xyz");
  App\Core\Settings::set("whatsapp_phone_number_id", "1234567890");
  App\Core\Settings::set("whatsapp_access_token", "test_token_abc");
  App\Core\Settings::set("whatsapp_verify_token", "verify-me-123");
  App\Core\Settings::set("whatsapp_enabled", "1");
' > /dev/null 2>&1

T=$(tok /login)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
  --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"

WA="254712345678"
NOW=$(date +%s)

echo ""
echo "=== 1. Secrets are not left lying in the table ==="
eq "access token encrypted at rest" \
   "$(q "SELECT IF(setting_value='test_token_abc','plaintext','encrypted') FROM settings WHERE setting_key='whatsapp_access_token';")" "encrypted"
eq "app secret encrypted at rest" \
   "$(q "SELECT IF(setting_value='$SECRET','plaintext','encrypted') FROM settings WHERE setting_key='whatsapp_app_secret';")" "encrypted"

echo ""
echo "=== 2. Meta's verification handshake ==="
eq "right token echoes the challenge" \
   "$(curl -s "$BASE/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me-123&hub.challenge=CH42")" "CH42"
eq "wrong token refused" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=CH42")" "403"

echo ""
echo "=== 3. Only Meta can post to the webhook ==="
BODY="{\"entry\":[{\"changes\":[{\"value\":{\"contacts\":[{\"profile\":{\"name\":\"Jane Wanjiku\"},\"wa_id\":\"$WA\"}],\"messages\":[{\"from\":\"$WA\",\"id\":\"wamid.T1\",\"timestamp\":\"$NOW\",\"type\":\"text\",\"text\":{\"body\":\"Is my banner ready?\"}}]}}]}]}"
eq "a forged signature is refused" \
   "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/whatsapp" -H 'Content-Type: application/json' -H 'X-Hub-Signature-256: sha256=deadbeef' --data "$BODY")" "401"
eq "no signature at all is refused" \
   "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/whatsapp" -H 'Content-Type: application/json' --data "$BODY")" "401"
eq "a properly signed one is accepted" "$(hook "$BODY")" "200"

echo ""
echo "=== 4. The message lands, and finds its client ==="
CID=$(q "SELECT id FROM whatsapp_conversations WHERE wa_id='$WA';")
eq "conversation opened"  "$([ -n "$CID" ] && echo yes)" "yes"
eq "name picked up"       "$(q "SELECT display_name FROM whatsapp_conversations WHERE id=$CID;")" "Jane Wanjiku"
eq "matched to the client" "$(q "SELECT IF(client_id IS NULL,'no','yes') FROM whatsapp_conversations WHERE id=$CID;")" "yes"
eq "counted as unread"    "$(q "SELECT unread_count FROM whatsapp_conversations WHERE id=$CID;")" "1"
eq "the 24-hour clock started" "$(q "SELECT IF(last_inbound_at IS NULL,'no','yes') FROM whatsapp_conversations WHERE id=$CID;")" "yes"

echo ""
echo "=== 5. Meta redelivers; we do not duplicate ==="
hook "$BODY" > /dev/null
hook "$BODY" > /dev/null
eq "still one message" "$(q "SELECT COUNT(*) FROM whatsapp_messages WHERE conversation_id=$CID;")" "1"

echo ""
echo "=== 6. Emoji and accents survive ==="
UNI="{\"entry\":[{\"changes\":[{\"value\":{\"messages\":[{\"from\":\"$WA\",\"id\":\"wamid.T2\",\"timestamp\":\"$NOW\",\"type\":\"text\",\"text\":{\"body\":\"Asante 👍 Café — sawa\"}}]}}]}]}"
hook "$UNI" > /dev/null

# Read back through PHP, not the mysql client. The client mangles 4-byte
# characters such as emoji on output whatever charset flag it is given, so
# asserting through it would report a fault in the data that is not there.
# PHP is the path the application actually reads by.
eq "stored exactly as sent" "$(php -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH."/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  $m = App\Core\Database::first("SELECT body FROM whatsapp_messages WHERE wa_message_id=\x27wamid.T2\x27");
  echo $m ? ($m["body"] === "Asante 👍 Café — sawa" ? "exact" : "differs") : "missing";
')" "exact"

echo ""
echo "=== 7. Media keeps its reference ==="
IMG="{\"entry\":[{\"changes\":[{\"value\":{\"messages\":[{\"from\":\"$WA\",\"id\":\"wamid.T3\",\"timestamp\":\"$NOW\",\"type\":\"image\",\"image\":{\"id\":\"MEDIA9\",\"mime_type\":\"image/jpeg\",\"caption\":\"The artwork\"}}]}}]}]}"
hook "$IMG" > /dev/null
eq "type recorded"    "$(q "SELECT msg_type FROM whatsapp_messages WHERE wa_message_id='wamid.T3';")" "image"
eq "media id kept"    "$(q "SELECT media_id FROM whatsapp_messages WHERE wa_message_id='wamid.T3';")" "MEDIA9"
eq "caption kept"     "$(q "SELECT body FROM whatsapp_messages WHERE wa_message_id='wamid.T3';")" "The artwork"

echo ""
echo "=== 8. Delivery receipts follow the message ==="
$MYSQL -e "INSERT INTO whatsapp_messages (conversation_id,wa_message_id,direction,msg_type,body,status,sent_by)
           VALUES ($CID,'wamid.OUT9','out','text','On its way','sent',1);"
hook "{\"entry\":[{\"changes\":[{\"value\":{\"statuses\":[{\"id\":\"wamid.OUT9\",\"status\":\"read\",\"timestamp\":\"$NOW\"}]}}]}]}" > /dev/null
eq "marked read" "$(q "SELECT status FROM whatsapp_messages WHERE wa_message_id='wamid.OUT9';")" "read"

echo ""
echo "=== 9. The 24-hour rule ==="
$MYSQL -e "UPDATE whatsapp_conversations SET last_inbound_at=NOW() WHERE id=$CID;"
OPEN=$(curl -s -b "$JAR" "$BASE/whatsapp?c=$CID")
eq "inside the window, you can type"  "$(printf '%s' "$OPEN" | grep -c 'data-wa-form')" "1"
eq "and no warning is shown"          "$(printf '%s' "$OPEN" | grep -c 'wa__closed')" "0"

$MYSQL -e "UPDATE whatsapp_conversations SET last_inbound_at=DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id=$CID;"
SHUT=$(curl -s -b "$JAR" "$BASE/whatsapp?c=$CID")
eq "outside it, the box is gone"      "$(printf '%s' "$SHUT" | grep -c 'data-wa-form')" "0"
eq "and the reason is explained"      "$(printf '%s' "$SHUT" | grep -c 'wa__closed')" "1"

T=$(tok "/whatsapp?c=$CID")
eq "the endpoint refuses as well" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE/whatsapp/$CID/send" --data "_token=$T&body=Trying")" "409"
eq "and nothing was recorded" "$(q "SELECT COUNT(*) FROM whatsapp_messages WHERE body='Trying';")" "0"

echo ""
echo "=== 10. Who may read and reply ==="
HASH=$(php -r 'echo password_hash("Role@2026", PASSWORD_DEFAULT);')
for r in reception production; do
  $MYSQL -e "DELETE FROM users WHERE email='wa$r@shanfix.co.ke';
    INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('WA $r','wa$r@shanfix.co.ke','$HASH','$r',1);
    INSERT INTO user_roles (user_id,role) SELECT id,'$r' FROM users WHERE email='wa$r@shanfix.co.ke';"
done
probe() {
  local jar="$D/wa_$1.txt"; rm -f "$jar"
  local t
  t=$(curl -s -c "$jar" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -b "$jar" -c "$jar" -X POST "$BASE/login" --data "_token=$t&email=wa$1@shanfix.co.ke&password=Role@2026"
  curl -s -o /dev/null -w '%{http_code}' -b "$jar" "$BASE/whatsapp"
  rm -f "$jar"
}
eq "reception can answer the company number" "$(probe reception)" "200"
eq "production has no business there"        "$(probe production)" "403"

echo ""
echo "=== 11. Switched off, the module says so rather than half-working ==="
php -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH."/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  App\Core\Settings::set("whatsapp_enabled", "0");
' > /dev/null 2>&1
eq "explains it is not connected" "$(curl -s -b "$JAR" "$BASE/whatsapp" | grep -c 'WhatsApp is not connected')" "1"

# Tidy up.
$MYSQL -e "DELETE FROM whatsapp_conversations;
           DELETE FROM users WHERE email LIKE 'wa%@shanfix.co.ke';"
php -r '
  require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
  App\Core\Config::load(CONFIG_PATH."/config.php");
  App\Core\Database::connect(App\Core\Config::get("db"));
  foreach (["whatsapp_enabled","whatsapp_phone_number_id","whatsapp_access_token",
            "whatsapp_app_secret","whatsapp_verify_token"] as $k) {
      App\Core\Settings::set($k, $k === "whatsapp_enabled" ? "0" : "");
  }
' > /dev/null 2>&1
rm -f "$JAR" "$TMP/wa_t_body.json" "$TMP/wa_t_sig.txt"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
