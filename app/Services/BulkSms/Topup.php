<?php
namespace App\Services\BulkSms;

use App\Core\ActivityLog;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Services\KopoKopo;
use App\Services\Notifier;

/**
 * Buying SMS units with M-Pesa, from the portal.
 *
 * The prompt goes out through the same integration that pays invoices —
 * one set of KopoKopo credentials, one callback, one place where a
 * payment is settled. What is different is what happens when the money
 * lands: instead of posting a payment against an invoice, the purchase
 * is completed and the units move.
 *
 * The purchase is recorded before the prompt is sent, so a callback that
 * arrives before our own HTTP response still finds something to credit.
 */
final class Topup
{
    /** Prompts one account may trigger in an hour, as on invoices. */
    public const MAX_ATTEMPTS_PER_HOUR = 6;

    /**
     * Can somebody buy units on their phone right now?
     *
     * Three things have to be true, and all three are worth reporting
     * separately when they are not — "M-Pesa is off" and "M-Pesa has no
     * till number" look identical to a customer staring at a missing
     * button, and cost the office the same phone call.
     *
     * @return array{ok:bool, reason:string}
     */
    public static function readiness(): array
    {
        if (!Settings::bool('bulk_sms_mpesa', true)) {
            return ['ok' => false, 'reason' => 'Paying for SMS units by M-Pesa is switched off in the SMS gateway settings.'];
        }

        if (!Settings::bool('kopokopo_enabled')) {
            return ['ok' => false, 'reason' => 'M-Pesa payments are switched off for the whole system, under Settings → Payments.'];
        }

        if (!(new KopoKopo())->isConfigured()) {
            return ['ok' => false, 'reason' => 'M-Pesa is on but has no client id, secret or till number yet, under Settings → Payments.'];
        }

        return ['ok' => true, 'reason' => 'Customers and partners can buy units on their phone.'];
    }

    public static function available(): bool
    {
        return self::readiness()['ok'];
    }

