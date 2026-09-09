<?php
/**
 * API: Initiate M-PESA STK Push
 * Client-authenticated endpoint.
 */

header('Content-Type: application/json');
require_once '../../includes/db_connect.php';
require_once 'token.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true);
$invoiceRef = trim($input['invoice_ref'] ?? '');
$rawPhone   = trim($input['phone']       ?? '');

if (empty($invoiceRef) || empty($rawPhone)) {
    echo json_encode(['success' => false, 'message' => 'Invoice reference and phone number are required.']);
    exit;
}

// Normalize phone to 254XXXXXXXXX
$phone = preg_replace('/\s+/', '', $rawPhone);
$phone = preg_replace('/^\+/', '', $phone);
if (preg_match('/^0([7|1][0-9]{8})$/', $phone, $m)) {
    $phone = '254' . $m[1];
}
if (!preg_match('/^254[71][0-9]{8}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number. Use 07XX or 254XX format (Safaricom).']);
    exit;
}

// Validate invoice belongs to this user and is unpaid
try {
    $stmt = $pdo->prepare("SELECT id, amount, status, reference FROM invoices WHERE reference = ? AND user_id = ?");
    $stmt->execute([$invoiceRef, $_SESSION['user_id']]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

if (!$invoice) {
    echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
    exit;
}
if ($invoice['status'] === 'paid') {
    echo json_encode(['success' => false, 'message' => 'This invoice has already been paid.']);
    exit;
}

// M-PESA credentials
$shortcode   = $_ENV['MPESA_SHORTCODE']    ?? '174379';
$passkey     = $_ENV['MPESA_PASSKEY']      ?? '';
$callbackUrl = $_ENV['MPESA_CALLBACK_URL'] ?? '';

if (empty($passkey)) {
    echo json_encode(['success' => false, 'message' => 'M-PESA is not configured on this server. Contact support.']);
    exit;
}
if (empty($callbackUrl) || str_starts_with($callbackUrl, 'https://yourdomain')) {
    echo json_encode(['success' => false, 'message' => 'M-PESA callback URL is not configured. Contact admin.']);
    exit;
}

$token = getMpesaToken();
if (!$token) {
    echo json_encode(['success' => false, 'message' => 'Could not connect to M-PESA. Please try again.']);
    exit;
}

$amount    = (int)ceil((float)$invoice['amount']);
$timestamp = date('YmdHis');
$password  = base64_encode($shortcode . $passkey . $timestamp);

$payload = [
    'BusinessShortCode' => $shortcode,
    'Password'          => $password,
    'Timestamp'         => $timestamp,
    'TransactionType'   => 'CustomerPayBillOnline',
    'Amount'            => $amount,
    'PartyA'            => $phone,
    'PartyB'            => $shortcode,
    'PhoneNumber'       => $phone,
    'CallBackURL'       => $callbackUrl,
    'AccountReference'  => $invoiceRef,
    'TransactionDesc'   => "Payment for {$invoiceRef}"
];

$baseUrl = getMpesaBaseUrl();
$ch = curl_init("{$baseUrl}/mpesa/stkpush/v1/processrequest");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$token}",
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$curlErr  = curl_errno($ch);
curl_close($ch);

if ($curlErr) {
    error_log("M-PESA STK Push cURL error: {$curlErr}");
    echo json_encode(['success' => false, 'message' => 'Network error contacting M-PESA. Try again.']);
    exit;
}

$result = json_decode($response, true);

if (isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO mpesa_transactions (invoice_id, checkout_request_id, merchant_request_id, phone, amount)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $invoice['id'],
            $result['CheckoutRequestID'],
            $result['MerchantRequestID'] ?? null,
            $phone,
            $amount
        ]);
        $transactionId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('M-PESA: Failed to save transaction — ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'STK Push sent but failed to record transaction.']);
        exit;
    }

    // Format phone for display: 254712345678 → +254 712 345 678
    $fmt = '+' . substr($phone, 0, 3) . ' ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3) . ' ' . substr($phone, 9);

    echo json_encode([
        'success'         => true,
        'transaction_id'  => $transactionId,
        'phone_formatted' => $fmt,
        'message'         => 'STK Push sent. Check your phone and enter your M-PESA PIN.'
    ]);
} else {
    $error = $result['errorMessage']       ??
             $result['ResponseDescription'] ??
             'M-PESA declined the request. Please try again.';
    error_log('M-PESA STK Push failed: ' . json_encode($result));
    echo json_encode(['success' => false, 'message' => $error]);
}
