<?php
/**
 * What a set of roles allows, one permission per line.
 *
 *   php tests/helpers/role_permissions.php sales [reception ...]
 *
 * A script rather than php -r in the suite. $ROOT is a Git Bash path
 * like /c/Shanfix System: the shell resolves it when it launches php,
 * but a require() inside the code is resolved by PHP itself, which on
 * Windows cannot open it. That failed silently, printed nothing, and a
 * test asserting on "the role's own permissions" then posted an empty
 * list and proved the opposite of what it claimed.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;

$roles = array_slice($argv, 1);

if ($roles === []) {
    fwrite(STDERR, "usage: role_permissions.php <role> [role ...]\n");
    exit(2);
}

// --without=x.y drops one, for a test that wants "everything but this".
$without = [];

foreach ($roles as $i => $arg) {
    if (str_starts_with($arg, '--without=')) {
        $without[] = substr($arg, 10);
        unset($roles[$i]);
    }
}

foreach (Auth::permissionsForRoles(array_values($roles)) as $permission) {
    if (!in_array($permission, $without, true)) {
        echo $permission, "\n";
    }
}
