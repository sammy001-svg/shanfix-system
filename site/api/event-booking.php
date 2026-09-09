<?php
/**
 * Event Booking API — Guest checkout + M-PESA STK push
 *
 * POST /api/event-booking.php
 *   { event_id, buyer_name, buyer_email, buyer_phone, mpesa_phone, tickets:[{type_id, qty}] }
 *
 * GET /api/event-booking.php?poll=<booking_id>
 *   → { status: 'pending'|'paid'|'failed' }
 */
header('Content-Type: application/json');
require_once '../includes/db_connect.php';
require_once '../includes/mailer.php';
require_once '../includes/env_loader.php';
loadEnv(__DIR__ . '/../.env');

// Auto-create tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `event_bookings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `reference` varchar(20) NOT NULL,
        `event_id` int(11) NOT NULL,
        `buyer_name` varchar(100) NOT NULL,
        `buyer_email` varchar(100) NOT NULL,
        `buyer_phone` varchar(25) NOT NULL,
        `total_amount` decimal(10,2) NOT NULL,
        `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
        `mpesa_receipt` varchar(50) DEFAULT NULL,
        `checkout_request_id` varchar(100) DEFAULT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `reference` (`reference`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `event_booking_tickets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `booking_id` int(11) NOT NULL,
        `ticket_type_id` int(11) NOT NULL,
        `ticket_type_name` varchar(100) NOT NULL,
        `quantity` int(11) NOT NULL DEFAULT 1,
        `unit_price` decimal(10,2) NOT NULL,
        PRIMARY KEY (`id`), KEY `booking_id` (`booking_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── GET: poll booking payment status ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll'])) {
    $bookingId = (int)$_GET['poll'];
    try {
        $s = $pdo->prepare("SELECT payment_status FROM event_bookings WHERE id = ?");
        $s->execute([$bookingId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => $row ? $row['payment_status'] : 'pending']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'pending']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$eventId    = (int)($body['event_id']    ?? 0);
$name       = trim($body['buyer_name']   ?? '');
$email      = trim($body['buyer_email']  ?? '');
$phone      = trim($body['buyer_phone']  ?? '');
$mpesa      = trim($body['mpesa_phone']  ?? $phone);
$tickets    = $body['tickets']           ?? []; // [{type_id, qty}]

if (!$eventId || !$name || !$email || !$phone || empty($tickets)) {
    echo json_encode(['success' => false, 'message' => 'All fields and at least one ticket are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Normalise M-PESA phone
$mpesa = preg_replace('/\s+/', '', $mpesa);
$mpesa = ltrim($mpesa, '+');
if (preg_match('/^0([71][0-9]{8})$/', $mpesa, $m)) $mpesa = '254' . $m[1];
if (!preg_match('/^254[71][0-9]{8}$/', $mpesa)) {
    echo json_encode(['success' => false, 'message' => 'Invalid M-PESA number. Use 07XX or 254XX format.']);
    exit;
}

// ── Validate event + ticket types ─────────────────────────────────────────────
try {
    $evStmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND status = 'published'");
    $evStmt->execute([$eventId]);
    $event = $evStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) { echo json_encode(['success' => false, 'message' => 'Event not found.']); exit; }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']); exit;
}

$lineItems = [];
$total     = 0;
foreach ($tickets as $t) {
    $typeId = (int)($t['type_id'] ?? 0);
    $qty    = max(1, (int)($t['qty'] ?? 1));
    if (!$typeId) continue;

    $ttStmt = $pdo->prepare("SELECT * FROM event_ticket_types WHERE id = ? AND event_id = ? AND status = 'active'");
    $ttStmt->execute([$typeId, $eventId]);
    $tt = $ttStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tt) { echo json_encode(['success' => false, 'message' => "Ticket type #{$typeId} not available."]); exit; }

    // Capacity check
    if ($tt['capacity'] !== null && ($tt['sold_count'] + $qty) > $tt['capacity']) {
        echo json_encode(['success' => false, 'message' => "Not enough seats for {$tt['name']}. Available: " . ($tt['capacity'] - $tt['sold_count'])]);
        exit;
    }

    $lineItems[] = ['type_id' => $typeId, 'name' => $tt['name'], 'price' => (float)$tt['price'], 'qty' => $qty];
    $total += (float)$tt['price'] * $qty;
}

if (empty($lineItems) || $total < 0) {
    echo json_encode(['success' => false, 'message' => 'No valid tickets selected.']); exit;
}
$amount = (int)ceil($total);

// ── Create booking ─────────────────────────────────────────────────────────────
$ref = 'EV-' . strtoupper(substr(md5(uniqid()), 0, 8));
try {
    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO event_bookings (reference,event_id,buyer_name,buyer_email,buyer_phone,total_amount) VALUES (?,?,?,?,?,?)"
    )->execute([$ref, $eventId, $name, $email, $phone, $amount]);
    $bookingId = (int)$pdo->lastInsertId();

    foreach ($lineItems as $li) {
        $pdo->prepare(
            "INSERT INTO event_booking_tickets (booking_id,ticket_type_id,ticket_type_name,quantity,unit_price) VALUES (?,?,?,?,?)"
        )->execute([$bookingId, $li['type_id'], $li['name'], $li['qty'], $li['price']]);
        // Increment sold count
        $pdo->prepare("UPDATE event_ticket_types SET sold_count = sold_count + ? WHERE id = ?")->execute([$li['qty'], $li['type_id']]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Could not create booking.']); exit;
}

// ── Fire M-PESA STK push (if configured) ──────────────────────────────────────
$shortcode   = $_ENV['MPESA_SHORTCODE']    ?? '';
$passkey     = $_ENV['MPESA_PASSKEY']      ?? '';
$callbackUrl = $_ENV['MPESA_CALLBACK_URL'] ?? '';

if (empty($passkey) || empty($shortcode) || $amount === 0) {
    // Free tickets or M-PESA not configured — mark as paid immediately for free tickets
    if ($amount === 0) {
        $pdo->prepare("UPDATE event_bookings SET payment_status='paid' WHERE id=?")->execute([$bookingId]);
        _sendConfirmationEmail($pdo, $bookingId, $event, $lineItems, $name, $email, $ref, $amount);
    }
    echo json_encode([
        'success'    => true,
        'stk_sent'   => false,
        'booking_id' => $bookingId,
        'reference'  => $ref,
        'message'    => $amount === 0 ? 'Tickets booked! Check your email.' : 'Booking saved. Our team will follow up for payment.',
    ]);
    exit;
}

require_once 'mpesa/token.php';
$token = getMpesaToken();
if (!$token) {
    echo json_encode(['success' => true, 'stk_sent' => false, 'booking_id' => $bookingId, 'reference' => $ref,
        'message' => 'Booking saved. Could not reach M-PESA — team will contact you.']);
    exit;
}

$timestamp = date('YmdHis');
$password  = base64_encode($shortcode . $passkey . $timestamp);
$payload   = [
    'BusinessShortCode' => $shortcode, 'Password' => $password, 'Timestamp' => $timestamp,
    'TransactionType'   => 'CustomerPayBillOnline',
    'Amount'            => $amount, 'PartyA' => $mpesa, 'PartyB' => $shortcode,
    'PhoneNumber'       => $mpesa, 'CallBackURL' => $callbackUrl,
    'AccountReference'  => $ref, 'TransactionDesc' => "Shanfix Event {$ref}",
];

$ch = curl_init(getMpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);
$response = curl_exec($ch);
$curlErr  = curl_errno($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['success' => true, 'stk_sent' => false, 'booking_id' => $bookingId, 'reference' => $ref,
        'message' => 'Booking saved. Network issue — team will contact you.']);
    exit;
}

$result = json_decode($response, true);

if (isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
    $pdo->prepare("UPDATE event_bookings SET checkout_request_id=? WHERE id=?")
        ->execute([$result['CheckoutRequestID'], $bookingId]);

    echo json_encode([
        'success'    => true,
        'stk_sent'   => true,
        'booking_id' => $bookingId,
        'reference'  => $ref,
        'message'    => 'STK Push sent. Enter your M-PESA PIN to confirm.',
    ]);
} else {
    $err = $result['errorMessage'] ?? $result['ResponseDescription'] ?? 'M-PESA declined.';
    echo json_encode(['success' => true, 'stk_sent' => false, 'booking_id' => $bookingId, 'reference' => $ref,
        'message' => "Booking saved ({$ref}). {$err} — team will follow up."]);
}

function _sendConfirmationEmail($pdo, $bookingId, $event, $lineItems, $name, $email, $ref, $total) {
    $ticketLines = implode(', ', array_map(fn($l) => "{$l['name']} ×{$l['qty']}", $lineItems));
    $eventDate   = date('d M Y, H:i', strtotime($event['event_date']));
    Mailer::send($email, "Event Tickets Confirmed — {$event['title']}",
        Mailer::buildEventConfirmation($name, $event['title'], $eventDate, $event['venue'] ?? '', $ticketLines, $ref, $total)
    );
}
