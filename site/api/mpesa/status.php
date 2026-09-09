<?php
/**
 * API: M-PESA Transaction Status
 * Polled by the client every 3 seconds to check payment result.
 */

header('Content-Type: application/json');
require_once '../../includes/db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$txnId = (int)($_GET['id'] ?? 0);
if (!$txnId) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT mt.status, mt.mpesa_receipt, mt.result_desc, mt.result_code
        FROM mpesa_transactions mt
        JOIN invoices i ON mt.invoice_id = i.id
        WHERE mt.id = ? AND i.user_id = ?
    ");
    $stmt->execute([$txnId, $_SESSION['user_id']]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

if (!$txn) {
    echo json_encode(['success' => false, 'message' => 'Transaction not found.']);
    exit;
}

echo json_encode([
    'success' => true,
    'status'  => $txn['status'],
    'receipt' => $txn['mpesa_receipt'] ?? '',
    'message' => $txn['result_desc']   ?? ''
]);
