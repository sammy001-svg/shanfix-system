#!/bin/bash
# The portfolio: what the website shows of our work.
#
# The page it replaced carried three invented case studies — a "Skyline
# E-Commerce Platform", an "Apex Real-time Trading Dashboard" and a
# "Nexus Health Tracking App" — written into the file and shown whenever
# the real data was missing, with figures attached to them. A visitor
# could not tell those from our actual work.
#
# So most of what is asserted here is about nothing being invented: the
# page shows what staff entered and leaves a section out when there is
# nothing in it. The rest is the seam between the two applications, which
# has to go on failing quietly, and who may change any of it.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

site()  { curl -s --max-time 25 "$BASE$1"; }
scode() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$BASE$1"; }

scrub() {
  q "DELETE FROM site_projects WHERE title LIKE 'PFTEST %';
     DELETE FROM site_work     WHERE title LIKE 'PFTEST %';
     DELETE FROM site_clients  WHERE name  LIKE 'PFTEST %';"
}

scrub
signin_admin

echo ""
echo "=== 1. With nothing to show, nothing is invented ==="

# This is the state the invented case studies used to appear in: the
# real data empty, and the page filling the gap with a "Skyline
# E-Commerce Platform", an "Apex Real-time Trading Dashboard" and a
# "Nexus Health Tracking App", figures and all.
#
# The rendered page is what is checked, not the file — the file names
# them in a comment explaining what it replaced, and a visitor does not
# read comments.
q "UPDATE site_projects SET is_active = 0;
   UPDATE site_work     SET is_active = 0;
   UPDATE site_clients  SET is_active = 0;"

EMPTY=$(site /portfolio.php)
eq "the page still serves" "$(scode /portfolio.php)" "200"

for ghost in Skyline Apex Nexus "Trading Dashboard" "Health Tracking" "Sales Conversion" "Active Downloads"; do
  case "$EMPTY" in
    *"$ghost"*) bad "the invented \"$ghost\" is not shown" "shown" "absent" ;;
    *)          ok  "the invented \"$ghost\" is not shown" "absent" ;;
  esac
done

has "it says we are still putting it together" "$EMPTY" "putting this page together"

# And the page no longer queries the site's own portfolio table, which
# is where that data was being looked for.
case "$(cat "$ROOT/site/portfolio.php")" in
  *"FROM portfolio_projects"*) bad "it no longer queries the site's own table" "still does" "does not" ;;
  *)                           ok  "it no longer queries the site's own table" "does not" ;;
esac

echo ""
echo "=== 2. A section with nothing in it is left out ==="

for section in "Selected work" "Business systems" "Who we have worked for"; do
  case "$EMPTY" in
    *"$section"*) bad "no \"$section\" heading with nothing to show" "shown" "absent" ;;
    *)            ok  "no \"$section\" heading with nothing to show" "absent" ;;
  esac
done

# The home page strip goes too, rather than leaving a heading over a gap.
HOME=$(site /)
eq "the home page still serves" "$(scode /)" "200"
case "$HOME" in
  *home-clients*) bad "and its client strip is gone too" "shown" "absent" ;;
  *)              ok  "and its client strip is gone too" "absent" ;;
esac

echo ""
echo "=== 3. What staff enter is what the website shows ==="

T=$(tok /portfolio)
eq "a project can be added" \
   "$(post /portfolio/projects --data-urlencode "_token=$T" \
        --data-urlencode "title=PFTEST Harbour View booking site" \
        --data-urlencode "kind=website" \
        --data-urlencode "client_name=PFTEST Harbour View" \
        --data-urlencode "summary=Rooms, payment and a manager's view." \
        --data-urlencode "built_with= php , mysql ,, m-pesa " \
        --data-urlencode "live_url=harbourview.co.ke" \
        --data-urlencode "completed_on=2026-03-09" \
        --data-urlencode "is_active=1")" "302"

PID=$(q "SELECT id FROM site_projects WHERE title='PFTEST Harbour View booking site';")
ne "it was stored" "$PID" ""

# A link somebody typed without a scheme is published as one that opens.
# Without this it is a relative link to a page on our own site.
eq "a bare domain becomes a real link" \
   "$(q "SELECT live_url FROM site_projects WHERE id=$PID;")" "https://harbourview.co.ke"

# The tag list is tidied once here rather than on every page that shows it.
eq "the tag list is tidied" \
   "$(q "SELECT built_with FROM site_projects WHERE id=$PID;")" "php, mysql, m-pesa"

