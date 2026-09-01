#!/bin/bash
# Getting the code onto the server, and answering at the right address.
#
# Nothing here touches the database. It covers the three things that have
# actually gone wrong in deployment, none of which announced themselves:
#
#   - every link on the site grew a /public nobody asked for, because
#     SCRIPT_NAME named a folder the visitor's URL never mentioned;
#   - the rewrite that fixed the address swallowed upgrade.php and the
#     certificate renewal check with it, so database changes could not be
#     applied and the certificate would have expired with no warning;
#   - the deployment file was set to copy a built package over a folder
#     that is already the repository, which would have deleted it.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

HT=$(cat "$SHANFIX_ROOT/.htaccess")
YML=$(cat "$SHANFIX_ROOT/.cpanel.yml")

# base_path() memoises within a request, so each case needs its own
# process. Neither value carries its leading slash: Git Bash rewrites
# anything argument- or path-shaped on its way to a native php.exe, and
# would turn "/erp/index.php" into a folder under its own installation.
cat > "$D/base_path_case.php" <<'PHPEOF'
<?php
define('APP_PATH', getenv('SHANFIX_ROOT'));
require getenv('SHANFIX_ROOT') . '/app/Core/Config.php';
$_SERVER['SCRIPT_NAME'] = '/' . getenv('BP_SCRIPT');
$_SERVER['REQUEST_URI'] = '/' . getenv('BP_URI');
require getenv('SHANFIX_ROOT') . '/app/Core/helpers.php';
echo base_path();
PHPEOF

# bp <label> <SCRIPT_NAME> <REQUEST_URI> <expected>   (both without the leading /)
bp() {
  eq "$1" "$(BP_SCRIPT="$2" BP_URI="$3" "$PHP" "$D/base_path_case.php" 2>&1)" "$4"
}

echo ""
echo "=== 1. Links point where the visitor actually is ==="

# The plain arrangements, where SCRIPT_NAME can be taken at its word.
bp "at the domain root"         "index.php"     "dashboard"     ""
bp "the front page of it"       "index.php"     ""              ""
bp "installed in a sub-folder"  "erp/index.php" "erp/dashboard" "/erp"
bp "the front page of that"     "erp/index.php" "erp"           "/erp"

# The arrangement this system is deployed under: a rewrite hands the
# domain root into public/, so SCRIPT_NAME says /public/index.php while
# the visitor is at /dashboard. Taking that at face value is what made
# the front page bounce to /public/dashboard.
bp "a rewrite maps the root onto public" "public/index.php"     "dashboard"       ""
bp "including its front page"            "public/index.php"     ""                ""
bp "and paths further in"                "public/index.php"     "clients/12/edit" ""
bp "a sub-folder with a rewrite too"     "erp/public/index.php" "erp/dashboard"   ""

# Somebody who really did type /public keeps it, so every link on the
# page they are looking at stays on the address they are already on.
bp "a visitor genuinely inside /public"  "public/index.php" "public/dashboard" "/public"
bp "or at /public exactly"               "public/index.php" "public"           "/public"

# The near misses.
bp "a query string is not part of the path" "public/index.php" "search?q=a/b"     ""
bp "a folder that merely shares a prefix"   "pub/index.php"    "public/dashboard" ""
bp "SCRIPT_NAME is not a front controller"  "files/up/l.png"   "files/up/l.png"   ""

echo ""
echo "=== 2. The rewrite does not swallow what has to stay reachable ==="

# Certificate renewal proves ownership by fetching a file from
# /.well-known. Handed into public/, where it is not, the check fails and
# the certificate expires with nothing to say why.
has "the ACME challenge is let through" "$HT" 'RewriteRule ^\.well-known/ - [L]'

# upgrade.php is how pending database changes get applied. Hiding it is
# how a deployment ends up looking like it did nothing at all.
has "upgrade.php is let through"        "$HT" 'RewriteRule ^(upgrade|check)\.php$ - [L]'

# And the files that must never run over the web, whatever the rewrites
# above them did or did not do.
has "routes.php is denied"      "$HT" '<Files "routes.php">'
has "dev-server.php is denied"  "$HT" '<Files "dev-server.php">'
has "check.php is denied"       "$HT" '<Files "check.php">'

# With the document root above public/, these folders sit inside the tree
# Apache serves. Their own .htaccess files say so too; this is the second
# rule that has to fail before the database password is on the open web.
has "the private folders are refused here as well" \
    "$HT" 'RedirectMatch 404 ^(.*)/(app|config|database|storage|tests|deploy)(/|$)'
has "and the git folder"  "$HT" 'RedirectMatch 404 ^(.*)/\.(git|github|env)(/|$)'
has "and the CLI scripts" "$HT" 'RedirectMatch 404 ^(.*)/(migrate|cron|build-cpanel)\.php$'

echo ""
echo "=== 3. The deployment file ==="

# cPanel pulls straight into the served folder on this account, so the
# default has to copy nothing. A DEPLOYPATH left filled in from an
# example would rsync --delete over a folder nobody meant to touch.
eq "DEPLOYPATH is empty by default"           "$(grep -c 'export DEPLOYPATH=""' "$SHANFIX_ROOT/.cpanel.yml")" "1"
has "it refuses to copy a folder onto itself" "$YML" "repository folder itself"
has "the live config is never overwritten"    "$YML" "--exclude 'config/config.php'"
has "nor is storage"                          "$YML" "--exclude 'storage/'"
has "nor the repository itself"               "$YML" "--exclude '.git/'"
has "migrations run as part of deploying"     "$YML" "migrate.php"
has "and a failed one fails the deployment"   "$YML" "exit 1"

# The tasks are shell, and cPanel runs them with no chance to fix a typo.
if command -v python >/dev/null 2>&1; then
  python -c "
import yaml, sys
d = yaml.safe_load(open(sys.argv[1]))
sys.stdout.write(chr(10).join(d['deployment']['tasks']))
" "$SHANFIX_ROOT/.cpanel.yml" > "$D/deploy_tasks.sh" 2>"$D/deploy_yaml.err"

  eq "the YAML parses"           "$(cat "$D/deploy_yaml.err")" ""
  eq "the tasks are valid shell" "$(sh -n "$D/deploy_tasks.sh" 2>&1 | head -1)" ""
fi

report
