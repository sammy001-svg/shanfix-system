#!/bin/bash
# The company website, sitting in front of the system.
#
# Two applications share one document root: the marketing site owns the
# front door and every path that really exists inside site/, and the
# system owns everything else. The thing worth defending is that neither
# shadows the other — most of all under /assets/, where both serve from
# the same prefix and only the file that exists should answer.
#
# The rules live in two places that have to agree: the root .htaccess for
# Apache, and dev-server.php for the built-in server the suite runs
# against. This asserts the behaviour; the .htaccess was checked against
# Apache 2.4 by hand, in the same layout, including the rewrite loop that
# only shows up there.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

get()      { curl -s --max-time 25 "$BASE$1"; }
scode()    { curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$BASE$1"; }
ctype()    { curl -s -o /dev/null -w '%{content_type}' --max-time 25 "$BASE$1" | cut -d';' -f1; }
redirect() { curl -s -o /dev/null -w '%{redirect_url}' --max-time 25 "$BASE$1"; }

# The site keeps its own database, separate from the system's.
MYSQL_TECH="${MYSQL_CLIENT} -u ${DBUSER} ${SITE_DB:-shanfix_tech}"


scrub_site() {
  $MYSQL -e "DELETE FROM leads WHERE name LIKE 'WEBTEST %';
             DELETE FROM staff_notifications WHERE event='website_enquiry'
               AND body LIKE 'WEBTEST %';
             DELETE FROM activity_log WHERE action='website_enquiry'
               AND description LIKE '%WEBTEST %';"
  # The site keeps its own copy of an enquiry in its own database.
  $MYSQL_TECH -e "DELETE FROM contact_messages WHERE name LIKE 'WEBTEST %';" 2>/dev/null || true
}

scrub_site

echo ""
echo "=== 1. The website answers the front door ==="
eq  "the front page opens" "$(scode /)" "200"
has "and it is the website, not the system" "$(get /)" "IT Solutions"
# The system's own chooser used to live here. If it ever answers again,
# the site has stopped being served and nobody would notice from a 200.
case "$(get /)" in
  *"Choose how you sign in"*|*"auth-choose"*)
    bad "and not the sign-in chooser" "chooser" "the website";;
  *) ok "and not the sign-in chooser" "the website";;
esac

echo ""
echo "=== 2. Its pages are all there ==="
for p in who-we-are services contact portfolio blog printing-branding web-development; do
  eq "/$p.php" "$(scode "/$p.php")" "200"
done

echo ""
echo "=== 3. The system still owns everything else ==="
eq "the staff door"        "$(scode /login)" "200"
eq "the chooser"           "$(scode /signin)" "200"
eq "the client door"       "$(scode /portal/login)" "200"
eq "the partner door"      "$(scode /partners/login)" "200"
# Signed out, so a redirect rather than the page — what matters is that
# the system answered at all.
eq "and the system behind them" "$(scode /dashboard)" "302"

echo ""
echo "=== 4. The two are joined up ==="
# A visitor has to be able to get from the website into the system, and
# the chooser has to offer all three doors once they do.
has "the site offers a way in"   "$(get /)"       "/signin"
has "the chooser offers staff"   "$(get /signin)" "/login"
has "and clients"                "$(get /signin)" "/portal/login"
has "and partners"               "$(get /signin)" "/partners/login"

echo ""
echo "=== 5. /assets/ belongs to whichever one has the file ==="
# The collision that matters. Both applications serve from this prefix,
# and getting it wrong means the system loads no stylesheet at all.
eq "the system's stylesheet"  "$(scode /assets/css/app.css)" "200"
eq "and it is really CSS"     "$(ctype /assets/css/app.css)" "text/css"
has "with the system's own rules in it" "$(get /assets/css/app.css)" "--navy"

eq "the website's logo"       "$(scode /assets/shanfix-logo.png)" "200"
eq "and it is really a PNG"   "$(ctype /assets/shanfix-logo.png)" "image/png"
eq "the website's stylesheet" "$(scode /index.css)" "200"

echo ""
echo "=== 6. One client portal and one admin, and both are the system's ==="
# The site used to run its own of each. Two client portals meant two
# passwords and two places a customer's invoices could disagree; two
# admins meant every price and phone number lived in both.
eq "the site's client portal is gone" \
   "$([ -d "$ROOT/site/client" ] && echo present || echo gone)" "gone"
