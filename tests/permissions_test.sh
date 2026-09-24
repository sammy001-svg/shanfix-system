#!/bin/bash
# Choosing what one person may see and do.
#
# Access used to be decided entirely by role: pick Sales and you get
# what Sales gets. That is still the default and roles_test.sh covers
# it. This covers the exception — the salesperson who also handles
# purchase orders, the designer who must not see what a job costs — and
# the thing that matters most about an exception, which is that it is
# real on the server and not only on the screen that set it.
#
# So almost nothing here asserts on the form. It signs in as the person
# whose access was changed and asks whether the page opens.
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

TJ="$D/perm_target.txt"

# What the system says this account may do, asked of Auth rather than
# recomputed here — a second copy of the rule would agree with itself
# and prove nothing.
effective() {
  $PHP "$ROOT/tests/helpers/effective_access.php" "$1"
}

can() {
  effective "$1" | grep -qx "$2" && echo yes || echo no
}

# Sign in as the person being experimented on and try to open a page.
as_them() {
  rm -f "$TJ"
  JAR="$TJ" login_as "perm.target@shanfix.co.ke" "PermPass1" >/dev/null 2>&1
  curl -s -o /dev/null -w '%{http_code}' -b "$TJ" "$BASE$1"
}

# What a role allows, and checks on the permission list itself. Both
# are scripts rather than php -r: $ROOT is a Git Bash path like
# /c/Shanfix System, which the shell resolves when it launches php but
# which a require() inside the code cannot open on Windows. That failed
# silently and printed nothing, so a test meaning "the role's own
# permissions" posted an empty list and proved the opposite.
role_perms() { $PHP "$ROOT/tests/helpers/role_permissions.php" "$@"; }
audit()      { $PHP "$ROOT/tests/helpers/permission_audit.php" "$@"; }

user_id() { q "SELECT id FROM users WHERE email='$1';"; }

overrides_of() {
  q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$1;"
}

# ---------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------
restore() {
  q "DELETE FROM users WHERE email IN ('perm.target@shanfix.co.ke','perm.second@shanfix.co.ke');"
}
trap restore EXIT

restore
signin_admin

HASH=$($PHP -r 'echo password_hash("PermPass1", PASSWORD_DEFAULT);')
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Perm Target','perm.target@shanfix.co.ke','$HASH','sales',1);"
UID_T=$(user_id 'perm.target@shanfix.co.ke')
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($UID_T,'sales');"

echo ""
echo "=== 1. The screen offers every permission the system has ==="

FORM=$(page /users/create)
TOTAL=$(audit count)

eq "every permission has a box"  "$(echo "$FORM" | grep -c 'name="permissions\[\]"')" "$TOTAL"
has "and there is a switch for tailoring"  "$FORM" 'name="custom_access"'
has "the roles are handed to the page as data" "$FORM" 'id="rolePermissions"'

# A permission with no box is one an administrator cannot grant, which
# is how a capability ends up belonging to nobody.
eq "none of them is missing from the screen" "$(audit missing <<< "$FORM")" "-"

# Grouping comes from the permission names themselves, so a new one
# cannot land outside every module.
eq "and every one belongs to a module" "$(audit orphans)" "-"

echo ""
echo "=== 2. Left alone, an account is its role and nothing else ==="

eq "no exceptions are stored"  "$(overrides_of "$UID_T")" "0"
eq "sales can see clients"     "$(can "$UID_T" 'clients.view')" "yes"
eq "and cannot buy anything"   "$(can "$UID_T" 'purchases.view')" "no"
eq "the clients page opens"    "$(as_them /clients)" "200"
eq "the purchasing page does not" "$(as_them /purchase-orders)" "403"

echo ""
echo "=== 3. Granting something the role does not give ==="

# Exactly what Sales gets, plus purchasing. Posted the way the form
# posts it: every box that is ticked, and the switch.
grant_purchasing() {
  local t extra
  t=$(tok "/users/$UID_T/edit")
  extra=""
  for p in $(role_perms sales); do
    extra="$extra --data-urlencode permissions[]=$p"
  done
  # shellcheck disable=SC2086
  post "/users/$UID_T" --data-urlencode "_token=$t" \
       --data-urlencode "name=Perm Target" \
       --data-urlencode "email=perm.target@shanfix.co.ke" \
       --data-urlencode "role=sales" --data-urlencode "roles[]=sales" \
       --data-urlencode "is_active=1" \
       --data-urlencode "custom_access=1" \
       $extra \
       --data-urlencode "permissions[]=purchases.view" \
       --data-urlencode "permissions[]=purchases.manage"
}

eq "the change is accepted"  "$(grant_purchasing)" "302"

eq "two exceptions are stored, not seventy-nine" "$(overrides_of "$UID_T")" "2"
eq "and both are grants" \
   "$(q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$UID_T AND allowed=1;")" "2"

