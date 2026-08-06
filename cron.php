<?php
/**
 * Shanfix Technology BMS — scheduled tasks.
 *
 * Set this up in cPanel → Cron Jobs, every 5 minutes:
 *
 *   /usr/local/bin/php /home/YOURUSER/shanfix/cron.php >/dev/null 2>&1
 *
 * What it does on each run:
 *   1. Sends anything waiting in the notification queue
 *   2. Queues overdue-invoice reminders (once per invoice per configured day)
 *   3. Marks invoices overdue once they pass their due date
 *   4. Expires quotations past their validity date
 *   5. Prunes old logs and stale queue locks
 *
 * Run `php cron.php --verbose` by hand to see what it is doing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cron.php can only be run from the command line.\n");
}

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Services\Notifier;

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

function say(string $message): void
{
    global $verbose;
    if ($verbose) {
        echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
    }
}

/**
 * A problem the operator has to know about.
 *
 * The documented cron line sends stdout and stderr to /dev/null, so a plain
 * say() would be invisible in exactly the unattended runs that matter. This
 * lands in storage/logs as well.
 */
function alert(string $message): void
{
    Logger::error('Cron: ' . $message);

    global $verbose;
    if ($verbose) {
        echo '[' . date('H:i:s') . '] ** ' . $message . PHP_EOL;
    } else {
        fwrite(STDERR, 'Cron: ' . $message . PHP_EOL);
    }
}

$started = microtime(true);

try {
    Config::load(CONFIG_PATH . '/config.php');
    Database::connect(Config::get('db'));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cron could not start: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

date_default_timezone_set(Config::get('app.timezone', 'Africa/Nairobi'));

say('Cron run starting');

// ---------------------------------------------------------------------
// Only one cron run at a time — a slow mail server must not cause overlap.
// ---------------------------------------------------------------------
$lockFile   = STORAGE_PATH . '/cron.lock';
$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    say('Another cron run is still going — exiting.');
    exit(0);
}

try {
    // -----------------------------------------------------------------
    // 1. Invoice status maintenance
    // -----------------------------------------------------------------
    $overdue = Database::run(
        "UPDATE documents
            SET status = 'overdue'
          WHERE doc_type = 'invoice'
            AND status IN ('sent','unpaid','partial')
            AND balance > 0
            AND due_date IS NOT NULL
            AND due_date < CURDATE()"
    )->rowCount();

    if ($overdue > 0) {
        say("Marked {$overdue} invoice(s) overdue");
    }

    // -----------------------------------------------------------------
    // 2. Expire stale quotations
    // -----------------------------------------------------------------
    $expired = Database::run(
        "UPDATE documents
            SET status = 'expired'
          WHERE doc_type = 'quotation'
            AND status IN ('sent','draft')
            AND valid_until IS NOT NULL
            AND valid_until < CURDATE()"
    )->rowCount();

    if ($expired > 0) {
        say("Expired {$expired} quotation(s)");
    }

    // -----------------------------------------------------------------
    // 3. Queue the date-based chases — respecting the sending window so a
    //    client is never texted at 3am.
    //
    //    Nothing goes out unless we can build a real link. Cron has no HTTP
    //    request to borrow a hostname from, so without app.url every share
    //    link, proof link and logo would point at localhost. Holding the
    //    message costs a run; sending a dead link costs a customer.
    // -----------------------------------------------------------------
    $canLink = Notifier::canBuildLinks();

    if (!$canLink) {
        alert(
            'app.url is not set in config/config.php, so client links would point at '
            . 'localhost. No notifications sent this run — set app.url and they will go '
            . 'out on the next one.'
        );
    }

    if ($canLink && withinSendWindow()) {
        foreach ([
            'overdue reminder'   => [Notifier::class, 'queueOverdueReminders'],
            'due reminder'       => [Notifier::class, 'queueDueReminders'],
            'expiring quotation' => [Notifier::class, 'queueExpiringQuotations'],
        ] as $label => $chaser) {
            $result = $chaser();

            if ($result['queued'] > 0) {
                say("Queued {$result['queued']} {$label}(s)");
            }
        }
    } elseif ($canLink) {
        say('Outside the sending window — reminders held back');
    }

    // -----------------------------------------------------------------
    // 4. Work the queue
    // -----------------------------------------------------------------
    if ($canLink && withinSendWindow()) {
        $result = Notifier::processQueue(40);

        if ($result['processed'] > 0) {
            say("Queue: {$result['sent']} sent, {$result['failed']} failed of {$result['processed']}");
        } else {
            say('Queue empty');
        }
    }

    // -----------------------------------------------------------------
    // 5. Housekeeping — weekly-ish, cheap enough to attempt every run
    // -----------------------------------------------------------------
    Database::run('DELETE FROM notification_locks WHERE created_at < DATE_SUB(NOW(), INTERVAL 120 DAY)');

    // Keep the queue table from growing without bound.
    $pruned = Database::run(
        "DELETE FROM notifications
          WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)"
    )->rowCount();

    if ($pruned > 0) {
        say("Pruned {$pruned} notification(s) older than a year");
    }

    // Old daily log files
    foreach (glob(STORAGE_PATH . '/logs/app-*.log') ?: [] as $log) {
        if (filemtime($log) < strtotime('-60 days')) {
            @unlink($log);
            say('Removed old log ' . basename($log));
        }
    }

    $seconds = round(microtime(true) - $started, 2);
    say("Done in {$seconds}s");
} catch (\Throwable $e) {
    Logger::error('Cron failed: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    fwrite(STDERR, 'Cron error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

/**
 * Messages only go out inside the configured hours, e.g. "08:00-18:00".
 */
function withinSendWindow(): bool
{
    $window = (string) Settings::get('notify_send_window', '');

    if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($window), $m)) {
        return true;   // no window configured, send any time
    }

    $now   = (int) date('H') * 60 + (int) date('i');
    $start = (int) $m[1] * 60 + (int) $m[2];
    $end   = (int) $m[3] * 60 + (int) $m[4];

    // A window that wraps past midnight, e.g. 20:00-06:00
    return $start <= $end
        ? ($now >= $start && $now <= $end)
        : ($now >= $start || $now <= $end);
}
