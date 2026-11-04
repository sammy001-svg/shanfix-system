<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Settings;

/**
 * What the partner module needs to know about the rest of the world.
 *
 * Everything in here is a question the module could not answer while it
 * assumed Kenya: where is this partner, what currency do they get paid
 * in, what can we pay them with from where we bank, what do we have to
 * withhold, and what is the rate today.
 *
 * It is one class rather than four because the answers lean on each
 * other — the country suggests the currency, the currency decides the
 * methods, the residency decides the withholding — and splitting them up
 * would mean the suggestion lived somewhere different from the thing it
 * suggests.
 *
 * Nothing here reaches the network. Rates are entered by finance (see
 * fx_rates in migration 064) because a cPanel box cannot be relied on to
 * make outbound calls, and because the rate that matters for a payout is
 * the one the bank actually gave us, which no public feed knows.
 */
class Partners
{
    /**
     * Countries we are prepared to sign a partner in, with the currency
     * they are most likely to want paying in and their dialling code.
     *
     * All of Africa, because that is the market next door and leaving a
     * country out of the list is the same as saying no to it. Then the
     * places Kenyan software and print work actually gets sold into:
     * the Gulf, the UK, Europe, North America, India, China.
     *
     * [name, dial code, default currency]
     */
    public const COUNTRIES = [
        // -- East Africa ------------------------------------------------
        'KE' => ['Kenya',                          '254', 'KES'],
        'UG' => ['Uganda',                         '256', 'UGX'],
        'TZ' => ['Tanzania',                       '255', 'TZS'],
        'RW' => ['Rwanda',                         '250', 'RWF'],
        'BI' => ['Burundi',                        '257', 'BIF'],
        'SS' => ['South Sudan',                    '211', 'USD'],
        'ET' => ['Ethiopia',                       '251', 'ETB'],
        'SO' => ['Somalia',                        '252', 'USD'],
        'DJ' => ['Djibouti',                       '253', 'DJF'],
        'ER' => ['Eritrea',                        '291', 'ERN'],
        'SD' => ['Sudan',                          '249', 'SDG'],

        // -- Southern Africa --------------------------------------------
        'ZA' => ['South Africa',                   '27',  'ZAR'],
        'ZM' => ['Zambia',                         '260', 'ZMW'],
        'ZW' => ['Zimbabwe',                       '263', 'USD'],
        'MW' => ['Malawi',                         '265', 'MWK'],
        'MZ' => ['Mozambique',                     '258', 'MZN'],
        'BW' => ['Botswana',                       '267', 'BWP'],
        'NA' => ['Namibia',                        '264', 'NAD'],
        'LS' => ['Lesotho',                        '266', 'LSL'],
        'SZ' => ['Eswatini',                       '268', 'SZL'],
        'AO' => ['Angola',                         '244', 'AOA'],
        'MG' => ['Madagascar',                     '261', 'MGA'],
        'MU' => ['Mauritius',                      '230', 'MUR'],
        'SC' => ['Seychelles',                     '248', 'SCR'],
        'KM' => ['Comoros',                        '269', 'KMF'],

        // -- West Africa ------------------------------------------------
        'NG' => ['Nigeria',                        '234', 'NGN'],
        'GH' => ['Ghana',                          '233', 'GHS'],
        'CI' => ["Côte d'Ivoire",                  '225', 'XOF'],
        'SN' => ['Senegal',                        '221', 'XOF'],
        'ML' => ['Mali',                           '223', 'XOF'],
        'BF' => ['Burkina Faso',                   '226', 'XOF'],
        'NE' => ['Niger',                          '227', 'XOF'],
        'TG' => ['Togo',                           '228', 'XOF'],
        'BJ' => ['Benin',                          '229', 'XOF'],
        'GN' => ['Guinea',                         '224', 'GNF'],
        'SL' => ['Sierra Leone',                   '232', 'SLE'],
        'LR' => ['Liberia',                        '231', 'LRD'],
        'GM' => ['Gambia',                         '220', 'GMD'],
        'GW' => ['Guinea-Bissau',                  '245', 'XOF'],
        'CV' => ['Cape Verde',                     '238', 'CVE'],
        'MR' => ['Mauritania',                     '222', 'MRU'],

        // -- Central Africa ---------------------------------------------
        'CM' => ['Cameroon',                       '237', 'XAF'],
        'CD' => ['DR Congo',                       '243', 'CDF'],
        'CG' => ['Congo',                          '242', 'XAF'],
        'GA' => ['Gabon',                          '241', 'XAF'],
        'TD' => ['Chad',                           '235', 'XAF'],
        'CF' => ['Central African Republic',       '236', 'XAF'],
        'GQ' => ['Equatorial Guinea',              '240', 'XAF'],
        'ST' => ['São Tomé and Príncipe',          '239', 'STN'],

        // -- North Africa -----------------------------------------------
        'EG' => ['Egypt',                          '20',  'EGP'],
        'MA' => ['Morocco',                        '212', 'MAD'],
        'DZ' => ['Algeria',                        '213', 'DZD'],
        'TN' => ['Tunisia',                        '216', 'TND'],
        'LY' => ['Libya',                          '218', 'LYD'],

        // -- The Gulf ---------------------------------------------------
        'AE' => ['United Arab Emirates',           '971', 'AED'],
        'SA' => ['Saudi Arabia',                   '966', 'SAR'],
        'QA' => ['Qatar',                          '974', 'QAR'],
        'OM' => ['Oman',                           '968', 'OMR'],
        'KW' => ['Kuwait',                         '965', 'KWD'],
        'BH' => ['Bahrain',                        '973', 'BHD'],

        // -- Europe -----------------------------------------------------
        'GB' => ['United Kingdom',                 '44',  'GBP'],
        'IE' => ['Ireland',                        '353', 'EUR'],
        'DE' => ['Germany',                        '49',  'EUR'],
        'FR' => ['France',                         '33',  'EUR'],
        'NL' => ['Netherlands',                    '31',  'EUR'],
        'BE' => ['Belgium',                        '32',  'EUR'],
        'ES' => ['Spain',                          '34',  'EUR'],
        'IT' => ['Italy',                          '39',  'EUR'],
        'PT' => ['Portugal',                       '351', 'EUR'],
        'SE' => ['Sweden',                         '46',  'SEK'],
        'NO' => ['Norway',                         '47',  'NOK'],
        'DK' => ['Denmark',                        '45',  'DKK'],
        'FI' => ['Finland',                        '358', 'EUR'],
        'PL' => ['Poland',                         '48',  'PLN'],
        'CH' => ['Switzerland',                    '41',  'CHF'],
        'AT' => ['Austria',                        '43',  'EUR'],
        'TR' => ['Türkiye',                        '90',  'TRY'],

        // -- The Americas -----------------------------------------------
        'US' => ['United States',                  '1',   'USD'],
        'CA' => ['Canada',                         '1',   'CAD'],
        'BR' => ['Brazil',                         '55',  'BRL'],
        'MX' => ['Mexico',                         '52',  'MXN'],

        // -- Asia and Oceania -------------------------------------------
        'IN' => ['India',                          '91',  'INR'],
        'PK' => ['Pakistan',                       '92',  'PKR'],
        'BD' => ['Bangladesh',                     '880', 'BDT'],
        'CN' => ['China',                          '86',  'CNY'],
        'HK' => ['Hong Kong',                      '852', 'HKD'],
        'SG' => ['Singapore',                      '65',  'SGD'],
        'MY' => ['Malaysia',                       '60',  'MYR'],
        'ID' => ['Indonesia',                      '62',  'IDR'],
        'PH' => ['Philippines',                    '63',  'PHP'],
        'TH' => ['Thailand',                       '66',  'THB'],
        'JP' => ['Japan',                          '81',  'JPY'],
        'KR' => ['South Korea',                    '82',  'KRW'],
        'AU' => ['Australia',                      '61',  'AUD'],
        'NZ' => ['New Zealand',                    '64',  'NZD'],
    ];

