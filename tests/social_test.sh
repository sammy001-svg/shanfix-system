#!/bin/bash
# Running the company's own social media.
#
# Somebody here posts for Shanfix every week and has been doing it out
# of their own head. This is the plan, the approval and the record —
# what is going out, whether anybody said yes to it, and how it did.
#
# Two properties everything else rests on.
#
# Approval means somebody else. An approval you can give your own work
# is not an approval, and the whole point of the step is that what goes
# out under the company's name has been read by a second person.
#
# A post nobody has checked on is not a post that reached nobody. Every
# figure here is typed in by hand days after the fact, so the reports
# have to be able to tell a zero from a blank — otherwise the month
# somebody forgot to record looks like the month that failed, and the
# office draws a lesson from it.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

WJ="$D/social_writer.txt"

as_writer() {
  local method="$1" path="$2"; shift 2
  if [ "$method" = GET ]; then
    curl -s -o /dev/null -w '%{http_code}' -b "$WJ" "$BASE$path"
  else
    curl -s -o /dev/null -w '%{http_code}' -b "$WJ" -c "$WJ" -X POST "$BASE$path" "$@"
  fi
}

writer_token() {
  curl -s -b "$WJ" -c "$WJ" "$BASE$1" \
    | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

newest_post() { q "SELECT id FROM social_posts ORDER BY id DESC LIMIT 1;"; }
status_of()   { q "SELECT status FROM social_posts WHERE id=$1;"; }

# ---------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------
BEFORE=$(q "SELECT setting_value FROM settings WHERE setting_key='social_approval_required';")

restore() {
  q "UPDATE settings SET setting_value='${BEFORE:-1}' WHERE setting_key='social_approval_required';"
  q "DELETE FROM social_posts WHERE title LIKE 'TEST %';"
  q "DELETE FROM social_accounts WHERE name LIKE 'TEST %';"
  q "DELETE FROM social_campaigns WHERE name LIKE 'TEST %';"
  q "DELETE FROM staff_notifications WHERE entity_type='social_post';"
  q "DELETE FROM users WHERE email='socialwriter@shanfix.co.ke';"
}
trap restore EXIT

restore
q "UPDATE settings SET setting_value='1' WHERE setting_key='social_approval_required';"

signin_admin

# Somebody who writes posts but cannot approve them. The whole of
# section 4 turns on this person existing.
HASH=$($PHP -r 'echo password_hash("SocialPass1", PASSWORD_DEFAULT);')
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Social Writer','socialwriter@shanfix.co.ke','$HASH','designer',1);"
WRITER=$(q "SELECT id FROM users WHERE email='socialwriter@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($WRITER,'designer');"
rm -f "$WJ"
JAR="$WJ" login_as "socialwriter@shanfix.co.ke" "SocialPass1" >/dev/null 2>&1

echo ""
echo "=== 1. The profiles we post from ==="

T=$(tok /social/accounts)
eq "a page can be added" \
   "$(post /social/accounts --data-urlencode "_token=$T" \
        --data-urlencode "name=TEST Facebook page" --data-urlencode "network=facebook" \
        --data-urlencode "handle=@testshanfix")" "302"

FB=$(q "SELECT id FROM social_accounts WHERE name='TEST Facebook page';")
ne "it was written down" "$FB" ""

T=$(tok /social/accounts)
post /social/accounts --data-urlencode "_token=$T" \
     --data-urlencode "name=TEST Instagram" --data-urlencode "network=instagram" >/dev/null
IG=$(q "SELECT id FROM social_accounts WHERE name='TEST Instagram';")

# A network the system does not know would give the reports a row they
# cannot label and the website an icon it does not have.
T=$(tok /social/accounts)
post /social/accounts --data-urlencode "_token=$T" \
     --data-urlencode "name=TEST Nonsense" --data-urlencode "network=myspace" >/dev/null
eq "a network we do not know is refused" \
   "$(q "SELECT COUNT(*) FROM social_accounts WHERE name='TEST Nonsense';")" "0"

# A follower count with no date on it is worse than none, because
# nobody can tell whether it is from this week or from March.
T=$(tok /social/accounts)
post /social/accounts --data-urlencode "_token=$T" --data-urlencode "id=$FB" \
     --data-urlencode "name=TEST Facebook page" --data-urlencode "network=facebook" \
     --data-urlencode "followers=4820" >/dev/null
eq "a follower count is kept"        "$(q "SELECT followers FROM social_accounts WHERE id=$FB;")" "4820"
eq "and stamped with when it was taken" \
   "$(q "SELECT COUNT(*) FROM social_accounts WHERE id=$FB AND followers_at IS NOT NULL;")" "1"

echo ""
echo "=== 2. Writing a post ==="

T=$(tok /social/new)
eq "a post can be started" \
   "$(post /social --data-urlencode "_token=$T" \
        --data-urlencode "title=TEST Graduation banners" \
        --data-urlencode "caption=Graduation season is here." \
        --data-urlencode "hashtags=graduation, #nakuru  printing" \
        --data-urlencode "link_url=shanfixtechnology.com/banners" \
        --data-urlencode "scheduled_for=$(date +%Y-%m-%d)T09:00" \
        --data-urlencode "owner_id=$WRITER" \
        --data-urlencode "accounts[]=$FB" --data-urlencode "accounts[]=$IG")" "302"

PID=$(newest_post)
eq "it starts as a draft"      "$(status_of "$PID")" "draft"
ne "and gets a reference"      "$(q "SELECT ref FROM social_posts WHERE id=$PID;")" ""

# One caption, several places. Writing it per platform is how three
# versions of the same words come to exist.
eq "it is going to both profiles" \
   "$(q "SELECT COUNT(*) FROM social_post_targets WHERE post_id=$PID;")" "2"

# Hashtags typed however somebody types them, kept as one tidy line.
eq "hashtags are tidied up" \
   "$(q "SELECT hashtags FROM social_posts WHERE id=$PID;")" "#graduation #nakuru #printing"

# A link that does not open is worse than no link.
eq "a link with no scheme is still a link" \
   "$(q "SELECT link_url FROM social_posts WHERE id=$PID;")" "https://shanfixtechnology.com/banners"

T=$(tok /social/new)
post /social --data-urlencode "_token=$T" --data-urlencode "title=" \
     --data-urlencode "caption=Nameless" >/dev/null
eq "a post with nothing to call it is refused" \
   "$(q "SELECT COUNT(*) FROM social_posts WHERE title='';")" "0"

echo ""
echo "=== 3. It appears where people look for it ==="

CAL=$(page "/social?month=$(date +%Y-%m)")
has "on the calendar"          "$CAL" "TEST Graduation banners"
has "in the list"              "$(page /social/list)" "TEST Graduation banners"
has "and the one it is on says where it is going" \
   "$(page "/social/$PID")" "TEST Facebook page"

# Anything without a date waits beside the calendar rather than being
# guessed onto today.
T=$(tok /social/new)
post /social --data-urlencode "_token=$T" --data-urlencode "title=TEST An idea with no date" >/dev/null
IDEA=$(newest_post)
eq "a post with no date has none" \
   "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$IDEA AND scheduled_for IS NULL;")" "1"
