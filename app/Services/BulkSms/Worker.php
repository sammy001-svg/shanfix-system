<?php
namespace App\Services\BulkSms;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Getting campaigns sent: starting workers, and rescuing dead ones.
 *
 * A campaign is sent by a separate PHP process, never by the web request
 * that created it — a hundred thousand messages take far longer than any
 * host lets a page run. When a campaign is queued, kick() starts that
 * process at once. cron.php calls tick() every run as well, which starts
 * anything kick() could not (a host that forbids exec) and anything whose
 * scheduled time has come, and rescues campaigns whose worker died.
 *
 * The rules are the old platform's:
 *   - at most N campaigns sending at once (a setting, default 5), because
 *     each holds a PHP process and a database connection;
 *   - one campaign per account per round, so a customer with twenty
 *     queued campaigns cannot hold every slot while others wait;
 *   - a campaign with no heartbeat for five minutes has a dead worker and
 *     goes back on the queue, keeping its counts so nobody is texted twice;
 *   - a single send left 'queued' for five minutes died waiting on the
 *     gateway and is marked failed so it shows up rather than hangs.
 */
final class Worker
{
    private const STALE_MINUTES = 5;

    /** Start a worker now, in the background. False if the host will not allow it. */
    public static function kick(?int $campaignId = null): bool
    {
        if (defined('BULK_SMS_NO_SPAWN') || getenv('BULK_SMS_NO_SPAWN') === '1') {
            return false;
        }

        $script = BASE_PATH . '/sms-worker.php';

        if (!is_file($script)) {
            return false;
        }

        $php  = self::phpBinary();
        $args = $campaignId !== null ? ' ' . (int) $campaignId : '';

        if (PHP_OS_FAMILY === 'Windows') {
            if (!self::allowed('popen')) {
                return false;
            }

            $h = @popen('start /B "" "' . $php . '" "' . $script . '"' . $args, 'r');

            if ($h === false) {
                return false;
            }

            pclose($h);
            return true;
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . $args . ' > /dev/null 2>&1 &';

        foreach (['exec', 'shell_exec', 'popen'] as $fn) {
            if (!self::allowed($fn)) {
                continue;
            }

            if ($fn === 'popen') {
                $h = @popen($cmd, 'r');
                if ($h !== false) {
                    pclose($h);
                    return true;
                }
                continue;
            }

            @$fn($cmd);
            return true;
        }

        return false;
    }

    /**
     * One round of housekeeping and dispatch. Called by cron and by the
     * worker script. Returns what it did, for the log.
     *
     * @param bool $inline send the campaigns here rather than spawning —
     *                     what the worker script itself does
     * @return array{rescued:int, stale:int, started:list<int>}
     */
    public static function tick(bool $inline = false): array
    {
        $stale = Database::run(
            "UPDATE bulk_messages
                SET status = 'failed',
                    failed_reason = 'The request timed out before the gateway answered. Check whether it arrived before sending again.'
              WHERE status = 'queued' AND campaign_id IS NULL
                AND created_at < NOW() - INTERVAL " . self::STALE_MINUTES . " MINUTE"
        )->rowCount();

        $rescued = Database::run(
            "UPDATE bulk_campaigns
                SET status = 'queued', locked_at = NULL, last_heartbeat_at = NULL
              WHERE status = 'sending'
                AND (last_heartbeat_at IS NULL OR last_heartbeat_at < NOW() - INTERVAL " . self::STALE_MINUTES . " MINUTE)"
        )->rowCount();

        if ($rescued > 0) {
            Logger::warning('Bulk SMS: rescued ' . $rescued . ' campaign(s) whose worker stopped.');
        }

        $cap    = max(1, Settings::int('bulk_sms_max_workers', 5));
        $active = (int) Database::scalar("SELECT COUNT(*) FROM bulk_campaigns WHERE status = 'sending'");
        $slots  = $cap - $active;

        $started = [];

        if ($slots > 0) {
            // The oldest waiting campaign of each account, oldest first.
            $due = Database::all(
                "SELECT MIN(id) AS id
                   FROM bulk_campaigns
                  WHERE status = 'queued' OR (status = 'scheduled' AND scheduled_at <= NOW())
                  GROUP BY account_id
                  ORDER BY MIN(created_at)
                  LIMIT " . (int) $slots
            );

            foreach ($due as $row) {
                $id = (int) $row['id'];

                if ($inline || !self::kick($id)) {
                    Engine::runCampaign($id);
                }

                $started[] = $id;
            }
        }

        // Housekeeping, now and then.
        if (random_int(1, 12) === 1) {
            Database::run('DELETE FROM bulk_rate_counters WHERE window_start < NOW() - INTERVAL 2 HOUR');
            self::trimLog(STORAGE_PATH . '/logs/onfon.log');
            self::trimLog(STORAGE_PATH . '/logs/sms-dlr.log');
        }

        return ['rescued' => $rescued, 'stale' => $stale, 'started' => $started];
    }

    /** Keep a log under 10 MB by keeping its newest 2 MB. */
    private static function trimLog(string $path): void
    {
        if (!is_file($path) || filesize($path) < 10 * 1024 * 1024) {
            return;
        }

        $fh = fopen($path, 'rb');

        if ($fh === false) {
            return;
        }

        fseek($fh, -2 * 1024 * 1024, SEEK_END);
        $tail = (string) stream_get_contents($fh);
        fclose($fh);

        file_put_contents($path, substr($tail, (int) strpos($tail, "\n") + 1), LOCK_EX);
    }

    private static function phpBinary(): string
    {
        $php = PHP_BINARY;

        // Under PHP-FPM, PHP_BINARY is the FPM daemon, not a CLI binary.
        if ($php === '' || !is_file($php) || str_contains(basename($php), 'fpm') || str_contains(basename($php), 'cgi')) {
            foreach (['/usr/local/bin/php', '/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php'] as $try) {
                if (is_file($try)) {
                    return $try;
                }
            }

            return 'php';
        }

        return $php;
    }

    private static function allowed(string $fn): bool
    {
        static $disabled = null;

        if ($disabled === null) {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        }

        return function_exists($fn) && !in_array($fn, $disabled, true);
    }
}
