#!/bin/bash
# Tax per line.
#
# VAT used to be one rate applied to a whole document. A print shop that
# sells only standard-rated work never noticed; the moment one line is
# zero-rated or exempt, that arithmetic over-charges the client and
# over-declares the output tax. eTIMS then refuses an invoice that does
# not carry a class per line at all.
#
# So what is asserted here is money. Every case goes in through the form
# the way a person fills it in, and what landed in the database is read
# back and added up:
#
#   1. an ordinary invoice is charged exactly as it always was
#   2. a mixed invoice charges each line at its own class
#   3. a discount comes off the classes in proportion
#   4. the class follows the catalogue item onto the line
#   5. the KRA code comes from the catalogue, never from the form
#   6. a conversion carries the classes with it
#   7. the printed invoice states what it charged
#
# The figures are worked out by hand in the comments, not read out of the
# code, because a test that asks the code what it thinks proves nothing.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

signin_admin

CID=$(q "SELECT id FROM clients ORDER BY id LIMIT 1;")

# Rates the suite relies on. Set explicitly rather than assumed, and put
# back at the end, because they are settings and another suite or a
# person may have moved them.
RATE_B_WAS=$(q "SELECT setting_value FROM settings WHERE setting_key='tax_rate_b';")
$MYSQL -e "UPDATE settings SET setting_value='16' WHERE setting_key='tax_rate_b';
           UPDATE settings SET setting_value='0' WHERE setting_key='approval_required';
           DELETE FROM documents WHERE title LIKE 'eTIMS suite%';
           DELETE FROM services WHERE code IN ('ETX-STD','ETX-ZERO');"

# Two catalogue services: one ordinary, one zero-rated for export, with a
# KRA classification code on it.
$MYSQL -e "INSERT INTO services (code,name,pricing_type,price,tax_type,etims_code,is_active)
           VALUES ('ETX-STD','eTIMS suite banners','fixed',10000,'B','5059790800',1),
                  ('ETX-ZERO','eTIMS suite export artwork','fixed',5000,'C','5022001700',1);"

SVC_STD=$(q "SELECT id FROM services WHERE code='ETX-STD';")
SVC_ZERO=$(q "SELECT id FROM services WHERE code='ETX-ZERO';")

