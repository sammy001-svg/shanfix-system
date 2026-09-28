<?php
/**
 * Work the outbound queue the way cron does, without a cron run.
 *
 *   php tests/helpers/work_queue.php                 send what is ready
 *   php tests/helpers/work_queue.php --outside-window as if the sending
 *                                                    window excluded now
 *   php tests/helpers/work_queue.php --one <id>      just that one
 *   php tests/helpers/work_queue.php --no-app-url    with app.url unset
 *   php tests/helpers/work_queue.php --renewals      queue renewal reminders
 *   php tests/helpers/work_queue.php --queue-with-no-url
 *                                                    what cron would say when
 *                                                    it cannot build a link
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

// The reported fault: with no app.url, cron worked none of the queue.
// Unsetting it here is what makes that testable without editing the
// config file on disk.
if (in_array('--no-app-url', $argv, true)) {
    Config::set('app.url', '');
}

Database::connect(Config::get('db'));

if (in_array('--queue-with-no-url', $argv, true)) {
    Config::set('app.url', '');
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
