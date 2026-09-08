#!/bin/bash
# Staff, payroll and the machines.
#
# The assertion that matters most is that a payslip is a statement of what
# was worked out at the time, not a recipe to recompute later. Rates
# change; last March's payslip must still say what March said.
#
# The other half is who may do what. Working out a payroll, approving one
# and paying it are three different authorities on purpose — whoever
# decides what everybody is paid should not be the only person who has
# looked at it.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

HRPASS='HrTest@2026'
FINPASS='FinTest@2026'
SALESPASS='SalesHr@2026'

scrub() {
  $MYSQL -e "
    DELETE FROM payslip_lines WHERE payslip_id IN
      (SELECT id FROM payslips WHERE run_id IN (SELECT id FROM payroll_runs WHERE period LIKE '2019-%'));
    DELETE FROM payslips WHERE run_id IN (SELECT id FROM payroll_runs WHERE period LIKE '2019-%');
    DELETE FROM payroll_runs WHERE period LIKE '2019-%';
    DELETE FROM equipment_service WHERE equipment_id IN (SELECT id FROM equipment WHERE name LIKE 'HRT %');
    DELETE FROM equipment WHERE name LIKE 'HRT %';
    DELETE FROM employee_pay_items WHERE employee_id IN (SELECT id FROM employees WHERE name LIKE 'HRT %');
    DELETE FROM employees WHERE name LIKE 'HRT %';
    DELETE FROM users WHERE email IN ('hrtest@shanfix.co.ke','fintest@shanfix.co.ke','saleshr@shanfix.co.ke','hrlogin@shanfix.co.ke');"
}

RATES_BEFORE=$(q "SELECT setting_value FROM settings WHERE setting_key='payroll_rates_confirmed';")
restore() { $MYSQL -e "UPDATE settings SET setting_value='$RATES_BEFORE' WHERE setting_key='payroll_rates_confirmed';"; }
trap restore EXIT

scrub
# Start from unconfirmed, which is how a fresh install arrives.
$MYSQL -e "UPDATE settings SET setting_value='0' WHERE setting_key='payroll_rates_confirmed';"

