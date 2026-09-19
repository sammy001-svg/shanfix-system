<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Settings;
use App\Services\BulkSms\DlrStatus;

/**
 * Delivery reports from Onfon.
 *
 * Onfon calls this when the network tells it what happened to a message:
 * delivered, the phone was off, the number does not exist. Public and
 * sessionless — the caller is Onfon's server.
 *
 * It answers at the old platform's address too (/webhooks/sms-dlr.php),
 * because that is the URL typed into the Onfon portal, and a switchover
 * that silently stopped every delivery report would look like every
 * message had stopped arriving.
 *
 * Two statuses are written per message, as before:
 *   status     — delivered / undelivered, when the report is final
 *   dlr_status — the carrier's own word, always, final or not; the
 *                delivery report pages are laid out by it
 */
class BulkSmsWebhookController extends Controller
{
    public function dlr(Request $request): void
    {
        header('Content-Type: application/json');

        // An optional shared secret. When set, Onfon's URL must carry it
        // as ?token=…, and anything without it is refused.
        $expected = trim((string) Settings::get('bulk_sms_dlr_token', ''));

        if ($expected !== '') {
            $given = (string) ($request->query('token', '') ?: ($_SERVER['HTTP_X_DLR_TOKEN'] ?? ''));

            if (!hash_equals($expected, $given)) {
                $this->log('refused: bad token');
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                return;
            }
        }

        // Onfon sends form fields or JSON depending on how the account is
        // set up. Both, with form fields winning.
        $raw  = $request->rawBody();
        $json = $raw !== '' ? json_decode($raw, true) : null;
        $data = array_merge(is_array($json) ? $json : [], $_GET, $_POST);

        $msgId = (string) ($data['MessageId'] ?? $data['messageId'] ?? $data['msgid'] ?? '');
        $code  = (string) ($data['Status'] ?? $data['status'] ?? $data['dlr_status'] ?? '');
        $when  = $data['Timestamp'] ?? $data['timestamp'] ?? null;

        // The descriptive status, when there is one, is richer than the
        // numeric code and is what Onfon's own report is laid out by.
        $words = $data['StatusDescription'] ?? $data['statusDescription']
            ?? $data['DeliveryStatus'] ?? $data['deliveryStatus']
            ?? $data['StatusText'] ?? $data['ErrorCode'] ?? null;

        $this->log('in: ' . mb_substr($raw !== '' ? $raw : json_encode($_POST), 0, 400));

        if ($msgId === '') {
            echo json_encode(['status' => 'ignored', 'reason' => 'no MessageId']);
            return;
        }

        $label  = DlrStatus::normalise(($words !== null && trim((string) $words) !== '') ? $words : $code);
        $status = DlrStatus::toEnum($label);

        try {
            if ($status === null) {
                // In flight: record what the carrier said, change nothing else.
                $n = Database::run(
                    'UPDATE bulk_messages SET dlr_status = :d WHERE gateway_msg_id = :m',
                    ['d' => $label, 'm' => $msgId]
                )->rowCount();

                echo json_encode(['status' => 'ok', 'terminal' => false, 'updated' => $n]);
                return;
            }

            $deliveredAt = null;
            $reason      = null;

            if ($status === 'delivered') {
                $ts = $when ? strtotime((string) $when) : false;
                $deliveredAt = date('Y-m-d H:i:s', $ts !== false ? $ts : time());
            } else {
                $reason = 'Undelivered: ' . $label;
            }

            // Only a message still marked sent moves. One refused at send
            // time stays failed — its units were refunded, and a late
            // receipt must not make it look delivered and charged.
            $n = Database::run(
                "UPDATE bulk_messages
                    SET status = :s, delivered_at = :at,
                        failed_reason = COALESCE(:r, failed_reason), dlr_status = :d
                  WHERE gateway_msg_id = :m AND status = 'sent'",
                ['s' => $status, 'at' => $deliveredAt, 'r' => $reason, 'd' => $label, 'm' => $msgId]
            )->rowCount();

            if ($n === 0) {
                // A repeat receipt: keep the carrier's latest word anyway.
                Database::run(
                    'UPDATE bulk_messages SET dlr_status = :d WHERE gateway_msg_id = :m',
                    ['d' => $label, 'm' => $msgId]
                );
            }

            $this->log('msg=' . $msgId . ' status=' . $status . ' dlr=' . $label . ' updated=' . $n);

            echo json_encode(['status' => 'ok', 'updated' => $n]);
        } catch (\Throwable $e) {
            $this->log('error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error']);
        }
    }

    private function log(string $line): void
    {
        @file_put_contents(STORAGE_PATH . '/logs/sms-dlr.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }
}
