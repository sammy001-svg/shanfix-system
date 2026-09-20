<?php
/**
 * Print one setting's real value, for the shell suites.
 *
 * Some settings are encrypted at rest — the Kopo Kopo keys among them —
 * so reading the row straight out of the database gives ciphertext. A
 * test that signs a webhook with that gets a 401 and looks like a broken
 * integration when it is only a broken test.
 *
 * Usage:  php tests/helpers/setting.php kopokopo_api_key
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

if (!str_contains((string) Database::scalar('SELECT DATABASE()'), 'test')) {
    fwrite(STDERR, "refusing: not a test database\n");
    exit(1);
}

echo (string) Settings::get((string) ($argv[1] ?? ''), '');
