<?php
/**
 * Work the outbound queue the way cron does, without a cron run.
 *
 *   php tests/helpers/work_queue.php                 send what is ready
 *   php tests/helpers/work_queue.php --outside-window as if the sending
 *                                                    window excluded now
 *   php tests/helpers/work_queue.php --one <id>      just that one
 *   php tests/helpers/work_queue.php --can-build-links
 *                                                    what cron asks before it
 *                                                    queues a client document
 *   php tests/helpers/work_queue.php --renewals      queue renewal reminders
 *
 * cron.php does a great deal besides this, and a suite that wanted to
 * check one send would otherwise run backups and invoicing to get there.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Notifier;

Config::load(CONFIG_PATH . '/config.php');

Database::connect(Config::get('db'));

// What cron asks before queueing a client document, reported on
// whatever the config file currently says. Config is deliberately
// immutable, so the one test that needs app.url blank blanks it on
// disk and puts it back, rather than this pretending.
if (in_array('--can-build-links', $argv, true)) {
    unset($_SERVER['HTTP_HOST']);

    echo Notifier::canBuildLinks()
        ? "links can be built\n"
        : "app.url is not set, so no client document would be queued\n";

    exit(0);
}

if (in_array('--renewals', $argv, true)) {
    $result = Notifier::queueRenewalReminders();
    echo 'checked ', $result['checked'], ', queued ', $result['queued'], "\n";
    exit(0);
}

$onlyId = null;
$at     = array_search('--one', $argv, true);

if ($at !== false && isset($argv[$at + 1])) {
    $onlyId = (int) $argv[$at + 1];
}

// False is what cron passes when the clock is outside the sending
// window: client messages wait, internal ones go.
$clientToo = !in_array('--outside-window', $argv, true);

$result = Notifier::processQueue(null, $onlyId, $clientToo);

if ($result['stopped'] !== null) {
    echo $result['stopped'], "\n";
}

echo 'sent ', $result['sent'],
     ', failed ', $result['failed'],
     ', processed ', $result['processed'],
     ', held ', $result['held'], "\n";
