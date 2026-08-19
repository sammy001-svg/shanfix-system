<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Taking a copy of the business.
 *
 * Everything this company has sold, been paid, owes and is owed lives in
 * one MySQL database on shared hosting. A dropped table, a bad upgrade or
 * a host going down takes the lot. This makes a copy that can be carried
 * off the server and loaded back.
 *
 * Written against PDO rather than shelling out to mysqldump: shared cPanel
 * accounts routinely disable exec(), and a backup that only works on some
 * hosts is not a backup. The cost is that this has to get the SQL right
 * itself, which is what most of the code below is doing.
 *
 * Memory is the other constraint. A cPanel account may allow 128MB, and
 * the documents table alone will outgrow that eventually, so nothing is
 * ever held whole: rows are read in batches and written straight out to a
 * gzip stream.
 */
class Backup
{
    /** Rows read from a table at a time. Small enough to stay in memory. */
    private const BATCH = 500;

    /** Where backups live. Denied to the web by storage/.htaccess. */
    public static function directory(): string
    {
        return BASE_PATH . '/storage/backups';
    }

    /**
     * Make a backup.
     *
     * @return array{ok:bool, file?:string, name?:string, bytes?:int,
     *                tables?:int, rows?:int, uploads?:int, error?:string}
     */
    public static function run(bool $includeUploads = true): array
    {
        $dir = self::directory();

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create the backup directory.'];
        }

        $stamp = date('Y-m-d_His');
        $name  = 'shanfix-' . $stamp;
        $sql   = $dir . '/' . $name . '.sql.gz';

        try {
            $written = self::dumpDatabase($sql);
        } catch (\Throwable $e) {
            @unlink($sql);
            Logger::error('Backup failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $result = [
            'ok'     => true,
            'file'   => $sql,
            'name'   => $name,
            'tables' => $written['tables'],
            'rows'   => $written['rows'],
            'bytes'  => (int) filesize($sql),
            'uploads' => 0,
        ];

        // The database knows a file was attached; only the uploads folder
        // has the file. A backup with one and not the other restores a
        // system full of broken links.
        if ($includeUploads) {
            $zip = $dir . '/' . $name . '-uploads.zip';
            $n   = self::archiveUploads($zip);

            if ($n > 0) {
                $result['uploads']       = $n;
                $result['uploads_file']  = $zip;
                $result['uploads_bytes'] = (int) filesize($zip);
                $result['bytes']        += (int) filesize($zip);
            }
        }

        Logger::info('Backup written', [
            'name'   => $name,
            'tables' => $result['tables'],
            'rows'   => $result['rows'],
            'bytes'  => $result['bytes'],
        ]);

        self::prune();

        return $result;
    }

    /**
     * Every backup on disk, newest first.
     *
     * @return array<int,array{name:string, at:int, bytes:int, sql:string,
     *                         uploads:?string, verified:?bool}>
     */
    public static function all(): array
    {
        $dir = self::directory();

        if (!is_dir($dir)) {
            return [];
        }

        $out = [];

        foreach (glob($dir . '/*.sql.gz') ?: [] as $sql) {
            $name    = basename($sql, '.sql.gz');
            $uploads = $dir . '/' . $name . '-uploads.zip';
            $bytes   = (int) filesize($sql);

            if (is_file($uploads)) {
                $bytes += (int) filesize($uploads);
            }

            $out[] = [
                'name'    => $name,
                'at'      => (int) filemtime($sql),
                'bytes'   => $bytes,
                'sql'     => $sql,
                'uploads' => is_file($uploads) ? $uploads : null,
            ];
        }

        usort($out, static fn($a, $b) => $b['at'] <=> $a['at']);

        return $out;
    }

    /** One backup by name, or null. Name is validated, never trusted. */
    public static function find(string $name): ?array
    {
        foreach (self::all() as $b) {
            if ($b['name'] === $name) {
                return $b;
            }
        }

        return null;
    }