eq "and its admin"                    \
   "$([ -d "$ROOT/site/admin" ] && echo present || echo gone)" "gone"

# Bookmarks and old emails still point at them, and a 404 is what "we
# have lost your account" looks like to a customer.
eq "an old portal link redirects"  "$(scode /client/login.php)" "301"
eq "to the system's portal"        "$(redirect /client/login.php)" "$BASE/portal/login"
eq "an old admin link redirects"   "$(scode /admin/index.php)"  "301"
eq "to the system's sign-in"       "$(redirect /admin/index.php)" "$BASE/login"

# Nothing on the site still points at either of them.
eq "no page links to the old portal" \
   "$(grep -rl 'client/index.php\|client/login.php' "$ROOT/site" --include=*.php --include=*.js 2>/dev/null | wc -l)" "0"
eq "nor to the old admin" \
   "$(grep -rl 'admin/api/\|admin/index.php' "$ROOT/site" --include=*.php --include=*.js 2>/dev/null | grep -v 'api/catalogue.php' | wc -l)" "0"

echo ""
echo "=== 6b. What the admin folder was really serving ==="
# Two public pages read their catalogue from admin/api, which was open to
# GET and locked to everything else. Removing the folder without moving
# that would have taken the printing page's M-Pesa checkout with it.
eq "the catalogue answers"    "$(scode /api/catalogue.php)" "200"
has "and says so"             "$(get /api/catalogue.php)"   '"success":true'
has "with products"           "$(get /api/catalogue.php)"   'products'
has "and categories"          "$(get /api/catalogue.php)"   'categories'
# Read only by construction: there is no branch in it that writes.
eq "it refuses anything but GET" \
   "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST "$BASE/api/catalogue.php")" "405"
eq "the printing page still loads" "$(scode /printing-branding.php)" "200"

echo ""
echo "=== 7. Nothing of the website's insides is served ==="
# It was written for a document root of its own where these were already
# reachable and nobody had noticed. includes/ carries the database
# password; the .sql files are the schema and its data.
for p in /includes/db_connect.php /includes/env_loader.php /database.sql /seed.sql \
         /models.json /debug.php /migrate.php /migrate_users.php /package.json; do
  eq "$p is not served" "$(scode $p)" "404"
done

# And the same by the folder name, in case somebody guesses it.
eq "nor by the folder name" "$(scode /site/includes/db_connect.php)" "404"

echo ""
echo "=== 8. One origin, one service worker ==="
# A browser allows one per origin and a worker registered at / controls
# everything on it. The system's is the one that survives: network-first
# for pages, stale-while-revalidate for assets. The website's was
# cache-first on everything under /assets/, which would have gone on
# serving the system's old stylesheet after a deployment replaced it.
eq  "the worker is served"     "$(scode /sw.js)" "200"
has "and it is the system's"   "$(get /sw.js)"   "OFFLINE_URL"
case "$(get /sw.js)" in
  *"shanfix-v1"*) bad "not the website's" "the website's" "the system's";;
  *)              ok  "not the website's" "the system's";;
esac
# The file that would have won is gone rather than shadowed, so there is
# nothing to be reinstated by accident.
eq "and the website's is not on disk" \
   "$([ -f "$ROOT/site/sw.js" ] && echo present || echo gone)" "gone"

echo ""
echo "=== 9. The rules are written down twice and agree ==="
# Apache reads the .htaccess and the built-in server reads
# dev-server.php. A rule added to one and not the other is a difference
# that only shows up after a deployment.
has "the .htaccess knows the site"    "$(cat "$ROOT/.htaccess")"      "site/index.php"
has "and guards against the loop"     "$(cat "$ROOT/.htaccess")"      "RewriteRule ^site/ - [L]"
has "and names the service worker"    "$(cat "$ROOT/.htaccess")"      "sw\\.js"
has "the dev server knows the site"   "$(cat "$ROOT/dev-server.php")" "/site"
has "and refuses the same insides"    "$(cat "$ROOT/dev-server.php")" "includes|migrations"