# The point of the whole exercise.
eq "they can buy now"          "$(can "$UID_T" 'purchases.view')" "yes"
eq "the purchasing page opens" "$(as_them /purchase-orders)" "200"
eq "and nothing else moved"    "$(can "$UID_T" 'payroll.view')" "no"

eq "who granted it is written down" \
   "$(q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$UID_T AND granted_by IS NOT NULL;")" "2"

echo ""
echo "=== 4. Taking away something the role does give ==="

# Sales gets clients.manage. This one does not.
revoke_clients() {
  local t extra
  t=$(tok "/users/$UID_T/edit")
  extra=""
  for p in $(role_perms sales --without=clients.manage); do
    extra="$extra --data-urlencode permissions[]=$p"
  done
  # shellcheck disable=SC2086
  post "/users/$UID_T" --data-urlencode "_token=$t" \
       --data-urlencode "name=Perm Target" \
       --data-urlencode "email=perm.target@shanfix.co.ke" \
       --data-urlencode "role=sales" --data-urlencode "roles[]=sales" \
       --data-urlencode "is_active=1" --data-urlencode "custom_access=1" $extra
}

eq "the change is accepted"   "$(revoke_clients)" "302"
eq "one exception, and it is a revoke" \
   "$(q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$UID_T AND allowed=0;")" "1"
eq "they cannot manage clients" "$(can "$UID_T" 'clients.manage')" "no"
eq "but can still see them"     "$(can "$UID_T" 'clients.view')" "yes"
eq "the client list still opens" "$(as_them /clients)" "200"
eq "adding one does not"         "$(as_them /clients/create)" "403"

echo ""
echo "=== 5. Only the differences are kept ==="

# Ticking exactly what the role gives is not an exception, so there is
# nothing to write down. This is what keeps a change to what Sales means
# reaching every salesperson instead of a hundred frozen copies.
exactly_the_role() {
  local t extra
  t=$(tok "/users/$UID_T/edit")
  extra=""
  for p in $(role_perms sales); do
    extra="$extra --data-urlencode permissions[]=$p"
  done
  # shellcheck disable=SC2086
  post "/users/$UID_T" --data-urlencode "_token=$t" \
       --data-urlencode "name=Perm Target" \
       --data-urlencode "email=perm.target@shanfix.co.ke" \
       --data-urlencode "role=sales" --data-urlencode "roles[]=sales" \
       --data-urlencode "is_active=1" --data-urlencode "custom_access=1" $extra
}

eq "saving the role's own set is accepted" "$(exactly_the_role)" "302"
eq "and writes nothing down"               "$(overrides_of "$UID_T")" "0"
eq "they are back to plain Sales"          "$(can "$UID_T" 'clients.manage')" "yes"

echo ""
echo "=== 6. Turning the switch off puts them back ==="

T=$(tok "/users/$UID_T/edit")
post "/users/$UID_T" --data-urlencode "_token=$T" \
     --data-urlencode "name=Perm Target" \
     --data-urlencode "email=perm.target@shanfix.co.ke" \
     --data-urlencode "role=sales" --data-urlencode "roles[]=sales" \
     --data-urlencode "is_active=1" \
     --data-urlencode "custom_access=1" \
     --data-urlencode "permissions[]=purchases.view" >/dev/null
eq "an exception is in place" "$(can "$UID_T" 'purchases.view')" "yes"

T=$(tok "/users/$UID_T/edit")
eq "saving with the switch off is accepted" \
   "$(post "/users/$UID_T" --data-urlencode "_token=$T" \
        --data-urlencode "name=Perm Target" \
        --data-urlencode "email=perm.target@shanfix.co.ke" \
        --data-urlencode "role=sales" --data-urlencode "roles[]=sales" \
        --data-urlencode "is_active=1" \
        --data-urlencode "permissions[]=purchases.view")" "302"

eq "the boxes are ignored without it"  "$(overrides_of "$UID_T")" "0"
eq "and they are on their role again"  "$(can "$UID_T" 'purchases.view')" "no"

echo ""
echo "=== 7. Nothing in a form post can invent access ==="

T=$(tok "/users/$UID_T/edit")
post "/users/$UID_T" --data-urlencode "_token=$T" \
     --data-urlencode "name=Perm Target" \
     --data-urlencode "email=perm.target@shanfix.co.ke" \
     --data-urlencode "role=sales" --data-urlencode "roles[]=sales" \
     --data-urlencode "is_active=1" --data-urlencode "custom_access=1" \
     --data-urlencode "permissions[]=everything.always" \
     --data-urlencode "permissions[]=users.manage; DROP TABLE users" >/dev/null

eq "a permission that does not exist is dropped" \
   "$(q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$UID_T AND permission LIKE '%everything%';")" "0"
eq "and so is one with a statement stapled to it" \
   "$(q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$UID_T AND permission LIKE '%DROP%';")" "0"