    /**
     * What a tax number is called where the partner is.
     *
     * Asking an Emirati partner for their "KRA PIN" is the sort of thing
     * that makes a company look like it has never worked abroad. Only
     * the countries with a name people actually use are listed; the rest
     * fall back to "Tax ID".
     */
    private const TAX_LABELS = [
        'KE' => 'KRA PIN',
        'UG' => 'TIN',
        'TZ' => 'TIN',
        'RW' => 'TIN',
        'NG' => 'TIN',
        'GH' => 'TIN',
        'ZA' => 'SARS tax number',
        'US' => 'EIN / SSN',
        'GB' => 'UTR / VAT number',
        'IN' => 'PAN / GSTIN',
        'AE' => 'TRN',
        'SA' => 'VAT number',
        'CA' => 'Business Number',
        'AU' => 'ABN',
        'DE' => 'USt-IdNr',
        'FR' => 'SIRET / TVA',
        'NL' => 'BTW number',
        'IE' => 'VAT number',
        'SG' => 'UEN',
        'EG' => 'Tax Registration Number',
    ];

    /** Every way we can send money, with the label a human reads. */
    public const PAY_METHODS = [
        'mpesa'  => 'M-Pesa',
        'bank'   => 'Kenyan bank transfer',
        'wire'   => 'International wire (SWIFT)',
        'wise'   => 'Wise',
        'paypal' => 'PayPal',
    ];

