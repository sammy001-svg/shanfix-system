<?php
/**
 * Build a stand-in for the old Bulk SMS platform, and fill it.
 *
 *   php tests/helpers/legacy_sms_fixture.php <database> [--drop]
 *
 * The real thing is sms.shanfixtechnology.com / bulk_sms_system. The
 * cutover cannot be rehearsed against that — it is live, and a rehearsal
 * that writes to it is not a rehearsal. So the old platform's own
 * schema.sql is loaded into a scratch database here and seeded with the
 * shapes that actually cause trouble:
 *
 *   - an admin holding the house units
 *   - a reseller with clients under them
 *   - a client belonging to no reseller
 *   - a contact whose group was deleted, so group_id points at nothing
 *   - a campaign left "running" when the platform stopped
 *   - a sender ID already approved, and one still pending
 *   - a password hash nothing can read
 *
 * The schema is read from the old platform's own file rather than
 * written out again here, so this cannot drift from what the importer
 * will meet on the night.
 *
 * Lives in tests/helpers and is never reachable over the web.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Config;

Config::load(CONFIG_PATH . '/config.php');

$name = (string) ($argv[1] ?? '');
$drop = in_array('--drop', $argv, true);

if ($name === '' || !preg_match('/^[a-z0-9_]+$/i', $name)) {
    fwrite(STDERR, "usage: legacy_sms_fixture.php <database> [--drop]\n");
    exit(2);
}

// The same refusal tests/config.sh makes: this creates and drops whole
// databases, so it will only ever touch one that says it is for testing.
if (!str_contains($name, 'test')) {
    fwrite(STDERR, "Refusing: '{$name}' does not look like a test database.\n");
    exit(2);
}

// Where the old platform's source sits on this machine. Its schema is
// the point of the exercise, so a missing copy is a hard stop rather
// than something to guess around.
$schemaCandidates = [
    'C:/Bulksms/shanfix-bulk-sms/database/schema.sql',
    'C:/xampp/htdocs/bulk-sms-system/database/schema.sql',
    'C:/xampp/htdocs/shanfix-bulk-sms/database/schema.sql',
];

$schema = null;

foreach ($schemaCandidates as $path) {
    if (is_readable($path)) {
        $schema = $path;
        break;
    }
}

if ($schema === null) {
    fwrite(STDERR, "Could not find the old platform's schema.sql. Looked in:\n  "
        . implode("\n  ", $schemaCandidates) . "\n");
    exit(3);
}

$db  = Config::get('db');
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $db['host'], $db['port']);

$pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

if ($drop) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
    echo "dropped {$name}\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
$pdo->exec("CREATE DATABASE `{$name}` DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$name}`");

// The file carries its own USE / CREATE DATABASE lines for the real
// name, which would put every table in the wrong place.
$sql = (string) file_get_contents($schema);
$sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/mi', '', $sql) ?? $sql;

$pdo->exec($sql);

// schema.sql seeds a default administrator of its own, and later files
// seed settings. Everything is cleared so the rows below are the whole
// story and their ids are the ones the assertions expect.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec("TRUNCATE TABLE `{$table}`");
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ── Who is on the old platform ───────────────────────────────────────────
$hash = password_hash('OldPass1', PASSWORD_DEFAULT);

$pdo->exec("INSERT INTO users (id, name, email, phone, password_hash, role, parent_id, company, sms_units, custom_unit_price, status) VALUES
  (1, 'Platform Admin',  'admin@legacy.test',   '0700000001', '{$hash}', 'admin',    NULL, 'Shanfix',        250000.00, NULL,   'active'),
  (2, 'Rift Resellers',  'rift@legacy.test',    '0700000002', '{$hash}', 'reseller', NULL, 'Rift Resellers', 40000.00,  0.5500, 'active'),
  (3, 'Nakuru Dental',   'dental@legacy.test',  '0700000003', '{$hash}', 'client',   2,    'Nakuru Dental',  1250.50,   0.8000, 'active'),
  (4, 'Java Junction',   'java@legacy.test',    '0700000004', '{$hash}', 'client',   2,    'Java Junction',  300.00,    NULL,   'suspended'),
  (5, 'Direct Client',   'direct@legacy.test',  '0700000005', '{$hash}', 'client',   NULL, 'Direct Ltd',     900.00,    NULL,   'active'),
  -- Nothing here can read this, so no login is made for them.
  (6, 'Ancient Account', 'ancient@legacy.test', '0700000006', 'md5:deadbeef', 'client', NULL, 'Ancient Ltd', 10.00,   NULL,   'active')
");

// ── Groups, one of which gets deleted underneath its contacts ────────────
$pdo->exec("INSERT INTO contact_groups (id, user_id, name, description) VALUES
  (1, 3, 'Patients',   'Everyone who has been in'),
  (2, 3, 'Reminders',  'Six-month check-up list'),
  (3, 2, 'Rift leads', NULL),
  (4, 1, 'Staff',      'Our own people')
");

$pdo->exec("INSERT INTO contacts (id, user_id, group_id, name, phone, email, metadata) VALUES
  (1, 3, 1,    'Grace Njeri',  '254722814500', 'grace@example.com', '{\"balance\":\"2400\"}'),
  (2, 3, 1,    'Peter Otieno', '254733445566', NULL,                NULL),
  (3, 3, 2,    'Amina Hassan', '254712345678', 'amina@example.com', NULL),
  -- Group 9 never existed: the old platform nulls on delete, but a row
  -- pointing at a gone group is the case that matters.
  (4, 3, NULL, 'Brian Kimani', '254701020304', NULL,                NULL),
  (5, 2, 3,    'Rift Lead A',  '254799887766', NULL,                NULL),
  (6, 1, 4,    'Our Office',   '254711223344', NULL,                NULL)
");

// ── Sender IDs: one live, one waiting, one refused ───────────────────────
$pdo->exec("INSERT INTO sender_ids (id, user_id, sender_id, purpose, status, reject_reason, approved_by, approved_at) VALUES
  (1, 3, 'NKRDENTAL', 'Appointment reminders', 'approved', NULL,                 1, '2025-03-04 09:12:00'),
  (2, 3, 'DENTAL2',   'Second line',           'pending',  NULL,              NULL, NULL),
  (3, 4, 'JAVAJUNC',  'Offers',                'rejected', 'Too close to a bank', 1, '2025-05-01 11:00:00'),
  (4, 2, 'RIFTSMS',   'Reseller sending',      'approved', NULL,                 1, '2024-11-20 08:00:00')
");

// ── Campaigns, including one caught mid-flight ───────────────────────────
$pdo->exec("INSERT INTO campaigns (id, user_id, name, sender_id, message, group_id, recipients, total_count, sent_count, failed_count, units_used, status, scheduled_at, sent_at, created_at) VALUES
  (1, 3, 'March reminders', 'NKRDENTAL', 'Your check-up is due.', 1, NULL, 3, 3, 0, 3.00, 'completed', NULL, '2025-03-10 10:00:00', '2025-03-10 09:55:00'),
  (2, 3, 'Half-year list',  'NKRDENTAL', 'Six months already!',   2, NULL, 1, 0, 0, 0.00, 'running',   NULL, NULL,                 '2025-06-01 08:00:00'),
  (3, 3, 'Draft idea',      'NKRDENTAL', 'Not sent yet',          NULL, '254722814500', 1, 0, 0, 0.00, 'draft', NULL, NULL,        '2025-06-02 08:00:00'),
  (4, 2, 'Rift promo',      'RIFTSMS',   'Buy units today',       3, NULL, 1, 1, 0, 1.00, 'completed', NULL, '2025-02-02 12:00:00', '2025-02-02 11:00:00'),
  (5, 1, 'Staff notice',    'SHANFIX',   'Office closed Monday',  4, NULL, 1, 1, 0, 1.00, 'completed', NULL, '2025-01-05 07:00:00', '2025-01-05 06:30:00')
");

$pdo->exec("INSERT INTO messages (id, campaign_id, user_id, sender_id, recipient, message, units_charged, status, gateway_msg_id, sent_at, delivered_at, failed_reason, dlr_status, created_at) VALUES
  (1, 1, 3, 'NKRDENTAL', '254722814500', 'Your check-up is due.', 1.0000, 'delivered', 'GW-1', '2025-03-10 10:00:01', '2025-03-10 10:00:20', NULL, 'DELIVRD', '2025-03-10 10:00:00'),
  (2, 1, 3, 'NKRDENTAL', '254733445566', 'Your check-up is due.', 1.0000, 'delivered', 'GW-2', '2025-03-10 10:00:02', '2025-03-10 10:00:25', NULL, 'DELIVRD', '2025-03-10 10:00:00'),
  (3, 1, 3, 'NKRDENTAL', '254712345678', 'Your check-up is due.', 1.0000, 'failed',    'GW-3', '2025-03-10 10:00:03', NULL, 'Absent subscriber', 'AbsentSubscriber', '2025-03-10 10:00:00'),
  (4, 4, 2, 'RIFTSMS',   '254799887766', 'Buy units today',       1.0000, 'delivered', 'GW-4', '2025-02-02 12:00:01', '2025-02-02 12:00:11', NULL, 'DELIVRD', '2025-02-02 12:00:00'),
  (5, 5, 1, 'SHANFIX',   '254711223344', 'Office closed Monday',  1.0000, 'delivered', 'GW-5', '2025-01-05 07:00:01', '2025-01-05 07:00:09', NULL, 'DELIVRD', '2025-01-05 07:00:00'),
  -- No campaign behind it: sent one at a time from the dashboard.
  (6, NULL, 3, 'NKRDENTAL', '254700111222', 'Quick note', 1.0000, 'sent', 'GW-6', '2025-07-01 15:00:00', NULL, NULL, NULL, '2025-07-01 15:00:00')
");

echo "built {$name} from " . basename($schema) . "\n";
echo "  users 6, groups 4, contacts 6, sender ids 4, campaigns 5, messages 6\n";
