#!/bin/bash
# Bringing the old Bulk SMS platform across.
#
# The old platform (sms.shanfixtechnology.com, database bulk_sms_system)
# has live customers on it. The cutover happens once, at night, against
# data nobody can put back — so everything about it is rehearsed here
# first, against a stand-in built from the old platform's own schema.sql
# rather than from a guess at what its columns are called.
#
# What this is really checking is not that rows move. It is that a run
# which stops half way can be finished by running it again, because that
# is what actually happens on the night: something times out, somebody
# presses Ctrl-C, the connection drops. A migration that can only be run
# once is a migration that has to work first time.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

LEGACY=shanfix_legacy_test

fixture() { $PHP "$ROOT/tests/helpers/legacy_sms_fixture.php" "$LEGACY" "$@"; }
import()  { $PHP "$ROOT/import-bulk-sms.php" --from="$LEGACY" "$@" 2>&1; }

# Everything the migration creates on this side, taken back out.
wipe_here() {
  q "SET FOREIGN_KEY_CHECKS=0;
     DELETE FROM bulk_messages WHERE legacy_id IS NOT NULL;
     DELETE FROM bulk_campaigns WHERE legacy_id IS NOT NULL;
     DELETE FROM bulk_contacts WHERE legacy_id IS NOT NULL;
     DELETE FROM bulk_contact_groups WHERE legacy_id IS NOT NULL;
     DELETE FROM bulk_sender_ids WHERE legacy_id IS NOT NULL;
     DELETE FROM bulk_ledger WHERE kind='migration';
     DELETE FROM bulk_accounts WHERE legacy_user_id IS NOT NULL;
     DELETE FROM client_users WHERE email LIKE '%@legacy.test';
     DELETE FROM clients WHERE email LIKE '%@legacy.test';
     DELETE FROM partners WHERE email LIKE '%@legacy.test';
     SET FOREIGN_KEY_CHECKS=1;"
}

restore() {
  wipe_here
  fixture --drop >/dev/null 2>&1
}
trap restore EXIT

# Counts on this side, by what they came from.
here() { q "SELECT COUNT(*) FROM $1 WHERE legacy_id IS NOT NULL;"; }

wipe_here
fixture >/dev/null || { echo "could not build the stand-in"; exit 1; }

echo ""
echo "=== 1. The stand-in is the old platform, not an idea of it ==="

# Built by loading the old platform's own schema.sql. If that file moves
# or changes shape, this fails here rather than on the night.
eq "the old schema loaded"       "$(q "SELECT COUNT(*) FROM information_schema.tables
                                        WHERE table_schema='$LEGACY';" | head -1)" \
   "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LEGACY';")"
eq "with its users table"        "$(q "SELECT COUNT(*) FROM $LEGACY.users;")" "6"
eq "its contacts"                "$(q "SELECT COUNT(*) FROM $LEGACY.contacts;")" "6"
eq "its sender IDs"              "$(q "SELECT COUNT(*) FROM $LEGACY.sender_ids;")" "4"
eq "and its history"             "$(q "SELECT COUNT(*) FROM $LEGACY.messages;")" "6"

echo ""
echo "=== 2. A dry run writes nothing ==="

OUT=$(import)
has "it says so plainly"         "$OUT" "Nothing will be written"
has "and counts what it found"   "$OUT" "Found to carry across"

eq "no account was made"         "$(q "SELECT COUNT(*) FROM bulk_accounts WHERE legacy_user_id IS NOT NULL;")" "0"
eq "no contact was made"         "$(here bulk_contacts)" "0"
eq "no message was made"         "$(here bulk_messages)" "0"

# The count has to cover everybody, not only the house. It used to report
# one of each, because a dry run creates no accounts and the pass that
# counts belongings had nothing to attach them to.
has "it counts every customer's contacts, not just ours" "$OUT" "contacts     6"
has "and every sender ID"                                "$OUT" "sender IDs   4"

echo ""
echo "=== 3. One run brings everything ==="

OUT=$(import --commit)

eq "groups"      "$(here bulk_contact_groups)" "4"
eq "contacts"    "$(here bulk_contacts)"       "6"
eq "sender IDs"  "$(here bulk_sender_ids)"     "4"
eq "campaigns"   "$(here bulk_campaigns)"      "5"
eq "messages"    "$(here bulk_messages)"       "6"

