<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Numbering;
use App\Core\Settings;

/**
 * The recurring side of the business: what renews, when, and the invoice
 * that goes out for it.
 *
 * Kept apart from the controller because the same operations run from two
 * places — someone pressing "Invoice now", and the nightly sweep — and the
 * two must behave identically. A period billed by hand must be
 * indistinguishable from one billed automatically, or the next sweep will
 * bill it again.
 */
class Renewals
{
    /** Cycle => how far the next renewal moves. */
    public const CYCLES = [
        'monthly'    => 'Monthly',
        'quarterly'  => 'Every 3 months',
        'semiannual' => 'Every 6 months',
        'annual'     => 'Yearly',
        'custom'     => 'Custom number of days',
    ];

    public const TYPES = [
        'website'     => 'Website',
        'hosting'     => 'Hosting',
        'domain'      => 'Domain name',
        'email'       => 'Business email',
        'maintenance' => 'Maintenance / support',
        'software'    => 'Software licence',
        'other'       => 'Other',
    ];

    /**
     * The day a period that starts on $from runs to.
     *
     * Uses calendar months rather than fixed day counts, so a site renewed
     * on the 15th stays on the 15th instead of drifting earlier each year.
     * PHP clamps an overflowing day — 31 January plus a month would be 3
     * March — so the month arithmetic is done on the first and the day
     * re-applied, capped to the length of the landing month.
     */
    public static function advance(string $from, string $cycle, int $customDays = 365): string
    {
        $ts  = strtotime($from);
        $day = (int) date('j', $ts);

        $months = match ($cycle) {
            'monthly'    => 1,
            'quarterly'  => 3,
            'semiannual' => 6,
            'annual'     => 12,
            default      => 0,
        };

        if ($months === 0) {
            return date('Y-m-d', strtotime($from . ' +' . max(1, $customDays) . ' days'));
        }

        $firstOfTarget = strtotime(date('Y-m-01', $ts) . ' +' . $months . ' months');
        $daysInTarget  = (int) date('t', $firstOfTarget);

        return date('Y-m-d', strtotime(date('Y-m', $firstOfTarget) . '-' . min($day, $daysInTarget)));
    }

    /** The renewal period that the given due date opens. */
    public static function periodFor(array $sub, ?string $start = null): array
    {
        $start = $start ?: $sub['next_renewal_date'];
        $end   = self::advance($start, $sub['billing_cycle'], (int) $sub['cycle_days']);

        return [
            'start' => $start,
            // The period runs up to the day before the next one opens.
            'end'   => date('Y-m-d', strtotime($end . ' -1 day')),
        ];
    }

    /** Subscriptions whose next renewal falls on or before $onOrBefore. */
    public static function dueBy(string $onOrBefore): array
    {
        return Database::all(
            "SELECT s.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone
               FROM subscriptions s
               JOIN clients c ON c.id = s.client_id
              WHERE s.status = 'active'
                AND s.next_renewal_date <= :d
           ORDER BY s.next_renewal_date, s.id",
            ['d' => $onOrBefore]
        );
    }