has "and waits beside the calendar" "$(page /social)" "TEST An idea with no date"

echo ""
echo "=== 4. Approval means somebody else ==="

# The writer puts their own post up. That much is theirs to do.
T=$(writer_token "/social/$PID")
eq "the writer can put it up for approval" \
   "$(as_writer POST "/social/$PID/move" --data-urlencode "_token=$T" --data-urlencode "to=awaiting")" "302"
eq "and it is waiting"  "$(status_of "$PID")" "awaiting"

# Whoever can approve is told, because otherwise it waits until
# somebody happens to look.
eq "the people who can approve are told" \
   "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='social_awaiting' AND entity_id=$PID;")" "1"

# And cannot approve it themselves.
T=$(writer_token "/social/$PID")
as_writer POST "/social/$PID/move" --data-urlencode "_token=$T" --data-urlencode "to=approved" >/dev/null
eq "the writer cannot approve their own work" "$(status_of "$PID")" "awaiting"

# Sent back, with a reason — "not approved" on its own tells the writer
# nothing to act on.
T=$(tok "/social/$PID")
post "/social/$PID/move" --data-urlencode "_token=$T" --data-urlencode "to=draft" \
     --data-urlencode "note=The price is last year's" >/dev/null
eq "it can be sent back"        "$(status_of "$PID")" "draft"
eq "with the reason attached"   "$(q "SELECT changes_asked FROM social_posts WHERE id=$PID;")" "The price is last year's"
eq "and the writer is told"     "$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='social_changes' AND user_id=$WRITER;")" "1"

