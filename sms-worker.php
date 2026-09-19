<?php
/**
 * Bulk SMS campaign worker — started in the background, never by a browser.
 *
 * The system starts this the moment a campaign is queued, so nobody waits
 * for cron. cron.php also runs the same dispatch every time it runs, which
 * covers hosts that refuse to start background processes and campaigns
 * whose scheduled time has come.
 *
 *   php sms-worker.php          send whatever is due, here, now
 *   php sms-worker.php 42       send campaign #42
 *
 * Safe to run twice at once: a campaign is claimed with one atomic UPDATE,
 * and the second worker to reach it finds it already taken.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("sms-worker.php can only be run from the command line.\n");
}

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\BulkSms\Engine;
use App\Services\BulkSms\Worker;

try {
    Config::load(CONFIG_PATH . '/config.php');
    Database::connect(Config::get('db'));
} catch (\Throwable $e) {
    fwrite(STDERR, 'SMS worker could not start: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

date_default_timezone_set(Config::get('app.timezone', 'Africa/Nairobi'));

$campaignId = isset($argv[1]) ? (int) $argv[1] : 0;

if ($campaignId > 0) {
    Engine::runCampaign($campaignId);
    exit(0);
}

// Everything due, sent here rather than by spawning more of us.
$result = Worker::tick(true);

if (in_array('--verbose', $argv, true)) {
    printf("rescued %d, stale %d, sent %s\n",
        $result['rescued'], $result['stale'],
        $result['started'] === [] ? 'nothing' : '#' . implode(', #', $result['started']));
}