    /**
     * Raise the invoice for one renewal period and move the subscription on.
     *
     * Returns the renewal row, whether or not this call created it: asking
     * twice for the same period is not an error, it is the second run of a
     * sweep that already did the work. The unique key on
     * (subscription_id, period_start) is what makes that safe even if two
     * runs overlap.
     *
     * @return array{renewal:array, document:?array, created:bool}
     */
    public static function invoicePeriod(array $sub, ?int $userId = null): array
    {
        $period = self::periodFor($sub);

        $existing = Database::first(
            'SELECT * FROM subscription_renewals
              WHERE subscription_id = :s AND period_start = :p LIMIT 1',
            ['s' => $sub['id'], 'p' => $period['start']]
        );

        if ($existing && $existing['document_id']) {
            return [
                'renewal'  => $existing,
                'document' => Database::first('SELECT * FROM documents WHERE id = :id', ['id' => $existing['document_id']]),
                'created'  => false,
            ];
        }

        $amount  = (float) $sub['amount'];
        $vatMode = (string) Settings::get('vat_default_mode', 'exclusive');
        $vatRate = (float) Settings::get('vat_rate', 16);

        $line = [[
            'item_type'   => 'service',
            'description' => self::lineDescription($sub, $period),
            'quantity'    => 1,
            'unit_price'  => $amount,
        ]];

        $totals = DocumentCalculator::compute($line, 'none', 0.0, $vatMode, $vatRate);

        $issue = date('Y-m-d');
        $due   = $sub['next_renewal_date'];

        // An invoice raised ahead of the renewal is due on the renewal day;
        // one raised late is due at once rather than back-dated.
        if (strtotime($due) < strtotime($issue)) {
            $due = $issue;
        }

        $docId = Database::insert('documents', [
            'doc_type'        => 'invoice',
            'doc_number'      => Numbering::next('invoice'),
            'client_id'       => (int) $sub['client_id'],
            'title'           => $sub['name'] . ' — renewal',
            'issue_date'      => $issue,
            'due_date'        => $due,
            'status'          => 'unpaid',
            'currency'        => $sub['currency'] ?: Settings::currency(),
            'subtotal'        => $totals['subtotal'],
            'discount_type'   => 'none',
            'discount_value'  => 0,
            'discount_amount' => $totals['discount_amount'],
            'vat_mode'        => $vatMode,
            'vat_rate'        => $vatRate,
            'vat_amount'      => $totals['vat_amount'],
            'total'           => $totals['total'],
            'amount_paid'     => 0,
            'balance'         => $totals['total'],
            'created_by'      => $userId,
        ]);

        Database::insert('document_items', [
            'document_id' => $docId,
            // 'service' when it points at the catalogue, otherwise a free
            // line — item_type is an enum and 'service' with no ref would
            // leave a dangling reference on the invoice.
            'item_type'   => $sub['service_id'] ? 'service' : 'custom',
            'ref_id'      => $sub['service_id'] ?: null,
            'description' => $line[0]['description'],
            'quantity'    => 1,
            'unit'        => 'each',
            'unit_price'  => $amount,
            'line_total'  => $totals['lines'][0] ?? $amount,
            'sort_order'  => 0,
        ]);

        if ($existing) {
            Database::update('subscription_renewals', [
                'document_id' => $docId,
                'status'      => 'invoiced',
                'invoiced_at' => date('Y-m-d H:i:s'),
                'amount'      => $amount,
            ], ['id' => $existing['id']]);
            $renewalId = (int) $existing['id'];
        } else {
            $renewalId = Database::insert('subscription_renewals', [
                'subscription_id' => (int) $sub['id'],
                'period_start'    => $period['start'],
                'period_end'      => $period['end'],
                'amount'          => $amount,
                'document_id'     => $docId,
                'status'          => 'invoiced',
                'invoiced_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        // Move the arrangement on to its next period. Done last, so a
        // failure above leaves the subscription due rather than silently
        // skipped with no invoice to show for it.
        Database::update('subscriptions', [
            'next_renewal_date' => self::advance(
                $sub['next_renewal_date'],
                $sub['billing_cycle'],
                (int) $sub['cycle_days']
            ),
            'last_invoiced_on'  => $issue,
        ], ['id' => $sub['id']]);

        return [
            'renewal'  => Database::first('SELECT * FROM subscription_renewals WHERE id = :id', ['id' => $renewalId]),
            'document' => Database::first('SELECT * FROM documents WHERE id = :id', ['id' => $docId]),
            'created'  => true,
        ];
    }

    /** "Company website — hosting, 1 Sep 2026 to 31 Aug 2027" */
    private static function lineDescription(array $sub, array $period): string
    {
        $type = self::TYPES[$sub['service_type']] ?? 'Service';

        return sprintf(
            '%s — %s renewal, %s to %s',
            $sub['name'],
            strtolower($type),
            date('j M Y', strtotime($period['start'])),
            date('j M Y', strtotime($period['end']))
        );
    }

    /**
     * What a client still owes on their recurring services.
     *
     * Read from the invoices themselves rather than kept as a running
     * figure on the subscription: a payment recorded against the invoice by
     * any route — cash, M-Pesa, a correction — is reflected here without
     * anything else needing to remember to update it.
     */
    public static function balanceForClient(int $clientId): float
    {
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(d.balance), 0)
               FROM subscription_renewals r
               JOIN documents d ON d.id = r.document_id
               JOIN subscriptions s ON s.id = r.subscription_id
              WHERE s.client_id = :c
                AND d.doc_type = 'invoice'
                AND d.status NOT IN ('cancelled','paid','draft')",
            ['c' => $clientId],
            0
        );
    }

    /** How many live recurring services a client has. */
    public static function countForClient(int $clientId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM subscriptions WHERE client_id = :c AND status = 'active'",
            ['c' => $clientId],
            0
        );
    }

    /**
     * Reconcile renewal rows against their invoices.
     *
     * The payment side of the system knows nothing about subscriptions, so
     * rather than teach it, this reads back the invoice status it already
     * maintains. Run from cron.
     */
    public static function syncPaidRenewals(): int
    {
        return Database::run(
            "UPDATE subscription_renewals r
               JOIN documents d ON d.id = r.document_id
                SET r.status = 'paid', r.paid_at = COALESCE(r.paid_at, NOW())
              WHERE r.status = 'invoiced' AND d.status = 'paid'"
        )->rowCount();
    }
}
