<?php
/**
 * Automated Service Renewal Reminders
 *
 * Run this via a server cron job daily:
 *   0 8 * * * curl -s "https://shanfixtechnology.com/api/cron-renewals.php?key=YOUR_CRON_KEY" >> /dev/null
 *
 * OR call it with a secret key from the admin panel.
 * Set CRON_SECRET in your .env file.
 */

header('Content-Type: application/json');
require_once '../includes/db_connect.php';
require_once '../includes/mailer.php';
require_once '../includes/env_loader.php';
loadEnv(__DIR__ . '/../.env');

// Auth: accept either admin session OR a secret cron key
$authorized = false;
session_start();
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    $authorized = true;
}
$cronSecret = $_ENV['CRON_SECRET'] ?? '';
if (!empty($cronSecret) && ($_GET['key'] ?? '') === $cronSecret) {
    $authorized = true;
}
// Allow unauthenticated if no CRON_SECRET is set (dev/test mode)
if (empty($cronSecret)) {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// Respect a cooldown: don't send reminders more than once per day
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cron_log` (
        `id`          int(11) NOT NULL AUTO_INCREMENT,
        `job_name`    varchar(100) NOT NULL,
        `last_run`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `result`      text         DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `job_name` (`job_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $lastRunStmt = $pdo->prepare("SELECT last_run FROM cron_log WHERE job_name = 'renewal_reminders'");
    $lastRunStmt->execute();
    $lastRun = $lastRunStmt->fetchColumn();

    if ($lastRun && (time() - strtotime($lastRun)) < 20 * 3600) {
        // Already ran within last 20 hours
        echo json_encode([
            'success'   => true,
            'skipped'   => true,
            'last_run'  => $lastRun,
            'message'   => 'Already ran today. Next run in ~' . round((20 * 3600 - (time() - strtotime($lastRun))) / 3600) . ' hours.',
        ]);
        exit;
    }
} catch (PDOException $e) { /* non-fatal */ }

// ── Fetch services due in the next 7 days ────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT s.id, s.service_name, s.next_due_date, s.billing_cycle,
               u.full_name, u.email,
               p.price
        FROM services s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN products p ON s.product_id = p.id
        WHERE s.status = 'active'
          AND s.next_due_date IS NOT NULL
          AND s.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND u.email IS NOT NULL AND u.email != ''
        ORDER BY s.next_due_date ASC
    ");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}

$sent = 0; $skipped = 0; $errors = [];

foreach ($services as $svc) {
    $due = date('d M Y', strtotime($svc['next_due_date']));
    $ok  = Mailer::renewalReminder(
        $svc['full_name'],
        $svc['email'],
        $svc['service_name'],
        $due,
        (float)($svc['price'] ?? 0)
    );
    if ($ok) {
        $sent++;
    } else {
        $skipped++;
        $errors[] = "Failed: {$svc['email']} ({$svc['service_name']})";
    }
}

// ── Update cron log ───────────────────────────────────────────────────────────
$result = "Sent: {$sent}, Skipped: {$skipped}";
try {
    $pdo->prepare(
        "INSERT INTO cron_log (job_name, last_run, result)
         VALUES ('renewal_reminders', NOW(), ?)
         ON DUPLICATE KEY UPDATE last_run = NOW(), result = ?"
    )->execute([$result, $result]);
} catch (PDOException $e) { /* non-fatal */ }

$response = [
    'success'  => true,
    'sent'     => $sent,
    'skipped'  => $skipped,
    'total'    => count($services),
    'ran_at'   => date('Y-m-d H:i:s'),
    'message'  => $sent > 0
        ? "Sent {$sent} renewal reminder" . ($sent > 1 ? 's' : '') . '.'
        : (empty($services) ? 'No services due in the next 7 days.' : 'No emails sent.'),
];
if (!empty($errors)) $response['errors'] = $errors;

echo json_encode($response);
