<?php
/**
 * Database Connection using PDO
 * Shanfix Technology - Premium Backend Infrastructure
 */

require_once __DIR__ . '/env_loader.php';

// Actually read it.
//
// This file required the loader and then never called it, so $_ENV was
// never populated and every value below fell through to its default:
// localhost, shanfix_tech, root, no password. On a developer's machine
// that is exactly right and nothing looks wrong. On a real server it is
// wrong three ways over — cPanel prefixes the database name with the
// account, the user is not root, and there is a password — so every page
// that opens the database answered "System Maintenance" no matter what
// anybody put in .env.
//
// api/printing-order.php had been calling loadEnv() itself for the same
// reason, which is the sort of thing that happens once the connection
// stops reading it.
loadEnv(__DIR__ . '/../.env');

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
     // In production, log error instead of echoing
     if (($_ENV['DEBUG'] ?? 'false') === 'true') {
         die("Database Connection Failed: " . $e->getMessage());
     } else {
         die("System Maintenance: We are currently upgrading our infrastructure. Please try again later.");
     }
}