# Round again, and approved by somebody who is not the writer.
T=$(writer_token "/social/$PID")
as_writer POST "/social/$PID/move" --data-urlencode "_token=$T" --data-urlencode "to=awaiting" >/dev/null
T=$(tok "/social/$PID")
post "/social/$PID/move" --data-urlencode "_token=$T" --data-urlencode "to=approved" >/dev/null
eq "somebody else can approve it"  "$(status_of "$PID")" "approved"
eq "and is recorded as having"     "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$PID AND approved_by IS NOT NULL;")" "1"
eq "the reason it was sent back is cleared" \
   "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$PID AND changes_asked IS NULL;")" "1"

echo ""
echo "=== 5. A post cannot skip the queue ==="

T=$(tok /social/new)
post /social --data-urlencode "_token=$T" --data-urlencode "title=TEST Straight to the world" >/dev/null
JUMP=$(newest_post)
q "UPDATE social_posts SET status='idea' WHERE id=$JUMP;"

T=$(tok "/social/$JUMP")
post "/social/$JUMP/move" --data-urlencode "_token=$T" --data-urlencode "to=published" >/dev/null
eq "an idea cannot go straight out" "$(status_of "$JUMP")" "idea"

T=$(tok "/social/$JUMP")
post "/social/$JUMP/move" --data-urlencode "_token=$T" --data-urlencode "to=approved" >/dev/null
eq "nor straight to approved"       "$(status_of "$JUMP")" "idea"

echo ""
echo "=== 6. What went out stays as it went out ==="

T=$(tok "/social/$PID")
post "/social/$PID/move" --data-urlencode "_token=$T" --data-urlencode "to=published" >/dev/null
eq "an approved post can be marked as out" "$(status_of "$PID")" "published"
eq "and is stamped with when"              "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$PID AND published_at IS NOT NULL;")" "1"

# The caption is the record of what the public saw. Editing it would
# make the record disagree with the thing itself.
eq "editing a published post is refused"   "$(code "/social/$PID/edit")" "302"

T=$(tok /social/list)
post "/social/$PID" --data-urlencode "_token=$T" --data-urlencode "title=TEST Rewritten after the fact" >/dev/null
eq "and so is saving over it" \
   "$(q "SELECT title FROM social_posts WHERE id=$PID;")" "TEST Graduation banners"

T=$(tok /social/list)
post "/social/$PID/delete" --data-urlencode "_token=$T" >/dev/null
eq "deleting it is refused too" "$(q "SELECT COUNT(*) FROM social_posts WHERE id=$PID;")" "1"

# Published is where a post stops.
T=$(tok "/social/$PID")
post "/social/$PID/move" --data-urlencode "_token=$T" --data-urlencode "to=draft" >/dev/null
eq "it cannot be walked back to a draft" "$(status_of "$PID")" "published"

echo ""
echo "=== 7. Where it landed, and how it did ==="

T1=$(q "SELECT id FROM social_post_targets WHERE post_id=$PID AND account_id=$FB;")
T2=$(q "SELECT id FROM social_post_targets WHERE post_id=$PID AND account_id=$IG;")

eq "nothing is recorded to begin with" \
   "$(q "SELECT COUNT(*) FROM social_post_targets WHERE post_id=$PID AND metrics_at IS NOT NULL;")" "0"

T=$(tok "/social/$PID")
eq "the numbers can be written down" \
   "$(post "/social/$PID/record" --data-urlencode "_token=$T" \
        --data-urlencode "t[$T1][post_url]=facebook.com/shanfix/posts/9" \
        --data-urlencode "t[$T1][reach]=1840" --data-urlencode "t[$T1][likes]=96" \
        --data-urlencode "t[$T1][comments]=14" --data-urlencode "t[$T1][shares]=22")" "302"

eq "the reach is kept"    "$(q "SELECT reach FROM social_post_targets WHERE id=$T1;")" "1840"
eq "and the link"         "$(q "SELECT post_url FROM social_post_targets WHERE id=$T1;")" "https://facebook.com/shanfix/posts/9"
eq "stamped with when somebody looked" \
   "$(q "SELECT COUNT(*) FROM social_post_targets WHERE id=$T1 AND metrics_at IS NOT NULL;")" "1"