eq "the users table is still there" \
   "$(q "SELECT COUNT(*) > 0 FROM users;")" "1"
eq "and they did not become an administrator" "$(can "$UID_T" 'users.manage')" "no"

echo ""
echo "=== 8. An administrator cannot lock themselves out ==="

ME=$(q "SELECT id FROM users WHERE email='admin@shanfix.co.ke';")
T=$(tok "/users/$ME/edit")

# Everything they have, minus the one permission that opens this screen.
MINE=""
for p in $(role_perms admin --without=users.manage); do
  MINE="$MINE --data-urlencode permissions[]=$p"
done

# shellcheck disable=SC2086
post "/users/$ME" --data-urlencode "_token=$T" \
     --data-urlencode "name=System Administrator" \
     --data-urlencode "email=admin@shanfix.co.ke" \
     --data-urlencode "role=admin" --data-urlencode "roles[]=admin" \
     --data-urlencode "is_active=1" --data-urlencode "custom_access=1" $MINE >/dev/null

eq "taking your own administration away is refused" \
   "$(q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$ME AND permission='users.manage' AND allowed=0;")" "0"
eq "and the users screen still opens" "$(code /users)" "200"

echo ""
echo "=== 9. Nor remove the last one ==="

# A second administrator, so there is somebody to take away.
q "INSERT INTO users (name,email,password_hash,role,is_active)
   VALUES ('Perm Second','perm.second@shanfix.co.ke','$HASH','admin',1);"
UID_S=$(user_id 'perm.second@shanfix.co.ke')
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($UID_S,'admin');"

eq "there are two administrators now" \
   "$(q "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id
          WHERE ur.role='admin' AND u.is_active=1;")" "2"

# Taking it from the other one is allowed, because one remains.
T=$(tok "/users/$UID_S/edit")
# shellcheck disable=SC2086
eq "one of two may be demoted" \
   "$(post "/users/$UID_S" --data-urlencode "_token=$T" \
        --data-urlencode "name=Perm Second" \
        --data-urlencode "email=perm.second@shanfix.co.ke" \
        --data-urlencode "role=staff" --data-urlencode "roles[]=staff" \
        --data-urlencode "is_active=1")" "302"
eq "and really loses it" "$(can "$UID_S" 'users.manage')" "no"

echo ""
echo "=== 10. Pickers ask who can, not what role ==="

# "Who may be given this lead?" has to mean who may actually work one.
# A role list alone gets it wrong in both directions.
q "DELETE FROM user_permissions WHERE user_id=$UID_T;"

eq "a salesperson is offered a lead" \
   "$($PHP "$ROOT/tests/helpers/users_with.php" leads.manage | grep -c "^$UID_T\$")" "1"

q "INSERT INTO user_permissions (user_id,permission,allowed) VALUES ($UID_T,'leads.manage',0);"
eq "one whose lead work was taken away is not" \
   "$($PHP "$ROOT/tests/helpers/users_with.php" leads.manage | grep -c "^$UID_T\$")" "0"

q "UPDATE user_permissions SET allowed=1, permission='livechat.use' WHERE user_id=$UID_T;"
eq "and one granted live chat by hand is offered it" \
   "$($PHP "$ROOT/tests/helpers/users_with.php" livechat.use | grep -c "^$UID_T\$")" "1"

# The department screen builds its staff list from the same question.
has "so the chat department screen offers them" "$(page /livechat/departments)" "Perm Target"

q "DELETE FROM user_permissions WHERE user_id=$UID_T;"

echo ""
echo "=== 11. Exceptions belong to the account and die with it ==="

q "INSERT INTO user_permissions (user_id,permission,allowed) VALUES ($UID_T,'reports.view',1);"
eq "an exception is in place" "$(overrides_of "$UID_T")" "1"

q "DELETE FROM users WHERE id=$UID_T;"
eq "deleting the account takes them with it" \
   "$(q "SELECT COUNT(*) FROM user_permissions WHERE user_id=$UID_T;")" "0"

# Put them back for anything that follows.
q "INSERT INTO users (id,name,email,password_hash,role,is_active)
   VALUES ($UID_T,'Perm Target','perm.target@shanfix.co.ke','$HASH','sales',1);"
q "INSERT IGNORE INTO user_roles (user_id,role) VALUES ($UID_T,'sales');"

echo ""
echo "=== 12. Everybody else is untouched ==="

# The whole point of storing differences: an account nobody has tailored
# is still decided by its role, and still moves when that role does.
eq "nobody has picked up an exception by accident" \
   "$(q "SELECT COUNT(*) FROM user_permissions;")" "0"
eq "a plain salesperson still sees clients" "$(can "$UID_T" 'clients.view')" "yes"
eq "and still cannot see payroll"           "$(can "$UID_T" 'payroll.view')" "no"

report
