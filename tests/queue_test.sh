#!/bin/bash
# The outbound queue: whether anything actually leaves the building.
#
# Two faults were reported together and turned out to be one. Renewal
# reminders were not reaching clients, and staff and partner messages
# sat at "queued" for ever. Both were queued correctly; what never
# happened was the sending.
#
# cron worked the queue only when app.url was set. That guard belongs
# on the queueing step and is still there — a client document carrying
# a link to localhost is worse than one sent tomorrow. Over the sending
# step it was wrong: a message already in the queue has its body
# already written, and refusing to post the envelope does not change
# what is inside it. An office with no app.url therefore had every
# notification of every kind stop, with nothing on any screen to say so.
#
# The other half of that: the sending window held everything, when it
# exists so a client is not texted at three in the morning. It was also
# holding a colleague's bell e-mail.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

# A message in the queue, as any of the three things that queue one.
queue() {
  local channel="$1" audience="$2" event="$3" recipient="$4"
  q "INSERT INTO notifications (channel,event,audience,recipient,recipient_name,subject,body,status)
     VALUES ('$channel','$event','$audience','$recipient','Someone','A subject','<p>body</p>','queued');"
  q "SELECT LAST_INSERT_ID();"
}

state()    { q "SELECT status   FROM notifications WHERE id=$1;"; }
attempts() { q "SELECT attempts FROM notifications WHERE id=$1;"; }

# Work the queue the way cron does, without the rest of a cron run.
work() { $PHP "$ROOT/tests/helpers/work_queue.php" "$@" 2>&1; }

# ---------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------
BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value) SEPARATOR ';') FROM settings
             WHERE setting_key IN ('notify_send_window','notify_max_attempts',
                                   'notify_batch_size','notify_give_up_after',
                                   'smtp_enabled','sms_enabled','cron_last_run');")

KEPT="$D/queue_notifications.sql"

NEEDS_CONFIG_RESTORE=0

restore() {
  # This suite blanks app.url on disk for one test. If it died in
  # between, the config is broken for everything that runs next.
  if [ "$NEEDS_CONFIG_RESTORE" = "1" ] && [ -f "$D/config.before-queue-test" ]; then
    cp "$D/config.before-queue-test" "$ROOT/config/config.php"
  fi

  rm -f "$D/config.before-queue-test"

  local pair key val
  while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    key="${pair%%=*}"; val="${pair#*=}"
    q "UPDATE settings SET setting_value='$(printf '%s' "$val" | sed "s/'/''/g")' WHERE setting_key='$key';"
  done <<EOF
$(printf '%s' "$BEFORE" | tr ';' '\n')
EOF

  q "DELETE FROM notifications WHERE subject='A subject' OR recipient LIKE '%@queue.test';"
}
trap restore EXIT

q "DELETE FROM notifications WHERE subject='A subject' OR recipient LIKE '%@queue.test';"
q "UPDATE settings SET setting_value='' WHERE setting_key='notify_send_window';"
q "UPDATE settings SET setting_value='3' WHERE setting_key='notify_max_attempts';"
q "UPDATE settings SET setting_value='60' WHERE setting_key='notify_batch_size';"
q "UPDATE settings SET setting_value='5' WHERE setting_key='notify_give_up_after';"
q "UPDATE settings SET setting_value='1' WHERE setting_key='smtp_enabled';"

signin_admin

echo ""
echo "=== 1. A message in the queue is attempted, app.url or no app.url ==="

# The reported fault. SMTP here points at a dead port, so nothing will
# succeed — but "attempted and failed" and "never looked at" are
# different things, and the second was what was happening.
STAFF=$(queue email internal social_awaiting 'colleague@queue.test')
eq "it starts untouched" "$(attempts "$STAFF")" "0"

