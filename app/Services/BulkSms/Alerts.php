<?php
namespace App\Services\BulkSms;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Services\Notifier;

/**
 * Telling an account's owner what happened to their SMS.
 *
 * The old platform built these e-mails by hand inside the send engine.
 * They go through the system's own notification queue now, so they use
 * the same templates, the same switches in Settings and the same outbox
 * as every other message the company sends — and an office that turns
 * e-mail off turns these off with it.
 *
 * Every method is best effort. A notice that cannot be sent must never
 * stop a campaign or a top-up that has already happened.
 */
final class Alerts
{
    public const EVENTS = [
        'bulk_campaign_done'   => 'SMS campaign finished sending',
        'bulk_low_balance'     => 'SMS balance running low',
        'bulk_topup'           => 'SMS units added to an account',
        'bulk_sender_approved' => 'Sender ID approved',
        'bulk_sender_rejected' => 'Sender ID not approved',
    ];

    public static function campaignFinished(int $campaignId): void
    {
        self::safely(static function () use ($campaignId): void {
            $c = Database::first('SELECT * FROM bulk_campaigns WHERE id = :id', ['id' => $campaignId]);

            if ($c === null) {
                return;
            }

            $total = (int) $c['sent_count'] + (int) $c['failed_count'];

            self::send('bulk_campaign_done', (int) $c['account_id'], [
                'campaign'     => $c['name'],
                'sent'         => number_format((int) $c['sent_count']),
                'failed'       => number_format((int) $c['failed_count']),
                'total'        => number_format($total),
                'rate'         => $total > 0 ? round(100 * (int) $c['sent_count'] / $total) . '%' : '0%',
                'units'        => Engine::units((float) $c['units_used']),
                'entity_type'  => 'bulk_campaign',
                'entity_id'    => $campaignId,
            ]);
        });
    }

    /**
     * Warn once a day when the balance drops under the threshold.
     *
     * The account's own threshold if it has one, else the system's; 0
     * means never. "Once a day" is a timestamp on the account rather than
     * a rate-limit table, and it resets when the balance is topped up
     * above the line, so the next dip warns again straight away.
     */
    public static function lowBalanceCheck(int $accountId): void
    {
        self::safely(static function () use ($accountId): void {
            $a = Accounts::find($accountId);

            if ($a === null || $a['owner_type'] === 'house') {
                return;
            }

            $threshold = $a['low_balance_threshold'] !== null
                ? (float) $a['low_balance_threshold']
                : (float) Settings::get('bulk_sms_low_balance', '0');

            if ($threshold <= 0) {
                return;
            }

            $balance = (float) $a['sms_units'];

            if ($balance >= $threshold) {
                if ($a['low_balance_alerted_at'] !== null) {
                    Database::run('UPDATE bulk_accounts SET low_balance_alerted_at = NULL WHERE id = :id', ['id' => $accountId]);
                }
                return;
            }

            // Claimed atomically, so two batches finishing together send
            // one warning between them.
            $claimed = Database::run(
                'UPDATE bulk_accounts SET low_balance_alerted_at = NOW()
                  WHERE id = :id AND (low_balance_alerted_at IS NULL OR low_balance_alerted_at < NOW() - INTERVAL 24 HOUR)',
                ['id' => $accountId]
            )->rowCount();

            if ($claimed === 0) {
                return;
            }

            self::send('bulk_low_balance', $accountId, [
                'balance'   => Engine::units($balance),
                'threshold' => Engine::units($threshold),
            ]);
        });
    }

    public static function toppedUp(int $purchaseId): void
    {
        self::safely(static function () use ($purchaseId): void {
            $p = Database::first('SELECT * FROM bulk_purchases WHERE id = :id', ['id' => $purchaseId]);

            if ($p === null) {
                return;
            }

            self::send('bulk_topup', (int) $p['account_id'], [
                'units'       => Engine::units((float) $p['units']),
                'amount'      => 'KES ' . number_format((float) $p['amount'], 2),
                'balance'     => Engine::units(Wallet::balance((int) $p['account_id'])),
                'reference'   => $p['transaction_ref'] ?: ('SMS-' . $p['id']),
                'entity_type' => 'bulk_purchase',
                'entity_id'   => $purchaseId,
            ]);
        });
    }

    public static function senderDecided(int $senderRowId): void
    {
        self::safely(static function () use ($senderRowId): void {
            $s = Database::first('SELECT * FROM bulk_sender_ids WHERE id = :id', ['id' => $senderRowId]);

            if ($s === null || $s['status'] === 'pending') {
                return;
            }

            self::send($s['status'] === 'approved' ? 'bulk_sender_approved' : 'bulk_sender_rejected',
                (int) $s['account_id'], [
                    'sender_id' => $s['sender_id'],
                    'reason'    => (string) ($s['reject_reason'] ?? ''),
                ]);
        });
    }

    // -----------------------------------------------------------------

    /**
     * Who to tell about an account: the client's own e-mail and phone, or
     * the partner's. The house is us, and we have the dashboard.
     */
    public static function recipient(int $accountId): ?array
    {
        $a = Accounts::find($accountId);

        if ($a === null) {
            return null;
        }

        if ($a['owner_type'] === 'client') {
            $c = Database::first(
                'SELECT id, name, contact_person, email, phone FROM clients WHERE id = :id',
                ['id' => $a['owner_id']]
            );

            return $c === null ? null : [
                'client_id'    => (int) $c['id'],
                'client_name'  => $c['name'],
                'contact_name' => $c['contact_person'] ?: $c['name'],
                'email'        => $c['email'],
                'phone'        => $c['phone'],
            ];
        }

        if ($a['owner_type'] === 'partner') {
            $p = Database::first('SELECT id, name, email, phone FROM partners WHERE id = :id', ['id' => $a['owner_id']]);

            return $p === null ? null : [
                'client_name'  => $p['name'],
                'contact_name' => $p['name'],
                'email'        => $p['email'],
                'phone'        => $p['phone'],
            ];
        }

        return null;
    }

    private static function send(string $event, int $accountId, array $context): void
    {
        $to = self::recipient($accountId);

        if ($to === null) {
            return;
        }

        Notifier::dispatch($event, $context + $to + [
            'company' => (string) Settings::get('company_name', 'Shanfix Technology'),
        ]);
    }

    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Logger::warning('Bulk SMS alert not sent: ' . $e->getMessage());
        }
    }
}