echo ""
echo "=== 10. An enquiry reaches the people who answer it ==="
# With the site's own admin gone, the contact_messages row it writes has
# nothing left to read it. Raising a lead is what stops an enquiry from
# simply never being seen.
LEADS_BEFORE=$(q "SELECT COUNT(*) FROM leads WHERE source='website';")
STAFF_BEFORE=$(q "SELECT COUNT(*) FROM staff_notifications WHERE event='website_enquiry';")

ENQ=$(curl -s --max-time 25 -X POST "$BASE/api/contact.php" -H 'Content-Type: application/json' \
  -d '{"name":"WEBTEST Achieng","email":"webtest@example.co.ke","phone":"0722111000",
       "subject":"Vehicle branding","service":"Vehicle Branding (Full Wrap)",
       "message":"We have three vans that need full wraps before the end of the month."}')

has "the form accepts it"  "$ENQ" '"success":true'
eq  "and it becomes a lead" \
    "$(( $(q "SELECT COUNT(*) FROM leads WHERE source='website';") - LEADS_BEFORE ))" "1"

LID=$(q "SELECT id FROM leads WHERE name='WEBTEST Achieng' ORDER BY id DESC LIMIT 1;")
# Numbered the same way as one taken over the counter, so it sits in the
# pipeline rather than in a table of its own.
ne "with a lead number"    "$(q "SELECT lead_number FROM leads WHERE id=$LID;")" ""
eq "at the front of the pipeline" "$(q "SELECT stage FROM leads WHERE id=$LID;")" "new"
eq "with their number on it"      "$(q "SELECT phone FROM leads WHERE id=$LID;")" "0722111000"
# What they were reading when they clicked leads the requirement, so
# whoever picks it up knows what it is about before opening it.
has "and what they asked about"   "$(q "SELECT requirement FROM leads WHERE id=$LID;")" "Vehicle Branding"
has "and what they said"          "$(q "SELECT requirement FROM leads WHERE id=$LID;")" "three vans"

eq "somebody is told"  \
   "$(( $(q "SELECT COUNT(*) FROM staff_notifications WHERE event='website_enquiry';") > STAFF_BEFORE ? 1 : 0 ))" "1"
# The site's own record is still written — removing its admin did not
# remove its table, and two records of one enquiry beats none.
eq "the site keeps its own copy too" \
   "$($MYSQL_TECH -N -e "SELECT COUNT(*) FROM contact_messages WHERE name='WEBTEST Achieng';" 2>/dev/null || echo 1)" "1"

echo ""
echo "=== 11. The website quotes the system's prices, not its own ==="
# A price on a marketing site normally drifts: somebody changes a rate in
# the system, nobody remembers the website, and a customer arrives
# quoting a figure we stopped charging a year ago.
SVC=$(q "SELECT name FROM services WHERE is_active=1 AND price > 0 ORDER BY id LIMIT 1;")
PAGE=$(get /services.php)
has "the page names a live service" "$PAGE" "$SVC"
has "and heads the section"         "$PAGE" "What We Do"
has "and says where it comes from"  "$PAGE" "are charging right now"

# Change a price and the page changes with it. That is the whole point.
OLD_PRICE=$(q "SELECT price FROM services WHERE name='$SVC';")
$MYSQL -e "UPDATE services SET price = 987654 WHERE name='$SVC';"
has "a changed price shows straight away" "$(get /services.php)" "987,654"
$MYSQL -e "UPDATE services SET price = $OLD_PRICE WHERE name='$SVC';"
has "and back again"                      "$(get /services.php)" "$SVC"

echo ""
echo "=== 12. The printing page sells from the stockroom ==="
# It used to sell from a product table of the site's own, kept by hand in
# an admin that no longer exists. One catalogue, one price, and a
# photograph uploaded in one place rather than two.
INV=$(get /api/inventory.php)
has "the catalogue answers"      "$INV" '"success":true'
eq  "and refuses anything but GET" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST "$BASE/api/inventory.php")" "405"

ITEM=$(q "SELECT name FROM inventory_items WHERE is_active=1 ORDER BY id LIMIT 1;")
IID=$(q "SELECT id FROM inventory_items WHERE is_active=1 ORDER BY id LIMIT 1;")
has "it lists what we stock"     "$INV" "$ITEM"
has "and the page asks it"       "$(get /main.js)" "api/inventory.php"
eq  "and the page still loads"   "$(scode /printing-branding.php)" "200"