# Raise an invoice through the form. Arguments are line triples of
# description/price/class; everything else is fixed.
raise() {
  local title="$1" mode="$2" disc_type="$3" disc_val="$4"; shift 4
  local args=() i=0

  while [ $# -gt 0 ]; do
    args+=(--data-urlencode "items[$i][item_type]=custom")
    args+=(--data-urlencode "items[$i][description]=$1")
    args+=(--data-urlencode "items[$i][quantity]=1")
    args+=(--data-urlencode "items[$i][unit_price]=$2")
    args+=(--data-urlencode "items[$i][tax_type]=$3")
    shift 3
    i=$((i+1))
  done

  post /invoices \
    --data-urlencode "_token=$(tok /invoices/create)" \
    --data-urlencode "client_id=$CID" \
    --data-urlencode "title=$title" \
    --data-urlencode "issue_date=2026-09-30" \
    --data-urlencode "status=draft" \
    --data-urlencode "vat_mode=$mode" \
    --data-urlencode "discount_type=$disc_type" \
    --data-urlencode "discount_value=$disc_val" \
    "${args[@]}" > /dev/null
}

docid() { q "SELECT id FROM documents WHERE title='$1' ORDER BY id DESC LIMIT 1;"; }

echo ""
echo "=== 1. An ordinary invoice is unchanged ==="

# 10,000 standard rated, VAT added on top: 1,600 tax, 11,600 to pay.
# This is what the single-rate arithmetic produced and it has to go on
# producing it, or every invoice in the business just moved.
raise "eTIMS suite plain" exclusive none 0 "Pull-up banner" 10000 B
D1=$(docid "eTIMS suite plain")

eq "subtotal"                 "$(q "SELECT subtotal FROM documents WHERE id=$D1;")"   "10000.00"
eq "VAT is 16% of it"         "$(q "SELECT vat_amount FROM documents WHERE id=$D1;")" "1600.00"
eq "total"                    "$(q "SELECT total FROM documents WHERE id=$D1;")"      "11600.00"
eq "the line is standard"     "$(q "SELECT tax_type FROM document_items WHERE document_id=$D1;")"   "B"
eq "and carries its own tax"  "$(q "SELECT tax_amount FROM document_items WHERE document_id=$D1;")" "1600.00"

echo ""
echo "=== 2. A mixed invoice charges each line at its own class ==="

# 10,000 standard + 5,000 zero-rated + 2,400 exempt.
# Tax is 16% of the 10,000 only: 1,600. The old arithmetic charged 16%
# of all 17,400 = 2,784, so this is the bug in figures.
raise "eTIMS suite mixed" exclusive none 0 \
      "Pull-up banner" 10000 B \
      "Export artwork" 5000 C \
      "Exempt service" 2400 A
D2=$(docid "eTIMS suite mixed")

eq "subtotal is all three lines" "$(q "SELECT subtotal FROM documents WHERE id=$D2;")"   "17400.00"
eq "VAT is only on the standard line" "$(q "SELECT vat_amount FROM documents WHERE id=$D2;")" "1600.00"
eq "total"                       "$(q "SELECT total FROM documents WHERE id=$D2;")"      "19000.00"

eq "the standard line pays"      "$(q "SELECT tax_amount FROM document_items WHERE document_id=$D2 AND tax_type='B';")" "1600.00"
eq "the zero-rated line pays nothing" "$(q "SELECT tax_amount FROM document_items WHERE document_id=$D2 AND tax_type='C';")" "0.00"
eq "the exempt line pays nothing" "$(q "SELECT tax_amount FROM document_items WHERE document_id=$D2 AND tax_type='A';")" "0.00"

# The three classes are all held apart. Collapsing A, C and D to "no tax"
# is how a VAT return comes back wrong: exempt is outside the system,
# zero-rated is inside it at nought.
eq "three classes on the document" \
   "$(q "SELECT COUNT(DISTINCT tax_type) FROM document_items WHERE document_id=$D2;")" "3"

# The lines must add back up to the document, or the breakdown on the
# invoice disagrees with the total printed under it.
eq "lines add to the document VAT" \
   "$(q "SELECT (SELECT SUM(tax_amount) FROM document_items WHERE document_id=$D2)
              = (SELECT vat_amount FROM documents WHERE id=$D2);")" "1"

echo ""
echo "=== 3. Inclusive prices ==="

# 11,600 standard rated with the tax inside it: 1,600 of it is VAT, and
# nothing is added on top. The zero-rated 5,000 contains no tax.
raise "eTIMS suite inclusive" inclusive none 0 \
      "Banner, VAT inside" 11600 B \
      "Export artwork" 5000 C
D3=$(docid "eTIMS suite inclusive")

eq "the tax is backed out"  "$(q "SELECT vat_amount FROM documents WHERE id=$D3;")" "1600.00"
eq "nothing is added on top" "$(q "SELECT total FROM documents WHERE id=$D3;")"     "16600.00"
eq "and only from the standard line" \
   "$(q "SELECT tax_amount FROM document_items WHERE document_id=$D3 AND tax_type='C';")" "0.00"

echo ""
echo "=== 4. A discount comes off the classes in proportion ==="

# 10,000 standard + 5,000 zero-rated, less 10% of the 15,000 = 1,500.
# Each line loses a tenth: the standard line is taxed on 9,000, so
# 1,440. Charging the whole discount against the first line would have
# taxed 8,500 and under-declared; against the second, 10,000 and over.
raise "eTIMS suite discount" exclusive percent 10 \
      "Pull-up banner" 10000 B \
      "Export artwork" 5000 C
D4=$(docid "eTIMS suite discount")

eq "the discount"        "$(q "SELECT discount_amount FROM documents WHERE id=$D4;")" "1500.00"
eq "VAT on the discounted standard line" "$(q "SELECT vat_amount FROM documents WHERE id=$D4;")" "1440.00"
eq "total"               "$(q "SELECT total FROM documents WHERE id=$D4;")"           "14940.00"

echo ""
echo "=== 5. A document marked exempt overrides its lines ==="

# Somebody who has set the whole document exempt has said something
# deliberate, and it is the coarser statement, so it wins.
raise "eTIMS suite exempt doc" exempt none 0 "Pull-up banner" 10000 B
D5=$(docid "eTIMS suite exempt doc")

eq "no VAT at all"      "$(q "SELECT vat_amount FROM documents WHERE id=$D5;")" "0.00"
eq "total is the goods" "$(q "SELECT total FROM documents WHERE id=$D5;")"      "10000.00"
eq "and the line records no tax" \
   "$(q "SELECT tax_amount FROM document_items WHERE document_id=$D5;")" "0.00"

echo ""
echo "=== 6. The class and code come from the catalogue ==="

# A catalogue line with no class posted at all — what an older cached
# form, or anything posting directly, would send. The item's own class
# has to be used rather than defaulting to standard, because defaulting
# is the failure being fixed.
post /invoices \
  --data-urlencode "_token=$(tok /invoices/create)" \
  --data-urlencode "client_id=$CID" \
  --data-urlencode "title=eTIMS suite catalogue" \
  --data-urlencode "issue_date=2026-09-30" \
  --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][item_type]=service" \
  --data-urlencode "items[0][ref_id]=$SVC_ZERO" \
  --data-urlencode "items[0][description]=Export artwork" \
  --data-urlencode "items[0][quantity]=1" \
  --data-urlencode "items[0][unit_price]=5000" > /dev/null