echo ""
echo "=== 4. Everything lands under the person who owns it ==="

# The whole point. A contact under the wrong account is a customer
# reading somebody else's marketing list.
eq "the dental practice's contacts are theirs" \
   "$(q "SELECT COUNT(*) FROM bulk_contacts c JOIN bulk_accounts a ON a.id=c.account_id
          WHERE a.owner_type='client'
            AND a.owner_id=(SELECT id FROM clients WHERE email='dental@legacy.test');")" "4"

eq "the reseller's contact is the reseller's" \
   "$(q "SELECT COUNT(*) FROM bulk_contacts c JOIN bulk_accounts a ON a.id=c.account_id
          WHERE a.owner_type='partner';")" "1"

eq "and ours is the house's" \
   "$(q "SELECT COUNT(*) FROM bulk_contacts c JOIN bulk_accounts a ON a.id=c.account_id
          WHERE a.owner_type='house';")" "1"

eq "a contact keeps the group it was in" \
   "$(q "SELECT g.name FROM bulk_contacts c JOIN bulk_contact_groups g ON g.id=c.group_id
          WHERE c.legacy_id=3;")" "Reminders"

# A group deleted on the old platform leaves its contacts pointing at
# nothing. They are still the customer's contacts.
eq "one whose group is gone still arrives, ungrouped" \
   "$(q "SELECT COUNT(*) FROM bulk_contacts WHERE legacy_id=4 AND group_id IS NULL;")" "1"

eq "the extra CSV columns survive" \
   "$(q "SELECT COUNT(*) FROM bulk_contacts WHERE legacy_id=1 AND metadata LIKE '%2400%';")" "1"

echo ""
echo "=== 5. A working sender ID stays working ==="

# Registered with the networks under the customer's own name, and days
# to approve. Arriving here as 'pending' would take a working sender
# away from somebody who already has one.
eq "an approved sender is still approved" \
   "$(q "SELECT status FROM bulk_sender_ids WHERE legacy_id=1;")" "approved"
eq "when it was decided comes with it" \
   "$(q "SELECT DATE(decided_at) FROM bulk_sender_ids WHERE legacy_id=1;")" "2025-03-04"

# decided_by points at a member of staff here. The old approver is an id
# in a different table, and writing it would name whichever colleague
# happens to hold that number.
eq "but not who decided, which would be somebody else here" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE legacy_id IS NOT NULL AND decided_by IS NOT NULL;")" "0"

eq "one still waiting is still waiting" \
   "$(q "SELECT status FROM bulk_sender_ids WHERE legacy_id=2;")" "pending"
eq "a refusal keeps its reason" \
   "$(q "SELECT reject_reason FROM bulk_sender_ids WHERE legacy_id=3;")" "Too close to a bank"
eq "and they are all marked as the customer's own" \
   "$(q "SELECT COUNT(*) FROM bulk_sender_ids WHERE legacy_id IS NOT NULL AND source='customer';")" "4"

echo ""
echo "=== 6. A campaign caught mid-flight does not look live ==="

# Nothing here is going to pick up and finish what the old platform was
# sending. Leaving it 'running' would have somebody waiting for messages
# that will never go.
eq "it arrives failed, not running" \
   "$(q "SELECT status FROM bulk_campaigns WHERE legacy_id=2;")" "failed"
has "and says why" \
   "$(q "SELECT failure_reason FROM bulk_campaigns WHERE legacy_id=2;")" "old platform was retired"

eq "a finished campaign is still finished"  "$(q "SELECT status FROM bulk_campaigns WHERE legacy_id=1;")" "completed"
eq "a draft is still a draft"               "$(q "SELECT status FROM bulk_campaigns WHERE legacy_id=3;")" "draft"
eq "and its counts are unchanged"           "$(q "SELECT sent_count FROM bulk_campaigns WHERE legacy_id=1;")" "3"

# file_path names a file in the old platform's uploads directory, which
# is not here. A path to nothing is worse than no path.
eq "no campaign points at a file that is not here" \
   "$(q "SELECT COUNT(*) FROM bulk_campaigns WHERE legacy_id IS NOT NULL AND file_path IS NOT NULL;")" "0"

echo ""
echo "=== 7. History keeps its shape ==="

