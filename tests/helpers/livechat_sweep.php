<?php
/**
 * Run the live chat escalation sweep on its own.
 *
 * cron.php does a great deal besides this — backups, invoicing, the
 * whole outbound queue — and a suite that wanted to check one escalation
 * would otherwise have to run all of it and wait. This calls the one
 * thing, and prints what it said so the suite can assert on it.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

// bootstrap.php loads the classes; a web request would connect after it.
// This is not a web request.
Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$result = \App\Services\LiveChat\Alerts::sweep();

foreach ($result['notes'] as $note) {
    echo $note, "\n";
}

echo 'checked ', $result['checked'], ', escalated ', $result['escalated'], "\n";
