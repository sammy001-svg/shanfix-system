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
use App\Services\Backup;
use App\Services\Notifier;
use App\Services\StaffNotifier;
use App\Services\Renewals;

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
            'renewal reminder'   => [Notifier::class, 'queueRenewalReminders'],
            'meeting reminder'   => [Notifier::class, 'queueMeetingReminders'],
            'monthly statement'  => [Notifier::class, 'queueStatements'],
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
    // 3b. Recurring services
    //
    //     Runs whether or not links can be built: raising an invoice is
    //     bookkeeping, not a message, and holding it back would leave the
    //     renewal silently unbilled. Only subscriptions set to invoice
    //     automatically are touched — the rest wait for someone to press
    //     the button, which is the point of the setting.
    // -----------------------------------------------------------------
    $lead    = (int) Settings::get('subscription_invoice_lead', 14);
    $horizon = date('Y-m-d', strtotime('+' . max(0, $lead) . ' days'));
    $raised  = 0;

    foreach (Renewals::dueBy($horizon) as $sub) {
        if ((int) $sub['auto_invoice'] !== 1) {
            continue;
        }

        try {
            $result = Renewals::invoicePeriod($sub);

            if ($result['created']) {
                $raised++;
                say('Invoiced renewal: ' . $sub['name'] . ' → ' . $result['document']['doc_number']);
            }
        } catch (Throwable $e) {
            // One bad subscription must not stop the rest being billed.
            alert('Could not invoice ' . $sub['name'] . ': ' . $e->getMessage());
        }
    }

    if ($raised === 0) {
        say('No renewals to invoice');
    }

    // Renewals whose invoice has since been paid.
    $settled = Renewals::syncPaidRenewals();

    if ($settled > 0) {
        say("Marked {$settled} renewal(s) as paid");
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
    // 5. Take a copy of everything
    // -----------------------------------------------------------------
    // Before the housekeeping below starts deleting things, not after.
    if (Backup::isDue()) {
        $backup = Backup::run(Settings::bool('backup_uploads', true));

        if ($backup['ok']) {
            say(sprintf(
                'Backup %s: %d tables, %s rows, %s',
                $backup['name'],
                $backup['tables'],
                number_format($backup['rows']),
                human_bytes($backup['bytes'])
            ));
        } else {
            // Worth waking somebody for. The whole point of a backup is
            // that it exists on the day it is needed.
            alert('Backup failed: ' . $backup['error']);
        }
    }

    // A backup that quietly stopped running is worse than none, because
    // everybody carries on believing there is one.
    $latest   = Backup::all()[0] ?? null;
    $warnDays = max(1, Settings::int('backup_warn_days', 3));

    $stale = Settings::bool('backup_enabled', true)
        && ($latest === null || (time() - $latest['at']) > $warnDays * 86400);

    // Cron runs every few minutes on most hosts. Without a lock this would
    // email the administrators every few minutes for as long as the problem
    // lasts, which is how a real warning ends up filtered into a folder
    // nobody reads. Once a day is enough to be heard.
    $warnedToday = false;

    if ($stale) {
        try {
            Database::run(
                'INSERT INTO notification_locks (lock_key) VALUES (:k)',
                ['k' => 'backup:stale:' . date('Y-m-d')]
            );
        } catch (\Throwable) {
            $warnedToday = true;   // already said so today
        }
    }

    if ($stale && !$warnedToday) {
        $howLong = $latest === null
            ? 'There is no backup at all.'
            : 'The most recent is from ' . date('j M Y', $latest['at']) . '.';

        StaffNotifier::notify(
            StaffNotifier::withRole(['admin']),
            [
                'event' => 'backup_stale',
                'title' => 'No recent backup of the system',
                'body'  => $howLong . ' Open Settings to take one now and check the scheduled task is still running.',
                'link'  => '/settings?tab=backups',
            ],
            ['email' => true, 'sms' => false]
        );

        say('Warned the administrators: ' . $howLong);
    }

    // -----------------------------------------------------------------
    // 5b. Remind somebody that the partners need paying
    // -----------------------------------------------------------------
    // Commission is paid monthly, which means it is paid by somebody
    // remembering — and a thing a business owes that depends on somebody
    // remembering is a thing it eventually forgets. On the payout day,
    // once, say what is owed and for which month.
    //
    // The month named is the one just gone, not the current one: this
    // month is still being earned, and a run against a moving total is
    // not a run.
    $payoutDay = max(1, min(28, Settings::int('partner_payout_day', 5)));

    if (Settings::bool('partners_enabled', true) && (int) date('j') === $payoutDay) {
        $period = date('Y-m', strtotime('first day of last month'));
        $owed   = \App\Services\Commission::runFor($period);

        $due = 0.0;
        foreach ($owed as $row) {
            $due += (float) $row['due'];
        }

        // Same lock as the stale-backup warning, and for the same reason:
        // cron runs every few minutes, and a reminder that arrives every
        // few minutes is one nobody reads.
        $alreadySaid = false;

        if ($due > 0.009) {
            try {
                Database::run(
                    'INSERT INTO notification_locks (lock_key) VALUES (:k)',
                    ['k' => 'partner:payout:' . $period]
                );
            } catch (\Throwable) {
                $alreadySaid = true;
            }
        }

        if ($due > 0.009 && !$alreadySaid) {
            $waiting = count(array_filter(
                $owed,
                static fn(array $r): bool => (float) $r['due'] > 0.009
            ));

            // Nobody can be paid without one, so it is worth saying in the
            // same breath rather than being found at the moment of paying.
            $noPin = count(array_filter(
                $owed,
                static fn(array $r): bool => (float) $r['due'] > 0.009 && trim((string) $r['kra_pin']) === ''
            ));

            StaffNotifier::notify(
                StaffNotifier::withRole(['admin', 'finance']),
                [
                    'event' => 'partner_payout_due',
                    'title' => 'Partner commission is due for ' . date('F Y', strtotime($period . '-01')),
                    'body'  => money($due) . ' is owed across ' . $waiting . ' partner'
                             . ($waiting === 1 ? '' : 's') . '.'
                             . ($noPin > 0
                                ? ' ' . $noPin . ' of them ' . ($noPin === 1 ? 'has' : 'have')
                                  . ' no KRA PIN on file and cannot be paid yet.'
                                : ''),
                    'link'  => '/partners-admin/runs?period=' . $period,
                ],
                ['email' => true, 'sms' => false]
            );

            say('Partner payout due for ' . $period . ': ' . money($due) . ' across ' . $waiting);
        }
    }

    // -----------------------------------------------------------------
    // 6. Housekeeping — weekly-ish, cheap enough to attempt every run
    // -----------------------------------------------------------------
    Database::run('DELETE FROM notification_locks WHERE created_at < DATE_SUB(NOW(), INTERVAL 120 DAY)');

    // Offline replay guards are only useful while a replay is still possible.
    $prunedKeys = \App\Core\Idempotency::prune();
    if ($prunedKeys > 0) {
        say("Pruned {$prunedKeys} expired offline key(s)");
    }

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
