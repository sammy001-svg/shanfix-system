<?php
/**
 * Checks on the permission list itself.
 *
 *   php tests/helpers/permission_audit.php count
 *   php tests/helpers/permission_audit.php orphans
 *   php tests/helpers/permission_audit.php missing < page.html
 *
 * count    — how many permissions the system defines
 * orphans  — any that belong to no module on the access screen
 * missing  — any with no box on the HTML fed in on stdin
 *
 * Both lists print "-" when there is nothing wrong, so a suite can
 * compare against one thing rather than against emptiness, which an
 * error also produces.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;

$what = (string) ($argv[1] ?? '');

switch ($what) {
    case 'count':
        echo count(Auth::allPermissions()), "\n";
        break;

    case 'orphans':
        $inAModule = [];

        foreach (Auth::modules() as $module) {
            $inAModule = array_merge($inAModule, array_keys($module['permissions']));
        }

        $loose = array_diff(Auth::allPermissions(), $inAModule);
        echo ($loose === [] ? '-' : implode(',', $loose)), "\n";
        break;

    case 'missing':
        $html    = (string) stream_get_contents(STDIN);
        $absent  = [];

        foreach (Auth::allPermissions() as $permission) {
            if (!str_contains($html, 'value="' . $permission . '"')) {
                $absent[] = $permission;
            }
        }

        echo ($absent === [] ? '-' : implode(',', $absent)), "\n";
        break;

    default:
        fwrite(STDERR, "usage: permission_audit.php count|orphans|missing\n");
        exit(2);
}
