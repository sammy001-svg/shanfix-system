#!/bin/bash
# Being reminded, and writing the numbers down.
#
# social_test.sh covers writing a post and approving it. This covers
# whether the module gets used in week three — which turns on two
# things, and neither of them existed when the module shipped.
#
# A calendar nobody is reminded by is a calendar somebody fills in once
# and then stops opening. Migration 053 seeded social_remind_hours and
# nothing read it; social media was not in cron at all. So a post
# scheduled for nine this morning that is still sitting unapproved at
# eleven looked exactly like one that had gone out.
#
# And a report nobody feeds is a report nobody trusts. Recording a
# month one post at a time is a dozen page loads, so it does not get
# done, so the report is empty, so nobody opens it.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

sweep() { $PHP "$ROOT/tests/helpers/social_sweep.php" 2>&1; }

notices_for() {
  q "SELECT COUNT(*) FROM staff_notifications
      WHERE event='$1' AND entity_type='social_post' AND entity_id=$2;"
}

newest_post() { q "SELECT id FROM social_posts ORDER BY id DESC LIMIT 1;"; }

# A post, dated relative to now, in whatever state.
plan() {
  local title="$1" status="$2" offset="$3"
  q "INSERT INTO social_posts (ref, title, status, owner_id, created_by, scheduled_for)
     VALUES (CONCAT('TR-', FLOOR(RAND()*100000)), '$title', '$status', $WRITER, $WRITER,
             DATE_ADD(NOW(), INTERVAL $offset));"
  newest_post
}

