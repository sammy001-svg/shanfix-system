#!/bin/bash
# Paying partners: the run, the file, and hearing back.
#
# The rule the whole feature exists to enforce is asserted here more than
# once, from different directions: commission becomes 'paid' when money
# actually lands, not when somebody presses a button. A run that has been
# built, approved and even handed to the bank has still paid nobody.
#
# The other half is who may do it. Sales look after partners and answer
# for them, and must be able to see whether somebody has been paid — but
# moving money, and choosing which account it moves to, is finance.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

PERIOD="2019-05"
NEXT="2019-06"

scrub() {
  $MYSQL -e "
    UPDATE commissions SET payout_line_id = NULL
      WHERE payout_line_id IN (SELECT id FROM payout_lines WHERE run_id IN
        (SELECT id FROM payout_runs WHERE period IN ('$PERIOD','$NEXT')));
    DELETE FROM payout_lines WHERE run_id IN
      (SELECT id FROM payout_runs WHERE period IN ('$PERIOD','$NEXT'));
    DELETE FROM payout_runs WHERE period IN ('$PERIOD','$NEXT');
    DELETE FROM commissions WHERE notes = 'POTEST';
    DELETE FROM document_items WHERE document_id IN (SELECT id FROM documents WHERE doc_number LIKE 'POTEST-%');
    DELETE FROM payments WHERE document_id IN (SELECT id FROM documents WHERE doc_number LIKE 'POTEST-%');
    DELETE FROM documents WHERE doc_number LIKE 'POTEST-%';
    UPDATE clients SET partner_id = NULL WHERE client_code LIKE 'POTESTC%';
    DELETE FROM clients WHERE client_code LIKE 'POTESTC%';
    DELETE FROM partners WHERE email LIKE 'potest%@example.co.ke';
    DELETE FROM users WHERE email IN ('pofin@shanfix.co.ke','posales@shanfix.co.ke','pomgr@shanfix.co.ke');"
}

# A partner owed something in $PERIOD. The pay columns are passed in so
# each case — M-Pesa, bank, nothing on file — can be set up in one line.
seed() {
  local code="$1" cols="$2" vals="$3" amount="$4" period="${5:-$PERIOD}"

  $MYSQL -e "
    INSERT INTO partners (partner_code, name, email, phone, default_rate, status $cols)
      VALUES ('$code', 'Payout $code', '$(echo "$code" | tr 'A-Z' 'a-z')@example.co.ke',
              '0722000111', 10.00, 'active' $vals);
    INSERT INTO clients (client_code, name, status, partner_id)
      VALUES ('POTESTC$code', 'Payout client $code', 'active',
              (SELECT id FROM partners WHERE partner_code = '$code'));
    INSERT INTO documents (doc_type, doc_number, client_id, issue_date, due_date, status,
                           approval_status, currency, subtotal, total, amount_paid, balance)
      VALUES ('invoice', 'POTEST-$code-$period', (SELECT id FROM clients WHERE client_code = 'POTESTC$code'),
              '$period-10', '$period-20', 'paid', 'approved', 'KES', 100000, 100000, 100000, 0);
    INSERT INTO commissions (partner_id, client_id, document_id, base_amount, rate, amount,
                             period, status, notes)
      VALUES ((SELECT id FROM partners WHERE partner_code = '$code'),
              (SELECT id FROM clients WHERE client_code = 'POTESTC$code'),
              (SELECT id FROM documents WHERE doc_number = 'POTEST-$code-$period'),
              100000, 10, $amount, '$period', 'earned', 'POTEST');"

  q "SELECT id FROM partners WHERE partner_code = '$code';"
}

pid_of()  { q "SELECT id FROM partners WHERE partner_code = '$1';"; }
line_of() { q "SELECT id FROM payout_lines WHERE run_id = $1 AND partner_id = $2;"; }
lstat()   { q "SELECT status FROM payout_lines WHERE id = $1;"; }
cstat()   { q "SELECT status FROM commissions WHERE partner_id = $1 AND notes = 'POTEST' LIMIT 1;"; }
attached() {
  q "SELECT IF(payout_line_id IS NULL, 'free', 'committed') FROM commissions
      WHERE partner_id = $1 AND notes = 'POTEST' LIMIT 1;"
}

scrub

MP=$(seed POTESTMP ", pay_method, pay_phone"        ", 'mpesa', '0712345678'"   5000)
BK=$(seed POTESTBK ", pay_method, bank_account_no, bank_name, bank_branch, bank_account_name" \
                    ", 'bank', '0123456789', 'Equity', 'Kisumu', 'Payout BK Ltd'" 7000)
NO=$(seed POTESTNO "" "" 3000)

signin_admin > /dev/null
AJ="$JAR"