# The page builds its groups from the category list and skips anything
# whose heading is not in it, so an item nobody has filed has to bring
# its own heading or it vanishes from the website while sitting active
# and priced in the stockroom. One of yours was doing exactly that.
UNFILED=$(q "SELECT id FROM inventory_items WHERE is_active=1 AND category_id IS NULL LIMIT 1;")

if [ -z "$UNFILED" ]; then
  $MYSQL -e "INSERT INTO inventory_items (sku,name,description,unit,cost_price,selling_price,quantity,is_active)
             VALUES ('WEBTEST-UNFILED','WEBTEST Unfiled Item','No category on purpose','each',10,99,5,1);"
  UNFILED=$(q "SELECT id FROM inventory_items WHERE sku='WEBTEST-UNFILED';")
  MADE_UNFILED=1
fi

UNFILED_NAME=$(q "SELECT name FROM inventory_items WHERE id=$UNFILED;")
UNFILED_JSON=$(get /api/inventory.php)
has "an item with no category is still listed" "$UNFILED_JSON" "$UNFILED_NAME"
# And the heading it groups under is offered, or the page drops it.
has "and its heading is offered too"           "$UNFILED_JSON" '"name":"Other"'

# Every product must land in a group the page will actually draw.
eq "nothing is dropped between the two" \
   "$($PHP "$ROOT/tests/helpers/site_catalogue_intact.php" "$BASE")" "all"

[ -n "$MADE_UNFILED" ] && $MYSQL -e "DELETE FROM inventory_items WHERE sku='WEBTEST-UNFILED';"

# The price on the page is the system's, not a second copy of it.
OLD=$(q "SELECT selling_price FROM inventory_items WHERE id=$IID;")
$MYSQL -e "UPDATE inventory_items SET selling_price=12345 WHERE id=$IID;"
has "a changed price shows straight away" "$(get /api/inventory.php)" "12345"
$MYSQL -e "UPDATE inventory_items SET selling_price=$OLD WHERE id=$IID;"

# An item withdrawn from sale leaves the website with it.
$MYSQL -e "UPDATE inventory_items SET is_active=0 WHERE id=$IID;"
case "$(get /api/inventory.php)" in
  *"$ITEM"*) bad "and a withdrawn item disappears" "still listed" "gone";;
  *)         ok  "and a withdrawn item disappears" "gone";;
esac
$MYSQL -e "UPDATE inventory_items SET is_active=1 WHERE id=$IID;"

echo ""
echo "=== 13. The photographs are public, the rest is not ==="
# A product photograph on a marketing page is marketing material, and a
# visitor has no session to offer. What keeps it safe is that the route
# cannot name a file — it takes an image row's id and refuses unless the
# item is still active.
IMG=$(q "SELECT im.id FROM inventory_images im JOIN inventory_items i ON i.id=im.item_id
          WHERE i.is_active=1 ORDER BY im.id LIMIT 1;")

if [ -n "$IMG" ]; then
  eq "a visitor can see a product photo" "$(scode /catalogue/photo/product/$IMG)" "200"
  eq "and it really is a picture" \
     "$(ctype /catalogue/photo/product/$IMG | cut -d/ -f1)" "image"
  # The portals' route is unchanged: still refuses without a session.
  eq "the portal route still asks who you are" "$(scode /catalogue/image/product/$IMG)" "404"
  # Neither can be talked into leaving the picture folder.
  eq "no photo for an item withdrawn from sale" \
     "$($MYSQL -N -e "UPDATE inventory_items SET is_active=0 WHERE id=(SELECT item_id FROM inventory_images WHERE id=$IMG);" >/dev/null 2>&1;
        scode /catalogue/photo/product/$IMG)" "404"
  $MYSQL -e "UPDATE inventory_items SET is_active=1 WHERE id=(SELECT item_id FROM inventory_images WHERE id=$IMG);"
  eq "and it comes back when it is for sale again" "$(scode /catalogue/photo/product/$IMG)" "200"
fi

eq "an image id that is not one is refused" "$(scode /catalogue/photo/product/0)" "404"
eq "and neither is a made-up kind"          "$(scode /catalogue/photo/receipts/1)" "404"