# ---------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------
BEFORE=$(q "SELECT GROUP_CONCAT(CONCAT(setting_key,'=',setting_value) SEPARATOR ';') FROM settings
             WHERE setting_key IN ('social_remind_hours','social_weekly_digest',
                                   'social_digest_day','social_digest_sent');")

restore() {
  local pair key val
  while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    key="${pair%%=*}"; val="${pair#*=}"
    q "UPDATE settings SET setting_value='$(printf '%s' "$val" | sed "s/'/''/g")' WHERE setting_key='$key';"
  done <<EOF
$(printf '%s' "$BEFORE" | tr ';' '\n')
EOF

  q "DELETE FROM staff_notifications WHERE entity_type='social_post' OR event='social_week';"
  q "DELETE FROM social_posts WHERE ref LIKE 'TR-%' OR title LIKE 'TEST %';"
  q "DELETE FROM social_accounts WHERE name LIKE 'TEST %';"
  q "DELETE FROM users WHERE email='rhythmwriter@shanfix.co.ke';"
}
trap restore EXIT

restore
q "UPDATE settings SET setting_value='24' WHERE setting_key='social_remind_hours';"
# The digest runs on its own day and would otherwise fire in the middle
# of the reminder checks and muddy the counts. Section 4 turns it on.
q "UPDATE settings SET setting_value='0' WHERE setting_key='social_weekly_digest';"

signin_admin

HASH=$($PHP -r 'echo password_hash("RhythmPass1", PASSWORD_DEFAULT);')
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Rhythm Writer','rhythmwriter@shanfix.co.ke','$HASH','designer',1);"
WRITER=$(q "SELECT id FROM users WHERE email='rhythmwriter@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($WRITER,'designer');"

q "INSERT INTO social_accounts (network,name,handle,status,position)
   VALUES ('facebook','TEST Page','@test','active',90);"
ACC=$(q "SELECT id FROM social_accounts WHERE name='TEST Page';")

echo ""
echo "=== 1. A post coming up gets a nudge ==="

SOON=$(plan 'TEST Due in six hours' 'approved' '6 HOUR')
FAR=$(plan 'TEST Due in a fortnight' 'approved' '14 DAY')

sweep >/dev/null

eq "the one due today is nudged"        "$(notices_for social_due "$SOON")" "1"
eq "the one due in a fortnight is not"  "$(notices_for social_due "$FAR")" "0"

eq "and it is stamped, so it is not nudged again" \
   "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$SOON AND reminded_at IS NOT NULL;")" "1"

sweep >/dev/null
eq "a second sweep says nothing more"   "$(notices_for social_due "$SOON")" "1"

# An approved post is ready; one still waiting for a yes is not, and
# the nudge has to say which — the writer can do something about one of
# those and not the other.
NOTYET=$(plan 'TEST Due soon but unapproved' 'awaiting' '5 HOUR')
sweep >/dev/null
has "a post not yet approved is told so" \
    "$(q "SELECT title FROM staff_notifications WHERE event='social_due' AND entity_id=$NOTYET;")" \
    "not approved yet"
has "and an approved one is told it is ready" \
    "$(q "SELECT title FROM staff_notifications WHERE event='social_due' AND entity_id=$SOON;")" \
    "Ready to post"

echo ""
echo "=== 2. A post that did not go out is a problem, not a note ==="

LATE=$(plan 'TEST Should have gone out' 'approved' '-2 HOUR')
sweep >/dev/null

eq "it is reported missed" "$(notices_for social_missed "$LATE")" "1"
has "and says when it was due" \
    "$(q "SELECT body FROM staff_notifications WHERE event='social_missed' AND entity_id=$LATE LIMIT 1;")" \
    "Due"

# A post stuck at "awaiting" at its own publishing hour is usually
# waiting on somebody else, so somebody else is told.
STUCK=$(plan 'TEST Stuck waiting for a yes' 'awaiting' '-1 HOUR')
sweep >/dev/null

has "one stuck for approval says why" \
    "$(q "SELECT body FROM staff_notifications WHERE event='social_missed' AND entity_id=$STUCK LIMIT 1;")" \
    "waiting for approval"

ADMIN=$(q "SELECT id FROM users WHERE email='admin@shanfix.co.ke';")
eq "and whoever can approve is told, not just the writer" \
   "$(q "SELECT COUNT(*) FROM staff_notifications
          WHERE event='social_missed' AND entity_id=$STUCK AND user_id=$ADMIN;")" "1"

# An approved post that simply was not posted is the writer's own to
# fix, so nobody else is dragged in.
eq "one that was merely not posted stays with the writer" \
   "$(q "SELECT COUNT(DISTINCT user_id) FROM staff_notifications
          WHERE event='social_missed' AND entity_id=$LATE;")" "1"

sweep >/dev/null
eq "and it is only said once" "$(notices_for social_missed "$LATE")" "1"

# Something three days late is a decision somebody made and did not
# write down. Shouting about it when the cron is first switched on
# would bury the ones that matter.
ANCIENT=$(plan 'TEST Late by a week' 'approved' '-7 DAY')
sweep >/dev/null
eq "a post a week late is left alone" "$(notices_for social_missed "$ANCIENT")" "0"

# What has gone out is not late.
q "UPDATE social_posts SET status='published', published_at=NOW() WHERE id=$SOON;"
q "UPDATE social_posts SET scheduled_for=DATE_SUB(NOW(), INTERVAL 3 HOUR), missed_at=NULL WHERE id=$SOON;"
sweep >/dev/null
eq "a published post is never late" "$(notices_for social_missed "$SOON")" "0"

echo ""
echo "=== 3. Moving a post means reminding about it again ==="

# The post that was moved is exactly the one most likely to be
# forgotten, so the stamps come off with the date.
MOVED=$(plan 'TEST Moved to another day' 'approved' '-2 HOUR')
sweep >/dev/null
eq "it was reported missed once" "$(notices_for social_missed "$MOVED")" "1"

T=$(tok "/social/$MOVED/edit")
eq "it can be moved to tomorrow" \
   "$(post "/social/$MOVED" --data-urlencode "_token=$T" \
        --data-urlencode "title=TEST Moved to another day" \
        --data-urlencode "scheduled_for=$(date -d '+4 hours' '+%Y-%m-%dT%H:%M' 2>/dev/null || date -v+4H '+%Y-%m-%dT%H:%M')")" "302"

eq "the stamps come off with the date" \
   "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$MOVED
          AND reminded_at IS NULL AND missed_at IS NULL;")" "1"

sweep >/dev/null
eq "so it is nudged about its new day" "$(notices_for social_due "$MOVED")" "1"

# Saving without touching the date must not start the reminders over,
# or every edit re-notifies.
T=$(tok "/social/$MOVED/edit")
WHEN=$(q "SELECT DATE_FORMAT(scheduled_for, '%Y-%m-%dT%H:%i') FROM social_posts WHERE id=$MOVED;")
post "/social/$MOVED" --data-urlencode "_token=$T" \
     --data-urlencode "title=TEST Moved to another day, reworded" \
     --data-urlencode "scheduled_for=$WHEN" >/dev/null
eq "editing the words leaves the stamp alone" \
   "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$MOVED AND reminded_at IS NOT NULL;")" "1"

