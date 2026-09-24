<?php
/**
 * Everything one account may actually do, one permission per line.
 *
 *   php tests/helpers/effective_access.php <user-id>
 *
 * Asked of Auth rather than worked out here. A test that recomputed the
 * rule would agree with its own copy of it and prove nothing.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$userId = (int) ($argv[1] ?? 0);

if ($userId <= 0) {
    fwrite(STDERR, "usage: effective_access.php <user-id>\n");
    exit(2);
}

$roles = array_column(
    Database::all('SELECT role FROM user_roles WHERE user_id = :id', ['id' => $userId]),
    'role'
);

// The primary role counts even where the join table has missed it,
// which is the same fallback Auth::roles() makes.
$primary = (string) Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $userId], '');

if ($primary !== '' && !in_array($primary, $roles, true)) {
    $roles[] = $primary;
}

$overrides = [];

foreach (Database::all(
    'SELECT permission, allowed FROM user_permissions WHERE user_id = :id',
    ['id' => $userId]
) as $row) {
    $overrides[$row['permission']] = (int) $row['allowed'] === 1;
}

foreach (Auth::effectivePermissions($roles, $overrides) as $permission) {
    echo $permission, "\n";
}