    /** The kinds of partner, and what each one actually does. */
    public const TYPES = [
        'referral'       => 'Referral — introduces customers to us',
        'reseller'       => 'Reseller — sells and supports in their own market',
        'implementation' => 'Implementation — deploys and trains on our systems',
        'affiliate'      => 'Affiliate — online promotion, tracked by link',
    ];

    // -- Countries ---------------------------------------------------------

    /** Our own country. A partner here is local; everyone else is not. */
    public static function homeCountry(): string
    {
        $code = strtoupper(trim((string) Settings::get('partner_home_country', 'KE')));

        return isset(self::COUNTRIES[$code]) ? $code : 'KE';
    }

    public static function isLocal(?string $country): bool
    {
        return strtoupper((string) $country) === self::homeCountry();
    }

    public static function countryName(?string $code): string
    {
        $code = strtoupper((string) $code);

        return self::COUNTRIES[$code][0] ?? ($code !== '' ? $code : 'Unknown');
    }

    public static function dialCode(?string $code): string
    {
        return self::COUNTRIES[strtoupper((string) $code)][1] ?? '';
    }

    /** The currency a partner in this country most likely wants. */
    public static function currencyFor(?string $code): string
    {
        return self::COUNTRIES[strtoupper((string) $code)][2] ?? 'USD';
    }

    /** What to call their tax number on a form they will read. */
    public static function taxLabel(?string $code): string
    {
        return self::TAX_LABELS[strtoupper((string) $code)] ?? 'Tax ID';
    }

    /**
     * Countries as a flat code => name list, ours first.
     *
     * Ours first because most partners are still here and a select that
     * opens on Afghanistan is a select somebody has to scroll.
     */
    public static function countryOptions(): array
    {
        $home = self::homeCountry();
        $out  = [$home => self::COUNTRIES[$home][0]];

        $rest = [];
        foreach (self::COUNTRIES as $code => $row) {
            if ($code !== $home) {
                $rest[$code] = $row[0];
            }
        }

        asort($rest);

        return $out + $rest;
    }

    /**
     * Put a phone number into the international form, given a country.
     *
     * `normalize_phone()` in helpers.php only knows Kenya and turns
     * anything else into nonsense by prefixing 254. This keeps a number
     * that already carries a country code, and uses the partner's
     * country to fix one typed the local way with a leading zero.
     */
    public static function e164(?string $phone, ?string $country): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $dial = self::dialCode($country);

        // Already carries its own country code.
        if ($dial !== '' && str_starts_with($digits, $dial)) {
            return '+' . $digits;
        }

        // Typed the way people type it at home: 07.., 08.., 0...
        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');

