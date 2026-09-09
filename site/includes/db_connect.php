<?php
/**
 * Database Connection using PDO
 * Shanfix Technology - Premium Backend Infrastructure
 */

// env_loader.php reads .env as it is included, so the settings below are
// already in place by this line.
require_once __DIR__ . '/env_loader.php';

$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'shanfix_tech';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$port = $_ENV['DB_PORT'] ?? '3306';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset;port=$port";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     // Say what actually happened, somewhere.
     //
     // This used to show the same ninety-byte page for every cause —
     // no .env, a wrong password, a database that does not exist,
     // a user without rights — and write nothing anywhere. From
     // outside, four quite different faults were indistinguishable, and
     // finding out which took days of guessing.
     //
     // The visitor still sees nothing but the notice. The reason goes to
     // the error log, where whoever is fixing it can read it, and it
     // names which .env was read and which settings came out of it. The
     // password is never written, only whether there is one.
     $envPath = __DIR__ . '/../.env';

     error_log(sprintf(
         'shanfix site: database connection failed — %s | .env: %s | host=%s port=%s db=%s user=%s password=%s',
         $e->getMessage(),
         is_file($envPath) ? 'read from ' . $envPath : 'NOT FOUND at ' . $envPath,
         $host,
         $port,
         $db,
         $user,
         $pass === '' ? 'none' : 'set'
     ));

     if (($_ENV['DEBUG'] ?? 'false') === 'true') {
         die('Database Connection Failed: ' . $e->getMessage()
             . "\n\n.env: " . (is_file($envPath) ? 'found' : 'NOT FOUND at ' . $envPath)
             . "\nhost=$host port=$port db=$db user=$user password="
             . ($pass === '' ? 'none' : 'set'));
     }

     die('System Maintenance: We are currently upgrading our infrastructure. Please try again later.');
}
