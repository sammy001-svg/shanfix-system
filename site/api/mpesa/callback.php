<?php
/**
 * M-PESA Daraja Callback
 * Called by Safaricom servers when a payment completes or fails.
 * MUST be publicly accessible via HTTPS.
 * No session/auth — validated by matching CheckoutRequestID.
 */

require_once '../../includes/db_connect.php';
require_once '../../includes/mailer.php';

// Log every callback for debugging
$raw = file_get_contents('php://input');
error_log('M-PESA Callback received: ' . $raw);

$input    = json_decode($raw, true);
$callback = $input['Body']['stkCallback'] ?? null;

if (!$callback) {
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

$checkoutRequestId = $callback['CheckoutRequestID'] ?? '';
$resultCode        = (int)($callback['ResultCode']  ?? -1);
$resultDesc        = $callback['ResultDesc']         ?? '';

try {
    $stmt = $pdo->prepare("SELECT id, invoice_id, amount FROM mpesa_transactions WHERE checkout_request_id = ?");
    $stmt->execute([$checkoutRequestId]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('M-PESA Callback DB error: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

if (!$txn) {
    error_log("M-PESA Callback: no transaction found for CheckoutRequestID {$checkoutRequestId}");
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

try {
    // ── Check event booking ───────────────────────────────────────────────────
    if (!$txn) {
        try {
            $evStmt = $pdo->prepare("
                SELECT eb.id, eb.buyer_name, eb.buyer_email, eb.buyer_phone, eb.total_amount, eb.reference,
                       e.title as event_title, e.event_date, e.venue
                FROM event_bookings eb
                JOIN events e ON eb.event_id = e.id
                WHERE eb.checkout_request_id = ?
            ");
            $evStmt->execute([$checkoutRequestId]);
            $evBooking = $evStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $evBooking = null; }

        if ($evBooking) {
            if ($resultCode === 0) {
                $meta    = $callback['CallbackMetadata']['Item'] ?? [];
                $receipt = '';
                foreach ($meta as $item) {
                    if (($item['Name'] ?? '') === 'MpesaReceiptNumber') $receipt = $item['Value'] ?? '';
                }
                $pdo->prepare("UPDATE event_bookings SET payment_status='paid', mpesa_receipt=? WHERE id=?")
                    ->execute([$receipt, $evBooking['id']]);

                // Send confirmation email
                $lineItems = $pdo->prepare("SELECT ticket_type_name as name, quantity as qty FROM event_booking_tickets WHERE booking_id=?");
                $lineItems->execute([$evBooking['id']]);
                $items = $lineItems->fetchAll(PDO::FETCH_ASSOC);
                $ticketStr = implode(', ', array_map(fn($l) => "{$l['name']} ×{$l['qty']}", $items));
                $eventDate = date('d M Y, H:i', strtotime($evBooking['event_date']));
                Mailer::send($evBooking['buyer_email'],
                    "Tickets Confirmed — {$evBooking['event_title']}",
                    Mailer::buildEventConfirmation($evBooking['buyer_name'], $evBooking['event_title'], $eventDate, $evBooking['venue'] ?? '', $ticketStr, $evBooking['reference'], (float)$evBooking['total_amount'])
                );
            } else {
                $pdo->prepare("UPDATE event_bookings SET payment_status='failed' WHERE id=?")->execute([$evBooking['id']]);
            }
            http_response_code(200);
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            exit;
        }
    }

    if ($resultCode === 0) {
        // ── Payment successful ────────────────────────────────────────────
        $meta    = $callback['CallbackMetadata']['Item'] ?? [];
        $receipt = '';
        $amount  = $txn['amount'];

        foreach ($meta as $item) {
            match ($item['Name'] ?? '') {
                'MpesaReceiptNumber' => ($receipt = $item['Value'] ?? ''),
                'Amount'             => ($amount  = $item['Value'] ?? $txn['amount']),
                default              => null
            };
        }

        // Update transaction
        $pdo->prepare(
            "UPDATE mpesa_transactions SET status='completed', mpesa_receipt=?, result_code=?, result_desc=? WHERE id=?"
        )->execute([$receipt, $resultCode, $resultDesc, $txn['id']]);

        // Mark invoice paid
        $pdo->prepare(
            "UPDATE invoices SET status='paid', paid_date=NOW() WHERE id=? AND status='unpaid'"
        )->execute([$txn['invoice_id']]);

        // Create receipt record (uses existing receipts table)
        $receiptRef = 'RCP-' . strtoupper(substr(md5($receipt . time()), 0, 8));
        $pdo->prepare(
            "INSERT IGNORE INTO receipts (invoice_id, receipt_ref, amount_paid, payment_method, transaction_ref)
             VALUES (?, ?, ?, 'M-PESA', ?)"
        )->execute([$txn['invoice_id'], $receiptRef, $amount, $receipt]);

        // Send payment confirmation email to client
        $clientStmt = $pdo->prepare("
            SELECT i.reference, u.full_name, u.email, i.guest_name, i.guest_email
            FROM invoices i
            LEFT JOIN users u ON i.user_id = u.id
            WHERE i.id = ?
        ");
        $clientStmt->execute([$txn['invoice_id']]);
        $inv = $clientStmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $clientName  = $inv['full_name']  ?: $inv['guest_name']  ?: 'Client';
            $clientEmail = $inv['email']       ?: $inv['guest_email'] ?: '';
            if ($clientEmail) {
                Mailer::paymentConfirmed($clientName, $clientEmail, $inv['reference'], (float)$amount, $receipt);
            }
        }

        error_log("M-PESA: Payment confirmed — invoice #{$txn['invoice_id']}, receipt {$receipt}");
    } else {
        // ── Payment failed / cancelled ────────────────────────────────────
        $pdo->prepare(
            "UPDATE mpesa_transactions SET status='failed', result_code=?, result_desc=? WHERE id=?"
        )->execute([$resultCode, $resultDesc, $txn['id']]);

        error_log("M-PESA: Payment failed — txn #{$txn['id']}, code {$resultCode}: {$resultDesc}");
    }
} catch (PDOException $e) {
    error_log('M-PESA Callback processing error: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
