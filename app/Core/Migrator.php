<?php
namespace App\Core;

use PDO;
use PDOException;

/**
 * Applies the .sql files in database/migrations/ and records what has run.
 *
 * Shared by migrate.php (command line) and upgrade.php (browser), so the two
 * cannot drift apart — a migration that applies cleanly from a terminal must
 * apply identically from cPanel, where there is often no terminal at all.
 */
class Migrator
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
        $this->ensureLedger();
    }

    /** The table recording which migrations have run. */
    private function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename   VARCHAR(180) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_migration (filename)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return string[] filenames already recorded as applied */
    public function applied(): array
    {
        return array_column(
            Database::all('SELECT filename FROM migrations ORDER BY filename'),
            'filename'
        );
    }

    /** @return string[] absolute paths of every migration file, in run order */
    public function all(): array
    {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files);

        return $files;
    }

    /** @return string[] absolute paths of migrations not yet applied */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->all(),
            static fn(string $f): bool => !in_array(basename($f), $applied, true)
        ));
    }

    /**
     * Split a migration into individual statements.
     *
     * Full-line comments are dropped first so a stray semicolon inside one
     * cannot split a statement in half.
     */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*[\r\n]/', (string) $sql)),
            static fn(string $s): bool => $s !== ''
        ));
    }

    /**
     * Apply one migration and record it.
     *
     * @return array{ok:bool, skipped:int, error?:string, statement?:string}
     */
    public function apply(string $file): array
    {
        $name    = basename($file);
        $skipped = 0;

        foreach ($this->statements((string) file_get_contents($file)) as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                // A migration that previously ran half-way should not be fatal
                // when the objects it creates are already there.
                $harmless = [
                    '42S01', // table already exists
                    '42S21', // duplicate column
                ];

                if (in_array($e->getCode(), $harmless, true)) {
                    $skipped++;
                    continue;
                }

                return [
                    'ok'        => false,
                    'skipped'   => $skipped,
                    'error'     => $e->getMessage(),
                    'statement' => substr($statement, 0, 200),
                ];
            }
        }

        Database::run('INSERT INTO migrations (filename) VALUES (:f)', ['f' => $name]);

        return ['ok' => true, 'skipped' => $skipped];
    }

    /**
     * Apply everything outstanding, stopping at the first genuine failure so a
     * broken migration cannot cascade into the ones after it.
     *
     * @return array{applied:string[], failed:?string, error:?string, statement:?string}
     */
    public function migrate(): array
    {
        $done = [];

        foreach ($this->pending() as $file) {
            $result = $this->apply($file);

            if (!$result['ok']) {
                return [
                    'applied'   => $done,
                    'failed'    => basename($file),
                    'error'     => $result['error'],
                    'statement' => $result['statement'],
                ];
            }

            $done[] = basename($file);
        }

        return ['applied' => $done, 'failed' => null, 'error' => null, 'statement' => null];
    }
}
