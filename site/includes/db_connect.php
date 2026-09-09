<?php
/**
 * Database Connection using PDO
 * Shanfix Technology - Premium Backend Infrastructure
 */

require_once 'env_loader.php';

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