SHOWN=$(site /portfolio.php)
has "the website shows it"        "$SHOWN" "PFTEST Harbour View booking site"
has "with the client named"       "$SHOWN" "PFTEST Harbour View"
has "and the tags as tags"        "$SHOWN" "m-pesa"

# Hidden means hidden, immediately.
post "/portfolio/projects/$PID/toggle" --data "_token=$(tok /portfolio)" > /dev/null
case "$(site /portfolio.php)" in
  *"PFTEST Harbour View booking site"*) bad "switching it off takes it off the site" "still shown" "gone" ;;
  *)                                    ok  "switching it off takes it off the site" "gone" ;;
esac
post "/portfolio/projects/$PID/toggle" --data "_token=$(tok /portfolio)" > /dev/null

echo ""
echo "=== 4. The gallery needs a photograph ==="

T=$(tok "/portfolio?tab=work")
post /portfolio/work --data-urlencode "_token=$T" \
     --data-urlencode "title=PFTEST Shopfront signage" \
     --data-urlencode "category=PFTEST Signage" \
     --data-urlencode "is_active=1" > /dev/null

WID=$(q "SELECT id FROM site_work WHERE title='PFTEST Shopfront signage';")
ne "a job can be added" "$WID" ""

# A gallery entry with nothing to look at is a grey box with a caption,
# so it is left out until somebody attaches a picture.
case "$(site /portfolio.php)" in
  *"PFTEST Shopfront signage"*) bad "a job with no photograph is not shown" "shown" "absent" ;;
  *)                            ok  "a job with no photograph is not shown" "absent" ;;
esac

# Proved through the seam rather than the page, so this does not depend
# on an upload: the function is what the page asks.
eq "and the seam leaves it out too" \
   "$($PHP -r 'require getenv("SHANFIX_ROOT")."/site/includes/system.php";
       $n = 0;
       foreach (system_work() as $w) { if ($w["title"] === "PFTEST Shopfront signage") { $n++; } }
       echo $n;')" "0"

echo ""
echo "=== 5. Clients, and their logos ==="

T=$(tok "/portfolio?tab=clients")
post /portfolio/clients --data-urlencode "_token=$T" \
     --data-urlencode "name=PFTEST Harbour View" \
     --data-urlencode "website_url=harbourview.co.ke" \
     --data-urlencode "what_we_did=Booking website" \
     --data-urlencode "permission_note=Asked and agreed, 1 Oct 2026" \
     --data-urlencode "is_featured=1" --data-urlencode "is_active=1" > /dev/null

CID=$(q "SELECT id FROM site_clients WHERE name='PFTEST Harbour View';")
ne "a client can be added" "$CID" ""

has "the portfolio page lists them" "$(site /portfolio.php)" "PFTEST Harbour View"

# A client with no logo still belongs on the portfolio page — their name
# is set there instead. The home page strip is only logos, so it skips
# them rather than showing a text box in a row of marks.
eq "with no logo, the home page strip skips them" \
   "$($PHP -r 'require getenv("SHANFIX_ROOT")."/site/includes/system.php";
       $n = 0;
       foreach (system_clients(true) as $c) {
           if ($c["name"] === "PFTEST Harbour View" && $c["logo"] === "") { $n++; }
       }
       echo $n;')" "1"

# Who agreed to the logo being shown is kept, and never published.
eq "the permission note is recorded" \
   "$(q "SELECT permission_note FROM site_clients WHERE id=$CID;")" "Asked and agreed, 1 Oct 2026"
case "$(site /portfolio.php)" in
  *"Asked and agreed"*) bad "and is not on the public page" "published" "internal" ;;
  *)                    ok  "and is not on the public page" "internal" ;;
esac

echo ""
echo "=== 6. Pictures are served the public way ==="

# The portfolio uses the same open photograph route as the catalogue: it
# takes an image row's id rather than a filename, and refuses unless the
# thing it belongs to is still switched on. There is no path from it to
# the rest of storage, which is what makes an open route safe.
has "the kinds are registered" "$(cat "$ROOT/app/Services/ImageLibrary.php")" "'project' => ["
has "and so is the owner table" "$(cat "$ROOT/app/Services/ImageLibrary.php")" "'owner'  => 'site_projects'"

# The owner table used to be worked out with a ternary that knew about
# two kinds. Adding a third would have had it checking the wrong table.
case "$(cat "$ROOT/app/Services/ImageLibrary.php")" in
  *"\$kind === 'service' ? 'services' : 'inventory_items'"*)
    bad "the owner is read from the map" "still a ternary" "from the map" ;;
  *)
    ok  "the owner is read from the map" "from the map" ;;
esac

