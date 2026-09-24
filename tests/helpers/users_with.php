<?php
/**
 * Who may actually do this, one user id per line.
 *
 *   php tests/helpers/users_with.php <permission>
 *
 * The question every "who should this be assigned to?" box asks. Run
 * through Auth so the test checks the same answer the pickers get,
 * rather than a second query that happens to agree.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$permission = (string) ($argv[1] ?? '');

if ($permission === '') {
    fwrite(STDERR, "usage: users_with.php <permission>\n");
    exit(2);
}

foreach (Auth::usersWith($permission) as $user) {
    echo (int) $user['id'], "\n";
}
