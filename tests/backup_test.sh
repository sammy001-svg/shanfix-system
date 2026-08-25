#!/bin/bash
# Backups: taking one, proving it restores, and the guards around it.
#
# The assertion that matters most is the restore. Everything else here is
# in service of it — a backup that cannot be loaded back is not a backup,
# it is a file that makes people feel safe.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"
ROOT="$ROOT"
DIR="$ROOT/storage/backups"
D="$(dirname "$0")"
PASS=0; FAIL=0

ok()  { printf "  \033[32mPASS\033[0m %-52s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad() { printf "  \033[31mFAIL\033[0m %-52s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()  { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }
has() { case "$2" in *"$3"*) ok "$1" "found";; *) bad "$1" "$2" "contains $3";; esac; }
ne()  { if [ "$2" != "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "anything but $3"; fi; }

q()   { $MYSQL -N -e "$1"; }
signin() {
  JAR="$D/bk_$1.txt"; rm -f "$JAR"
  local t
  t=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$t&email=$1@shanfix.co.ke&password=$2"
}
tok()  { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
page() { curl -s -b "$JAR" -c "$JAR" "$BASE$1"; }
post() { local p="$1"; shift; curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE$p" "$@"; }
count() { ls "$DIR"/*.sql.gz 2>/dev/null | wc -l | tr -d ' '; }

$MYSQL -e "UPDATE settings SET setting_value='1'  WHERE setting_key IN ('backup_enabled','backup_uploads');
           UPDATE settings SET setting_value='24' WHERE setting_key='backup_every_hours';
           UPDATE settings SET setting_value='7'  WHERE setting_key='backup_keep';
           UPDATE settings SET setting_value='3'  WHERE setting_key='backup_warn_days';
           DELETE FROM activity_log WHERE action='login_failed';
           DELETE FROM notification_locks WHERE lock_key LIKE 'backup:stale%';
           DELETE FROM staff_notifications WHERE event='backup_stale';
           DELETE FROM notifications WHERE event='backup_stale';"

echo ""
echo "=== 1. Taking one ==="
signin admin Shanfix@2026
BEFORE=$(count)
T=$(tok /settings/backups)
eq "the page opens"    "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/settings/backups")" "200"
eq "back up now"       "$(post /settings/backups --data "_token=$T")" "302"
eq "a copy appeared"   "$(( $(count) - BEFORE ))" "1"

NAME=$(ls -t "$DIR"/*.sql.gz | head -1 | while read -r p; do basename "$p" .sql.gz; done)
eq "the database file is there" "$([ -f "$DIR/$NAME.sql.gz" ] && echo yes)" "yes"
eq "the uploads file is there"  "$([ -f "$DIR/$NAME-uploads.zip" ] && echo yes)" "yes"
eq "it is real gzip"            "$(gunzip -t "$DIR/$NAME.sql.gz" 2>&1 && echo yes || echo no)" "yes"
eq "it is logged"               "$(q "SELECT COUNT(*) FROM activity_log WHERE action='backup_created';")" "1"

echo ""
echo "=== 2. It says it is complete, and it is ==="
eq "the end marker is present" "$(gunzip -c "$DIR/$NAME.sql.gz" | tail -1 | grep -c 'End of backup')" "1"
eq "check reports it usable"   "$(post "/settings/backups/$NAME/verify" --data "_token=$(tok /settings/backups)")" "302"
has "and says so"              "$(page /settings/backups)" "Readable and complete"

# A file cut short must be refused, not waved through. This is the whole
# reason the dump writes an end marker.
cp "$DIR/$NAME.sql.gz" "$D/good.gz"
gunzip -c "$DIR/$NAME.sql.gz" | head -20 | gzip > "$DIR/$NAME.sql.gz"
post "/settings/backups/$NAME/verify" --data "_token=$(tok /settings/backups)" > /dev/null
has "a truncated file is refused" "$(page /settings/backups)" "not usable"
cp "$D/good.gz" "$DIR/$NAME.sql.gz"; rm -f "$D/good.gz"

echo ""
echo "=== 3. It restores ==="
# The assertion the rest of the suite exists to support.
$MYSQL_NODB -e "DROP DATABASE IF EXISTS shanfix_bktest;
                                         CREATE DATABASE shanfix_bktest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip < "$DIR/$NAME.sql.gz" | $MYSQL_NODB shanfix_bktest 2>/dev/null
R="$MYSQL_NODB -N shanfix_bktest"

eq "same number of tables" \
   "$($R -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='shanfix_bktest' AND table_type='BASE TABLE';")" \
   "$(q  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='shanfix_test'   AND table_type='BASE TABLE';")"

for t in documents clients payments users settings jobs leads document_items; do
  eq "$t restored intact" "$($R -e "SELECT COUNT(*) FROM $t;")" "$(q "SELECT COUNT(*) FROM $t;")"
done

# Money has to come back to the cent, and text to the character.
eq "invoiced totals are exact" \
   "$($R -e "SELECT ROUND(SUM(total),2) FROM documents;")" \
   "$(q  "SELECT ROUND(SUM(total),2) FROM documents;")"
eq "settings are byte-identical" \
   "$($R -e "SELECT MD5(GROUP_CONCAT(setting_key,setting_value ORDER BY setting_key)) FROM settings;")" \
   "$(q  "SELECT MD5(GROUP_CONCAT(setting_key,setting_value ORDER BY setting_key)) FROM settings;")"

$MYSQL_NODB -e "DROP DATABASE shanfix_bktest;"

echo ""
echo "=== 4. Downloading it ==="
curl -s -b "$JAR" -o "$D/dl.gz" "$BASE/settings/backups/$NAME/download"
eq "the download is valid gzip"  "$(gunzip -t "$D/dl.gz" 2>&1 && echo yes || echo no)" "yes"
eq "and is the whole file"       "$(stat -c%s "$D/dl.gz")" "$(stat -c%s "$DIR/$NAME.sql.gz")"
curl -s -b "$JAR" -o "$D/dl.zip" "$BASE/settings/backups/$NAME/download?part=uploads"
eq "the uploads download is a valid zip" "$(unzip -t "$D/dl.zip" >/dev/null 2>&1 && echo yes || echo no)" "yes"
rm -f "$D/dl.gz" "$D/dl.zip"

echo ""
echo "=== 5. The name reaches the filesystem, so it must not escape ==="
# Anything that resolves outside the backup directory would hand out
# config/config.php, which holds the database password.
for bad in "..%2F..%2Fconfig%2Fconfig" "shanfix%00" "%2Fetc%2Fpasswd"; do
  ne "refused: $bad" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/settings/backups/$bad/download")" "200"
done

echo ""
echo "=== 6. Only an administrator ==="
HASH=$(php -r 'echo password_hash("Bk@2026", PASSWORD_DEFAULT);')
$MYSQL -e "DELETE FROM users WHERE email='bkmgr@shanfix.co.ke';
           INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('Backup Manager','bkmgr@shanfix.co.ke','$HASH','manager',1);
           INSERT INTO user_roles (user_id,role) SELECT id,'manager' FROM users WHERE email='bkmgr@shanfix.co.ke';"
signin bkmgr Bk@2026
ne "a manager cannot see the page"  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/settings/backups")" "200"
ne "nor download one"               "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/settings/backups/$NAME/download")" "200"
BEFORE=$(count)
post /settings/backups --data "_token=$(tok /dashboard)" > /dev/null
eq "nor take one" "$(count)" "$BEFORE"
$MYSQL -e "DELETE FROM users WHERE email='bkmgr@shanfix.co.ke'; DELETE FROM activity_log WHERE action='login_failed';"

echo ""
echo "=== 7. Old copies are dropped, but never the last one ==="
signin admin Shanfix@2026
$MYSQL -e "UPDATE settings SET setting_value='2' WHERE setting_key='backup_keep';"
php -r '
require getenv("SHANFIX_ROOT") . "/app/bootstrap.php";
App\Core\Config::load(CONFIG_PATH."/config.php");
App\Core\Database::connect(App\Core\Config::get("db"));
for ($i = 0; $i < 3; $i++) { App\Services\Backup::run(false); sleep(1); }
' > /dev/null 2>&1
eq "rotation holds the limit" "$(count)" "2"

# Deleting down to nothing would leave the business with no copy at all,
# which is never what somebody tidying a list intends.
OLDEST=$(ls -t "$DIR"/*.sql.gz | tail -1 | while read -r p; do basename "$p" .sql.gz; done)
post "/settings/backups/$OLDEST/delete" --data "_token=$(tok /settings/backups)" > /dev/null
eq "one can be deleted" "$(count)" "1"
LAST=$(ls -t "$DIR"/*.sql.gz | head -1 | while read -r p; do basename "$p" .sql.gz; done)
post "/settings/backups/$LAST/delete" --data "_token=$(tok /settings/backups)" > /dev/null
eq "the last one cannot"        "$(count)" "1"
has "and it says why"           "$(page /settings/backups)" "only backup there is"
$MYSQL -e "UPDATE settings SET setting_value='7' WHERE setting_key='backup_keep';"

echo ""
echo "=== 8. Nobody is told twice about the same silence ==="
# Cron runs every few minutes on most hosts. A warning that repeats every
# few minutes is a warning nobody reads.
$MYSQL -e "UPDATE settings SET setting_value='720' WHERE setting_key='backup_every_hours';
           DELETE FROM staff_notifications WHERE event='backup_stale';
           DELETE FROM notifications WHERE event='backup_stale';
           DELETE FROM notification_locks WHERE lock_key LIKE 'backup:stale%';"
touch -d "10 days ago" "$DIR"/*.sql.gz "$DIR"/*.zip 2>/dev/null

# State the precondition rather than assuming it. Anything that took a
# backup after the backdating above — another suite, or a cron run by hand
# while debugging — leaves a fresh copy sitting here, and then "not stale"
# is the correct answer and the two assertions below fail for a reason
# that has nothing to do with the code. Better to say which it is.
NEWEST=$(ls -t "$DIR"/*.sql.gz 2>/dev/null | head -1)
AGE_DAYS=$([ -n "$NEWEST" ] && echo $(( ( $(date +%s) - $(stat -c %Y "$NEWEST") ) / 86400 )) || echo -1)
eq "precondition: the newest backup is old enough to warn about" \
   "$([ "$AGE_DAYS" -gt 3 ] && echo yes || echo "no, it is ${AGE_DAYS}d old")" "yes"

for i in 1 2 3; do php "$ROOT/cron.php" > /dev/null 2>&1; done
eq "the administrators are warned"  "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='backup_stale';")" "1"
eq "once, not once per cron run"    "$(q "SELECT COUNT(*) FROM notifications WHERE event='backup_stale';")" "1"

# And when it is working again, silence.
$MYSQL -e "UPDATE settings SET setting_value='24' WHERE setting_key='backup_every_hours';
           DELETE FROM staff_notifications WHERE event='backup_stale';
           DELETE FROM notifications WHERE event='backup_stale';
           DELETE FROM notification_locks WHERE lock_key LIKE 'backup:stale%';"
php "$ROOT/cron.php" > /dev/null 2>&1
eq "a fresh backup raises nothing" "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='backup_stale';")" "0"

echo ""
echo "=== 9. Tidy up ==="
$MYSQL -e "DELETE FROM activity_log WHERE action LIKE 'backup_%';
           DELETE FROM notification_locks WHERE lock_key LIKE 'backup:stale%';
           DELETE FROM staff_notifications WHERE event='backup_stale';
           DELETE FROM notifications WHERE event='backup_stale';"
eq "at least one backup survives the suite" "$([ "$(count)" -ge 1 ] && echo yes)" "yes"
rm -f "$D"/bk_*.txt

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
[ "$FAIL" -eq 0 ]
