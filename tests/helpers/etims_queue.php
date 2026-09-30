<?php
/**
 * Drive the eTIMS queue from a suite.
 *
 * The queue has no screen of its own yet — an invoice is put on it by
 * the controller or by cron, and neither is convenient to call one step
 * at a time. This calls the one operation asked for and prints what it
 * said, so a suite can assert on the answer rather than on a side
 * effect.
 *
 *   php etims_queue.php queue <document id>
 *   php etims_queue.php send  <document id>
 *   php etims_queue.php sweep
 *   php etims_queue.php check <document id>   what is wrong with it
 *   php etims_queue.php state                 whether we could send
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Etims\Oscu;
use App\Services\Etims\Setup;
use App\Services\Etims\Transmission;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$what = $argv[1] ?? 'state';
$id   = (int) ($argv[2] ?? 0);

switch ($what) {
    case 'queue':
        $r = Transmission::queue($id);
        echo $r['ok'] ? "ok\n" : 'refused: ' . $r['error'] . "\n";
        break;

    case 'send':
        $r = Transmission::send($id);
        echo $r['ok'] ? "ok\n" : 'refused: ' . $r['error'] . "\n";
        break;

    case 'sweep':
        $r = Transmission::sweep();
        echo 'sent ', $r['sent'], ', failed ', $r['failed'], ', held ', $r['held'], "\n";

        if (isset($r['reason'])) {
            echo 'reason: ', $r['reason'], "\n";
        }
        break;

    case 'check':
        $doc   = Database::first('SELECT * FROM documents WHERE id = :id', ['id' => $id]);
        $items = Database::all(
            'SELECT * FROM document_items WHERE document_id = :id ORDER BY sort_order, id',
            ['id' => $id]
        );

        echo Oscu::whatIsWrongWith($doc ?: [], $items) ?? "nothing\n";
        echo "\n";
        break;

    case 'state':
    default:
        echo 'configured: ', Setup::isConfigured() ? 'yes' : 'no', "\n";
        echo 'enabled: ',    Setup::enabled()      ? 'yes' : 'no', "\n";
        echo 'can send: ',   Setup::canSend()      ? 'yes' : 'no', "\n";
        echo 'implemented: ', Oscu::isImplemented() ? 'yes' : 'no', "\n";
        echo 'waiting: ',    Transmission::waitingCount(), "\n";
        echo 'says: ',       Setup::describe(), "\n";
        break;
}