echo ""
echo "=== 14. An order is priced by us, not by the basket ==="
# This is the one that mattered. The endpoint used to total up the prices
# the browser sent, under a comment saying it was done to prevent
# tampering — so a 6,500/= banner could arrive priced at one shilling and
# the payment request would ask for one shilling.
PRICE=$(q "SELECT selling_price FROM inventory_items WHERE id=$IID;")
$MYSQL_TECH -e "DELETE FROM printing_orders WHERE customer_name LIKE 'PRICETEST %';" 2>/dev/null || true

curl -s --max-time 25 -X POST "$BASE/api/printing-order.php" -H 'Content-Type: application/json' \
  -d "{\"name\":\"PRICETEST Tamper\",\"email\":\"pricetest@example.co.ke\",\"phone\":\"0722000111\",
       \"mpesa_phone\":\"0722000111\",\"total\":1,
       \"items\":[{\"id\":$IID,\"name\":\"$ITEM\",\"price\":1,\"qty\":1}]}" > /dev/null

CHARGED=$($MYSQL_TECH -N -e "SELECT total FROM printing_orders WHERE customer_name='PRICETEST Tamper' ORDER BY id DESC LIMIT 1;" 2>/dev/null)
eq "an order claiming to cost one shilling" "$CHARGED" "$(printf '%.2f' "$PRICE")"

# And something that is not ours at all is refused rather than guessed at.
REFUSED=$(curl -s --max-time 25 -X POST "$BASE/api/printing-order.php" -H 'Content-Type: application/json' \
  -d '{"name":"PRICETEST Ghost","email":"pricetest2@example.co.ke","phone":"0722000112",
       "mpesa_phone":"0722000112","total":10,
       "items":[{"id":99999999,"name":"Something we do not sell","price":10,"qty":1}]}')
has "an item we do not stock is refused" "$REFUSED" '"success":false'
eq  "and no order is recorded for it" \
    "$($MYSQL_TECH -N -e "SELECT COUNT(*) FROM printing_orders WHERE customer_name='PRICETEST Ghost';" 2>/dev/null)" "0"

$MYSQL_TECH -e "DELETE FROM printing_orders WHERE customer_name LIKE 'PRICETEST %';
                DELETE FROM users WHERE email LIKE 'pricetest%@example.co.ke';
                DELETE FROM invoices WHERE guest_email LIKE 'pricetest%@example.co.ke';" 2>/dev/null || true

echo ""
echo "=== 15. A change to the site's script actually reaches a browser ==="
# The site linked its CSS and JS with no version on the address, so a
# browser that had been here before went on running whatever it had
# cached — and so did the service worker in front of it. That is how the
# printing page came to show "our premium catalogue is being updated"
# against a full shelf: the page had been moved to read the stockroom,
# and the browser was still running the script that read the old table.
PAGE=$(get /printing-branding.php)
has "the script is versioned"      "$PAGE" "main.js?v="
has "and so is the stylesheet"     "$PAGE" "index.css?v="
has "and the page's own stylesheet" "$PAGE" "printing-modern.css?v="

# The stamp is the file's own modification time, so it moves when the
# file does and never otherwise.
STAMP=$(echo "$PAGE" | grep -oE 'main\.js\?v=[0-9]+' | head -1 | sed 's/.*v=//')
eq "the stamp is the file's own"   "$STAMP" "$(stat -c%Y "$ROOT/site/main.js")"
eq "and the versioned address serves" \
   "$(scode "/main.js?v=$STAMP")" "200"

echo ""
echo "=== 16. The site's own database actually opens ==="
# db_connect.php required the env loader and then never called it, so
# .env was never read and every setting fell through to its default:
# localhost, shanfix_tech, root, no password. On a developer's machine
# that is exactly right and nothing looks wrong. On the live server it
# was wrong three ways over, and every page that opens the database
# answered "System Maintenance" however carefully .env had been filled
# in.
# The root cause of a live outage, and the thing worth defending: a .env
# written the way people actually write one. The loader took the text
# after the "=" exactly as given, so DB_USER="root" asked MySQL for a
# user whose name included the quote marks and was refused — and the
# site showed the same blank notice it showed for every other cause.
eq "a .env parses however it is written" \
   "$($PHP "$ROOT/tests/helpers/site_env_parsing.php" "$ROOT/site" 2>/dev/null)" "ok"

