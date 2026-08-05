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
$pdo = Database::connect(Config::get('db'));

// Ledger of what has already run.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename   VARCHAR(180) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_migration (filename)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = array_column(
    Database::all('SELECT filename FROM migrations ORDER BY filename'),
    'filename'
);

$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);

if ($files === []) {
    out('No migration files found in database/migrations/.', CYAN);
    exit(0);
}

$pending = array_values(array_filter(
    $files,
    static fn(string $f): bool => !in_array(basename($f), $applied, true)
));

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
    $name = basename($file);
    out('  ' . $name);

    $sql = (string) file_get_contents($file);

    // Drop full-line comments so they cannot confuse the statement splitter.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $statements = array_values(array_filter(
        array_map('trim', preg_split('/;\s*[\r\n]/', $sql)),
        static fn(string $s): bool => $s !== ''
    ));

    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            // Re-running a migration that partially applied should not be fatal
            // when the objects already exist.
            $harmless = [
                '42S01', // table already exists
                '42S21', // duplicate column
            ];

            if (in_array($e->getCode(), $harmless, true)) {
                out('    ' . DIM . 'skipped (already applied): ' . substr($statement, 0, 60) . '…' . OFF);
                continue;
            }

            out('');
            out('  FAILED: ' . $e->getMessage(), ERR);
            out('  Statement: ' . substr($statement, 0, 200) . '…', DIM);
            out('');
            out('  No further migrations were applied. Fix the error and run again.', ERR);
            exit(1);
        }
    }

    Database::run('INSERT INTO migrations (filename) VALUES (:f)', ['f' => $name]);
    out('    ' . OK . 'done' . OFF);
}

out('');
out('All migrations applied.', OK);
out('');
