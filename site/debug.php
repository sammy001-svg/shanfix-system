<?php
/**
 * SHANFIX TECHNOLOGY - SERVER DIAGNOSTICS
 * Run this on your cPanel server to identify connection issues.
 */

header('Content-Type: text/plain');
echo "=== SHANFIX SERVER DIAGNOSTICS ===\n\n";

// 1. Check PHP Version
echo "PHP Version: " . PHP_VERSION . "\n";

// 2. Check PDO MySQL Driver
echo "PDO MySQL Driver: " . (extension_loaded('pdo_mysql') ? "ENABLED" : "MISSING") . "\n";

// 3. Check .env existence
$envPath = __DIR__ . '/.env';
echo ".env File: " . (file_exists($envPath) ? "FOUND" : "NOT FOUND") . " at $envPath\n";

// 4. Check Environment Loading
require_once 'includes/env_loader.php';
echo "DB_HOST from ENV: " . ($_ENV['DB_HOST'] ?? 'NOT SET') . "\n";
echo "DB_NAME from ENV: " . ($_ENV['DB_NAME'] ?? 'NOT SET') . "\n";
echo "DB_USER from ENV: " . ($_ENV['DB_USER'] ?? 'NOT SET') . "\n";

// 5. Test Database Connection
echo "\n--- Database Connection Test ---\n";
try {
    require_once 'includes/db_connect.php';
    echo "Connection: SUCCESSFUL\n";
    
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables Found: " . implode(', ', $tables) . "\n";
} catch (Exception $e) {
    echo "Connection: FAILED\n";
    echo "Error Message: " . $e->getMessage() . "\n";
}

// 6. Check File Permissions
echo "\n--- Permission Check ---\n";
echo "client/api directory: " . (is_writable('client/api') ? "WRITABLE" : "NOT WRITABLE") . "\n";

echo "\n================================\n";