echo ""
echo "=== 1. The page opens and shows what is waiting ==="
eq "the payouts page opens" "$(code /payouts)" "200"
PAGE=$(page "/payouts?period=$PERIOD")
has "it lists a partner who is owed" "$PAGE" "Payout POTESTMP"
has "and says where the money would go" "$PAGE" "0712345678"
has "and says when it could not"        "$PAGE" "No payment method chosen"

echo ""
echo "=== 2. Building a run gathers the month ==="
T=$(echo "$PAGE" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
eq "the run is built" "$(post /payouts --data "_token=$T&period=$PERIOD")" "302"
RUN=$(q "SELECT id FROM payout_runs WHERE period = '$PERIOD' ORDER BY id DESC LIMIT 1;")
ne "a run exists"        "$RUN" ""
eq "two partners can be paid" "$(q "SELECT COUNT(*) FROM payout_lines WHERE run_id=$RUN AND status='pending';")" "2"
eq "one cannot, and is held" "$(q "SELECT COUNT(*) FROM payout_lines WHERE run_id=$RUN AND status='held';")" "1"
eq "the total is only what can go out" "$(q "SELECT total FROM payout_runs WHERE id=$RUN;")" "12000.00"

echo ""
echo "=== 3. Building a run has paid nobody ==="
# The whole point of the rework. A run is an intention, not a payment.
eq "the M-Pesa partner is still owed" "$(cstat "$MP")" "earned"
eq "the bank partner is still owed"   "$(cstat "$BK")" "earned"
eq "but their money is committed"     "$(attached "$MP")" "committed"
eq "a held partner keeps theirs free" "$(attached "$NO")" "free"

echo ""
echo "=== 4. A second run for the same month is refused ==="
# Not because it would double-pay — the commitment stops that — but
# because it would look like a real run and pay nothing.
eq "it redirects to the one that exists" "$(post /payouts --data "_token=$T&period=$PERIOD")" "302"
eq "and no second run was made" "$(q "SELECT COUNT(*) FROM payout_runs WHERE period='$PERIOD';")" "1"

echo ""
echo "=== 5. Approving, then sending ==="
RT=$(tok "/payouts/$RUN")
eq "the run page opens"  "$(code "/payouts/$RUN")" "200"
eq "it cannot be exported while a draft" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/payouts/$RUN/export/mpesa")" "302"
eq "it is approved" "$(post "/payouts/$RUN/approve" --data "_token=$RT")" "302"
eq "and says so"    "$(q "SELECT status FROM payout_runs WHERE id=$RUN;")" "approved"
eq "approving still pays nobody" "$(cstat "$MP")" "earned"

echo ""
echo "=== 6. The file the bank gets ==="
CSV=$(curl -s -b "$JAR" "$BASE/payouts/$RUN/export/mpesa")
has "the M-Pesa file has a header"   "$CSV" "Phone,Amount,Name"
has "the number is normalised"       "$CSV" "254712345678"
eq  "it is offered as a download" \
    "$(curl -s -o /dev/null -w '%{content_type}' -b "$JAR" "$BASE/payouts/$RUN/export/mpesa" | cut -d';' -f1)" \
    "text/csv"
BCSV=$(curl -s -b "$JAR" "$BASE/payouts/$RUN/export/bank")
has "the bank file carries the account" "$BCSV" "0123456789"
has "and the name the bank holds"       "$BCSV" "Payout BK Ltd"
case "$CSV$BCSV" in *POTESTNO*) bad "a held partner is in neither file" "found" "absent";;
                    *) ok "a held partner is in neither file" "absent";; esac

echo ""
echo "=== 7. Downloading did not commit us to having sent it ==="
# Fetching a file to check it is not the same as sending it.
eq "the run is still merely approved" "$(q "SELECT status FROM payout_runs WHERE id=$RUN;")" "approved"
eq "saying it has gone"  "$(post "/payouts/$RUN/sent" --data "_token=$RT")" "302"
eq "moves it to sent"    "$(q "SELECT status FROM payout_runs WHERE id=$RUN;")" "sent"
eq "and the lines with it" "$(lstat "$(line_of "$RUN" "$MP")")" "sent"
eq "which still pays nobody" "$(cstat "$MP")" "earned"

echo ""
echo "=== 8. Settling is the only thing that pays ==="
MPL=$(line_of "$RUN" "$MP")
eq "the line settles" "$(post "/payouts/line/$MPL/settle" --data "_token=$RT&ref=QGH7X2")" "302"
eq "and its commission is paid" "$(cstat "$MP")" "paid"
eq "with the reference on it" \
   "$(q "SELECT payout_ref FROM commissions WHERE partner_id=$MP AND notes='POTEST' LIMIT 1;")" "QGH7X2"
