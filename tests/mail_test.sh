#!/bin/bash
# Each member of staff's own mailbox.
#
# Runs against tests/helpers/fake_mail.py, a stand-in cPanel mail server
# (IMAP + SMTP) started by this suite, so nothing here touches real mail.
#
# The engine — IMAP, MIME, the sanitizer, sending — is proved by
# tests/helpers/mailbox_engine.php. This suite proves the web side, and
# above all the privacy promise: a member of staff reaches their own
# mailbox and nobody else's, administrators included.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

IMAP_PORT=59243
SMTP_PORT=59125
MAIL_PID=""

KEYS="'mail_enabled','mail_imap_host','mail_imap_port','mail_imap_security','mail_smtp_host','mail_smtp_port','mail_smtp_security','mail_max_attach_mb'"
BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value) SEPARATOR ';') FROM settings WHERE setting_key IN ($KEYS);")

restore() {
  local pair key val
  for pair in $(echo "$BEFORE" | tr ';' ' '); do
    key="${pair%%=*}"; val="${pair#*=}"
    q "UPDATE settings SET setting_value='$val' WHERE setting_key='$key';"
  done
  q "DELETE FROM mail_accounts WHERE email LIKE '%@shanfix.test';"
  q "DELETE FROM users WHERE email IN ('mailtest.alice@shanfix.co.ke','mailtest.bob@shanfix.co.ke');"
  [ -n "$MAIL_PID" ] && kill "$MAIL_PID" 2>/dev/null
}
trap restore EXIT

python "$ROOT/tests/helpers/fake_mail.py" $IMAP_PORT $SMTP_PORT > /dev/null 2>&1 &
MAIL_PID=$!
for i in $(seq 1 30); do
  (exec 3<>/dev/tcp/127.0.0.1/$IMAP_PORT) 2>/dev/null && break
  sleep 0.2
done

echo ""
echo "=== 0. The engine, against the fake server ==="
ENGINE=$($PHP "$ROOT/tests/helpers/mailbox_engine.php" $IMAP_PORT $SMTP_PORT 2>&1)
echo "$ENGINE" | grep -E "FAIL" | sed 's/^/    /'
eq "every engine check passes" "$(echo "$ENGINE" | grep -o 'FAILED: [0-9]*')" "FAILED: 0"

# The engine run changed the fake server's mailboxes; start it afresh.
kill "$MAIL_PID" 2>/dev/null; wait "$MAIL_PID" 2>/dev/null
python "$ROOT/tests/helpers/fake_mail.py" $IMAP_PORT $SMTP_PORT > /dev/null 2>&1 &
MAIL_PID=$!
for i in $(seq 1 30); do
  (exec 3<>/dev/tcp/127.0.0.1/$IMAP_PORT) 2>/dev/null && break
  sleep 0.2
done

# Two members of staff, signing in as themselves.
HASH=$($PHP -r 'echo password_hash("Mail@2026", PASSWORD_DEFAULT);')
for u in alice bob; do
  q "DELETE FROM users WHERE email='mailtest.$u@shanfix.co.ke';
     INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('${u^} Mail','mailtest.$u@shanfix.co.ke','$HASH','staff',1);
     INSERT INTO user_roles (user_id, role) SELECT id,'staff' FROM users WHERE email='mailtest.$u@shanfix.co.ke';"
done
as() { signin "mailtest.$1" Mail@2026 > /dev/null; }

echo ""
echo "=== 1. Before the server is set up ==="
q "UPDATE settings SET setting_value='' WHERE setting_key IN ('mail_imap_host','mail_smtp_host');"
as alice
eq "the mailbox sends you to set-up" "$(code /mail)" "302"
has "which says the server is not ready" "$(page /mail/setup)" "has not been set up yet"

echo ""
echo "=== 2. An administrator sets up the server ==="
signin_admin
eq "the Email server tab exists" "$(code '/settings?tab=email')" "200"
T=$(tok '/settings?tab=email')
post /settings/email --data "_token=$T&mail_enabled=1&mail_imap_host=127.0.0.1&mail_imap_port=$IMAP_PORT&mail_imap_security=none&mail_smtp_host=127.0.0.1&mail_smtp_port=$SMTP_PORT&mail_smtp_security=none&mail_max_attach_mb=20&check=1" > /dev/null
eq "the server is saved" "$(q "SELECT setting_value FROM settings WHERE setting_key='mail_imap_host';")" "127.0.0.1"
has "and the check reaches it" "$(page '/settings?tab=email')" "Both the incoming and outgoing mail servers answered"

echo ""
echo "=== 3. Connecting your own mailbox ==="
as alice
T=$(tok /mail/setup)
post /mail/setup --data-urlencode "_token=$T" --data-urlencode "email=alice@shanfix.test" --data-urlencode "password=wrong" > /dev/null
has "a wrong password is refused" "$(page /mail/setup)" "did not accept"
T=$(tok /mail/setup)
post /mail/setup --data-urlencode "_token=$T" --data-urlencode "email=alice@shanfix.test" \
     --data-urlencode "password=alice-pass-1" --data-urlencode "display_name=Alice Mail" --data-urlencode "signature=Alice, Shanfix" > /dev/null
