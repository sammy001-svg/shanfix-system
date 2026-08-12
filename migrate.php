<?php
/**
 * Shanfix Technology BMS — database migrations.
 *
 *   php migrate.php           apply any pending migrations
 *   php migrate.php --status  list what has run and what is pending
 *
 * Migrations live in database/migrations/ and run in filename order.
 * Each one is recorded, so re-running is safe.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php can only be run from the command line.\n");
}

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

const OK   = "\033[32m";
const ERR  = "\033[31m";
const CYAN = "\033[36m";
const DIM  = "\033[2m";
const OFF  = "\033[0m";

function out(string $msg, string $colour = ''): void
{
    echo $colour . $msg . ($colour ? OFF : '') . PHP_EOL;
}

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

// The ledger, the file list and the statement splitting all live in Migrator,
// shared with upgrade.php so a migration behaves the same from a terminal and
// from a browser.
$migrator = new Migrator();

$applied = $migrator->applied();
$files   = $migrator->all();
$pending = $migrator->pending();

if ($files === []) {
    out('No migration files found in database/migrations/.', CYAN);
    exit(0);
}

// -- Status only ------------------------------------------------------
if (in_array('--status', $argv, true)) {
    out('');
    out('Migrations', CYAN);
    foreach ($files as $file) {
        $name = basename($file);
        $done = in_array($name, $applied, true);
        out(sprintf('  %s %s', $done ? OK . '[applied]' . OFF : DIM . '[pending]' . OFF, $name));
    }
    out('');
    out(sprintf('  %d applied, %d pending', count($applied), count($pending)));
    out('');
    exit(0);
}

// -- Apply ------------------------------------------------------------
if ($pending === []) {
    out('');
    out('Database is up to date — nothing to apply.', OK);
    out('');
    exit(0);
}

out('');
out(sprintf('Applying %d migration(s)…', count($pending)), CYAN);
out('');

foreach ($pending as $file) {
    out('  ' . basename($file));

    $result = $migrator->apply($file);

    if ($result['skipped'] > 0) {
        out('    ' . DIM . $result['skipped'] . ' statement(s) already applied, skipped' . OFF);
    }

    if (!$result['ok']) {
        out('');
        out('  FAILED: ' . $result['error'], ERR);
        out('  Statement: ' . $result['statement'] . '…', DIM);
        out('');
        out('  No further migrations were applied. Fix the error and run again.', ERR);
        exit(1);
    }

    out('    ' . OK . 'done' . OFF);
}

out('');
out('All migrations applied.', OK);
out('');