    /**
     * Read a backup back and check it is what it claims to be.
     *
     * An untested backup is a guess. This does not restore anything: it
     * reads the whole file through gzip, which fails loudly on a truncated
     * or corrupt archive, and counts what it finds so the figures can be
     * compared against the database it came from.
     *
     * @return array{ok:bool, tables:int, inserts:int, error?:string}
     */
    public static function verify(string $name): array
    {
        $backup = self::find($name);

        if (!$backup) {
            return ['ok' => false, 'tables' => 0, 'inserts' => 0, 'error' => 'No such backup.'];
        }

        $fh = @gzopen($backup['sql'], 'rb');

        if (!$fh) {
            return ['ok' => false, 'tables' => 0, 'inserts' => 0, 'error' => 'The file could not be opened.'];
        }

        $tables  = 0;
        $inserts = 0;
        $sawEnd  = false;

        while (($line = gzgets($fh, 1024 * 64)) !== false) {
            if (str_starts_with($line, 'CREATE TABLE')) {
                $tables++;
            } elseif (str_starts_with($line, 'INSERT INTO')) {
                $inserts++;
            } elseif (str_starts_with($line, '-- End of backup')) {
                $sawEnd = true;
            }
        }

        gzclose($fh);

        // The dump writes its own end marker last. Without it the file was
        // cut short — the host timed out, or the disk filled.
        if (!$sawEnd) {
            return [
                'ok'      => false,
                'tables'  => $tables,
                'inserts' => $inserts,
                'error'   => 'The file is incomplete — it has no end marker, so the backup was cut short.',
            ];
        }

        return ['ok' => true, 'tables' => $tables, 'inserts' => $inserts];
    }

    /** Delete one backup and its uploads archive. */
    public static function delete(string $name): bool
    {
        $backup = self::find($name);

        if (!$backup) {
            return false;
        }

        @unlink($backup['sql']);

        if ($backup['uploads']) {
            @unlink($backup['uploads']);
        }

        return true;
    }

    /**
     * Drop the oldest backups past the retention setting.
     *
     * Shared hosting sells a fixed disk quota, and a backup that fills it
     * takes the website down with it — the failure mode is worse than
     * having one fewer old copy.
     */
    public static function prune(): int
    {
        $keep = max(1, Settings::int('backup_keep', 7));
        $all  = self::all();
        $gone = 0;

        foreach (array_slice($all, $keep) as $old) {
            self::delete($old['name']);
            $gone++;
        }

        if ($gone > 0) {
            Logger::info('Old backups removed', ['removed' => $gone, 'kept' => $keep]);
        }

        return $gone;
    }

    /** Whether a backup is due, by the daily schedule. */
    public static function isDue(): bool
    {
        if (!Settings::bool('backup_enabled', true)) {
            return false;
        }

        $all = self::all();

        if ($all === []) {
            return true;
        }

        $hours = max(1, Settings::int('backup_every_hours', 24));

        return (time() - $all[0]['at']) >= $hours * 3600;
    }

    // -- Internals ---------------------------------------------------------