D6=$(docid "eTIMS suite catalogue")

eq "the class came off the service" \
   "$(q "SELECT tax_type FROM document_items WHERE document_id=$D6;")" "C"
eq "so no VAT was charged" "$(q "SELECT vat_amount FROM documents WHERE id=$D6;")" "0.00"
eq "and the KRA code came with it" \
   "$(q "SELECT etims_code FROM document_items WHERE document_id=$D6;")" "5022001700"

# The KRA code is read from the catalogue and never from the form. It has
# to match what was registered with KRA, and a hidden field in a browser
# is not somewhere to keep it.
post /invoices \
  --data-urlencode "_token=$(tok /invoices/create)" \
  --data-urlencode "client_id=$CID" \
  --data-urlencode "title=eTIMS suite forged code" \
  --data-urlencode "issue_date=2026-09-30" \
  --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][item_type]=service" \
  --data-urlencode "items[0][ref_id]=$SVC_STD" \
  --data-urlencode "items[0][description]=Pull-up banner" \
  --data-urlencode "items[0][quantity]=1" \
  --data-urlencode "items[0][unit_price]=10000" \
  --data-urlencode "items[0][etims_code]=9999999999" > /dev/null

D7=$(docid "eTIMS suite forged code")

eq "a posted KRA code is ignored" \
   "$(q "SELECT etims_code FROM document_items WHERE document_id=$D7;")" "5059790800"

# The class, unlike the code, may be changed on the line: a single export
# sale of an ordinarily standard-rated item is zero-rated, and that is a
# decision about the sale rather than about the item.
post /invoices \
  --data-urlencode "_token=$(tok /invoices/create)" \
  --data-urlencode "client_id=$CID" \
  --data-urlencode "title=eTIMS suite one-off export" \
  --data-urlencode "issue_date=2026-09-30" \
  --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][item_type]=service" \
  --data-urlencode "items[0][ref_id]=$SVC_STD" \
  --data-urlencode "items[0][description]=Pull-up banner, exported" \
  --data-urlencode "items[0][quantity]=1" \
  --data-urlencode "items[0][unit_price]=10000" \
  --data-urlencode "items[0][tax_type]=C" > /dev/null

D8=$(docid "eTIMS suite one-off export")

eq "the line may be zero-rated by hand" \
   "$(q "SELECT tax_type FROM document_items WHERE document_id=$D8;")" "C"
eq "and then carries no VAT" "$(q "SELECT vat_amount FROM documents WHERE id=$D8;")" "0.00"

# Nonsense is not a class. An unknown letter falls back to standard
# rather than being stored, because a class outside A-E is an invoice
# eTIMS will reject.
post /invoices \
  --data-urlencode "_token=$(tok /invoices/create)" \
  --data-urlencode "client_id=$CID" \
  --data-urlencode "title=eTIMS suite bad class" \
  --data-urlencode "issue_date=2026-09-30" \
  --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][item_type]=custom" \
  --data-urlencode "items[0][description]=Something" \
  --data-urlencode "items[0][quantity]=1" \
  --data-urlencode "items[0][unit_price]=1000" \
  --data-urlencode "items[0][tax_type]=Z" > /dev/null

D9=$(docid "eTIMS suite bad class")
eq "an unknown class becomes standard" \
   "$(q "SELECT tax_type FROM document_items WHERE document_id=$D9;")" "B"

echo ""
echo "=== 7. A conversion carries the classes with it ==="

# A quotation with a zero-rated line, accepted and turned into an
# invoice. If the classes did not travel, the client would be quoted one
# figure and invoiced another.
$MYSQL -e "DELETE FROM documents WHERE title='eTIMS suite quote';"

post /quotations \
  --data-urlencode "_token=$(tok /quotations/create)" \
  --data-urlencode "client_id=$CID" \
  --data-urlencode "title=eTIMS suite quote" \
  --data-urlencode "issue_date=2026-09-30" \
  --data-urlencode "status=draft" \
  --data-urlencode "vat_mode=exclusive" \
  --data-urlencode "items[0][item_type]=custom" \
  --data-urlencode "items[0][description]=Pull-up banner" \
  --data-urlencode "items[0][quantity]=1" \
  --data-urlencode "items[0][unit_price]=10000" \
  --data-urlencode "items[0][tax_type]=B" \
  --data-urlencode "items[1][item_type]=custom" \
  --data-urlencode "items[1][description]=Export artwork" \
  --data-urlencode "items[1][quantity]=1" \
  --data-urlencode "items[1][unit_price]=5000" \
  --data-urlencode "items[1][tax_type]=C" > /dev/null