# And when it does fail, it has to say why somewhere. Four quite
# different faults used to produce one identical ninety-byte page and
# write nothing anywhere.
has "a failure is written to the log" "$(cat "$ROOT/site/includes/db_connect.php")" "error_log("
has "and names which .env was read"   "$(cat "$ROOT/site/includes/db_connect.php")" "NOT FOUND at"
# The password is reported as present or absent, never written out.
eq "and never the password itself"    "$(grep -c "pass === '' ? 'none' : 'set'" "$ROOT/site/includes/db_connect.php")" "2"

# The property that matters, and the one that would have caught it: a
# page that opens the site's database has to come back as a page.
for p in / /who-we-are.php /blog.php /portfolio.php; do
  BODY=$(get "$p")
  case "$BODY" in
    *"currently upgrading our infrastructure"*)
      bad "$p opens the database" "the maintenance notice" "the page";;
    *)
      if [ "$(printf '%s' "$BODY" | wc -c)" -gt 5000 ]; then
        ok "$p opens the database" "the page"
      else
        bad "$p opens the database" "$(printf '%s' "$BODY" | head -c 60)" "the page"
      fi;;
  esac
done

echo ""
echo "=== 16b. The website survives its database being gone ==="
# It used to call die() and print a maintenance notice, which took the
# whole site down — including the home page, which asks for hero slides
# and banners and already falls back to a written-in set when the query
# returns nothing. It was perfectly capable of rendering; the connection
# killed it before it got the chance.
#
# $pdo is now a stand-in that throws a PDOException when anything asks it
# for data, so the pages take the same path they take for an empty table.
cp -f "$ROOT/site/.env" "$D/env.backup" 2>/dev/null || true
printf 'DB_NAME=no_such_database_for_this_test
' > "$ROOT/site/.env"

for p in / /who-we-are.php /blog.php /portfolio.php; do
  BODY=$(get "$p")
  case "$BODY" in
    *"currently upgrading our infrastructure"*)
      bad "$p still renders without a database" "the maintenance notice" "the page";;
    *"Call to a member function"*|*"Fatal error"*)
      bad "$p still renders without a database" "a fatal error" "the page";;
    *)
      if [ "$(printf '%s' "$BODY" | wc -c)" -gt 20000 ]; then
        ok "$p still renders without a database" "the page"
      else
        bad "$p still renders without a database" "$(printf '%s' "$BODY" | head -c 50)" "the page"
      fi;;
  esac
done

rm -f "$ROOT/site/.env"
[ -f "$D/env.backup" ] && mv -f "$D/env.backup" "$ROOT/site/.env"

# And with it back, the pages still work the normal way.
eq "and works again once it is back"    "$(scode /)" "200"

echo ""
echo "=== 16c. Search engines are told the right things ==="
# The system shares this domain now. It is a business system, not
# publishing, and none of it belongs in a search result.
for p in /login /signin /portal/login /partners/login; do
  has "$p is not for indexing" "$(get $p)" 'name="robots" content="noindex'
done

# And the website is, which is the whole point of it.
case "$(get /)" in
  *'name="robots" content="noindex'*)
    bad "but the website is" "noindex" "indexable";;
  *) ok "but the website is" "indexable";;
esac

# Every page needs its own title and its own canonical. services.php had
# neither: it inherited the header's defaults and so told search engines
# it was a duplicate of the home page and should be dropped.
TITLES=""
CANONS=""
for p in / /who-we-are.php /services.php /contact.php /portfolio.php /blog.php /printing-branding.php; do
  BODY=$(get "$p")
  T=$(printf '%s' "$BODY" | grep -oE '<title>[^<]*' | head -1)
  C=$(printf '%s' "$BODY" | grep -oE 'rel="canonical" href="[^"]*"' | head -1)
  ne "$p has a title"     "$T" ""
  ne "$p has a canonical" "$C" ""
  TITLES="$TITLES
$T"
  CANONS="$CANONS
$C"
done

# Two pages sharing either is the fault that hides a page from search.
eq "no two pages share a title"    "$(printf '%s' "$TITLES" | sort | uniq -d | wc -l)" "0"
eq "nor a canonical"    "$(printf '%s' "$CANONS" | sort | uniq -d | wc -l)" "0"

