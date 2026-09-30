<?php
namespace App\Services;

use App\Services\Etims\TaxTypes;

/**
 * Single source of truth for document arithmetic.
 *
 * The same rules run in PHP here and in JS (app.js) so the figure the user
 * sees while typing always matches what is saved.
 *
 * VAT modes say what the prices typed in mean:
 *   exclusive - line prices exclude VAT; VAT is added on top.
 *   inclusive - line prices already contain VAT; it is backed out for display.
 *   exempt    - no VAT at all, whatever the lines say.
 *
 * Tax classes say what each line is. A line carries a KRA class — A
 * exempt, B standard, C zero-rated, D non-VAT, E reduced — and its tax
 * is worked out at that class's rate.
 *
 * Those are two different questions and this used to conflate them: one
 * rate was applied to the whole net, so a zero-rated line sitting on an
 * invoice beside a standard-rated one was charged the standard rate
 * along with it. A business selling only standard-rated work never saw
 * it; one selling anything exported or exempt was over-charging its
 * clients and over-declaring its output tax.
 */
class DocumentCalculator
{
    /**
     * @param array<int,array{quantity:float,unit_price:float,tax_type?:string}> $items
     *
     * @return array{
     *   subtotal:float, discount_amount:float, net:float,
     *   vat_amount:float, total:float, lines:array<int,float>,
     *   line_tax:array<int,float>, by_class:array<string,array{net:float,tax:float}>
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

        // An exempt document is exempt whatever its lines claim. It is
        // the coarser statement and it wins — somebody who has marked
        // the whole document exempt has said something deliberate.
        $exempt = $vatMode === 'exempt';

        $lineTax  = [];
        $byClass  = [];
        $vat      = 0.0;

        foreach ($items as $i => $item) {
            $line = $lines[$i];

            // The discount came off the document, so it comes off every
            // line in proportion. Charging it all against the first line
            // would move tax between classes on a mixed invoice.
            $share = $subtotal > 0 ? self::round($line - ($discount * ($line / $subtotal))) : 0.0;

            $class = $exempt ? 'A' : TaxTypes::clean($item['tax_type'] ?? null);
            $rate  = $exempt ? 0.0 : TaxTypes::rate($class);

            if ($rate <= 0) {
                $tax     = 0.0;
                $taxable = $share;
            } elseif ($vatMode === 'inclusive') {
                // The price typed already contains the tax.
                $taxable = self::round($share / (1 + ($rate / 100)));
                $tax     = self::round($share - $taxable);
            } else {
                $taxable = $share;
                $tax     = self::round($share * ($rate / 100));
            }

            $lineTax[$i] = $tax;
            $vat        += $tax;

            $byClass[$class] ??= ['net' => 0.0, 'tax' => 0.0];
            $byClass[$class]['net'] = self::round($byClass[$class]['net'] + $taxable);
            $byClass[$class]['tax'] = self::round($byClass[$class]['tax'] + $tax);
        }

        $vat   = self::round($vat);
        $total = $vatMode === 'inclusive' ? $net : self::round($net + $vat);

        ksort($byClass);

        return [
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'net'             => $net,
            'vat_amount'      => $vat,
            'total'           => $total,
            'lines'           => $lines,
            // Per line, for writing onto document_items, and per class,
            // which is the shape eTIMS reports totals in.
            'line_tax'        => $lineTax,
            'by_class'        => $byClass,
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