QID=$(docid "eTIMS suite quote")
eq "the quotation is taxed per line" "$(q "SELECT vat_amount FROM documents WHERE id=$QID;")" "1600.00"

post "/quotations/$QID/convert" --data "_token=$(tok "/quotations/$QID")" > /dev/null
INV=$(q "SELECT id FROM documents WHERE doc_type='invoice' AND title='eTIMS suite quote' ORDER BY id DESC LIMIT 1;")

ne "an invoice was raised" "$INV" ""
eq "with the same VAT"       "$(q "SELECT vat_amount FROM documents WHERE id=$INV;")" "1600.00"
eq "and the classes travelled" \
   "$(q "SELECT GROUP_CONCAT(tax_type ORDER BY tax_type) FROM document_items WHERE document_id=$INV;")" "B,C"
eq "and so did the per-line tax" \
   "$(q "SELECT tax_amount FROM document_items WHERE document_id=$INV AND tax_type='C';")" "0.00"

echo ""
echo "=== 8. The invoice states what it charged ==="

# On a mixed invoice the total is shown band by band. "VAT @ 16%" over an
# invoice where only part of it is standard rated is a false statement on
# a tax document, and per class is the shape KRA reports totals in.
PRINT=$(page "/invoices/$D2/print")
has "the standard band is named"  "$PRINT" "Standard rated"
has "with its rate"               "$PRINT" "16%"
has "the zero-rated band too"     "$PRINT" "Zero rated"
has "and the exempt one"          "$PRINT" "Exempt"
has "each band says what it was charged on" "$PRINT" "on "

# An ordinary invoice reads exactly as it always did.
PLAIN=$(page "/invoices/$D1/print")
has "a single-rate invoice says so" "$PLAIN" "VAT @ 16%"

# And the staff page agrees with the printed copy.
SHOW=$(page "/invoices/$D2")
has "the staff page breaks it down too" "$SHOW" "Zero rated"

echo ""
echo "=== 9. The editor offers the classes ==="

FORM=$(page /invoices/create)
has "the line table has a VAT column" "$FORM" "col-w-vat"
has "and a class picker per line"     "$FORM" 'data-f="tax_type"'
has "naming the standard class"       "$FORM" "Standard"
has "and the zero-rated class"        "$FORM" 'value="C"'
has "named, not just lettered"        "$FORM" "Zero"
has "with the full meaning on hover"  "$FORM" "exports, and a few goods"

# The rate is no longer typed per document: each line carries its class,
# so a single figure for the whole document would be ignored or wrong.
has "the rate box is gone"  "$FORM" 'type="hidden" id="vat_rate"'
has "and it says where it comes from" "$FORM" "Standard rate is"

# The browser has to work the total out the same way the server does, or
# the figure shown while typing disagrees with the one saved.
has "the rates are sent to the browser" "$FORM" '"rates"'
has "and the browser mirrors the arithmetic" \
   "$(cat "$ROOT/public/assets/js/app.js")" "SHANFIX_TAX_RATES"

# Both catalogue forms let the class be set on the thing itself, which is
# where it belongs — it is a fact about what is sold, not about one sale.
has "services carry a class"  "$(page "/services/$SVC_STD/edit")" 'name="tax_type"'
has "and a KRA code"          "$(page "/services/$SVC_STD/edit")" 'name="etims_code"'
eq  "which saved"             "$(q "SELECT etims_code FROM services WHERE id=$SVC_STD;")" "5059790800"

IID=$(q "SELECT id FROM inventory_items ORDER BY id LIMIT 1;")
if [ -n "$IID" ]; then
  has "stock items carry one too" "$(page "/inventory/$IID/edit")" 'name="tax_type"'
fi

echo ""
echo "=== 10. Tidy up ==="

$MYSQL -e "DELETE FROM documents WHERE title LIKE 'eTIMS suite%';
           DELETE FROM services WHERE code IN ('ETX-STD','ETX-ZERO');
           UPDATE settings SET setting_value='$RATE_B_WAS' WHERE setting_key='tax_rate_b';"

eq "the test documents are gone" \
   "$(q "SELECT COUNT(*) FROM documents WHERE title LIKE 'eTIMS suite%';")" "0"
eq "the standard rate is back" \
   "$(q "SELECT setting_value FROM settings WHERE setting_key='tax_rate_b';")" "$RATE_B_WAS"

report
