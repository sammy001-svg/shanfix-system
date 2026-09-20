<?php
/**
 * Send one message through the engine, for the shell suite.
 *
 * A script rather than php -r, because Git Bash only rewrites paths in
 * arguments, not inside a -r string: as a one-liner the bootstrap path
 * fails to open, the snippet dies, and a test asserting "nothing was
 * sent" passes for the wrong reason.
 *
 * Prints "sent", or "refused: <why>".
 *
 * Usage: php tests/helpers/bulk_sms_send.php <account-id> <phone> <sender> [message]
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\BulkSms\Engine;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

if (!str_contains((string) Database::scalar('SELECT DATABASE()'), 'test')) {
    echo 'refused: not a test database';
    exit(1);
}

$result = Engine::sendNow(
    (int) ($argv[1] ?? 0),
    [(string) ($argv[2] ?? '')],
    (string) ($argv[4] ?? 'test message'),
    (string) ($argv[3] ?? ''),
    ['source' => 'admin']
);

echo $result['ok'] ? 'sent' : 'refused: ' . ($result['error'] ?? 'no reason');