eq "the other partner is untouched" "$(cstat "$BK")" "earned"

echo ""
echo "=== 9. A payment that bounces leaves them owed ==="
BKL=$(line_of "$RUN" "$BK")
eq "the line is marked failed" \
   "$(post "/payouts/line/$BKL/fail" --data "_token=$RT&reason=Account+closed")" "302"
eq "the commission is owed again" "$(cstat "$BK")" "earned"
eq "and is free for the next run"  "$(attached "$BK")" "free"
eq "one failing did not un-pay the other" "$(cstat "$MP")" "paid"
eq "the run closes once nothing is in flight" "$(q "SELECT status FROM payout_runs WHERE id=$RUN;")" "closed"
eq "and its total is what actually went" "$(q "SELECT total FROM payout_runs WHERE id=$RUN;")" "5000.00"

echo ""
echo "=== 10. What bounced falls into the next run ==="
eq "a new run can be built" "$(post /payouts --data "_token=$T&period=$PERIOD")" "302"
RUN2=$(q "SELECT id FROM payout_runs WHERE period='$PERIOD' ORDER BY id DESC LIMIT 1;")
ne "it is a different run" "$RUN2" "$RUN"
eq "the bounced partner is in it" \
   "$(q "SELECT COUNT(*) FROM payout_lines WHERE run_id=$RUN2 AND partner_id=$BK AND status='pending';")" "1"
eq "the partner already paid is not" \
   "$(q "SELECT COUNT(*) FROM payout_lines WHERE run_id=$RUN2 AND partner_id=$MP;")" "0"

echo ""
echo "=== 11. Fixing payment details lets a held partner in ==="
R2T=$(tok "/payouts/$RUN2")
HELD=$(line_of "$RUN2" "$NO")
eq "it is still held" "$(lstat "$HELD")" "held"
eq "admitting it fails while there is nowhere to send it" \
   "$(post "/payouts/line/$HELD/admit" --data "_token=$R2T")" "302"
eq "so it stays held" "$(lstat "$HELD")" "held"

PT=$(tok "/partners-admin/$NO?tab=money")
eq "finance sets where the money goes" \
   "$(post "/partners-admin/$NO/pay-details" --data "_token=$PT&pay_method=mpesa&pay_phone=0733444555")" "302"
eq "it is stored" "$(q "SELECT pay_phone FROM partners WHERE id=$NO;")" "0733444555"

eq "now it can be admitted" "$(post "/payouts/line/$HELD/admit" --data "_token=$R2T")" "302"
eq "and is waiting to go out" "$(lstat "$HELD")" "pending"
eq "with its commission committed" "$(attached "$NO")" "committed"

echo ""
echo "=== 12. A method with nowhere to send it is refused ==="
eq "bank with no account number is refused" \
   "$(post "/partners-admin/$NO/pay-details" --data "_token=$PT&pay_method=bank&bank_account_no=")" "302"
eq "so the old details stand" "$(q "SELECT pay_method FROM partners WHERE id=$NO;")" "mpesa"

echo ""
echo "=== 13. Holding a line back releases the money ==="
eq "it can be held" "$(post "/payouts/line/$HELD/hold" --data "_token=$R2T&reason=Query")" "302"
eq "and the commission is free again" "$(attached "$NO")" "free"

echo ""
echo "=== 14. Settling the rest in one batch ==="
eq "the batch settles" "$(post "/payouts/$RUN2/settle" --data "_token=$R2T&ref=BATCH-99")" "302"
eq "the bounced partner is finally paid" "$(cstat "$BK")" "paid"
eq "against the batch reference" \
   "$(q "SELECT payout_ref FROM commissions WHERE partner_id=$BK AND notes='POTEST' LIMIT 1;")" "BATCH-99"

echo ""
echo "=== 15. Paying somebody outside the run still records it ==="
# The old button flipped rows to 'paid' with no record of where the money
# went, and could not see commission already committed to an open run —
# so the same money could go out twice. It goes through a run now.
D2=$(seed POTESTAD ", pay_method, pay_phone" ", 'mpesa', '0700999888'" 2500 "$NEXT")
DT=$(tok "/partners-admin/$D2?tab=money")
eq "the direct payment is taken" \
   "$(post "/partners-admin/$D2/payout" --data "_token=$DT&payout_ref=CASH-1")" "302"
eq "the commission is paid" "$(cstat "$D2")" "paid"
eq "and a run records where it went" \
   "$(q "SELECT COUNT(*) FROM payout_lines l JOIN payout_runs r ON r.id=l.run_id
          WHERE l.partner_id=$D2 AND l.status='settled' AND l.ref='CASH-1';")" "1"

