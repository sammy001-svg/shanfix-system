<?php

namespace App\Services\Etims;

/**
 * What a saved document's VAT actually consists of, read back from its
 * own lines.
 *
 * A document used to have one VAT figure and one rate, and "VAT @ 16%"
 * printed under the total said everything there was to say. Once lines
 * carry their own tax class that sentence is only true of a document
 * where every line happens to be standard rated — on a mixed one it is
 * a false statement on a tax invoice, which is worse than an unhelpful
 * one.
 *
 * So this reads the lines and reports per class. On the common document
 * it comes back with one band and the views print what they always
 * printed; on a mixed one it comes back with several and they print the
 * breakdown, which is also the shape KRA asks totals to be reported in.
 *
 * It reads document_items.tax_amount rather than recomputing. What the
 * client was charged is what the invoice must say, whatever the rates
 * have done since. The taxable amount per band it does have to work
 * out, because a document-level discount is not stored per line, and it
 * apportions that discount exactly as DocumentCalculator did when the
 * tax was charged — otherwise the rate printed beside a band is not the
 * rate that was applied to it.
 */
final class TaxSummary
{
    /**
     * @param array<int,array<string,mixed>> $items rows from document_items
     * @param array<string,mixed>            $doc   the documents row
     *
     * @return array<int,array{class:string,name:string,rate:float,net:float,tax:float}>
     *         one band per class present, in class order
     */
    public static function bands(array $items, array $doc = []): array
    {
        $mode     = (string) ($doc['vat_mode'] ?? 'exclusive');
        $discount = (float) ($doc['discount_amount'] ?? 0);
        $subtotal = (float) ($doc['subtotal'] ?? 0);

        // Without a subtotal to divide by there is nothing to apportion,
        // so the lines stand as they are.
        if ($subtotal <= 0) {
            $discount = 0.0;
            $subtotal = 0.0;
        }

        $bands = [];

        foreach ($items as $item) {
            $class = TaxTypes::clean($item['tax_type'] ?? null);
            $line  = (float) ($item['line_total'] ?? 0);
            $tax   = (float) ($item['tax_amount'] ?? 0);

            // The line's share of the document after the discount — the
            // same figure the tax was charged on.
            $share = $subtotal > 0
                ? round($line - ($discount * ($line / $subtotal)), 2)
                : $line;

            // Inclusive prices contain the tax, so the taxable amount is
            // the share less what is inside it.
            $net = $mode === 'inclusive' ? round($share - $tax, 2) : $share;

            $bands[$class] ??= [
                'class' => $class,
                'name'  => TaxTypes::name($class),
                'rate'  => 0.0,
                'net'   => 0.0,
                'tax'   => 0.0,
            ];

            $bands[$class]['net'] = round($bands[$class]['net'] + $net, 2);
            $bands[$class]['tax'] = round($bands[$class]['tax'] + $tax, 2);
        }

        // The rate each band was actually charged at, worked out from the
        // money rather than looked up, so a rate changed since the
        // document was issued cannot rewrite what it says. Rounded to a
        // whole number where it lands on one, because 16.0 is the rate
        // and "16.00%" on an invoice is just noise.
        foreach ($bands as $class => $band) {
            $bands[$class]['rate'] = $band['net'] > 0
                ? round(($band['tax'] / $band['net']) * 100, 2)
                : 0.0;
        }

        ksort($bands);

        return array_values($bands);
    }

    /** True when the document's VAT has to be shown band by band. */
    public static function isMixed(array $items): bool
    {
        $classes = [];

        foreach ($items as $item) {
            $classes[TaxTypes::clean($item['tax_type'] ?? null)] = true;
        }

        return count($classes) > 1;
    }

    /**
     * The one-line description for a totals row: "VAT @ 16%" where that
     * is the whole truth, "VAT" where it is not.
     */
    public static function label(array $items, array $doc = []): string
    {
        $bands = self::bands($items, $doc);
        $rated = array_values(array_filter($bands, static fn($band) => $band['tax'] > 0));

        $suffix = ($doc['vat_mode'] ?? '') === 'inclusive' ? ' (incl.)' : '';

        if (count($rated) === 1) {
            return 'VAT @ ' . self::percent($rated[0]['rate']) . '%' . $suffix;
        }

        // A document issued before tax was stored per line: its lines have
        // no tax on them but the document does. It said "VAT @ 16%" when
        // it was sent and it has to go on saying so — reprinting an old
        // invoice must not change what it states.
        if ($rated === [] && (float) ($doc['vat_amount'] ?? 0) > 0) {
            return 'VAT @ ' . self::percent((float) ($doc['vat_rate'] ?? 0)) . '%' . $suffix;
        }

        // Nothing rated, and nothing stored either. Then the class is the
        // useful thing to say: "VAT (zero rated)" tells the client why
        // there is none, which a bare "VAT — 0.00" does not.
        if (count($bands) === 1) {
            return 'VAT (' . mb_strtolower($bands[0]['name']) . ')';
        }

        return 'VAT' . $suffix;
    }

    /** 16.00 => "16", 7.50 => "7.5". */
    public static function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