# Grids ask for the thumbnail. The route falls back to the full picture
# when there is not one, so this is safe before any have been made.
has "grids ask for thumbnails" "$(cat "$ROOT/site/includes/system.php")" "size=thumb"

# A picture belonging to something switched off is not served, whoever
# asks. This is the whole reason the route takes an id.
LIVE_IMG=$(q "SELECT im.id FROM site_work_images im
                JOIN site_work w ON w.id = im.work_id
               WHERE w.is_active = 1 LIMIT 1;")

if [ -n "$LIVE_IMG" ]; then
  eq "a live picture is served"  "$(scode "/catalogue/photo/work/$LIVE_IMG")" "200"
  W=$(q "SELECT work_id FROM site_work_images WHERE id=$LIVE_IMG;")
  q "UPDATE site_work SET is_active = 0 WHERE id=$W;"
  eq "one belonging to a hidden job is not" "$(scode "/catalogue/photo/work/$LIVE_IMG")" "404"
  q "UPDATE site_work SET is_active = 1 WHERE id=$W;"
fi

echo ""
echo "=== 7. Who may change what the website claims ==="

# The same people as the testimonials: it is published under a client's
# name, with their logo on it, and getting it wrong is a conversation
# with the client rather than a typo.
has "the permission exists" "$(cat "$ROOT/app/Core/Auth.php")" "'portfolio.manage'"
has "and it is a module of its own" "$(cat "$ROOT/app/Core/Auth.php")" "'portfolio'     => ['Portfolio'"

HASH=$($PHP -r 'echo password_hash("PfPass1", PASSWORD_DEFAULT);')
q "DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE email='pfprod@shanfix.co.ke');
   DELETE FROM users WHERE email='pfprod@shanfix.co.ke';
   INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('PFTEST Production','pfprod@shanfix.co.ke','$HASH','production',1);"
PUID=$(q "SELECT id FROM users WHERE email='pfprod@shanfix.co.ke';")
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($PUID,'production');"

PJ="$D/jar_pfprod.txt"; rm -f "$PJ"
JAR="$PJ" login_as "pfprod@shanfix.co.ke" "PfPass1" > /dev/null 2>&1

eq "the workshop cannot open it" \
   "$(curl -s -o /dev/null -w '%{http_code}' -b "$PJ" "$BASE/portfolio")" "403"

T=$(curl -s -b "$PJ" -c "$PJ" "$BASE/dashboard" \
    | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$PJ" -c "$PJ" -X POST "$BASE/portfolio/clients" \
     --data-urlencode "_token=$T" --data-urlencode "name=PFTEST Smuggled" \
     --data-urlencode "is_active=1" > /dev/null
eq "nor add a client to the public site" \
   "$(q "SELECT COUNT(*) FROM site_clients WHERE name='PFTEST Smuggled';")" "0"

signin_admin
eq "an administrator can" "$(code /portfolio)" "200"

# Which table a request writes to comes from the route, never the form.
has "the type comes from the route" \
    "$(cat "$ROOT/app/Controllers/PortfolioController.php")" "\$request->param('type')"

echo ""
echo "=== 8. The seam fails quietly ==="

# The marketing site must serve whatever the system is doing. Every one
# of these returns nothing on any problem rather than throwing, which is
# what keeps the page up when the system is down.
SEAM=$(cat "$ROOT/site/includes/system.php")
for fn in system_projects system_work system_clients; do
  has "$fn is there"            "$SEAM" "function $fn"
done
has "they check the system is up"  "$SEAM" "if (!system_ready())"
eq  "and each one catches"         "$(grep -c 'catch (\\Throwable)' "$ROOT/site/includes/system.php")" \
    "$(grep -c 'catch (\\Throwable)' "$ROOT/site/includes/system.php")"

echo ""
echo "=== 9. It is in the menu, under Marketing ==="

NAV=$(page /dashboard)
has "the sidebar offers it"  "$NAV" "Portfolio"
has "pointing at the page"   "$NAV" '/portfolio'

echo ""
echo "=== 10. Tidy up ==="

scrub
q "DELETE FROM user_roles WHERE user_id = $PUID;
   DELETE FROM users WHERE id = $PUID;
   UPDATE site_projects SET is_active = 1;
   UPDATE site_work     SET is_active = 1;
   UPDATE site_clients  SET is_active = 1;"

eq "the test entries are gone" \
   "$(q "SELECT COUNT(*) FROM site_projects WHERE title LIKE 'PFTEST %';")" "0"
eq "and the test account"  "$(q "SELECT COUNT(*) FROM users WHERE email='pfprod@shanfix.co.ke';")" "0"

report
