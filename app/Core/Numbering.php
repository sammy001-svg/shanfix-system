<?php
namespace App\Core;

/**
 * Sequential document numbers: QTN-2026-0001, INV-2026-0042, LD-2026-0007 ...
 *
 * Uses the `counters` table with an atomic UPDATE so two users saving at the
 * same instant can never receive the same number.
 */
class Numbering
{
    private const PREFIX_KEYS = [
        'quotation'     => 'quotation_prefix',
        'invoice'       => 'invoice_prefix',
        'receipt'       => 'receipt_prefix',
        'payment'       => 'payment_prefix',
        'expense'       => 'expense_prefix',
        'lead'          => 'lead_prefix',
        'client'        => 'client_prefix',
        'job'           => 'job_prefix',
        'delivery_note' => 'delivery_note_prefix',
        'supplier'      => 'supplier_prefix',
        'purchase_order'=> 'purchase_order_prefix',
        'proposal'      => 'proposal_prefix',
        'agreement'     => 'agreement_prefix',
        'artwork'       => 'artwork_prefix',
    ];

    private const DEFAULTS = [
        'quotation'     => 'QTN',
        'invoice'       => 'INV',
        'receipt'       => 'RCP',
        'payment'       => 'PMT',
        'expense'       => 'EXP',
        'lead'          => 'LD',
        'client'        => 'CL',
        'job'           => 'JOB',
        'delivery_note' => 'DN',
        'supplier'      => 'SUP',
        'purchase_order'=> 'PO',
        'proposal'      => 'PRO',
        'agreement'     => 'AGR',
        'artwork'       => 'ART',
    ];

    /**
     * @param string $type One of the keys in self::DEFAULTS
     */
    public static function next(string $type): string
    {
        if (!isset(self::DEFAULTS[$type])) {
            throw new \InvalidArgumentException("Unknown document type: {$type}");
        }

        $year   = date('Y');
        $key    = "{$type}:{$year}";
        $prefix = Settings::get(self::PREFIX_KEYS[$type], self::DEFAULTS[$type]);

        $seq = self::bump($key);

        return sprintf('%s-%s-%04d', $prefix, $year, $seq);
    }

    /**
     * Atomically increment and return the counter for $key.
     */
    private static function bump(string $key): int
    {
        // INSERT ... ON DUPLICATE KEY UPDATE is atomic under InnoDB, so the
        // read-back below cannot see another session's value for this row.
        Database::run(
            'INSERT INTO counters (counter_key, last_value) VALUES (:k, 1)
             ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)',
            ['k' => $key]
        );

        $id = (int) Database::pdo()->lastInsertId();

        // On a fresh insert lastInsertId() is 0 because the table has no
        // AUTO_INCREMENT column; that row is by definition sequence 1.
        return $id > 0 ? $id : 1;
    }

    /**
     * Preview the next number without consuming it (for form placeholders).
     */
    public static function peek(string $type): string
    {
        $year   = date('Y');
        $prefix = Settings::get(self::PREFIX_KEYS[$type] ?? '', self::DEFAULTS[$type] ?? 'DOC');
        $last   = (int) Database::scalar(
            'SELECT last_value FROM counters WHERE counter_key = :k',
            ['k' => "{$type}:{$year}"],
            0
        );

        return sprintf('%s-%s-%04d', $prefix, $year, $last + 1);
    }
}
