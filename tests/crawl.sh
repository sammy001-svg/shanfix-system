#!/bin/bash
# Walk every GET page the router knows about, as an administrator and as
# each role, and report anything that is not a page or a redirect.
#
# The list comes from routes.php rather than being typed out here, so a
# page added later is crawled without anybody remembering to add it.
#
# The point is the 500s. A permission refusal is a 302 and expected; a
# 500 is a page nobody can open, and those hide in the corners of a
# system this size until somebody walks into one.
BASE="http://127.0.0.1:8000"
MYSQL="/c/xampp/mysql/bin/mysql.exe -u root shanfix_test"
D="$(dirname "$0")"
q() { $MYSQL -N -e "$1"; }

signin() {
  JAR="$D/crawl_$1.txt"; rm -f "$JAR"
  local t
  t=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$BASE/login" --data "_token=$t&email=$1@shanfix.co.ke&password=$2"
}

# A real id per resource, so the {id} pages are exercised against
# something that exists rather than always taking the not-found branch.
id_for() {
  case "$1" in
    /clients/*)         q "SELECT id FROM clients ORDER BY id LIMIT 1;" ;;
    /jobs/*)            q "SELECT id FROM jobs ORDER BY id LIMIT 1;" ;;
    /leads/*)           q "SELECT id FROM leads ORDER BY id LIMIT 1;" ;;
    /suppliers/*)       q "SELECT id FROM suppliers ORDER BY id LIMIT 1;" ;;
    /inventory/*)       q "SELECT id FROM inventory_items ORDER BY id LIMIT 1;" ;;
    /services/*)        q "SELECT id FROM services ORDER BY id LIMIT 1;" ;;
    /subscriptions/*)   q "SELECT id FROM subscriptions ORDER BY id LIMIT 1;" ;;
    /meetings/*)        q "SELECT id FROM meetings ORDER BY id LIMIT 1;" ;;
    /users/*)           q "SELECT id FROM users ORDER BY id LIMIT 1;" ;;
    /expenses/*)        q "SELECT id FROM expenses ORDER BY id LIMIT 1;" ;;
    /notifications/*)   q "SELECT id FROM notifications ORDER BY id LIMIT 1;" ;;
    /alerts/*)          q "SELECT id FROM staff_notifications ORDER BY id LIMIT 1;" ;;
    /chat/*)            q "SELECT id FROM chat_conversations ORDER BY id LIMIT 1;" ;;
    /artwork/*)         q "SELECT id FROM artwork_requests ORDER BY id LIMIT 1;" ;;
    /delivery-notes/*)  q "SELECT id FROM delivery_notes ORDER BY id LIMIT 1;" ;;
    /purchase-orders/*) q "SELECT id FROM purchase_orders ORDER BY id LIMIT 1;" ;;
    /sms-campaigns/*)   q "SELECT id FROM sms_campaigns ORDER BY id LIMIT 1;" ;;
    /quotations/*)      q "SELECT id FROM documents WHERE doc_type='quotation' ORDER BY id LIMIT 1;" ;;
    /invoices/*)        q "SELECT id FROM documents WHERE doc_type='invoice'   ORDER BY id LIMIT 1;" ;;
    /receipts/*)        q "SELECT id FROM documents WHERE doc_type='receipt'   ORDER BY id LIMIT 1;" ;;
    /proposals/*)       q "SELECT id FROM documents WHERE doc_type='proposal'  ORDER BY id LIMIT 1;" ;;
    /agreements/*)      q "SELECT id FROM documents WHERE doc_type='agreement' ORDER BY id LIMIT 1;" ;;
    *)                  echo "1" ;;
  esac
}

# Build the page list from the router. The five document types are
# expanded by hand because routes.php builds those paths in a loop.
build_pages() {
  php -r '
    $src = file_get_contents("c:/Shanfix System/routes.php");
    preg_match_all("/\\\$r->get\(\s*[\x27\"]([^\x27\"]+)[\x27\"]/", $src, $m);
    $out = [];
    foreach (array_unique($m[1]) as $p) {
        if (str_contains($p, "{\$path}")) {
            foreach (["proposals","quotations","invoices","receipts","agreements"] as $t) {
                $out[] = str_replace("{\$path}", $t, $p);
            }
            continue;
        }
        $out[] = $p;
    }
    sort($out);
    echo implode("\n", $out);
  '
}

# Pages that are not part of the signed-in application: public token
# links, assets, the service worker, the webhook endpoint. They are
# either exercised by their own suite or need a token we do not have.
skip() {
  case "$1" in
    *"{token}"*|*"{path*}"*|*"{size}"*) return 0 ;;
    /sw.js|/manifest.webmanifest|/offline|/offline/precache) return 0 ;;
    /webhooks/*|/brand/*|/files/*) return 0 ;;
    /login|/) return 0 ;;
    *) return 1 ;;
  esac
}

# One account per role, made here rather than borrowed from another
# suite, so the crawl covers every role whatever order things run in.
HASH=$(php -r 'echo password_hash("Role@2026", PASSWORD_DEFAULT);')
for r in manager finance sales production reception staff; do
  $MYSQL -e "DELETE FROM users WHERE email='$r@shanfix.co.ke';
             INSERT INTO users (name,email,password_hash,role,is_active)
             VALUES ('Crawl $r','$r@shanfix.co.ke','$HASH','$r',1);
             INSERT INTO user_roles (user_id, role)
             SELECT id,'$r' FROM users WHERE email='$r@shanfix.co.ke';"
done
mapfile -t RAW < <(build_pages)
PAGES=()
for p in "${RAW[@]}"; do
  skip "$p" && continue
  if [[ "$p" == *"{id}"* ]]; then
    v=$(id_for "$p")
    [ -z "$v" ] && continue          # nothing of that kind exists to look at
    p=${p//\{id\}/$v}
  fi
  if [[ "$p" == *"{userId}"* ]]; then
    p=${p//\{userId\}/$(q "SELECT id FROM users ORDER BY id LIMIT 1;")}
  fi
  PAGES+=("$p")
done

echo ""
echo "Crawling ${#PAGES[@]} pages per role."
echo ""

TOTAL=0; ERRORS=0

crawl() {
  local who="$1" pass="$2"
  signin "$who" "$pass"
  local ok=0 redir=0 err=0 notfound=0
  for p in "${PAGES[@]}"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE$p")
    TOTAL=$((TOTAL+1))
    case "$code" in
      200|304) ok=$((ok+1)) ;;
      302|303) redir=$((redir+1)) ;;
      403)     redir=$((redir+1)) ;;
      404)     notfound=$((notfound+1)); printf "    \033[33m404\033[0m %-42s (%s)\n" "$p" "$who" ;;
      *)       err=$((err+1)); ERRORS=$((ERRORS+1)); printf "    \033[31m%s\033[0m %-42s (%s)\n" "$code" "$p" "$who" ;;
    esac
  done
  printf "  %-12s %3d ok  %3d refused/redirected  %2d not-found  \033[31m%d broken\033[0m\n" \
         "$who" "$ok" "$redir" "$notfound" "$err"
}

crawl admin Shanfix@2026
for r in manager finance sales production reception staff; do
  if [ -n "$(q "SELECT id FROM users WHERE email='$r@shanfix.co.ke';")" ]; then
    crawl "$r" Role@2026
  fi
done

$MYSQL -e "DELETE FROM users WHERE email IN ('manager@shanfix.co.ke','finance@shanfix.co.ke',
           'sales@shanfix.co.ke','production@shanfix.co.ke','reception@shanfix.co.ke','staff@shanfix.co.ke');"
$MYSQL -e "DELETE FROM activity_log WHERE action='login_failed';"
rm -f "$D"/crawl_*.txt
echo ""
echo "==================================================="
printf "  %d requests, \033[31m%d broken\033[0m\n" "$TOTAL" "$ERRORS"
echo "==================================================="
[ "$ERRORS" -eq 0 ]