# Reproduced the way it happened: a real cron run against a config with
# no app.url. Blanked on disk because Config is deliberately immutable,
# and put back immediately — and again from the trap, in case this dies
# in between.
CONF="$ROOT/config/config.php"
cp "$CONF" "$D/config.before-queue-test"
NEEDS_CONFIG_RESTORE=1
$PHP -r '$f=$argv[1]; $s=file_get_contents($f);
         file_put_contents($f, preg_replace("/('url'\s*=>\s*)'[^']*'/", "$1''", $s, 1));' "$CONF"

eq "with app.url blank, cron still refuses to queue a client document"    "$(work --can-build-links | grep -c 'app.url is not set')" "1"

$PHP "$ROOT/cron.php" >/dev/null 2>&1

cp "$D/config.before-queue-test" "$CONF"
NEEDS_CONFIG_RESTORE=0

ne "but it did attempt the message already in the queue" "$(attempts "$STAFF")" "0"

echo ""
echo "=== 2. The window holds a client's text, not a colleague's email ==="

# A window that does not include now.
NOW_H=$(date +%H)
FAR=$(( (10#$NOW_H + 4) % 24 ))
END=$(( (10#$NOW_H + 6) % 24 ))
q "UPDATE settings SET setting_value='$(printf '%02d' $FAR):00-$(printf '%02d' $END):00'
    WHERE setting_key='notify_send_window';"

CLIENT=$(queue email client invoice_sent 'client@queue.test')
MATE=$(queue email internal chat_mention 'mate@queue.test')

work --outside-window >/dev/null

eq "a client message is held"        "$(attempts "$CLIENT")" "0"
ne "a colleague's message goes"      "$(attempts "$MATE")" "0"
eq "and the held one is still queued, not failed" "$(state "$CLIENT")" "queued"

# Inside the window, the client's goes too.
work >/dev/null
ne "inside the window the client message is attempted" "$(attempts "$CLIENT")" "0"

q "UPDATE settings SET setting_value='' WHERE setting_key='notify_send_window';"

echo ""
echo "=== 3. A dead mail server stops the run instead of grinding ==="

# Forty attempts against a refused connection is most of the gap
# between cron runs, and it buries the log. Five in a row is the server
# being down; the sixth will fail exactly the same way.
q "DELETE FROM notifications WHERE subject='A subject';"

for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
  queue email internal social_due "bulk$i@queue.test" >/dev/null
done

OUT=$(work)
has "it says it gave up"     "$OUT" "gave up after"
has "and why"                "$OUT" "looks to be down"

TRIED=$(q "SELECT COUNT(*) FROM notifications WHERE subject='A subject' AND attempts > 0;")
eq "it stopped at the give-up point rather than trying all twelve" "$TRIED" "5"

eq "the rest are untouched and wait for the next run" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE subject='A subject' AND attempts=0 AND status='queued';")" "7"

echo ""
echo "=== 4. Giving up for good, and being given another chance ==="

q "DELETE FROM notifications WHERE subject='A subject';"
DOOMED=$(queue email internal social_due 'doomed@queue.test')

# Three attempts is the configured limit.
work --one "$DOOMED" >/dev/null
work --one "$DOOMED" >/dev/null
work --one "$DOOMED" >/dev/null

eq "after three tries it is marked failed" "$(state "$DOOMED")" "failed"
eq "and is not attempted a fourth time"    "$(attempts "$DOOMED")" "3"

work >/dev/null
eq "an ordinary run leaves it alone" "$(attempts "$DOOMED")" "3"

# After an outage there can be two hundred of these, and retrying them
# one at a time is not a thing anybody does.
T=$(tok /notifications)
eq "they can all be put back at once" \
   "$(post /notifications/retry-failed --data-urlencode "_token=$T")" "302"
ne "and it was tried again" "$(attempts "$DOOMED")" "3"

echo ""
echo "=== 5. The screen says why nothing is moving ==="

# The most useful part. "The queue is stuck" and "cron has not run
# since the hosting was migrated" looked identical from every screen,
# and they are the two likeliest causes by a distance.
q "UPDATE settings SET setting_value=NOW() WHERE setting_key='cron_last_run';"
queue email internal social_due 'fresh@queue.test' >/dev/null

PAGE=$(page /notifications)
has "it says when cron last ran"  "$PAGE" "Cron last ran"
has "and that the queue is moving" "$PAGE" "The queue is being worked"

q "UPDATE settings SET setting_value=DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE setting_key='cron_last_run';"
PAGE=$(page /notifications)
has "a stopped cron is called out"  "$PAGE" "while it is not running, nothing goes out"
has "with how many are waiting"     "$PAGE" "waiting"

q "UPDATE settings SET setting_value='0' WHERE setting_key='smtp_enabled';"
has "email being switched off is called out" \
    "$(page /notifications)" "Email is switched off"
q "UPDATE settings SET setting_value='1' WHERE setting_key='smtp_enabled';"

# Never having run at all is its own message: there is a line to add to
# cPanel, and "1970" would not tell anybody that.
q "UPDATE settings SET setting_value='' WHERE setting_key='cron_last_run';"
has "an installation where cron never ran is told plainly" \
    "$(page /notifications)" "never run"

q "UPDATE settings SET setting_value=NOW() WHERE setting_key='cron_last_run';"

echo ""
echo "=== 6. Cron writes down that it ran ==="

q "UPDATE settings SET setting_value='' WHERE setting_key='cron_last_run';"
$PHP "$ROOT/cron.php" >/dev/null 2>&1
ne "a real cron run records itself" \
   "$(q "SELECT setting_value FROM settings WHERE setting_key='cron_last_run';")" ""

echo ""
echo "=== 7. What queues a message says who it is for ==="

# The distinction the window rests on, taken from the row rather than
# guessed from the event name — which is a list that drifts.
eq "staff notifications mark themselves internal" \
   "$(grep -c \"'audience'       => 'internal'\" "$ROOT/app/Services/StaffNotifier.php")" "1"

eq "and a client document is a client's by default" \
   "$(q "SELECT COLUMN_DEFAULT FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='notifications'
            AND column_name='audience';")" "client"

echo ""
echo "=== 8. Renewal reminders do reach the queue ==="

# The first half of the report. These were queueing correctly all
# along; it was the sending that never happened.
q "DELETE FROM notifications WHERE event='renewal_due';"
q "DELETE FROM notification_locks;"
q "DELETE FROM subscriptions WHERE name='QTEST renewal';"

CL=$(q "SELECT id FROM clients WHERE email IS NOT NULL AND email<>'' ORDER BY id LIMIT 1;")
q "INSERT INTO subscriptions (client_id,name,status,next_renewal_date,amount,billing_cycle,reminder_days)
   VALUES ($CL,'QTEST renewal','active',DATE_ADD(CURDATE(), INTERVAL 7 DAY),18000,'yearly','30,14,7,1');"

$PHP "$ROOT/tests/helpers/work_queue.php" --renewals >/dev/null

ne "a renewal due in seven days is queued" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='renewal_due';")" "0"
eq "to the client's own address" \
   "$(q "SELECT COUNT(*) FROM notifications n JOIN clients c ON c.id=n.client_id
          WHERE n.event='renewal_due' AND n.recipient=c.email AND n.channel='email';")" "1"
eq "and it is a client message, so the window applies to it" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='renewal_due' AND audience='client';")" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='renewal_due';")"

# Once per offset per renewal, however many times cron runs.
$PHP "$ROOT/tests/helpers/work_queue.php" --renewals >/dev/null
eq "and running again queues no duplicates" \
   "$(q "SELECT COUNT(*) FROM notifications WHERE event='renewal_due';")" "2"

q "DELETE FROM subscriptions WHERE name='QTEST renewal';"
q "DELETE FROM notifications WHERE event='renewal_due';"
q "DELETE FROM notification_locks;"

report