    /**
     * Ask for the money and record what it is for.
     *
     * @param array{plan_id?:int, units?:float, actor_type?:string, actor_id?:int} $what
     * @return array{ok:bool, error?:string, purchase_id?:int, stk_id?:int, pending?:bool, amount?:float, units?:float}
     */
    public static function request(int $accountId, string $rawPhone, array $what, string $source): array
    {
        if (!self::available()) {
            return ['ok' => false, 'error' => 'Paying by M-Pesa is switched off at the moment. Please contact us to buy units.'];
        }

        $account = Accounts::find($accountId);

        if ($account === null || $account['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This SMS account cannot buy units.'];
        }

        $phone = normalize_phone($rawPhone);

        if ($phone === null) {
            return ['ok' => false, 'error' => 'Enter a valid M-Pesa number, for example 0712345678.'];
        }

        $recent = (int) Database::scalar(
            "SELECT COUNT(*) FROM stk_requests
              WHERE purpose = 'bulk_sms' AND bulk_purchase_id IN
                    (SELECT id FROM bulk_purchases WHERE account_id = :a)
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            ['a' => $accountId], 0
        );

        if ($recent >= self::MAX_ATTEMPTS_PER_HOUR) {
            return ['ok' => false, 'error' => 'That is a lot of payment attempts in one hour. Please try again later, or contact us to pay another way.'];
        }

        // A prompt already on the handset: point at it rather than sending
        // a second one, which only confuses the person holding the phone.
        $pending = Database::first(
            "SELECT s.id, s.bulk_purchase_id FROM stk_requests s
               JOIN bulk_purchases p ON p.id = s.bulk_purchase_id
              WHERE s.purpose = 'bulk_sms' AND p.account_id = :a AND s.status = 'pending'
                AND s.created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
              ORDER BY s.id DESC LIMIT 1",
            ['a' => $accountId]
        );

        if ($pending) {
            return [
                'ok'          => true,
                'pending'     => true,
                'stk_id'      => (int) $pending['id'],
                'purchase_id' => (int) $pending['bulk_purchase_id'],
                'error'       => 'A payment request is already on your phone. Enter your M-Pesa PIN to finish it.',
            ];
        }

        $purchase = Purchases::create($accountId, [
            'plan_id'    => $what['plan_id'] ?? null,
            'units'      => $what['units'] ?? 0,
            'method'     => 'mpesa_stk',
            'phone'      => $phone,
            'actor_type' => $what['actor_type'] ?? 'client_user',
            'actor_id'   => $what['actor_id'] ?? null,
        ]);

        if (!$purchase['ok']) {
            return $purchase;
        }

        if ($purchase['amount'] < 1) {
            Purchases::fail($purchase['id'], 'Amount too small for M-Pesa');
            return ['ok' => false, 'error' => 'That is too small to pay for by M-Pesa. Buy a few more units.'];
        }

        $who     = Alerts::recipient($accountId) ?? [];
        $names   = preg_split('/\s+/', trim((string) ($who['client_name'] ?? 'Customer')));
        $clientId = $account['owner_type'] === 'client' ? (int) $account['owner_id'] : null;

        $stkId = Database::insert('stk_requests', [
            'client_id'        => $clientId,
            'document_id'      => null,
            'purpose'          => 'bulk_sms',
            'bulk_purchase_id' => $purchase['id'],
            'phone'            => $phone,
            'amount'           => $purchase['amount'],
            'status'           => 'pending',
        ]);

        $result = (new KopoKopo())->stkPush(
            phone:       $phone,
            amount:      (float) $purchase['amount'],
            callbackUrl: Notifier::absoluteUrl('/webhooks/kopokopo'),
            reference:   'SMS-' . $purchase['id'],
            firstName:   $names[0] ?? 'Customer',
            lastName:    count($names) > 1 ? (string) end($names) : '-',
            email:       $who['email'] ?? null,
            metadata:    [
                'purpose'     => 'bulk_sms',
                'purchase_id' => (string) $purchase['id'],
                'account_id'  => (string) $accountId,
                'stk_id'      => (string) $stkId,
                'source'      => $source,
            ]
        );

        Database::update('stk_requests', [
            'kopokopo_id'      => $result['id'] ?? null,
            'location_url'     => $result['location'] ?? null,
            'request_payload'  => json_encode($result['request'] ?? [], JSON_UNESCAPED_SLASHES),
            'response_payload' => mb_substr((string) ($result['response'] ?? ''), 0, 4000),
            'status'           => $result['ok'] ? 'pending' : 'failed',
            'result_desc'      => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? ''), 0, 255),
        ], ['id' => $stkId]);

        if (!$result['ok']) {
            Purchases::fail($purchase['id'], 'M-Pesa could not be reached');
            Logger::warning('Bulk SMS top-up could not start: ' . ($result['error'] ?? ''));

            return ['ok' => false, 'error' => 'We could not reach M-Pesa just now. Please try again in a moment.'];
        }

        ActivityLog::record('bulksms_topup', 'bulk_purchase', (int) $purchase['id'],
            'M-Pesa prompt sent to ' . $phone . ' for ' . Present::units($purchase['units']) . ' units');

        return [
            'ok'          => true,
            'stk_id'      => $stkId,
            'purchase_id' => $purchase['id'],
            'amount'      => $purchase['amount'],
            'units'       => $purchase['units'],
        ];
    }

    /**
     * Hand the units over for a payment that has arrived.
     *
     * Called by the payment callback, and by the poll behind the portal's
     * "waiting for your PIN" screen. Safe to call twice.
     */
    public static function settle(int $purchaseId, ?string $receipt): bool
    {
        $result = Purchases::complete($purchaseId, ['type' => 'system', 'id' => null], $receipt);

        if ($result['ok'] && empty($result['already'])) {
            ActivityLog::record('bulksms_topup_paid', 'bulk_purchase', $purchaseId,
                'M-Pesa payment received' . ($receipt ? ' (ref ' . $receipt . ')' : ''));
        }

        return $result['ok'];
    }

    /**
     * Where a prompt has got to, for the screen that is waiting on it.
     *
     * Scoped to the account as well as the request, so an id belonging to
     * somebody else's top-up answers nothing.
     *
     * @return array{status:string, message:string, units?:string, balance?:string}|null
     */
    public static function status(int $stkId, int $accountId): ?array
    {
        $stk = Database::first(
            "SELECT s.*, p.account_id, p.units, p.status AS purchase_status
               FROM stk_requests s
               JOIN bulk_purchases p ON p.id = s.bulk_purchase_id
              WHERE s.id = :id AND s.purpose = 'bulk_sms' AND p.account_id = :a",
            ['id' => $stkId, 'a' => $accountId]
        );

        if ($stk === null) {
            return null;
        }

        // The webhook can be slow or blocked. After a few seconds, ask
        // KopoKopo directly rather than leaving somebody watching a
        // spinner for a payment that has already gone through.
        if ($stk['status'] === 'pending' && $stk['location_url']
            && (time() - strtotime((string) $stk['created_at'])) > 12) {
            $poll = (new KopoKopo())->pollStatus((string) $stk['location_url']);

            if (($poll['ok'] ?? false) && isset($poll['status'])) {
                $mapped = match (strtolower((string) $poll['status'])) {
                    'success', 'received' => 'success',
                    'failed', 'rejected'  => 'failed',
                    default               => 'pending',
                };

                if ($mapped === 'success') {
                    self::settle((int) $stk['bulk_purchase_id'], $poll['receipt'] ?? null);
                    Database::update('stk_requests', [
                        'status'        => 'success',
                        'mpesa_receipt' => $poll['receipt'] ?? null,
                        'result_desc'   => 'Payment received',
                    ], ['id' => $stkId]);
                    $stk['status'] = 'success';
                } elseif ($mapped === 'failed') {
                    Database::update('stk_requests', [
                        'status'      => 'failed',
                        'result_desc' => 'Payment was not completed',
                    ], ['id' => $stkId]);
                    Purchases::fail((int) $stk['bulk_purchase_id'], 'Payment was not completed');
                    $stk['status'] = 'failed';
                }
            }
        }

        return match ($stk['status']) {
            'success' => [
                'status'  => 'success',
                'message' => Present::units($stk['units']) . ' units have been added to your account.',
                'units'   => Present::units($stk['units']),
                'balance' => Present::units(Wallet::balance($accountId)),
            ],
            'failed', 'cancelled', 'timeout' => [
                'status'  => 'failed',
                'message' => (string) ($stk['result_desc'] ?: 'The payment was not completed.'),
            ],
            default => [
                'status'  => 'pending',
                'message' => 'Check your phone and enter your M-Pesa PIN.',
            ],
        };
    }
}
