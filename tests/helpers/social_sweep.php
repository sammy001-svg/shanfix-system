<?php
/**
 * Run the social media reminder sweep on its own.
 *
 *   php tests/helpers/social_sweep.php
 *
 * cron.php does a great deal besides this, and a suite that wanted to
 * check one reminder would otherwise have to run all of it and wait.
 * This calls the one thing and prints what it said.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Social\Reminders;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$result = Reminders::sweep();

foreach ($result['notes'] as $note) {
    echo $note, "\n";
}

echo 'nudged ', $result['nudged'],
     ', missed ', $result['missed'],
     ', digest ', $result['digest'], "\n";
