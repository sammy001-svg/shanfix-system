<?php
/**
 * Guest Printing & Branding Order + M-PESA STK Push
 * No session required — open to any visitor on printing-branding.php
 */

header('Content-Type: application/json');
require_once '../includes/db_connect.php';
require_once '../includes/env_loader.php';
loadEnv(__DIR__ . '/../.env');

// ── GET: poll payment status ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll'])) {
    $tid = (int)$_GET['poll'];
    try {
        $s = $pdo->prepare("SELECT status FROM mpesa_transactions WHERE id = ?");
        $s->execute([$tid]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => $row ? ($row['status'] ?? 'pending') : 'pending']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'pending']);
    }
    exit;
}

// ── POST: create order + fire STK push ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true);
$name  = trim($body['name']        ?? '');
$email = trim($body['email']       ?? '');
$phone = trim($body['phone']       ?? '');
$mpesa = trim($body['mpesa_phone'] ?? $phone);
$items = $body['items'] ?? [];
$total = (float)($body['total']    ?? 0);

// Basic validation
if (!$name || !$email || !$mpesa || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Name, email, phone, and at least one item are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Normalise M-PESA phone → 254XXXXXXXXX
$mpesa = preg_replace('/\s+/', '', $mpesa);
$mpesa = preg_replace('/^\+/', '', $mpesa);
if (preg_match('/^0([71][0-9]{8})$/', $mpesa, $m)) $mpesa = '254' . $m[1];
if (!preg_match('/^254[71][0-9]{8}$/', $mpesa)) {
    echo json_encode(['success' => false, 'message' => 'Invalid M-PESA number. Use 07XX or 254XX format.']);
    exit;
}

// ── Price the order from the stockroom, not from the basket ──────────────
//
// This used to add up the prices the browser sent, under a comment
// saying it was done "to prevent tampering". It prevented nothing: the
// prices came from the same request as everything else, so an order for
// a 6,500/= banner could arrive priced at one shilling and the STK push
// would ask for one shilling.
//
// It could not have been done any other way at the time — the card
// carried no product id, only a name, so there was nothing to look the
// price up by. Now that the catalogue is the system's inventory, every
// line has an id and the price is ours to decide.
require_once '../includes/system.php';

$computedTotal = 0.0;
$priced        = [];

foreach ($items as $item) {
    $qty    = max(1, (int) ($item['qty'] ?? 1));
    $itemId = (int) ($item['id'] ?? 0);
    $price  = $itemId > 0 ? system_item_price($itemId) : null;

    if ($price === null) {
        // Either it is not one of ours, or it has been withdrawn since
        // the page was loaded. Refusing beats guessing at what to charge.
        echo json_encode([
            'success' => false,
            'message' => 'One of the items is no longer available. Please refresh the page and try again.',
        ]);
        exit;
    }

    $computedTotal += $price * $qty;

    // What is recorded is what was charged, at the price we set.
    $priced[] = [
        'id'    => $itemId,
        'name'  => (string) ($item['name'] ?? ''),
        'qty'   => $qty,
        'price' => $price,
    ];
}

$items  = $priced;
$amount = (int) ceil($computedTotal);

if ($amount < 1) {
    echo json_encode(['success' => false, 'message' => 'Order total must be greater than zero.']);
    exit;
}

// ── 1. Find or create guest user ─────────────────────────────────────────
try {
    $s = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $s->execute([$email]);
    $user = $s->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $userId = $user['id'];
    } else {
        $pdo->prepare(
            "INSERT INTO users (full_name, email, phone, role, status, password) VALUES (?, ?, ?, 'client', 'active', ?)"
        )->execute([$name, $email, $phone, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
        $userId = (int)$pdo->lastInsertId();
    }
} catch (PDOException $e) {
    error_log('printing-order: user upsert failed — ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save order. Please try again.']);
    exit;
}

// ── 2. Create order ──────────────────────────────────────────────────────
$orderRef = 'PB-' . strtoupper(substr(md5(uniqid()), 0, 8));
$itemsSummary = implode(', ', array_map(fn($i) => "{$i['name']} ×{$i['qty']}", $items));

try {
    // Ensure printing_orders table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `printing_orders` (
        `id`          int(11)      NOT NULL AUTO_INCREMENT,
        `reference`   varchar(20)  NOT NULL,
        `user_id`     int(11)      DEFAULT NULL,
        `customer_name` varchar(100) NOT NULL,
        `customer_email` varchar(100) NOT NULL,
        `customer_phone` varchar(20) DEFAULT NULL,
        `items_json`  text         NOT NULL,
        `total`       decimal(10,2) NOT NULL,
        `status`      enum('pending','paid','cancelled') DEFAULT 'pending',
        `created_at`  timestamp    DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `reference` (`reference`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->prepare(
        "INSERT INTO printing_orders (reference, user_id, customer_name, customer_email, customer_phone, items_json, total)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$orderRef, $userId, $name, $email, $phone, json_encode($items), $amount]);
    $orderId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    error_log('printing-order: order insert failed — ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save order.']);
    exit;
}

// ── 3. Create invoice ────────────────────────────────────────────────────
try {
    $pdo->prepare(
        "INSERT INTO invoices (user_id, reference, amount, status, notes, created_at)
         VALUES (?, ?, ?, 'unpaid', ?, NOW())"
    )->execute([$userId, $orderRef, $amount, "Printing & Branding: {$itemsSummary}"]);
} catch (PDOException $e) {
    error_log('printing-order: invoice insert failed — ' . $e->getMessage());
    // Non-fatal — continue to STK push
}

// ── 4. Fire M-PESA STK push ──────────────────────────────────────────────
$shortcode   = $_ENV['MPESA_SHORTCODE']    ?? '';
$passkey     = $_ENV['MPESA_PASSKEY']      ?? '';
$callbackUrl = $_ENV['MPESA_CALLBACK_URL'] ?? '';

if (empty($passkey) || empty($shortcode)) {
    // M-PESA not configured — order saved, notify admin by email later
    echo json_encode([
        'success'     => true,
        'stk_sent'    => false,
        'order_ref'   => $orderRef,
        'message'     => 'Order received. Our team will contact you to complete payment.',
    ]);
    exit;
}

require_once 'mpesa/token.php';
$token = getMpesaToken();
if (!$token) {
    echo json_encode([
        'success'   => true,
        'stk_sent'  => false,
        'order_ref' => $orderRef,
        'message'   => 'Order saved. Could not reach M-PESA — our team will contact you.',
    ]);
    exit;
}

$timestamp = date('YmdHis');
$password  = base64_encode($shortcode . $passkey . $timestamp);
$payload   = [
    'BusinessShortCode' => $shortcode,
    'Password'          => $password,
    'Timestamp'         => $timestamp,
    'TransactionType'   => 'CustomerPayBillOnline',
    'Amount'            => $amount,
    'PartyA'            => $mpesa,
    'PartyB'            => $shortcode,
    'PhoneNumber'       => $mpesa,
    'CallBackURL'       => $callbackUrl,
    'AccountReference'  => $orderRef,
    'TransactionDesc'   => "Shanfix Print Order {$orderRef}",
];

$baseUrl = getMpesaBaseUrl();
$ch = curl_init("{$baseUrl}/mpesa/stkpush/v1/processrequest");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$curlErr  = curl_errno($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['success' => true, 'stk_sent' => false, 'order_ref' => $orderRef,
        'message' => 'Order saved. Network issue reaching M-PESA — team will contact you.']);
    exit;
}

$result = json_decode($response, true);

if (isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
    // Save transaction for polling
    $transactionId = null;
    try {
        // Ensure mpesa_transactions table has order_id column or use invoice_id
        $pdo->prepare(
            "INSERT INTO mpesa_transactions (invoice_id, checkout_request_id, merchant_request_id, phone, amount, status)
             SELECT id, ?, ?, ?, ?, 'pending' FROM invoices WHERE reference = ? LIMIT 1"
        )->execute([
            $result['CheckoutRequestID'],
            $result['MerchantRequestID'] ?? null,
            $mpesa,
            $amount,
            $orderRef,
        ]);
        $transactionId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('printing-order: mpesa_transactions insert failed — ' . $e->getMessage());
    }

    echo json_encode([
        'success'        => true,
        'stk_sent'       => true,
        'order_ref'      => $orderRef,
        'transaction_id' => $transactionId,
        'message'        => 'STK Push sent. Enter your M-PESA PIN to complete payment.',
    ]);
} else {
    $err = $result['errorMessage'] ?? $result['ResponseDescription'] ?? 'M-PESA request failed.';
    error_log('printing-order STK failed: ' . json_encode($result));
    // Order is still saved — just STK failed
    echo json_encode([
        'success'   => true,
        'stk_sent'  => false,
        'order_ref' => $orderRef,
        'message'   => "Order saved ({$orderRef}). " . $err . " — our team will follow up.",
    ]);
}