mkuser() {  # mkuser <local> <role> <password> <name>
  local h; h=$($PHP -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$3")
  $MYSQL -e "INSERT INTO users (name,email,password_hash,role,is_active)
             VALUES ('$4','$1@shanfix.co.ke','$h','$2',1);"
}

mkuser hrtest    hr      "$HRPASS"    "HR Tester"
mkuser fintest   finance "$FINPASS"   "Finance Tester"
mkuser saleshr   sales   "$SALESPASS" "Sales Tester"
mkuser hrlogin   staff   "$HRPASS"    "HRT Loginholder"
LOGIN_ID=$(q "SELECT id FROM users WHERE email='hrlogin@shanfix.co.ke';")

signin_admin > /dev/null

echo ""
echo "=== 1. Adding somebody ==="
eq "the page opens" "$(code /staff/new)" "200"
T=$(tok /staff/new)
eq "they are added" "$(post /staff/new \
  --data-urlencode "_token=$T" \
  --data-urlencode "first_name=HRT" --data-urlencode "middle_name=Wanjiku" --data-urlencode "last_name=Kamau" \
  --data-urlencode "id_number=HRT12345" --data-urlencode "kra_pin=a001122334x" \
  --data-urlencode "job_title=Machine operator" --data-urlencode "department=Production" \
  --data-urlencode "employment_type=permanent" --data-urlencode "started_on=2018-01-15" \
  --data-urlencode "status=active" --data-urlencode "basic_salary=50000" \
  --data-urlencode "phone=0722334455" --data-urlencode "pay_method=mpesa" \
  --data-urlencode "user_id=$LOGIN_ID")" "302"

EID=$(q "SELECT id FROM employees WHERE name='HRT Wanjiku Kamau';")
ne "the record exists"      "$EID" ""
eq "with a number"          "$(q "SELECT IF(employee_number IS NULL,'none','set') FROM employees WHERE id=$EID;")" "set"
# The name that has to match an ID is kept in three parts, and the display
# name is written from them so the two cannot drift.
eq "the name is built from its parts" "$(q "SELECT name FROM employees WHERE id=$EID;")" "HRT Wanjiku Kamau"
eq "the PIN is upper-cased" "$(q "SELECT kra_pin FROM employees WHERE id=$EID;")" "A001122334X"
eq "and their login is attached" "$(q "SELECT user_id FROM employees WHERE id=$EID;")" "$LOGIN_ID"

echo ""
echo "=== 2. A login belongs to one person ==="
# Otherwise a payslip could not say whose it was.
BEFORE=$(q "SELECT COUNT(*) FROM employees;")
T=$(tok /staff/new)
eq "a second claim is refused" "$(post /staff/new \
  --data-urlencode "_token=$T" \
  --data-urlencode "first_name=HRT" --data-urlencode "last_name=Impostor" \
  --data-urlencode "basic_salary=1000" --data-urlencode "user_id=$LOGIN_ID")" "302"
eq "and nobody was added"      "$(q "SELECT COUNT(*) FROM employees;")" "$BEFORE"

echo ""
echo "=== 3. Allowances and deductions ==="
T=$(tok "/staff/$EID?tab=pay")
eq "a taxed allowance"     "$(post "/staff/$EID/pay-items" --data "_token=$T&kind=allowance&name=House&amount=10000&taxable=1&recurring=1")" "302"
T=$(tok "/staff/$EID?tab=pay")
eq "an untaxed allowance"  "$(post "/staff/$EID/pay-items" --data "_token=$T&kind=allowance&name=Airtime&amount=2000&recurring=1")" "302"
T=$(tok "/staff/$EID?tab=pay")
eq "and a deduction"       "$(post "/staff/$EID/pay-items" --data "_token=$T&kind=deduction&name=Salary+advance&amount=4000&recurring=1")" "302"

eq "three items are on file" "$(q "SELECT COUNT(*) FROM employee_pay_items WHERE employee_id=$EID AND is_active=1;")" "3"
# A non-taxable allowance is paid without being taxed, which is the whole
# point of one. Getting the flag backwards underpays PAYE.
eq "the airtime is not taxed" "$(q "SELECT taxable FROM employee_pay_items WHERE employee_id=$EID AND name='Airtime';")" "0"
eq "the house allowance is"   "$(q "SELECT taxable FROM employee_pay_items WHERE employee_id=$EID AND name='House';")" "1"

echo ""
echo "=== 4. A payroll cannot be approved on unchecked rates ==="
T=$(tok /payroll)
eq "the month is worked out" "$(post /payroll --data "_token=$T&period=2019-07")" "302"
RUN=$(q "SELECT id FROM payroll_runs WHERE period='2019-07';")
ne "a run exists" "$RUN" ""

RT=$(tok "/payroll/$RUN")
eq "approving is refused"    "$(post "/payroll/$RUN/approve" --data "_token=$RT")" "302"
eq "so it is still a draft"  "$(q "SELECT status FROM payroll_runs WHERE id=$RUN;")" "draft"

echo ""
echo "=== 5. The arithmetic on the payslip ==="
# basic 50,000 + 10,000 taxed + 2,000 untaxed = 62,000 gross.
# NSSF 6% of 62,000 = 3,720. SHIF 2.75% = 1,705. Levy 1.5% = 930.
# Taxable gross 60,000 less 6,355 = 53,645.
# PAYE 2,400 + 2,083.25 + 21,312 x 30% = 10,876.85, less 2,400 = 8,476.85.
# Deductions 3,720 + 1,705 + 930 + 8,477 + 4,000 = 18,832. Net 43,168.
SLIP=$(q "SELECT id FROM payslips WHERE run_id=$RUN AND employee_id=$EID;")
ne "they have a payslip" "$SLIP" ""
eq "gross"        "$(q "SELECT ROUND(gross)            FROM payslips WHERE id=$SLIP;")" "62000"
eq "NSSF"         "$(q "SELECT ROUND(nssf)             FROM payslips WHERE id=$SLIP;")" "3720"
eq "SHIF"         "$(q "SELECT ROUND(shif)             FROM payslips WHERE id=$SLIP;")" "1705"
eq "housing levy" "$(q "SELECT ROUND(housing_levy)     FROM payslips WHERE id=$SLIP;")" "930"
# The untaxed allowance is in gross and out of taxable pay.
eq "taxable pay"  "$(q "SELECT ROUND(taxable_pay)      FROM payslips WHERE id=$SLIP;")" "53645"
eq "PAYE"         "$(q "SELECT ROUND(paye)             FROM payslips WHERE id=$SLIP;")" "8477"
eq "the advance"  "$(q "SELECT ROUND(other_deductions) FROM payslips WHERE id=$SLIP;")" "4000"
eq "net pay"      "$(q "SELECT ROUND(net_pay)          FROM payslips WHERE id=$SLIP;")" "43168"
eq "and it adds up" \
   "$(q "SELECT ROUND(gross - total_deductions - net_pay) FROM payslips WHERE id=$SLIP;")" "0"
# What the business pays on top of wages, kept apart from the deduction.
eq "the employer matches NSSF" "$(q "SELECT ROUND(employer_nssf) FROM payslips WHERE id=$SLIP;")" "3720"

eq "the lines explain it" "$(q "SELECT COUNT(*) FROM payslip_lines WHERE payslip_id=$SLIP;")" "7"
eq "the payslip prints"   "$(code "/payroll/payslip/$SLIP")" "200"
has "and shows the working" "$(page "/payroll/payslip/$SLIP")" "How the tax was worked out"

echo ""
echo "=== 6. Confirming the rates lets it be approved ==="
RTOK=$(tok /payroll/rates)
eq "the rates are saved and confirmed" "$(post /payroll/rates \
  --data-urlencode "_token=$RTOK" \
  --data-urlencode 'payroll_paye_bands=[{"upto":24000,"rate":10},{"upto":32333,"rate":25},{"upto":500000,"rate":30},{"upto":800000,"rate":32.5},{"upto":null,"rate":35}]' \
  --data-urlencode 'payroll_pre_tax_deductions=["nssf","shif","housing"]' \
  --data-urlencode "payroll_personal_relief=2400" \
  --data-urlencode "payroll_nssf_enabled=1" --data-urlencode "payroll_nssf_rate=6" \
  --data-urlencode "payroll_nssf_lel=8000"   --data-urlencode "payroll_nssf_uel=72000" \
  --data-urlencode "payroll_shif_enabled=1"  --data-urlencode "payroll_shif_rate=2.75" \
  --data-urlencode "payroll_shif_min=300" \
  --data-urlencode "payroll_housing_enabled=1" --data-urlencode "payroll_housing_rate=1.5" \
  --data-urlencode "payroll_housing_employer_rate=1.5" \
  --data-urlencode "payroll_rates_confirmed=1")" "302"
eq "and they are marked checked" "$(q "SELECT setting_value FROM settings WHERE setting_key='payroll_rates_confirmed';")" "1"

RT=$(tok "/payroll/$RUN")
eq "now it approves"  "$(post "/payroll/$RUN/approve" --data "_token=$RT")" "302"
eq "and says so"      "$(q "SELECT status FROM payroll_runs WHERE id=$RUN;")" "approved"

echo ""
echo "=== 7. Rubbish bands are refused rather than saved ==="
BANDS_BEFORE=$(q "SELECT setting_value FROM settings WHERE setting_key='payroll_paye_bands';")
RTOK=$(tok /payroll/rates)
eq "a band with no rate is turned back" "$(post /payroll/rates \
  --data-urlencode "_token=$RTOK" \
  --data-urlencode 'payroll_paye_bands=[{"upto":24000}]' \
  --data-urlencode 'payroll_pre_tax_deductions=["nssf"]')" "302"
eq "and the bands are untouched" \
   "$(q "SELECT setting_value FROM settings WHERE setting_key='payroll_paye_bands';")" "$BANDS_BEFORE"

echo ""
echo "=== 8. A payslip keeps the figures it was worked out with ==="
# Move a rate, and last month's payslip must not move with it.
$MYSQL -e "UPDATE settings SET setting_value='30' WHERE setting_key='payroll_shif_rate';"
eq "the payslip is unchanged" "$(q "SELECT ROUND(shif) FROM payslips WHERE id=$SLIP;")" "1705"
$MYSQL -e "UPDATE settings SET setting_value='2.75' WHERE setting_key='payroll_shif_rate';"

echo ""
echo "=== 9. Paying it ==="
signin fintest "$FINPASS" > /dev/null
eq "finance can see it"     "$(code "/payroll/$RUN")" "200"
FT=$(tok "/payroll/$RUN")
eq "and records the payment" "$(post "/payroll/$RUN/paid" --data "_token=$FT&ref=BATCH-HR-1")" "302"
eq "the run is paid"         "$(q "SELECT status FROM payroll_runs WHERE id=$RUN;")" "paid"
eq "and so is the payslip"   "$(q "SELECT status FROM payslips WHERE id=$SLIP;")" "paid"
eq "against the reference"   "$(q "SELECT ref FROM payslips WHERE id=$SLIP;")" "BATCH-HR-1"

echo ""
echo "=== 10. Three authorities, not one ==="
# HR works a payroll out and does not sign it off.
signin hrtest "$HRPASS" > /dev/null
eq "HR sees the staff"        "$(code /staff)" "200"
eq "and the payroll"          "$(code /payroll)" "200"
HT=$(tok /payroll)
eq "HR can work a month out"  "$(post /payroll --data "_token=$HT&period=2019-08")" "302"
RUN2=$(q "SELECT id FROM payroll_runs WHERE period='2019-08';")
HT=$(tok "/payroll/$RUN2")
eq "but cannot approve it"    "$(post "/payroll/$RUN2/approve" --data "_token=$HT")" "403"
eq "so it stays a draft"      "$(q "SELECT status FROM payroll_runs WHERE id=$RUN2;")" "draft"
eq "nor pay it"               "$(post "/payroll/$RUN2/paid" --data "_token=$HT&ref=SNEAK")" "403"

# Finance pays and does not work one out.
signin fintest "$FINPASS" > /dev/null
FT=$(tok /payroll)
BEFORE=$(q "SELECT COUNT(*) FROM payroll_runs;")
eq "finance cannot work one out" "$(post /payroll --data "_token=$FT&period=2019-09")" "403"
eq "and none was made"           "$(q "SELECT COUNT(*) FROM payroll_runs;")" "$BEFORE"
eq "nor change a tax rate"       "$(post /payroll/rates --data "_token=$FT&payroll_paye_bands=[]")" "403"

echo ""
echo "=== 11. Salaries are not everybody's business ==="
signin saleshr "$SALESPASS" > /dev/null
eq "sales are signed in"     "$(code /dashboard)" "200"
eq "and cannot see the staff records" "$(code /staff)" "403"
eq "nor one person"          "$(code "/staff/$EID")" "403"
eq "nor the payroll"         "$(code /payroll)" "403"
eq "nor a payslip"           "$(code "/payroll/payslip/$SLIP")" "403"
# But they can look up a machine, because whether the laminator works
# decides what can be promised today.
eq "they can read the register" "$(code /equipment)" "200"

echo ""
echo "=== 12. The machines ==="
signin_admin > /dev/null
T=$(tok /equipment/new)
eq "a machine is added" "$(post /equipment/new \
  --data-urlencode "_token=$T" \
  --data-urlencode "name=HRT Roland printer" --data-urlencode "category=Printer" \
  --data-urlencode "make=Roland" --data-urlencode "model=XR-640" \
  --data-urlencode "serial_number=HRT-SN-1" --data-urlencode "location=Workshop" \
  --data-urlencode "status=in_service" --data-urlencode "purchase_cost=1200000" \
  --data-urlencode "purchased_on=2019-03-01" \
  --data-urlencode "service_interval_days=90" \
  --data-urlencode "last_serviced_on=2019-03-01" \
  --data-urlencode "assigned_to=$EID")" "302"

QID=$(q "SELECT id FROM equipment WHERE name='HRT Roland printer';")
ne "it is on the register" "$QID" ""
eq "with a code"           "$(q "SELECT IF(asset_code IS NULL,'none','set') FROM equipment WHERE id=$QID;")" "set"
# 90 days after 1 March 2019 is 30 May 2019, worked out rather than typed.
eq "and a next service worked out" "$(q "SELECT next_service_on FROM equipment WHERE id=$QID;")" "2019-05-30"
eq "somebody is answerable for it" "$(q "SELECT assigned_to FROM equipment WHERE id=$QID;")" "$EID"

echo ""
echo "=== 13. Recording a service moves the next one ==="
ST=$(tok "/equipment/$QID")
eq "the service is recorded" "$(post "/equipment/$QID/service" \
  --data-urlencode "_token=$ST" \
  --data-urlencode "serviced_on=2019-06-10" --data-urlencode "kind=service" \
  --data-urlencode "summary=Print heads cleaned and aligned" \
  --data-urlencode "cost=15000" --data-urlencode "done_by_employee=$EID")" "302"

eq "the history has it"    "$(q "SELECT COUNT(*) FROM equipment_service WHERE equipment_id=$QID;")" "1"
eq "the machine moved on"  "$(q "SELECT last_serviced_on FROM equipment WHERE id=$QID;")" "2019-06-10"
# 90 days after 10 June 2019 is 8 September 2019.
eq "and so did the next one" "$(q "SELECT next_service_on FROM equipment WHERE id=$QID;")" "2019-09-08"

echo ""
echo "=== 14. Catching up on old paperwork does not drag it backwards ==="
ST=$(tok "/equipment/$QID")
eq "an older service is recorded" "$(post "/equipment/$QID/service" \
  --data-urlencode "_token=$ST" \
  --data-urlencode "serviced_on=2019-04-02" --data-urlencode "kind=repair" \
  --data-urlencode "summary=Belt replaced" --data-urlencode "cost=8000")" "302"

eq "the history keeps both" "$(q "SELECT COUNT(*) FROM equipment_service WHERE equipment_id=$QID;")" "2"
eq "but the last service stands"  "$(q "SELECT last_serviced_on FROM equipment WHERE id=$QID;")" "2019-06-10"
eq "and so does the next"         "$(q "SELECT next_service_on FROM equipment WHERE id=$QID;")" "2019-09-08"
eq "what it has cost us"          "$(q "SELECT ROUND(SUM(cost)) FROM equipment_service WHERE equipment_id=$QID;")" "23000"

echo ""
echo "=== 15. What is falling due ==="
$MYSQL -e "UPDATE equipment SET next_service_on = CURDATE() - INTERVAL 3 DAY WHERE id=$QID;"
has "an overdue machine is listed" "$(page '/equipment?due=1')" "HRT Roland printer"
has "and says it is overdue"       "$(page "/equipment/$QID")"  "Service overdue"

$MYSQL -e "UPDATE equipment SET next_service_on = CURDATE() + INTERVAL 200 DAY WHERE id=$QID;"
case "$(page '/equipment?due=1')" in
  *"HRT Roland printer"*) bad "one that is not due is left out" "found" "absent";;
  *)                      ok  "one that is not due is left out" "absent";;
esac

echo ""
echo "=== 16. Tidy up ==="
scrub
restore
eq "the test staff are gone"    "$(q "SELECT COUNT(*) FROM employees WHERE name LIKE 'HRT %';")" "0"
eq "and the test machines"      "$(q "SELECT COUNT(*) FROM equipment WHERE name LIKE 'HRT %';")" "0"
eq "and the test payroll"       "$(q "SELECT COUNT(*) FROM payroll_runs WHERE period LIKE '2019-%';")" "0"

report
