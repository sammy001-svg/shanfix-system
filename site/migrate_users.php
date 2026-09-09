<?php
require_once 'includes/db_connect.php';

try {
    $sql = "ALTER TABLE users 
            ADD COLUMN first_name VARCHAR(50), 
            ADD COLUMN last_name VARCHAR(50), 
            ADD COLUMN address1 VARCHAR(255), 
            ADD COLUMN address2 VARCHAR(255), 
            ADD COLUMN city VARCHAR(100), 
            ADD COLUMN state_region VARCHAR(100), 
            ADD COLUMN county VARCHAR(100), 
            ADD COLUMN billing_contact VARCHAR(100), 
            ADD COLUMN language VARCHAR(50), 
            ADD COLUMN tax_id VARCHAR(50), 
            ADD COLUMN referral_source VARCHAR(100)";
    
    $pdo->exec($sql);
    echo "Migration successful: users table updated.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
