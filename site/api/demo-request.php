<?php
/**
 * Public API: somebody asking to see one of the systems we sell.
 *
 * No authentication — it is a form on a marketing page. A honeypot
 * field catches the bulk of the robots, and the rest is validation.
 *
 * WHAT HAPPENS IF THE SYSTEM IS DOWN
 *
 * The request is written to the site's own table first and pushed into
 * the business system second. A demo request is a sale asking to
 * happen, and losing one because the other application was restarting
 * is not acceptable — so the site keeps its own copy either way, and
 * the visitor is told it was received, because it was.
 *
 * When the push does work, the reference comes back and is shown to
 * them. "We have it, it is DEMO-2026-0004" reads like being dealt with
 * rather than like a form that went nowhere.
 */

header('Content-Type: application/json');

require_once '../includes/db_connect.php';
require_once '../includes/system.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = $_POST;
}

// Honeypot: a robot fills every field it finds. Accepted and dropped,
// because telling it that it failed only teaches it to try again.
if (!empty($input['website'])) {
    echo json_encode(['success' => true, 'message' => 'Thank you — we will be in touch shortly.']);
    exit;
}

$name         = trim((string) ($input['name'] ?? ''));
$organisation = trim((string) ($input['organisation'] ?? ''));
$role         = trim((string) ($input['role'] ?? ''));
$email        = trim((string) ($input['email'] ?? ''));
$phone        = trim((string) ($input['phone'] ?? ''));
$members      = trim((string) ($input['members'] ?? ''));
$message      = trim((string) ($input['message'] ?? ''));
$product      = trim((string) ($input['product'] ?? 'sacco'));

if ($name === '' || $email === '' || $phone === '') {
    echo json_encode([
        'success' => false,
        'message' => 'We need your name, your email and your phone number — the login goes to both.',
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'That email address does not look right.']);
    exit;
}

// Kenyan numbers in any of the ways people write them, and enough of an
// international number to be dialable. The login is sent here by text,
// so a number that cannot receive one is worth catching now rather than
// at the point of sending.
$digits = preg_replace('/[^0-9]/', '', $phone);

if (strlen($digits) < 9 || strlen($digits) > 15) {
    echo json_encode(['success' => false, 'message' => 'That phone number does not look right. We text the login to it.']);
    exit;
}

try {
    // The site's own copy, written first and unconditionally.
    $pdo->prepare(
        'CREATE TABLE IF NOT EXISTS demo_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product VARCHAR(40) NOT NULL DEFAULT "sacco",
            name VARCHAR(160) NOT NULL,
            organisation VARCHAR(180) DEFAULT NULL,
            role VARCHAR(120) DEFAULT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            members VARCHAR(40) DEFAULT NULL,
            message TEXT DEFAULT NULL,
            reference VARCHAR(20) DEFAULT NULL,
            pushed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pushed (pushed, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    )->execute();

    $stmt = $pdo->prepare(
        'INSERT INTO demo_requests (product, name, organisation, role, email, phone, members, message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $product, $name, $organisation ?: null, $role ?: null,
        $email, $phone, $members ?: null, $message ?: null,
    ]);

    $localId = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('Demo request could not be saved locally: ' . $e->getMessage());
    $localId = 0;
}

// And into the system, where staff actually work.
$reference = system_request_demo([
    'product'      => $product,
    'name'         => $name,
    'organisation' => $organisation,
    'role'         => $role,
    'email'        => $email,
    'phone'        => $phone,
    'members'      => $members,
    'message'      => $message,
    'source_page'  => substr((string) ($input['source'] ?? 'sacco.php'), 0, 120),
    'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
]);

if ($reference !== null && $localId > 0) {
    try {
        $pdo->prepare('UPDATE demo_requests SET reference = ?, pushed = 1 WHERE id = ?')
            ->execute([$reference, $localId]);
    } catch (Throwable $e) {
        error_log('Demo request reference could not be recorded: ' . $e->getMessage());
    }
}

if ($localId === 0 && $reference === null) {
    // Neither store took it. This is the one case where saying it
    // worked would be a lie.
    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong at our end. Please call us on the number below and we will set it up for you.',
    ]);
    exit;
}

echo json_encode([
    'success'   => true,
    'reference' => $reference,
    'message'   => $reference !== null
        ? 'Thank you. Your request is in — reference ' . $reference
          . '. We will set up your demo and text the login to ' . $phone . '.'
        : 'Thank you. Your request is in and we will be in touch shortly with your demo login.',
]);