echo ""
echo "=== 16. A direct payment cannot pay what a run is holding ==="
S2=$(seed POTESTHD ", pay_method, pay_phone" ", 'mpesa', '0711222333'" 4000 "$NEXT")
eq "a run for that month is built" "$(post /payouts --data "_token=$T&period=$NEXT")" "302"
RUN3=$(q "SELECT id FROM payout_runs WHERE period='$NEXT' AND status='draft' ORDER BY id DESC LIMIT 1;")
eq "it is holding their money" "$(attached "$S2")" "committed"

HT=$(tok "/partners-admin/$S2?tab=money")
eq "a direct payment finds nothing to pay" \
   "$(post "/partners-admin/$S2/payout" --data "_token=$HT&payout_ref=CASH-2")" "302"
eq "the commission is untouched" "$(cstat "$S2")" "earned"
eq "and nothing was settled against that reference" \
   "$(q "SELECT COUNT(*) FROM payout_lines WHERE ref='CASH-2';")" "0"

echo ""
echo "=== 17. Sales may read the money and not move it ==="
SPASS='PoSales@2026'
SHASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$SPASS")
$MYSQL -e "INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('PO Sales','posales@shanfix.co.ke','$SHASH','sales',1);"
signin posales "$SPASS" > /dev/null
eq "they are signed in"        "$(code /dashboard)" "200"
eq "and can read the payouts"  "$(code /payouts)" "200"
eq "and one run"               "$(code "/payouts/$RUN2")" "200"

SP=$(page "/payouts/$RUN2")
eq "with nothing to type a reference into" "$(echo "$SP" | grep -c 'name="ref"')" "0"

ST=$(echo "$SP" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
BEFORE=$(q "SELECT COUNT(*) FROM payout_runs;")
eq "they cannot build a run"   "$(post /payouts --data "_token=$ST&period=$NEXT")" "403"
eq "and none was built"        "$(q "SELECT COUNT(*) FROM payout_runs;")" "$BEFORE"

L3=$(line_of "$RUN3" "$S2")
eq "they cannot settle a line" "$(post "/payouts/line/$L3/settle" --data "_token=$ST&ref=SNEAK")" "403"
eq "the line is untouched"     "$(lstat "$L3")" "pending"
eq "they cannot download the bank file" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/payouts/$RUN2/export/bank")" "403"

echo ""
echo "=== 18. A manager may edit a partner and not redirect their money ==="
# Editing a phone number and changing the account the commission lands in
# are not the same kind of act, so they are not the same permission.
MPASS='PoMgr@2026'
MHASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$MPASS")
$MYSQL -e "INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('PO Manager','pomgr@shanfix.co.ke','$MHASH','manager',1);"
signin pomgr "$MPASS" > /dev/null
eq "the manager is signed in" "$(code /dashboard)" "200"
eq "and can open the partner" "$(code "/partners-admin/$NO")" "200"

MT=$(tok "/partners-admin/$NO")
eq "they can still edit the partner" \
   "$(post "/partners-admin/$NO" --data "_token=$MT&name=Payout+POTESTNO&email=potestno@example.co.ke&phone=0722000111&default_rate=10")" \
   "302"
eq "but not the payment details" \
   "$(post "/partners-admin/$NO/pay-details" --data "_token=$MT&pay_method=mpesa&pay_phone=0799999999")" "403"
eq "so the money still goes where it did" "$(q "SELECT pay_phone FROM partners WHERE id=$NO;")" "0733444555"

MPAGE=$(page "/partners-admin/$NO?tab=money")
eq "and the form is not even on the page" "$(echo "$MPAGE" | grep -c 'name="pay_method"')" "0"

echo ""
echo "=== 19. Finance may ==="
FPASS='PoFin@2026'
FHASH=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$FPASS")
$MYSQL -e "INSERT INTO users (name,email,password_hash,role,is_active)
           VALUES ('PO Finance','pofin@shanfix.co.ke','$FHASH','finance',1);"
signin pofin "$FPASS" > /dev/null
eq "finance is signed in"      "$(code /dashboard)" "200"
FT=$(tok "/partners-admin/$NO?tab=money")
eq "and can set where money goes" \
   "$(post "/partners-admin/$NO/pay-details" --data "_token=$FT&pay_method=mpesa&pay_phone=0788777666")" "302"
eq "which took effect" "$(q "SELECT pay_phone FROM partners WHERE id=$NO;")" "0788777666"

echo ""
echo "=== 20. Tidy up ==="
scrub
eq "the test partners are gone" "$(q "SELECT COUNT(*) FROM partners WHERE email LIKE 'potest%@example.co.ke';")" "0"
eq "and their runs with them"   "$(q "SELECT COUNT(*) FROM payout_runs WHERE period IN ('$PERIOD','$NEXT');")" "0"

report
