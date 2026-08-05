<?php
namespace App\Services;

/**
 * Single source of truth for document arithmetic.
 *
 * The same rules run in PHP here and in JS (app.js) so the figure the user
 * sees while typing always matches what is saved.
 *
 * VAT modes:
 *   exclusive - line prices exclude VAT; VAT is added on top.
 *   inclusive - line prices already contain VAT; it is backed out for display.
 *   exempt    - no VAT at all (zero-rated or exempt supply).
 */
class DocumentCalculator
{
    /**
     * @param array<int,array{quantity:float,unit_price:float}> $items
     *
     * @return array{
     *   subtotal:float, discount_amount:float, net:float,
     *   vat_amount:float, total:float, lines:array<int,float>
     * }
     */
    public static function compute(
        array $items,
        string $discountType = 'none',
        float $discountValue = 0.0,
        string $vatMode = 'exclusive',
        float $vatRate = 16.0
    ): array {
        $subtotal = 0.0;
        $lines    = [];

        foreach ($items as $i => $item) {
            $line = self::round(((float) $item['quantity']) * ((float) $item['unit_price']));
            $lines[$i] = $line;
            $subtotal += $line;
        }

        $subtotal = self::round($subtotal);

        $discount = match ($discountType) {
            'percent' => self::round($subtotal * ($discountValue / 100)),
            'amount'  => self::round($discountValue),
            default   => 0.0,
        };

        // A discount can never exceed the goods value or go negative.
        $discount = max(0.0, min($discount, $subtotal));

        $net = self::round($subtotal - $discount);

        if ($vatMode === 'exclusive') {
            $vat   = self::round($net * ($vatRate / 100));
            $total = self::round($net + $vat);
        } elseif ($vatMode === 'inclusive') {
            // Net already includes VAT: vat = net - (net / (1 + rate))
            $vat   = self::round($net - ($net / (1 + ($vatRate / 100))));
            $total = $net;
        } else {
            $vat   = 0.0;
            $total = $net;
        }

        return [
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'net'             => $net,
            'vat_amount'      => $vat,
            'total'           => $total,
            'lines'           => $lines,
        ];
    }

    /**
     * Work out the status an invoice should carry given what has been paid.
     */
    public static function invoiceStatus(float $total, float $paid, ?string $dueDate, string $current): string
    {
        // Manual states are never overwritten by the payment maths.
        if (in_array($current, ['cancelled', 'draft'], true)) {
            return $current;
        }

        if ($paid >= $total - 0.009) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partial';
        }

        if ($dueDate && strtotime($dueDate) !== false && strtotime($dueDate) < strtotime('today')) {
            return 'overdue';
        }

        return $current === 'sent' ? 'sent' : 'unpaid';
    }

    /** Round half-up to 2dp, avoiding float drift in stored totals. */
    public static function round(float $value): float
    {
        return round($value + 0.0000001, 2);
    }
}