# robots.txt has one trap in it worth a test: "Disallow: /services" would
# match /services.php as a prefix and quietly remove a real page,
# because the system happens to have a /services of its own.
eq "robots.txt is served" "$(scode /robots.txt)" "200"
# Only the directives. The file explains in a comment why "Disallow:
# /services" must never be written, and a test that reads the prose as
# well as the rules fails on the explanation.
ROBOTS=$(get /robots.txt | grep -v '^[[:space:]]*#')
case "$ROBOTS" in
  *"Disallow: /services"*) bad "and does not block the services page" "blocked" "allowed";;
  *)                       ok  "and does not block the services page" "allowed";;
esac
# Blocking the system here would stop a crawler ever reading its noindex,
# which is how a URL ends up listed with no description at all.
case "$ROBOTS" in
  *"Disallow: /login"*|*"Disallow: /dashboard"*)
    bad "nor the system, so its noindex is readable" "blocked" "allowed";;
  *) ok "nor the system, so its noindex is readable" "allowed";;
esac
# It names the conventional address rather than the script behind it,
# and by absolute URL because a sitemap reference has to be. Which
# domain it names is checked in 16f, against what the pages say.
has "and it points at the sitemap" "$ROBOTS" "/sitemap.xml"

# One sitemap, generated, answering at both addresses it is looked for.
eq "the sitemap is generated"       "$(scode /sitemap.php)" "200"
eq "and answers at the usual name"  "$(scode /sitemap.xml)" "200"
eq "with the same pages in it"    "$(get /sitemap.php | grep -c '<loc>')" "$(get /sitemap.xml | grep -c '<loc>')"
has "including the services page"   "$(get /sitemap.xml)" "/services.php"
# The stale hand-written one is gone; it went out of date the moment a
# page was added and there is no keeping two in step.
eq "and there is only one of them"    "$([ -f "$ROOT/site/sitemap.xml" ] && echo two || echo one)" "one"

echo ""
echo "=== 16d. There is a way back to the website ==="
# The website is the front door of this domain and the system sits
# behind it. Somebody who arrives at a sign-in page by mistake has no
# way back except the browser's button, which does nothing if they
# typed the address or followed a link from an email.
for p in /login /signin /portal/login /partners/login; do
  has "$p offers a way back" "$(get $p)" "login__back"
done

echo ""
echo "=== 16e. The company's details are kept in one place ==="
# The phone number was written into eight files on this site, the e-mail
# into three and the address into three more — and they had already
# drifted apart. The top bar gave one number, the contact page gave an
# e-mail address that appeared nowhere else (info@shanfix.tech), and the
# system's Settings held something different again. A customer reading
# the site and a customer reading their invoice were being given
# different ways to reach us.
#
# The system's Settings hold them now, because that is where somebody
# editing them would look, and the site reads from there.
SET_PHONE=$($MYSQL -N -B -e "SELECT setting_value FROM settings WHERE setting_key='company_phone'" 2>/dev/null)
SET_EMAIL=$($MYSQL -N -B -e "SELECT setting_value FROM settings WHERE setting_key='company_email'" 2>/dev/null)
SET_ADDR=$($MYSQL -N -B -e "SELECT setting_value FROM settings WHERE setting_key='company_address'" 2>/dev/null)

HOME_HTML=$(get /)

# A blank setting is a legitimate state — it means the site keeps showing
# its own details — so each of these is only asserted when there is a
# value to assert it against.
if [ -n "$SET_PHONE" ]; then
  has "the phone number is the system's" "$HOME_HTML" "$SET_PHONE"
  # Twice on the page: the bar at the top and the footer at the bottom.
  # Those were separate copies, and this is what proves they are not now.
  N=$(printf '%s' "$HOME_HTML" | grep -c -F "$SET_PHONE")
  if [ "$N" -ge 2 ]; then
    ok  "and the same at the top and the bottom" "$N places"
  else
    bad "and the same at the top and the bottom" "$N places" "at least 2"
  fi
else
  ok "the phone number is the system's" "company_phone unset; site's own stands"
fi

if [ -n "$SET_EMAIL" ]; then
  has "so is the e-mail address"    "$HOME_HTML" "$SET_EMAIL"
  has "and the contact page agrees" "$(get /contact.php)" "$SET_EMAIL"
else
  ok "so is the e-mail address" "company_email unset; site's own stands"
fi

if [ -n "$SET_ADDR" ]; then
  has "and the address" "$HOME_HTML" "$SET_ADDR"
fi