# The property the reports depend on: the other profile has no numbers,
# and that is different from having zeroes.
eq "the profile nobody checked is still unrecorded" \
   "$(q "SELECT COUNT(*) FROM social_post_targets WHERE id=$T2 AND metrics_at IS NULL;")" "1"

# Figures belonging to another post must not be writable through this
# one's form.
OTHER=$(q "SELECT t.id FROM social_post_targets t WHERE t.post_id <> $PID LIMIT 1;")
if [ -n "$OTHER" ]; then
  WAS=$(q "SELECT reach FROM social_post_targets WHERE id=$OTHER;")
  T=$(tok "/social/$PID")
  post "/social/$PID/record" --data-urlencode "_token=$T" \
       --data-urlencode "t[$OTHER][reach]=999999" >/dev/null
  eq "another post's numbers cannot be written through this one" \
     "$(q "SELECT reach FROM social_post_targets WHERE id=$OTHER;")" "$WAS"
fi

echo ""
echo "=== 8. The report counts what was checked, not what was missed ==="

FROM=$(date +%Y-%m-01)
TO=$(date +%Y-%m-%d)
REP=$(page "/social/reports?from=$FROM&to=$TO")

has "it opens"                      "$REP" "Social media report"
has "and says what was reached"     "$REP" "1,840"

# The warning that makes the rest of it trustworthy.
has "it says how much has not been checked" "$REP" "no numbers written down yet"
has "and splits the figures by network"     "$REP" "Network by network"

# A blank is not a nought. Nothing was recorded for Instagram, so it
# must not appear as a network that reached nobody.
eq "a profile with no numbers is not counted as a failure" \
   "$(q "SELECT COALESCE(SUM(reach),0) FROM social_post_targets WHERE metrics_at IS NULL;")" "0"

echo ""
echo "=== 9. Reading is one thing, changing is another ==="

# The writer holds social.manage through the designer role, so a
# permission that opens everything is tested against somebody who has
# none of it at all.
NJ="$D/social_nobody.txt"
rm -f "$NJ"
q "DELETE FROM users WHERE email='socialnobody@shanfix.co.ke';"
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Social Nobody','socialnobody@shanfix.co.ke','$HASH','production',1);"
NOBODY=$(q "SELECT id FROM users WHERE email='socialnobody@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($NOBODY,'production');"
JAR="$NJ" login_as "socialnobody@shanfix.co.ke" "SocialPass1" >/dev/null 2>&1

eq "somebody with no reason to be here cannot open it" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$NJ" "$BASE/social")" "403"
eq "nor write a post" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$NJ" -X POST "$BASE/social" \
        --data-urlencode "title=TEST Sneaky")" "403"
eq "and nothing was written" "$(q "SELECT COUNT(*) FROM social_posts WHERE title='TEST Sneaky';")" "0"

# The writer can write but not set up profiles: adding a page is a
# decision about where the company speaks from.
eq "a writer cannot add a profile" \
   "$(as_writer GET /social/accounts)" "403"

q "DELETE FROM users WHERE email='socialnobody@shanfix.co.ke';"

echo ""
echo "=== 10. With approval switched off ==="

q "UPDATE settings SET setting_value='0' WHERE setting_key='social_approval_required';"

T=$(tok /social/new)
post /social --data-urlencode "_token=$T" --data-urlencode "title=TEST No approval needed" \
     --data-urlencode "accounts[]=$FB" >/dev/null
QUICK=$(newest_post)

# The office has said it trusts whoever is posting. Somebody may then
# approve their own work — which is the setting doing what it says,
# not a hole in it.
T=$(tok "/social/$QUICK")
post "/social/$QUICK/move" --data-urlencode "_token=$T" --data-urlencode "to=approved" >/dev/null
eq "a post can be approved by its own writer" "$(status_of "$QUICK")" "approved"

q "UPDATE settings SET setting_value='1' WHERE setting_key='social_approval_required';"

echo ""
echo "=== 11. It is in the menu, under Marketing ==="

NAV=$(page /dashboard)
has "the sidebar offers it" "$NAV" "Social media"
has "in the marketing group" "$NAV" '/social'

report