echo ""
echo "=== 4. The Monday note ==="

q "DELETE FROM staff_notifications WHERE event='social_week';"
q "UPDATE settings SET setting_value='1' WHERE setting_key='social_weekly_digest';"
q "UPDATE settings SET setting_value='$(date +%u)' WHERE setting_key='social_digest_day';"
q "UPDATE settings SET setting_value='' WHERE setting_key='social_digest_sent';"

sweep >/dev/null
ne "it goes out on its day" "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='social_week';")" "0"

SENT=$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='social_week';")
sweep >/dev/null
eq "once a week, not once a sweep" \
   "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='social_week';")" "$SENT"

has "and it says what is waiting" \
    "$(q "SELECT body FROM staff_notifications WHERE event='social_week' LIMIT 1;")" \
    "waiting for approval"

# On any other day, nothing.
q "DELETE FROM staff_notifications WHERE event='social_week';"
q "UPDATE settings SET setting_value='' WHERE setting_key='social_digest_sent';"
OTHER=$(( ($(date +%u) % 7) + 1 ))
q "UPDATE settings SET setting_value='$OTHER' WHERE setting_key='social_digest_day';"
sweep >/dev/null
eq "on another day of the week, nothing" \
   "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='social_week';")" "0"

q "UPDATE settings SET setting_value='0' WHERE setting_key='social_weekly_digest';"

echo ""
echo "=== 5. Writing a month's numbers down in one go ==="

# The weak point of the module: a dozen page loads is a thing that does
# not get done, and then the report is empty.
P1=$(plan 'TEST Published, unrecorded one' 'published' '-4 DAY')
P2=$(plan 'TEST Published, unrecorded two' 'published' '-3 DAY')
q "UPDATE social_posts SET published_at = scheduled_for WHERE id IN ($P1,$P2);"
q "INSERT INTO social_post_targets (post_id, account_id) VALUES ($P1,$ACC),($P2,$ACC);"

T1=$(q "SELECT id FROM social_post_targets WHERE post_id=$P1;")
T2=$(q "SELECT id FROM social_post_targets WHERE post_id=$P2;")

PAGE=$(page /social/catch-up)
eq  "the page opens"                "$(code /social/catch-up)" "200"
has "and lists the first"           "$PAGE" "TEST Published, unrecorded one"
has "and the second"                "$PAGE" "TEST Published, unrecorded two"
has "saying how many are owed"      "$PAGE" "nothing written down"

T=$(tok /social/catch-up)
eq "both can be written down at once" \
   "$(post /social/catch-up --data-urlencode "_token=$T" \
        --data-urlencode "t[$T1][reach]=1200" --data-urlencode "t[$T1][likes]=45" \
        --data-urlencode "t[$T2][reach]=800"  --data-urlencode "t[$T2][likes]=21")" "302"

eq "the first is kept"   "$(q "SELECT reach FROM social_post_targets WHERE id=$T1;")" "1200"
eq "and the second"      "$(q "SELECT reach FROM social_post_targets WHERE id=$T2;")" "800"
eq "both stamped"        "$(q "SELECT COUNT(*) FROM social_post_targets
                                WHERE id IN ($T1,$T2) AND metrics_at IS NOT NULL;")" "2"

# Now they are done, they drop off the list — the page is a queue, not
# a table.
PAGE=$(page /social/catch-up)
case "$PAGE" in
  *"TEST Published, unrecorded one"*) bad "a recorded post leaves the list" "still there" "gone";;
  *)                                  ok  "a recorded post leaves the list" "gone";;
esac
has "unless you ask to see everything" "$(page '/social/catch-up?all=1')" "TEST Published, unrecorded one"