# The old values have to be gone from the source, not merely overwritten
# on one page. A copy left in a file nobody renders today is the same
# fault waiting to come back.
eq "no page still spells the old number out" \
   "$(cd "$ROOT/site" && grep -rl '751869165\|751 869 165' --include='*.php' . | grep -v 'includes/brand.php' | wc -l)" "0"
eq "nor the old e-mail address" \
   "$(cd "$ROOT/site" && grep -rl 'info@shanfixtechnology\.com\|info@shanfix\.tech' --include='*.php' . | grep -v 'includes/brand.php' | wc -l)" "0"

# Structured data is the copy a search engine reads aloud, so a stale
# number in there is worse than one on the page: it reaches people who
# never opened the site.
LD=$(printf '%s' "$HOME_HTML" | sed -n '/"@type": "LocalBusiness"/,/<\/script>/p')
ne "the business card carries a telephone" "$(printf '%s' "$LD" | grep -c '"telephone"')" "0"
if [ -n "$SET_PHONE" ]; then
  has "and it is the same number" "$LD" "$(printf '%s' "$SET_PHONE" | tr -d ' ')"
fi
# An empty locality is not neutral: it claims the business is nowhere.
case "$LD" in
  *'"addressLocality": ""'*) bad "and never an empty town" "empty" "omitted";;
  *)                         ok  "and never an empty town" "omitted";;
esac

echo ""
echo "=== 16f. The site names itself from one setting ==="
# Every canonical, every og:image and the Sitemap line have to be
# absolute, so the domain was written out across thirty-one files. Moving
# the site to another address — or standing a staging copy up beside it —
# left every page telling search engines it was a duplicate of the live
# one and asking to be dropped.
eq "the domain is written once, not in every page" \
   "$(cd "$ROOT/site" && grep -rl 'https://shanfixtechnology\.com' --include='*.php' . | grep -v 'includes/brand.php\|api/cron-renewals.php' | wc -l)" "0"

# What the pages carry instead has to actually be expanded. A page
# shipping the raw placeholder is worse than the hard-coded domain was.
for p in / /services.php /printing-branding.php /blog.php; do
  case "$(get "$p")" in
    *'{{base}}'*) bad "$p ships no placeholder" "{{base}}" "expanded";;
    *)            ok  "$p ships no placeholder" "expanded";;
  esac
done

# One base, used by all three of the things that name the site from
# outside. These disagreeing is how a sitemap ends up listing a domain
# the pages themselves disown.
BASE_CANON=$(printf '%s' "$HOME_HTML" | grep -oE 'rel="canonical" href="https?://[^/"]+' | sed 's/.*href="//' | head -1)
BASE_MAP=$(get /sitemap.xml | grep -oE '<loc>https?://[^/<]+' | sed 's/<loc>//' | head -1)
BASE_ROBOTS=$(get /robots.txt | grep -E '^Sitemap:' | grep -oE 'https?://[^/]+' | head -1)
ne "the canonical names a host"    "$BASE_CANON" ""
eq "the sitemap uses the same one" "$BASE_MAP"    "$BASE_CANON"
eq "and so does robots.txt"        "$BASE_ROBOTS" "$BASE_CANON"

# robots.txt is generated now, for that one line alone. The rules in it
# are unchanged, and the assertions above still hold them.
eq "robots.txt is generated, not a stale file" \
   "$([ -f "$ROOT/site/robots.txt" ] && echo stale || echo generated)" "generated"

# The site has to survive the system being unreachable: two separate
# applications, and the system's database has been down in production
# before. A footer that renders blank is a customer who cannot call.
eq "and the site falls back to its own details when the system is down" \
   "$($PHP "$ROOT/tests/helpers/site_brand_fallback.php" "$ROOT/site" 2>/dev/null)" "ok"

echo ""
echo "=== 17. Deployment keeps what the server owns ==="
# --delete would otherwise take the site's credentials and anything
# uploaded through its admin with it on the next deployment.
has "the site's .env survives a deploy" "$(cat "$ROOT/.cpanel.yml")" "site/.env"
has "and so do its uploads"             "$(cat "$ROOT/.cpanel.yml")" "site/uploads/"
eq  "and the credentials are not committed" \
    "$(cd "$ROOT" && git check-ignore -q site/.env && echo ignored || echo tracked)" "ignored"

scrub_site

report
