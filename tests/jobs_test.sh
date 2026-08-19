#!/bin/bash
# End-to-end test of the production workflow:
# invoice -> job card -> artwork -> proof -> client approval -> print -> ready -> delivered
BASE="http://127.0.0.1:8000"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root shanfix_test"
JAR="$(dirname "$0")/jobs.txt"; rm -f "$JAR"
PASS=0; FAIL=0

ok()   { printf "  \033[32mPASS\033[0m %-50s %s\n" "$1" "$2"; PASS=$((PASS+1)); }
bad()  { printf "  \033[31mFAIL\033[0m %-50s got '%s' want '%s'\n" "$1" "$2" "$3"; FAIL=$((FAIL+1)); }
eq()   { if [ "$2" = "$3" ]; then ok "$1" "$2"; else bad "$1" "$2" "$3"; fi; }

tok()  { curl -s -b "$JAR" -c "$JAR" "$BASE$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code() { curl -s -o /tmp/j.html -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE$1"; }
post() { curl -s -o /tmp/j.html -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE$1" --data "$2"; }

echo ""
echo "=== Setup: sign in, client, invoice ==="
T=$(tok /login)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$T&email=admin@shanfix.co.ke&password=Shanfix@2026"
eq "signed in" "$(code /dashboard)" "200"

T=$(tok /clients/create)
post "/clients" "_token=$T&client_type=company&name=Westlands+Dental&phone=0733445566&email=admin@wdental.co.ke&city=Nairobi&status=active&credit_limit=0" > /dev/null
CID=$($MYSQL -N -e "SELECT id FROM clients WHERE name='Westlands Dental' ORDER BY id DESC LIMIT 1;")

T=$(tok "/invoices/create")
post "/invoices" "_token=$T&client_id=$CID&issue_date=$(date +%Y-%m-%d)&due_date=$(date -d '+14 days' +%Y-%m-%d)&title=Clinic+signage+package&status=unpaid&discount_type=none&discount_value=0&vat_mode=exclusive&vat_rate=16&items[0][item_type]=custom&items[0][description]=Illuminated+facia+sign+2400x600mm&items[0][quantity]=1&items[0][unit_price]=85000&items[1][item_type]=custom&items[1][description]=Directional+wall+signs&items[1][quantity]=6&items[1][unit_price]=4500" > /dev/null
INV=$($MYSQL -N -e "SELECT id FROM documents WHERE doc_type='invoice' AND client_id=$CID ORDER BY id DESC LIMIT 1;")
eq "invoice created" "$([ -n "$INV" ] && echo yes)" "yes"

echo ""
echo "=== 1. Raise a job card from the invoice ==="
T=$(tok "/invoices/$INV")
eq "POST /documents/{id}/job" "$(post "/documents/$INV/job" "_token=$T")" "302"
JOB=$($MYSQL -N -e "SELECT id FROM jobs WHERE document_id=$INV;")
eq "job exists"              "$([ -n "$JOB" ] && echo yes)" "yes"
eq "job number format"       "$($MYSQL -N -e "SELECT job_number FROM jobs WHERE id=$JOB;" | grep -cE '^JOB-[0-9]{4}-[0-9]{4}$')" "1"
eq "starts queued"           "$($MYSQL -N -e "SELECT stage FROM jobs WHERE id=$JOB;")" "pending"
eq "invoice items copied"    "$($MYSQL -N -e "SELECT COUNT(*) FROM job_items WHERE job_id=$JOB;")" "2"
eq "opening stage logged"    "$($MYSQL -N -e "SELECT COUNT(*) FROM job_stages WHERE job_id=$JOB;")" "1"
eq "job page loads"          "$(code /jobs/$JOB)" "200"
eq "job card prints"         "$(code /jobs/$JOB/print)" "200"

echo ""
echo "=== 2. Duplicate job card is refused ==="
T=$(tok "/invoices/$INV")
post "/documents/$INV/job" "_token=$T" > /dev/null
eq "still exactly one job" "$($MYSQL -N -e "SELECT COUNT(*) FROM jobs WHERE document_id=$INV;")" "1"

echo ""
echo "=== 3. Assign and move to artwork ==="
T=$(tok "/jobs/$JOB")
eq "assign to admin" "$(post "/jobs/$JOB/assign" "_token=$T&assigned_to=1")" "302"
eq "assignment stored" "$($MYSQL -N -e "SELECT assigned_to FROM jobs WHERE id=$JOB;")" "1"

T=$(tok "/jobs/$JOB")
eq "move to artwork" "$(post "/jobs/$JOB/stage" "_token=$T&stage=artwork&stage_note=Designer+started")" "302"
eq "stage is artwork" "$($MYSQL -N -e "SELECT stage FROM jobs WHERE id=$JOB;")" "artwork"

echo ""
echo "=== 4. Upload a proof (multipart) ==="
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > /tmp/proof-v1.pdf
T=$(tok "/jobs/$JOB")
C=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE/jobs/$JOB/files" \
     -F "_token=$T" -F "file_type=proof" -F "notes=First proof for client" -F "file=@/tmp/proof-v1.pdf")
eq "proof uploaded"          "$C" "302"
eq "file row created"        "$($MYSQL -N -e "SELECT COUNT(*) FROM job_files WHERE job_id=$JOB AND file_type='proof';")" "1"
eq "proof starts pending"    "$($MYSQL -N -e "SELECT status FROM job_files WHERE job_id=$JOB;")" "pending"
eq "version numbered v1"     "$($MYSQL -N -e "SELECT version FROM job_files WHERE job_id=$JOB;")" "1"
eq "upload logged to history" "$($MYSQL -N -e "SELECT COUNT(*) FROM job_stages WHERE job_id=$JOB AND notes LIKE 'Proof v1%';")" "1"

echo ""
echo "=== 4b. A text file wearing a .pdf name is rejected ==="
echo "not really a pdf" > /tmp/bogus.pdf
T=$(tok "/jobs/$JOB")
C=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE/jobs/$JOB/files"      -F "_token=$T" -F "file_type=artwork" -F "file=@/tmp/bogus.pdf")
eq "mismatched file type rejected" "$C" "422"
eq "no extra file row"             "$($MYSQL -N -e "SELECT COUNT(*) FROM job_files WHERE job_id=$JOB;")" "1"

echo ""
echo "=== 5. Unapproved proof blocks the press ==="
T=$(tok "/jobs/$JOB")
post "/jobs/$JOB/stage" "_token=$T&stage=production" > /dev/null
eq "blocked from production" "$($MYSQL -N -e "SELECT stage FROM jobs WHERE id=$JOB;")" "artwork"

echo ""
echo "=== 6. Client rejects the proof ==="
FID=$($MYSQL -N -e "SELECT id FROM job_files WHERE job_id=$JOB ORDER BY id DESC LIMIT 1;")
T=$(tok "/jobs/$JOB")
eq "rejection recorded" "$(post "/jobs/files/$FID/decide" "_token=$T&decision=rejected&client_feedback=Logo+too+small,+use+brand+green")" "302"
eq "proof marked rejected"   "$($MYSQL -N -e "SELECT status FROM job_files WHERE id=$FID;")" "rejected"
eq "feedback stored"         "$($MYSQL -N -e "SELECT client_feedback FROM job_files WHERE id=$FID;")" "Logo too small, use brand green"
eq "job sent back to artwork" "$($MYSQL -N -e "SELECT stage FROM jobs WHERE id=$JOB;")" "artwork"

echo ""
echo "=== 7. Proof v2 approved ==="
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > /tmp/proof-v2.pdf
T=$(tok "/jobs/$JOB")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/jobs/$JOB/files" \
  -F "_token=$T" -F "file_type=proof" -F "file=@/tmp/proof-v2.pdf" > /dev/null
eq "v2 auto-numbered" "$($MYSQL -N -e "SELECT MAX(version) FROM job_files WHERE job_id=$JOB AND file_type='proof';")" "2"

FID2=$($MYSQL -N -e "SELECT id FROM job_files WHERE job_id=$JOB AND version=2;")
T=$(tok "/jobs/$JOB")
eq "approval recorded" "$(post "/jobs/files/$FID2/decide" "_token=$T&decision=approved")" "302"
eq "proof approved"        "$($MYSQL -N -e "SELECT status FROM job_files WHERE id=$FID2;")" "approved"
eq "approver stamped"      "$($MYSQL -N -e "SELECT IF(approved_at IS NOT NULL,'yes','no') FROM job_files WHERE id=$FID2;")" "yes"
eq "job cleared to print"  "$($MYSQL -N -e "SELECT stage FROM jobs WHERE id=$JOB;")" "approved"

echo ""
echo "=== 8. Production -> finishing -> ready ==="
for S in production finishing ready; do
  T=$(tok "/jobs/$JOB")
  post "/jobs/$JOB/stage" "_token=$T&stage=$S" > /dev/null
done
eq "stage is ready"        "$($MYSQL -N -e "SELECT stage FROM jobs WHERE id=$JOB;")" "ready"
eq "started_at stamped"    "$($MYSQL -N -e "SELECT IF(started_at IS NOT NULL,'yes','no') FROM jobs WHERE id=$JOB;")" "yes"
eq "completed_at stamped"  "$($MYSQL -N -e "SELECT IF(completed_at IS NOT NULL,'yes','no') FROM jobs WHERE id=$JOB;")" "yes"

echo ""
echo "=== 9. Tick items off the checklist ==="
IID=$($MYSQL -N -e "SELECT id FROM job_items WHERE job_id=$JOB ORDER BY id LIMIT 1;")
T=$(tok "/jobs/$JOB")
eq "item toggled" "$(post "/jobs/$JOB/items/$IID/toggle" "_token=$T")" "302"
eq "item marked done"  "$($MYSQL -N -e "SELECT is_done FROM job_items WHERE id=$IID;")" "1"
eq "who did it stored"  "$($MYSQL -N -e "SELECT IF(done_by IS NOT NULL,'yes','no') FROM job_items WHERE id=$IID;")" "yes"

echo ""
echo "=== 10. Book a material cost against the job ==="
T=$(tok "/expenses/create")
post "/expenses" "_token=$T&description=Acrylic+sheet+and+LED+modules&vendor=Signage+Supplies+Ltd&amount=32000&vat_amount=4413.79&expense_date=$(date +%Y-%m-%d)&payment_method=bank&job_id=$JOB&client_id=$CID&is_billable=1" > /dev/null
eq "expense linked to job" "$($MYSQL -N -e "SELECT COUNT(*) FROM expenses WHERE job_id=$JOB;")" "1"
eq "cost amount"           "$($MYSQL -N -e "SELECT amount FROM expenses WHERE job_id=$JOB;")" "32000.00"
# invoice 85000 + 6*4500 = 112000 net; margin vs 32000 cost
eq "job page shows costing" "$(curl -s -b "$JAR" "$BASE/jobs/$JOB" | grep -c 'Job costing')" "1"

echo ""
echo "=== 11. Delivery note ==="
T=$(tok "/jobs/$JOB")
eq "delivery note raised" "$(post "/jobs/$JOB/delivery-note" "_token=$T")" "302"
DN=$($MYSQL -N -e "SELECT id FROM delivery_notes WHERE job_id=$JOB;")
eq "dn exists"          "$([ -n "$DN" ] && echo yes)" "yes"
eq "dn items copied"    "$($MYSQL -N -e "SELECT COUNT(*) FROM delivery_note_items WHERE delivery_note_id=$DN;")" "2"
eq "dn number format"   "$($MYSQL -N -e "SELECT dn_number FROM delivery_notes WHERE id=$DN;" | grep -cE '^DN-[0-9]{4}-[0-9]{4}$')" "1"
eq "dn page loads"      "$(code /delivery-notes/$DN)" "200"
eq "dn prints"          "$(code /delivery-notes/$DN/print)" "200"
eq "dn list loads"      "$(code /delivery-notes)" "200"

echo ""
echo "=== 12. Delivered without a signature is refused ==="
T=$(tok "/delivery-notes/$DN")
post "/delivery-notes/$DN" "_token=$T&delivery_date=$(date +%Y-%m-%d)&status=delivered&received_by=" > /dev/null
eq "still not delivered" "$($MYSQL -N -e "SELECT status FROM delivery_notes WHERE id=$DN;")" "draft"

echo ""
echo "=== 13. Confirm delivery — closes the job ==="
T=$(tok "/delivery-notes/$DN")
eq "delivery confirmed" "$(post "/delivery-notes/$DN" "_token=$T&delivery_date=$(date +%Y-%m-%d)&status=delivered&received_by=Dr+Amina+Yusuf&delivered_by=Otieno&vehicle_reg=KDA+123X")" "302"
eq "dn delivered"        "$($MYSQL -N -e "SELECT status FROM delivery_notes WHERE id=$DN;")" "delivered"
eq "received_at stamped" "$($MYSQL -N -e "SELECT IF(received_at IS NOT NULL,'yes','no') FROM delivery_notes WHERE id=$DN;")" "yes"
eq "JOB auto-closed"     "$($MYSQL -N -e "SELECT stage FROM jobs WHERE id=$JOB;")" "delivered"
eq "delivered_at stamped" "$($MYSQL -N -e "SELECT IF(delivered_at IS NOT NULL,'yes','no') FROM jobs WHERE id=$JOB;")" "yes"
eq "closure logged"      "$($MYSQL -N -e "SELECT COUNT(*) FROM job_stages WHERE job_id=$JOB AND to_stage='delivered';")" "1"

echo ""
echo "=== 14. Board & list views ==="
eq "board view"        "$(code /jobs)" "200"
eq "list view"         "$(code '/jobs?view=list')" "200"
eq "my-jobs filter"    "$(code '/jobs?assigned=me')" "200"
eq "show-finished"     "$(code '/jobs?done=1')" "200"
eq "new job form"      "$(code /jobs/create)" "200"
eq "edit job form"     "$(code /jobs/$JOB/edit)" "200"

echo ""
echo "=== 15. Audit trail recorded the lot ==="
eq "job events logged" "$($MYSQL -N -e "SELECT IF(COUNT(*)>=6,'yes','no') FROM activity_log WHERE entity_type='job';")" "yes"

echo ""
echo "==================================================="
printf "  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n" "$PASS" "$FAIL"
echo "==================================================="
exit $FAIL