echo ""
echo "=== 6. A blank is not a nought ==="

# The property the reports rest on. Somebody who fills in three boxes
# and leaves five empty has recorded three figures, not five zeroes.
P3=$(plan 'TEST Partly recorded' 'published' '-2 DAY')
q "UPDATE social_posts SET published_at = scheduled_for WHERE id=$P3;"
q "INSERT INTO social_post_targets (post_id, account_id) VALUES ($P3,$ACC);"
T3=$(q "SELECT id FROM social_post_targets WHERE post_id=$P3;")

T=$(tok /social/catch-up)
post /social/catch-up --data-urlencode "_token=$T" \
     --data-urlencode "t[$T3][reach]=500" \
     --data-urlencode "t[$T3][likes]=" --data-urlencode "t[$T3][shares]=" >/dev/null

eq "what was given is kept"        "$(q "SELECT reach FROM social_post_targets WHERE id=$T3;")" "500"
eq "what was left blank stays nought, not a recorded nought" \
   "$(q "SELECT likes FROM social_post_targets WHERE id=$T3;")" "0"
eq "but the row counts as checked, because something was given" \
   "$(q "SELECT COUNT(*) FROM social_post_targets WHERE id=$T3 AND metrics_at IS NOT NULL;")" "1"

# Saving an entirely empty form must not mark everything as checked.
P4=$(plan 'TEST Nothing filled in' 'published' '-1 DAY')
q "UPDATE social_posts SET published_at = scheduled_for WHERE id=$P4;"
q "INSERT INTO social_post_targets (post_id, account_id) VALUES ($P4,$ACC);"
T4=$(q "SELECT id FROM social_post_targets WHERE post_id=$P4;")

T=$(tok /social/catch-up)
post /social/catch-up --data-urlencode "_token=$T" \
     --data-urlencode "t[$T4][reach]=" --data-urlencode "t[$T4][likes]=" >/dev/null
eq "an empty form records nothing" \
   "$(q "SELECT COUNT(*) FROM social_post_targets WHERE id=$T4 AND metrics_at IS NULL;")" "1"

echo ""
echo "=== 7. Nobody writes numbers onto somebody else's work ==="

NJ="$D/rhythm_nobody.txt"
rm -f "$NJ"
q "DELETE FROM users WHERE email='rhythmnobody@shanfix.co.ke';"
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Rhythm Nobody','rhythmnobody@shanfix.co.ke','$HASH','production',1);"
NOBODY=$(q "SELECT id FROM users WHERE email='rhythmnobody@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($NOBODY,'production');"
JAR="$NJ" login_as "rhythmnobody@shanfix.co.ke" "RhythmPass1" >/dev/null 2>&1

eq "somebody with no reason to be here cannot open it" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$NJ" "$BASE/social/catch-up")" "403"

WAS=$(q "SELECT reach FROM social_post_targets WHERE id=$T1;")
curl -s -o /dev/null -b "$NJ" -X POST "$BASE/social/catch-up" \
     --data-urlencode "t[$T1][reach]=999999" >/dev/null
eq "and cannot write a figure through the back door" \
   "$(q "SELECT reach FROM social_post_targets WHERE id=$T1;")" "$WAS"

q "DELETE FROM users WHERE email='rhythmnobody@shanfix.co.ke';"

echo ""
echo "=== 8. The report sends people to the page that fixes it ==="

REP=$(page "/social/reports?from=$(date -d '-30 days' +%Y-%m-%d 2>/dev/null || date -v-30d +%Y-%m-%d)&to=$(date +%Y-%m-%d)")
has "the warning offers a way to act on it" "$REP" "/social/catch-up"

echo ""
echo "=== 9. The sweep is wired into cron ==="

# The whole reason this phase existed: the setting was seeded and read
# by nothing, and social media was not in cron at all.
eq "cron calls it" \
   "$(grep -c 'Reminders::sweep' "$ROOT/cron.php")" "1"
ne "and the setting migration 053 seeded is read by something now" \
   "$(grep -c 'social_remind_hours' "$ROOT/app/Services/Social/Reminders.php")" "0"

report
