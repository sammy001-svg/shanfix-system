<?php

namespace App\Services\Etims;

use App\Core\Settings;

/**
 * The tax classes KRA uses, and what each one costs.
 *
 * Every line on an invoice sent to eTIMS carries one of these letters,
 * and the totals are reported per class rather than as one figure. They
 * are also simply the right way to hold VAT: a business that sells
 * anything zero-rated alongside anything standard-rated cannot say what
 * it owes with a single rate on the whole document.
 *
 * The rates are settings, not constants. VAT rates are moved by the
 * Finance Act — the reduced rate has already been 8% and then not —
 * and a rate compiled into this file is a rate that needs a deployment
 * to change.
 *
 * A, C and D are all nought, and they are still three different things.
 * Exempt is outside the VAT system; zero-rated is inside it at nought,
 * which is what lets an exporter reclaim what they paid; non-VAT is
 * for a business or a line that VAT does not reach. KRA wants to know
 * which, and collapsing them to "no tax" is how a return comes back
 * wrong.
 */
final class TaxTypes
{
    /**
     * @return array<string, array{name:string, hint:string, rate:float}>
     */
    public static function all(): array
    {
        return [
            'A' => [
                'name' => 'Exempt',
                'hint' => 'Outside VAT altogether — no VAT charged and none reclaimable',
                'rate' => 0.0,
            ],
            'B' => [
                'name' => 'Standard rated',
                'hint' => 'The ordinary rate. Nearly everything a print shop sells',
                'rate' => self::rate('B'),
            ],
            'C' => [
                'name' => 'Zero rated',
                'hint' => 'Inside VAT at nought — exports, and a few goods named in the Act',
                'rate' => 0.0,
            ],
            'D' => [
                'name' => 'Non-VAT',
                'hint' => 'For a line VAT does not reach at all',
                'rate' => 0.0,
            ],
            'E' => [
                'name' => 'Reduced rate',
                'hint' => 'The lower rate, where it applies',
                'rate' => self::rate('E'),
            ],
        ];
    }

    public static function isValid(string $letter): bool
    {
        return isset(self::all()[strtoupper($letter)]);
    }

    /** The letter, or B, which is what almost everything is. */
    public static function clean(?string $letter): string
    {
        $letter = strtoupper(trim((string) $letter));

        return self::isValid($letter) ? $letter : 'B';
    }

    /** What this class costs, as a percentage. */
    public static function rate(string $letter): float
    {
        return match (strtoupper($letter)) {
            'B'     => (float) Settings::get('tax_rate_b', 16),
            'E'     => (float) Settings::get('tax_rate_e', 8),
            default => 0.0,
        };
    }

    public static function name(string $letter): string
    {
        return self::all()[strtoupper($letter)]['name'] ?? $letter;
    }

    /**
     * The name cut down to fit a table cell: "B · Standard 16%".
     *
     * A column narrow enough for an invoice line cannot hold "Standard
     * rated", and a column showing only the letter asks the person
     * raising the invoice to have learnt the KRA classes. Neither is
     * acceptable on the screen where the tax is actually decided.
     */
    public static function short(string $letter): string
    {
        return match (strtoupper($letter)) {
            'A'     => 'Exempt',
            'B'     => 'Standard',
            'C'     => 'Zero',
            'D'     => 'Non-VAT',
            'E'     => 'Reduced',
            default => strtoupper($letter),
        };
    }

    /**
     * "B · Standard", for a picker on an invoice line.
     *
     * Without the rate: it does not fit a column narrow enough to sit
     * between the price and the line total, and the rate is stated
     * beside the total and again in the sidebar.
     */
    public static function chip(string $letter): string
    {
        return strtoupper($letter) . ' · ' . self::short($letter);
    }

    /** "B — Standard rated (16%)", for a dropdown. */
    public static function label(string $letter): string
    {
        $letter = strtoupper($letter);
        $one    = self::all()[$letter] ?? null;

        if ($one === null) {
            return $letter;
        }

        return $letter . ' — ' . $one['name']
             . ($one['rate'] > 0 ? ' (' . rtrim(rtrim(number_format($one['rate'], 2, '.', ''), '0'), '.') . '%)' : '');
    }
}