eq "the right one connects" "$(q "SELECT COUNT(*) FROM mail_accounts WHERE email='alice@shanfix.test';")" "1"
eq "and the password is not stored as typed" "$(q "SELECT password_enc LIKE '%alice-pass-1%' FROM mail_accounts WHERE email='alice@shanfix.test';")" "0"

echo ""
echo "=== 4. Reading ==="
INBOX=$(page /mail)
has "the inbox opens"                 "$INBOX" "Quote for 500 flyers"
has "folders are listed by role"      "$INBOX" ">Sent<"
has "an encoded sender is readable"   "$INBOX" "José Müller"
READ=$(page '/mail?folder=INBOX&uid=2')
has "a message opens in the reading pane" "$READ" "Hostile HTML"
has "its body loads in a sandboxed frame with no scripts" "$READ" 'sandbox="allow-popups allow-popups-to-escape-sandbox"'
has "and the reader is told pictures were held back" "$READ" "Show pictures"

BODY=$(curl -s -D - -b "$JAR" "$BASE/mail/message/2/body?folder=INBOX")
has "the body carries its own strict policy"  "$BODY" "default-src 'none'"
eq  "the body runs no script"                 "$(echo "$BODY" | grep -ci '<script')" "0"
eq  "and loads no tracking pixel"             "$(echo "$BODY" | grep -c 'tracker.example')" "0"
has "until pictures are allowed"              "$(curl -s -b "$JAR" "$BASE/mail/message/2/body?folder=INBOX&images=1")" "tracker.example/pixel.gif"

ATT=$(curl -s -D - -b "$JAR" "$BASE/mail/message/3/attachment/0?folder=INBOX")
has "an attachment downloads" "$ATT" "%PDF-1.4 fake invoice"
has "always as a download, never shown in the page" "$ATT" "Content-Disposition: attachment"

echo ""
echo "=== 5. Sending ==="
# The Windows curl cannot read a Git Bash path such as /tmp/..., and then
# silently sends nothing at all (exit 26). cygpath gives it one it can.
echo "Proof for Bob" > "$D/mail-proof.txt"
PROOF="$D/mail-proof.txt"
command -v cygpath > /dev/null 2>&1 && PROOF=$(cygpath -m "$PROOF")
T=$(tok /mail/compose)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/mail/send" \
     -F "_token=$T" -F "to=Bob <bob@shanfix.test>" -F "subject=From the web" -F "text=Hello Bob" \
     -F "attachments[]=@$PROOF"
has "it says Sent" "$(page /mail)" "Sent."
has "and a copy is in Sent" "$(page '/mail?folder=INBOX.Sent')" "From the web"

T=$(tok /mail/compose)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/mail/send" -F "_token=$T" -F "to=not an address" -F "subject=Kept" -F "text=Please keep me"
COMPOSE=$(page /mail/compose)
has "a bad address is refused" "$COMPOSE" "do not look like email addresses"
has "and what was typed is kept, not lost" "$COMPOSE" "Please keep me"

has "reply fills in the sender and quotes them" "$(page '/mail/compose?mode=reply&folder=INBOX&uid=1')" "&gt; Please send a quote for 500 A5 flyers"
has "forward offers the original attachment" "$(page '/mail/compose?mode=forward&folder=INBOX&uid=3')" "invoice-001.pdf"

echo ""
echo "=== 6. Tidying ==="
T=$(tok /mail)
post /mail/action --data "_token=$T&folder=INBOX&uids[]=1&do=delete" > /dev/null
eq "delete takes it out of the inbox" "$(page /mail | grep -c 'Quote for 500 flyers')" "0"
has "and into Trash" "$(page '/mail?folder=INBOX.Trash')" "Quote for 500 flyers"

echo ""
echo "=== 7. Nobody else's mail ==="
# Bob has connected nothing. There is no way for him to reach Alice's
# mailbox: every route works on the signed-in person only, and none takes
# an id to say otherwise.
as bob
eq "Bob, with no mailbox, is sent to set up his own" "$(code /mail)" "302"
eq "Bob cannot read Alice's message by its number"   "$(code '/mail/message/2/body?folder=INBOX')" "302"
eq "nor download her attachment"                    "$(code '/mail/message/3/attachment/0?folder=INBOX')" "302"
T=$(tok /mail/setup)
post /mail/setup --data-urlencode "_token=$T" --data-urlencode "email=alice@shanfix.test" --data-urlencode "password=alice-pass-1" > /dev/null
has "nor connect her mailbox as his own, even knowing her password" "$(page /mail/setup)" "already connected that mailbox"
T=$(tok /mail/setup)
post /mail/setup --data-urlencode "_token=$T" --data-urlencode "email=bob@shanfix.test" --data-urlencode "password=bob-pass-2" > /dev/null
BOBBOX=$(page /mail)
has "his own mailbox shows his own mail" "$BOBBOX" "From the web"
eq  "and none of Alice's" "$(echo "$BOBBOX" | grep -c 'Hostile HTML')" "0"

signin_admin
eq "no mail route takes a user id" "$(grep -E "'/mail" "$ROOT/routes.php" | grep -ciE 'user|\{id\}')" "0"

echo ""
echo "=== 8. The sidebar count ==="
as alice
has "unread count answers" "$(curl -s -b "$JAR" "$BASE/mail/unread")" '"ok":true'

report