    /**
     * Write the whole database as gzipped SQL.
     *
     * @return array{tables:int, rows:int}
     */
    private static function dumpDatabase(string $path): array
    {
        $fh = gzopen($path, 'wb9');

        if (!$fh) {
            throw new \RuntimeException('Could not open the backup file for writing.');
        }

        $pdo = Database::pdo();

        $put = static function (string $s) use ($fh): void {
            if (gzwrite($fh, $s) === false) {
                throw new \RuntimeException('Writing to the backup file failed — the disk may be full.');
            }
        };

        $put("-- Shanfix Technology — database backup\n");
        $put('-- Taken ' . date('Y-m-d H:i:s') . "\n");
        $put("-- Restore with:  gunzip < this-file.sql.gz | mysql -u USER -p DATABASE\n");
        $put("--\n\n");
        $put("SET NAMES utf8mb4;\n");
        $put("SET FOREIGN_KEY_CHECKS = 0;\n");
        $put("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
        $count  = 0;
        $rows   = 0;

        foreach ($tables as $row) {
            $table = (string) $row[0];

            // A view has no rows of its own and restoring one before the
            // tables it reads would fail, so views are written last.
            if (($row[1] ?? '') === 'VIEW') {
                continue;
            }

            $count += 1;
            $rows  += self::dumpTable($pdo, $table, $put);
        }

        foreach ($tables as $row) {
            if (($row[1] ?? '') !== 'VIEW') {
                continue;
            }

            $create = $pdo->query('SHOW CREATE VIEW ' . self::quote((string) $row[0]))
                          ->fetch(\PDO::FETCH_NUM);

            $put("\nDROP VIEW IF EXISTS " . self::quote((string) $row[0]) . ";\n");
            $put(($create[1] ?? '') . ";\n");
        }

        $put("\nSET FOREIGN_KEY_CHECKS = 1;\n");

        // Read back by verify() to prove the file is not truncated.
        $put("\n-- End of backup\n");

        gzclose($fh);

        return ['tables' => $count, 'rows' => $rows];
    }

    /**
     * One table: its structure, then its rows in batches.
     *
     * @param callable(string):void $put
     */
    private static function dumpTable(\PDO $pdo, string $table, callable $put): int
    {
        $quoted = self::quote($table);
        $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(\PDO::FETCH_NUM);

        $put("\n--\n-- {$table}\n--\n\n");
        $put("DROP TABLE IF EXISTS {$quoted};\n");
        $put(($create[1] ?? '') . ";\n\n");

        $total  = (int) $pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();
        $offset = 0;
        $done   = 0;

        while ($offset < $total) {
            $stmt = $pdo->query("SELECT * FROM {$quoted} LIMIT " . self::BATCH . " OFFSET {$offset}");
            $batch = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($batch === []) {
                break;
            }

            $cols = implode(', ', array_map([self::class, 'quote'], array_keys($batch[0])));

            foreach ($batch as $record) {
                $values = [];

                foreach ($record as $v) {
                    $values[] = self::literal($pdo, $v);
                }

                // One INSERT per row rather than one long multi-row
                // statement: it restores under any max_allowed_packet, and
                // a single corrupt row does not take the whole table with it.
                $put("INSERT INTO {$quoted} ({$cols}) VALUES (" . implode(', ', $values) . ");\n");
                $done++;
            }

            $offset += self::BATCH;

            // fetchAll holds the batch; let it go before reading the next.
            unset($batch, $stmt);
        }

        return $done;
    }

    /** A single value as SQL. */
    private static function literal(\PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }

        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        $s = (string) $v;

        // Binary columns would not survive being written as text, so they
        // go out as hex, which MySQL accepts directly.
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '0x' . bin2hex($s);
        }

        return $pdo->quote($s);
    }

    /** An identifier, safe to interpolate. */
    private static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Everything under storage/uploads, zipped.
     *
     * Returns the number of files added, or 0 if there was nothing to add
     * or the archive could not be created.
     */
    private static function archiveUploads(string $path): int
    {
        $root = BASE_PATH . '/storage/uploads';

        if (!is_dir($root) || !class_exists(\ZipArchive::class)) {
            return 0;
        }

        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Logger::warning('Could not create the uploads archive', ['path' => $path]);

            return 0;
        }

        $added = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $full = $file->getPathname();

            // Forward slashes always. A zip written with Windows separators
            // extracts on Linux as one file with backslashes in its name.
            $rel = str_replace('\\', '/', substr($full, strlen($root) + 1));

            if ($rel === '' || str_starts_with($rel, '.')) {
                continue;
            }

            if ($zip->addFile($full, $rel)) {
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($path);
        }

        return $added;
    }
}