eq "a message finds its campaign again" \
   "$(q "SELECT c.legacy_id FROM bulk_messages m JOIN bulk_campaigns c ON c.id=m.campaign_id
          WHERE m.legacy_id=1;")" "1"
eq "one sent on its own has no campaign, and that is right" \
   "$(q "SELECT COUNT(*) FROM bulk_messages WHERE legacy_id=6 AND campaign_id IS NULL;")" "1"
eq "a delivery report survives"  "$(q "SELECT dlr_status FROM bulk_messages WHERE legacy_id=3;")" "AbsentSubscriber"
eq "so does why it failed"       "$(q "SELECT failed_reason FROM bulk_messages WHERE legacy_id=3;")" "Absent subscriber"
eq "and when it was sent"        "$(q "SELECT DATE(sent_at) FROM bulk_messages WHERE legacy_id=1;")" "2025-03-10"

echo ""
echo "=== 8. Running it again changes nothing ==="

# The property the night depends on.
import --commit >/dev/null
import --commit >/dev/null

eq "groups, still"      "$(here bulk_contact_groups)" "4"
eq "contacts, still"    "$(here bulk_contacts)"       "6"
eq "sender IDs, still"  "$(here bulk_sender_ids)"     "4"
eq "campaigns, still"   "$(here bulk_campaigns)"      "5"
eq "messages, still"    "$(here bulk_messages)"       "6"
eq "and no second set of accounts" \
   "$(q "SELECT COUNT(*) FROM bulk_accounts WHERE legacy_user_id IS NOT NULL;")" "5"

echo ""
echo "=== 9. A run that dies half way can be finished ==="

# What being interrupted actually looks like: the accounts exist, some
# of the belongings do not. The second run has to pick up the rest — and
# this is the case that was silently broken, because only resellers were
# being remembered, so every ordinary customer's contacts and history
# were left behind while the run reported success.
q "DELETE FROM bulk_messages WHERE legacy_id > 2;"
q "DELETE FROM bulk_contacts WHERE legacy_id > 2;"

eq "half the contacts are gone"  "$(here bulk_contacts)" "2"
eq "half the history too"        "$(here bulk_messages)" "2"

import --commit >/dev/null

eq "a second run brings the rest of the contacts" "$(here bulk_contacts)" "6"
eq "and the rest of the history"                  "$(here bulk_messages)" "6"

echo ""
echo "=== 10. Choosing how much history to bring ==="

wipe_here
import --commit --skip-history >/dev/null

eq "with --skip-history the contacts still come" "$(here bulk_contacts)" "6"
eq "and the sender IDs"                          "$(here bulk_sender_ids)" "4"
eq "but no messages at all"                      "$(here bulk_messages)" "0"

import --commit --since=2025-06-01 >/dev/null
eq "--since brings only what was sent after it"  "$(here bulk_messages)" "1"
eq "and it is the right one" \
   "$(q "SELECT legacy_id FROM bulk_messages WHERE legacy_id IS NOT NULL;")" "6"

# Then the rest, on a later pass. This is how a big platform is moved:
# the recent history first so people can work, the archive overnight.
import --commit >/dev/null
eq "a later run without it brings the archive too" "$(here bulk_messages)" "6"

BAD=$(import --since=not-a-date 2>&1)
has "a date that is not one is refused" "$BAD" "wants a date like"

echo ""
echo "=== 11. It will not run against the wrong database ==="

# It creates clients and partners. Pointed at something that is not the
# old platform, it has to stop rather than guess.
WRONG=$($PHP "$ROOT/import-bulk-sms.php" --from="$DB" 2>&1)
has "a database with no old users table is refused" "$WRONG" "does not look like the old platform"

# And the stand-in builder will only ever touch a test database, because
# it drops and recreates whole ones.
LIVE=$($PHP "$ROOT/tests/helpers/legacy_sms_fixture.php" bulk_sms_system 2>&1)
has "the fixture refuses a database that is not for testing" "$LIVE" "does not look like a test database"

echo ""
echo "=== 12. The web cannot reach any of it ==="

eq "the importer refuses to run over HTTP" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/import-bulk-sms.php")" "404"
eq "nor can the fixture builder" \
   "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/tests/helpers/legacy_sms_fixture.php")" "404"

report