            return $dial !== '' ? '+' . $dial . $digits : '+' . $digits;
        }

        // No leading zero and no country code: assume it is a local
        // subscriber number, which is what a 9-digit Kenyan one looks
        // like when somebody drops the zero.
        if ($dial !== '' && strlen($digits) <= 10) {
            return '+' . $dial . $digits;
        }

        return '+' . $digits;
    }

    // -- Currency ----------------------------------------------------------

    /** The currency commission is earned in. Always ours. */
    public static function baseCurrency(): string
    {
        return strtoupper(Settings::get('currency', 'KES'));
    }

    /**
     * Currencies finance is willing to pay out in.
     *
     * A short allow-list rather than every currency in the world, because
     * each one is a real banking arrangement somebody has to have made.
     */
    public static function payoutCurrencies(): array
    {
        $raw  = (string) Settings::get('partner_payout_currencies', 'KES,USD,EUR,GBP');
        $list = [];

        foreach (explode(',', $raw) as $code) {
            $code = strtoupper(trim($code));

            if ($code !== '' && preg_match('/^[A-Z]{3}$/', $code)) {
                $list[$code] = $code;
            }
        }

        // The base currency is always available, whatever the setting says.
        $base = self::baseCurrency();
        $list = [$base => $base] + $list;

        return $list;
    }

    public static function money(float $amount, string $currency): string
    {
        return $currency . ' ' . number_format($amount, 2);
    }

    // -- How we can pay them -----------------------------------------------

    /**
     * The methods that make sense for a partner in this country.
     *
     * M-Pesa reaches Kenyan numbers from our paybill and nowhere else, so
     * it is offered here and not abroad. "Kenyan bank transfer" and
     * "international wire" are separate for the same reason: one needs a
     * branch and a local account number, the other needs SWIFT and an
     * IBAN, and the file the bank gets is not the same file.
     */
    public static function payMethodsFor(?string $country): array
    {
        if (self::isLocal($country)) {
            return [
                'mpesa' => self::PAY_METHODS['mpesa'],
                'bank'  => self::PAY_METHODS['bank'],
            ];
        }

        return [
            'wire'   => self::PAY_METHODS['wire'],
            'wise'   => self::PAY_METHODS['wise'],
            'paypal' => self::PAY_METHODS['paypal'],
        ];
    }

    public static function payMethodLabel(?string $method): string
    {
        return self::PAY_METHODS[(string) $method] ?? 'Not set';
    }

    /** Whether a method moves money out of the country. */
    public static function isCrossBorder(?string $method): bool
    {
        return in_array((string) $method, ['wire', 'wise', 'paypal'], true);
    }

    // -- Withholding -------------------------------------------------------

    public static function withholdingEnabled(): bool
    {
        return Settings::bool('partner_withholding_enabled', false);
    }

    /**
     * The percentage to hold back from this partner's payout.
     *
     * Three levels, most specific first:
     *
     *   1. The partner's own `withholding_rate`. This exists because a
     *      double taxation treaty can set a rate for one country that is
     *      not the general non-resident rate, and no table in here is
     *      going to know every treaty Kenya has signed. Zero is a real
     *      answer and means do not withhold — which is why the column is
     *      nullable rather than defaulting to zero.
     *
     *   2. The rate for their residency, from settings.
     *
     *   3. Nothing, when withholding is switched off altogether.
     */
    public static function withholdingRate(array $partner): float
    {
        if (!self::withholdingEnabled()) {
            return 0.0;
        }

        $own = $partner['withholding_rate'] ?? null;

        if ($own !== null && $own !== '') {
            return max(0.0, min(100.0, (float) $own));
        }

        $key = ($partner['tax_residency'] ?? 'resident') === 'non_resident'
            ? 'partner_wht_non_resident'
            : 'partner_wht_resident';

        return max(0.0, min(100.0, Settings::float($key, 0.0)));
    }

    /**
     * Split a gross commission into what is withheld and what is sent.
     *
     * @return array{gross: float, rate: float, withheld: float, net: float}
     */
    public static function split(float $gross, array $partner): array
    {
        $rate     = self::withholdingRate($partner);
        $withheld = round($gross * $rate / 100, 2);

        return [
            'gross'    => round($gross, 2),
            'rate'     => $rate,
            'withheld' => $withheld,
            'net'      => round($gross - $withheld, 2),
        ];
    }

    // -- Exchange rates ----------------------------------------------------

    /**
     * KES per one unit of a currency, as at a date.
     *
     * The latest rate recorded on or before that day, so a payout built
     * for September uses September's rate however long afterwards
     * somebody looks at it. Returns null when we have never been told —
     * which the payout run treats as a reason to hold the line, not as a
     * reason to guess.
     */
    public static function fxRate(string $currency, ?string $asOf = null): ?float
    {
        $currency = strtoupper(trim($currency));

        if ($currency === self::baseCurrency()) {
            return 1.0;
        }

        self::ensureSchema();

        $rate = Database::scalar(
            "SELECT rate FROM fx_rates
              WHERE currency = :c AND as_of <= :d
           ORDER BY as_of DESC, id DESC
              LIMIT 1",
            ['c' => $currency, 'd' => $asOf ?: date('Y-m-d')],
            null
        );

        return $rate === null ? null : (float) $rate;
    }

    /** Record a rate. One per currency per day; a repeat corrects it. */
    public static function setFxRate(
        string $currency,
        float $rate,
        string $asOf,
        ?string $source = null,
        ?int $userId = null
    ): void {
        self::ensureSchema();
        $currency = strtoupper(trim($currency));

        Database::run(
            "INSERT INTO fx_rates (currency, rate, as_of, source, set_by)
             VALUES (:c, :r, :d, :s, :u)
             ON DUPLICATE KEY UPDATE rate = :r2, source = :s2, set_by = :u2",
            [
                'c'  => $currency,
                'r'  => $rate,
                'd'  => $asOf,
                's'  => $source,
                'u'  => $userId,
                'r2' => $rate,
                's2' => $source,
                'u2' => $userId,
            ]
        );
    }

    /** The newest rate on file for each currency we pay out in. */
    public static function latestRates(): array
    {
        self::ensureSchema();

        $rows = Database::all(
            "SELECT f.currency, f.rate, f.as_of, f.source, u.name AS set_by_name
               FROM fx_rates f
          LEFT JOIN users u ON u.id = f.set_by
               JOIN (SELECT currency, MAX(as_of) AS d
                       FROM fx_rates GROUP BY currency) latest
                 ON latest.currency = f.currency AND latest.d = f.as_of
           ORDER BY f.currency"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['currency']] = $row;
        }

        return $out;
    }

    /**
     * Currencies a partner is set to be paid in that we have no rate for.
     *
     * Finance wants to know this before building a run, not after it has
     * held six lines.
     */
    public static function currenciesMissingRates(): array
    {
        self::ensureSchema();

        $rows = Database::all(
            "SELECT DISTINCT payout_currency AS currency
               FROM partners
              WHERE status = 'active' AND payout_currency <> :base",
            ['base' => self::baseCurrency()]
        );

        $missing = [];
        foreach ($rows as $row) {
            if (self::fxRate((string) $row['currency']) === null) {
                $missing[] = (string) $row['currency'];
            }
        }

        return $missing;
    }

    // -- Tiers -------------------------------------------------------------

    /** @return array<string, array<string,mixed>> keyed by code, in order */
    public static function tiers(bool $publicOnly = false): array
    {
        self::ensureSchema();

        $rows = Database::all(
            'SELECT * FROM partner_tiers'
            . ($publicOnly ? ' WHERE is_public = 1' : '')
            . ' ORDER BY sort, min_billed_12m'
        );

        $out = [];
        foreach ($rows as $row) {
            $row['benefit_list'] = self::lines((string) ($row['benefits'] ?? ''));
            $out[$row['code']]   = $row;
        }

        return $out;
    }

    public static function tier(?string $code): ?array
    {
        $tiers = self::tiers();

        return $tiers[(string) $code] ?? null;
    }

    public static function tierName(?string $code): string
    {
        $tier = self::tier($code);

        return $tier['name'] ?? ucfirst((string) $code);
    }

    /** Benefits are stored one per line; blank lines are not benefits. */
    private static function lines(string $text): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * What a partner has introduced in the last twelve months.
     *
     * Invoiced value of their customers' work, net of VAT, excluding
     * drafts. This is the number the tier thresholds are written against,
     * so it is defined once here.
     */
    public static function billed12m(int $partnerId): float
    {
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(d.subtotal - d.discount_amount), 0)
               FROM documents d
               JOIN clients c ON c.id = d.client_id
              WHERE c.partner_id = :p
                AND d.doc_type = 'invoice'
                AND d.status <> 'draft'
                AND d.issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)",
            ['p' => $partnerId],
            0
        );
    }

    /**
     * The highest tier this partner's own numbers earn them.
     *
     * Kept separate from the tier they are ON, because promotion is a
     * decision somebody makes — a partner can be left at a tier they
     * have outgrown while a contract is sorted out, and somebody who had
     * a good year is not demoted automatically when it ends.
     */
    public static function earnedTier(int $partnerId): ?array
    {
        $billed = self::billed12m($partnerId);
        $best   = null;

        foreach (self::tiers() as $tier) {
            if ($billed >= (float) $tier['min_billed_12m']) {
                $best = $tier;
            }
        }

        return $best;
    }

    // -- Readiness ---------------------------------------------------------

    /**
     * What is wrong with where the money would be sent, and only that.
     *
     * Its own method because it answers a narrower question than
     * blockers() does: an administrator saving payment details should be
     * stopped by a missing SWIFT code, and should not be stopped because
     * somebody has not typed the partner's tax number yet. Those are two
     * different pieces of work, often done by two different people.
     *
     * @return string[]
     */
    public static function destinationBlockers(array $partner): array
    {
        $out     = [];
        $country = (string) ($partner['country'] ?? 'KE');
        $method  = (string) ($partner['pay_method'] ?? '');

        if ($method === '') {
            return ['No payment method chosen'];
        }

        if (!isset(self::payMethodsFor($country)[$method])) {
            $out[] = sprintf(
                '%s cannot be used for a partner in %s',
                self::payMethodLabel($method),
                self::countryName($country)
            );
        }

        switch ($method) {
            case 'mpesa':
                if (trim((string) ($partner['pay_phone'] ?? '')) === ''
                    && trim((string) ($partner['phone'] ?? '')) === '') {
                    $out[] = 'No M-Pesa number on file';
                }
                break;

            case 'bank':
                if (trim((string) ($partner['bank_account_no'] ?? '')) === '') {
                    $out[] = 'No bank account number on file';
                }
                if (trim((string) ($partner['bank_name'] ?? '')) === '') {
                    $out[] = 'No bank name on file';
                }
                break;

            case 'wire':
                if (trim((string) ($partner['bank_account_no'] ?? '')) === ''
                    && trim((string) ($partner['bank_iban'] ?? '')) === '') {
                    $out[] = 'A wire needs an IBAN or an account number';
                }
                if (trim((string) ($partner['bank_swift'] ?? '')) === '') {
                    $out[] = 'No SWIFT/BIC on file — the wire cannot be addressed';
                }
                break;

            case 'wise':
            case 'paypal':
                if (trim((string) ($partner['pay_email'] ?? '')) === '') {
                    $out[] = 'No ' . self::payMethodLabel($method) . ' address on file';
                }
                break;
        }

        return $out;
    }

    /**
     * Everything that will actually stop a payout run paying them.
     *
     * `Payouts::destinationFor()` answers the same question but stops at
     * the first problem, because a payout line has room for one reason.
     * An administrator looking at the partner wants the whole list, so
     * they can fix it in one conversation rather than four.
     *
     * Governance comes before plumbing. Several can be true at once, and
     * when only one reason fits on a payout line it should be the one
     * that needs a decision rather than a form filling in.
     *
     * Note what is NOT here: a missing tax number. It is a real thing to
     * chase and it is on the partner page, but it has never stopped a
     * payment and making it stop one would quietly strand partners who
     * are being paid perfectly well today. That list is
     * complianceWarnings().
     *
     * @return string[]
     */
    public static function blockers(array $partner): array
    {
        $out = [];

        if (($partner['status'] ?? '') === 'suspended') {
            $out[] = 'Partner is suspended';
        }

        $expires = (string) ($partner['agreement_expires_at'] ?? '');

        if ($expires !== '' && $expires < date('Y-m-d')) {
            $out[] = 'Partner agreement expired on ' . $expires;
        }

        $out = array_merge($out, self::destinationBlockers($partner));

        // Converting at a rate nobody has given us would mean inventing
        // the amount, so this one does stop the payment.
        $currency = (string) ($partner['payout_currency'] ?? self::baseCurrency());

        if ($currency !== self::baseCurrency() && self::fxRate($currency) === null) {
            $out[] = 'No exchange rate on file for ' . $currency;
        }

        return $out;
    }

    /**
     * Things to chase that do not stop the money.
     *
     * Kept apart from blockers() so that "cannot be paid" stays true
     * when it is said. A partner missing a tax number can be paid, and
     * is; somebody should still go and get the number.
     *
     * @return string[]
     */
    public static function complianceWarnings(array $partner): array
    {
        $out     = [];
        $country = (string) ($partner['country'] ?? 'KE');

        // A local partner needs the PIN we have always wanted. Everyone
        // else needs whatever their own authority calls it — and wanting
        // it is sharper once withholding is on, because a deduction has
        // to be reported against somebody KRA can identify.
        if (self::isLocal($country)) {
            if (trim((string) ($partner['kra_pin'] ?? '')) === '') {
                $out[] = 'No KRA PIN on file';
            }
        } elseif (trim((string) ($partner['tax_id'] ?? '')) === ''
                  && trim((string) ($partner['kra_pin'] ?? '')) === '') {
            $out[] = 'No ' . self::taxLabel($country) . ' on file';
        }

        if (self::withholdingEnabled() && self::withholdingRate($partner) > 0) {
            $hasId = trim((string) ($partner['kra_pin'] ?? '')) !== ''
                  || trim((string) ($partner['tax_id'] ?? '')) !== '';

            if (!$hasId) {
                $out[] = 'Tax is being withheld from a payee with no tax number, '
                       . 'which cannot be reported on the return';
            }
        }

        $signed = (string) ($partner['agreement_signed_at'] ?? '');

        if ($signed === '' && !self::isLocal($country) && ($partner['status'] ?? '') === 'active') {
            $out[] = 'No signed agreement recorded';
        }

        return $out;
    }

    // -- Reporting ---------------------------------------------------------

    /**
     * Partners grouped by country, for the overview.
     *
     * One row per country with the counts and the money, so the admin
     * page can answer "where are we actually selling" without pulling
     * every partner down and counting in PHP.
     */
    public static function byCountry(): array
    {
        self::ensureSchema();

        return Database::all(
            "SELECT p.country,
                    COUNT(*)                 AS partners,
                    SUM(p.status = 'active')  AS active,
                    SUM(p.status = 'pending') AS pending,
                    (SELECT COUNT(*) FROM clients c
                       JOIN partners p2 ON p2.id = c.partner_id
                      WHERE p2.country = p.country) AS customers,
                    (SELECT COALESCE(SUM(cm.amount), 0) FROM commissions cm
                       JOIN partners p3 ON p3.id = cm.partner_id
                      WHERE p3.country = p.country AND cm.status <> 'void') AS commission
               FROM partners p
           GROUP BY p.country
           ORDER BY active DESC, partners DESC"
        );
    }

    /** Agreements running out in the next N days, soonest first. */
    public static function expiringAgreements(int $days = 60): array
    {
        self::ensureSchema();

        return Database::all(
            "SELECT id, partner_code, name, company, country, tier,
                    agreement_ref, agreement_expires_at
               FROM partners
              WHERE status = 'active'
                AND agreement_expires_at IS NOT NULL
                AND agreement_expires_at <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
           ORDER BY agreement_expires_at",
            ['d' => $days]
        );
    }

    /** Pending partner applications for the sidebar badge. */
    public static function waiting(): int
    {
        try {
            return (int) Database::scalar("SELECT COUNT(*) FROM partners WHERE status = 'pending'", [], 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static bool $schemaChecked = false;

    /**
     * Ensure the international-partner schema (migration 064) is present.
     *
     * On shared/cPanel hosting a deployment can land before php migrate.php
     * or upgrade.php has been run. Checking once on entry and applying the
     * partner migrations if any column or table is missing prevents a 500
     * when staff or partners open the module.
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $ready = (int) Database::scalar(
                "SELECT COUNT(*)
                   FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND (
                        (table_name = 'partners'      AND column_name IN ('country', 'tier', 'payout_currency'))
                     OR (table_name = 'payout_lines'  AND column_name = 'net_fx')
                     OR (table_name = 'payout_runs'   AND column_name = 'withheld_total')
                     OR (table_name = 'partner_tiers' AND column_name = 'code')
                     OR (table_name = 'fx_rates'      AND column_name = 'rate')
                    )",
                [],
                0
            );

            if ($ready >= 7) {
                return;
            }

            $migrator = new \App\Core\Migrator();
            foreach ([
                '2026_09_07_029_partners.sql',
                '2026_09_07_030_partner_accounts.sql',
                '2026_09_07_031_partner_messages.sql',
                '2026_09_07_032_partner_rates.sql',
                '2026_09_08_033_partner_payouts.sql',
                '2026_09_08_034_partner_application.sql',
                '2026_09_08_035_partner_client_messages.sql',
                '2026_11_04_064_international_partners.sql',
            ] as $migration) {
                $file = BASE_PATH . '/database/migrations/' . $migration;
                if (is_file($file)) {
                    $migrator->apply($file);
                }
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Partners schema check failed: ' . $e->getMessage());
        }
    }
}
