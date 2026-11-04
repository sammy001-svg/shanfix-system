<?php

namespace App\Services;

use App\Core\Database;

/**
 * Paying partners, and being able to prove it afterwards.
 *
 * The rule this whole file exists to enforce: a commission row turns
 * 'paid' when money actually lands, not when somebody presses a button.
 * Pressing the button builds a RUN; the run produces a file for the bank
 * or for M-Pesa; and each LINE in it settles on its own, with its own
 * reference, when we hear back. One line bouncing must not un-pay the
 * other thirty-nine, and must not quietly swallow what that partner is
 * owed — a failed line releases its commission so it is simply owed
 * again and falls into the next run.
 *
 * A commission is EARNED gross, in the currency the customer paid us
 * in, and that is what the ledger keeps. Two things can happen to it on
 * the way out, and both belong here rather than in the ledger:
 *
 *   * Withholding. Tax deducted and remitted to KRA on the partner's
 *     behalf is a fact about the payment, not about the sale, so it sits
 *     on the line. It is off until somebody switches it on — see
 *     migration 064.
 *
 *   * Conversion. A partner abroad is paid in their own currency, at the
 *     rate that stood when the run was built. The rate is copied onto
 *     the line for the same reason the commission rate is copied into
 *     the ledger: a rate that moves next month must not move what we
 *     already sent.
 */
class Payouts
{
    /** Statuses a line can be in while it still intends to pay somebody. */
    private const LIVE = ['pending', 'sent'];

    // -- Where the money goes ---------------------------------------------

    /**
     * The destination for a partner, or a reason we cannot pay them.
     *
     * The reason comes from Partners::blockers(), which answers the same
     * question in full. A payout line has room for one reason, so this
     * takes the first; the partner page shows the whole list.
     *
     * @return array{method: ?string, destination: ?string, name: ?string,
     *               currency: string, fx_rate: float, withholding_rate: float,
     *               problem: ?string}
     */
    public static function destinationFor(array $partner): array
    {
        $base = Partners::baseCurrency();

        $none = [
            'method'           => null,
            'destination'      => null,
            'name'             => null,
            'currency'         => $base,
            'fx_rate'          => 1.0,
            'withholding_rate' => 0.0,
        ];

        // Everything that could stop this payment, governance first. A
        // suspended partner may well be owed the money, but a suspended
        // account is suspended for a reason and finance should look at it
        // before it goes. The line can still be admitted by hand.
        $blockers = Partners::blockers($partner);

        if ($blockers !== []) {
            return $none + ['problem' => $blockers[0]];
        }

        $method   = (string) ($partner['pay_method'] ?? '');
        $currency = strtoupper((string) ($partner['payout_currency'] ?? $base)) ?: $base;
        $rate     = Partners::fxRate($currency);

        if ($rate === null || $rate <= 0) {
            // blockers() has already checked this, so reaching here means
            // the rate was withdrawn between the two calls. Refusing is
            // still better than converting at a number we invented.
            return $none + ['problem' => 'No exchange rate on file for ' . $currency];
        }

        // The name on the instruction. A bank, a wire and a wallet all
        // match on the name they hold, which is not always the trading
        // name we know them by.
        $accountName = trim((string) ($partner['bank_account_name'] ?? ''))
            ?: trim((string) ($partner['company'] ?? ''))
            ?: (string) ($partner['name'] ?? '');

        $found = [
            'method'           => $method,
            'currency'         => $currency,
            'fx_rate'          => (float) $rate,
            'withholding_rate' => Partners::withholdingRate($partner),
            'problem'          => null,
        ];

        switch ($method) {
            case 'mpesa':
                return $found + [
                    'destination' => trim((string) ($partner['pay_phone'] ?? ''))
                                     ?: trim((string) ($partner['phone'] ?? '')),
                    'name'        => (string) ($partner['name'] ?? ''),
                ];

            case 'bank':
                return $found + [
                    'destination' => trim((string) $partner['bank_account_no']),
                    'name'        => $accountName,
                ];

            case 'wire':
                // An IBAN where there is one, because that is what a
                // correspondent bank routes on; the account number is the
                // fallback for countries that do not use IBANs.
                return $found + [
                    'destination' => trim((string) ($partner['bank_iban'] ?? ''))
                                     ?: trim((string) ($partner['bank_account_no'] ?? '')),
                    'name'        => $accountName,
                ];

            case 'wise':
            case 'paypal':
                return $found + [
                    'destination' => trim((string) ($partner['pay_email'] ?? '')),
                    'name'        => $accountName,
                ];
        }

        return $none + ['problem' => 'No payment method chosen'];
    }

    /**
     * What a gross amount becomes on a line: tax off, then converted.
     *
     * In that order, and the order matters. Withholding is a Kenyan tax
     * on a Kenyan-sourced commission, so it is worked out in KES on the
     * gross; only what is left crosses the border.
     *
     * @param  array $where the result of destinationFor()
     * @return array{withheld: float, net_amount: float, net_fx: float}
     */
    public static function amountsFor(float $gross, array $where): array
    {
        $rate     = (float) ($where['withholding_rate'] ?? 0.0);
        $withheld = round($gross * $rate / 100, 2);
        $net      = round($gross - $withheld, 2);
        $fx       = (float) ($where['fx_rate'] ?? 1.0) ?: 1.0;

        return [
            'withheld'   => $withheld,
            'net_amount' => $net,
            'net_fx'     => round($net / $fx, 2),
        ];
    }

    // -- Building a run ----------------------------------------------------

    /** The run for this period that is still going, if there is one. */
    public static function openRun(string $period): ?array
    {
        return Database::first(
            "SELECT * FROM payout_runs
              WHERE period = :p AND status <> 'closed'
           ORDER BY id DESC LIMIT 1",
            ['p' => $period]
        );
    }

    /**
     * What is owed for a month and not already committed to a run.
     *
     * "Available" is exactly: earned, and no payout line against it. That
     * one condition is what stops a second run paying the same commission
     * twice, so it is written once here and used everywhere.
     */
    public static function available(string $period): array
    {
        Partners::ensureSchema();

        return Database::all(
            "SELECT p.*,
                    cm.amount,
                    cm.entries
               FROM (
                    SELECT partner_id,
                           SUM(amount) AS amount,
                           COUNT(*)    AS entries
                      FROM commissions
                     WHERE status = 'earned'
                       AND payout_line_id IS NULL
                       AND period = :p
                  GROUP BY partner_id
                    HAVING SUM(amount) > 0.009
                    ) cm
               JOIN partners p ON p.id = cm.partner_id
           ORDER BY p.name",
            ['p' => $period]
        );
    }

    /**
     * Build a draft run for a month.
     *
     * Partners we cannot pay go in as HELD lines rather than being left
     * out. Dropping them silently is how somebody goes six months without
     * being paid and nobody notices; a held line is a visible piece of
     * work with the reason written on it. Held lines take no commission
     * with them, so the money stays plainly owed until it can go out.
     *
     * @return array{run_id: int, lines: int, held: int, total: float}
     */
    public static function build(string $period, ?int $userId = null): array
    {
        return Database::transaction(static function () use ($period, $userId): array {
            $runId = Database::insert('payout_runs', [
                'period'     => $period,
                'status'     => 'draft',
                'created_by' => $userId,
            ]);

            $lines    = 0;
            $held     = 0;
            $total    = 0.0;
            $withheld = 0.0;

            foreach (self::available($period) as $partner) {
                $amount = round((float) $partner['amount'], 2);
                $where  = self::destinationFor($partner);
                $split  = self::amountsFor($amount, $where);

                $lineId = Database::insert('payout_lines', [
                    'run_id'           => $runId,
                    'partner_id'       => (int) $partner['id'],
                    'amount'           => $amount,
                    'entries'          => (int) $partner['entries'],
                    'method'           => $where['method'],
                    'destination'      => $where['destination'],
                    'account_name'     => $where['name'],
                    'currency'         => $where['currency'],
                    'fx_rate'          => $where['fx_rate'],
                    'withholding_rate' => $where['withholding_rate'],
                    'withheld'         => $split['withheld'],
                    'net_amount'       => $split['net_amount'],
                    'net_fx'           => $split['net_fx'],
                    'status'           => $where['problem'] === null ? 'pending' : 'held',
                    'failure_reason'   => $where['problem'],
                ]);

                if ($where['problem'] !== null) {
                    $held++;
                    continue;
                }

                self::attach($lineId, (int) $partner['id'], $period);

                $lines++;
                $total    += $amount;
                $withheld += $split['withheld'];
            }

            Database::update('payout_runs', [
                'total'          => round($total, 2),
                'withheld_total' => round($withheld, 2),
                'line_count'     => $lines,
            ], ['id' => $runId]);

            return [
                'run_id'   => $runId,
                'lines'    => $lines,
                'held'     => $held,
                'total'    => round($total, 2),
                'withheld' => round($withheld, 2),
            ];
        });
    }

    /** Commit a partner's owed commission for a month to one line. */
    private static function attach(int $lineId, int $partnerId, string $period): int
    {
        return Database::run(
            "UPDATE commissions SET payout_line_id = :line
              WHERE status = 'earned' AND payout_line_id IS NULL
                AND partner_id = :p AND period = :period",
            ['line' => $lineId, 'p' => $partnerId, 'period' => $period]
        )->rowCount();
    }

    /**
     * Bring a held line into the run after its problem has been fixed.
     *
     * Re-reads the partner rather than trusting what was copied in when
     * the run was built, because the whole point is that something has
     * changed since.
     */
    public static function admit(int $lineId): bool
    {
        $line = Database::first('SELECT * FROM payout_lines WHERE id = :id', ['id' => $lineId]);

        if (!$line || $line['status'] !== 'held') {
            return false;
        }

        $run     = Database::first('SELECT * FROM payout_runs WHERE id = :id', ['id' => $line['run_id']]);
        $partner = Database::first('SELECT * FROM partners WHERE id = :id', ['id' => $line['partner_id']]);

        if (!$run || !$partner || $run['status'] === 'closed') {
            return false;
        }

        $where = self::destinationFor($partner);

        if ($where['problem'] !== null) {
            // Still nowhere to send it. Update the reason, so whoever is
            // looking can see what is still missing.
            Database::update('payout_lines', ['failure_reason' => $where['problem']], ['id' => $lineId]);

            return false;
        }

        $n = self::attach($lineId, (int) $line['partner_id'], (string) $run['period']);

        if ($n === 0) {
            Database::update('payout_lines', [
                'failure_reason' => 'Nothing outstanding for this month any more',
            ], ['id' => $lineId]);

            return false;
        }

        $amount = (float) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE payout_line_id = :l',
            ['l' => $lineId],
            0
        );

        $split = self::amountsFor(round($amount, 2), $where);

        Database::update('payout_lines', [
            'amount'           => round($amount, 2),
            'entries'          => $n,
            'method'           => $where['method'],
            'destination'      => $where['destination'],
            'account_name'     => $where['name'],
            // Re-read with the rest, so a line admitted a week later is
            // converted at the rate that is current now rather than at
            // whatever stood when the run was first built.
            'currency'         => $where['currency'],
            'fx_rate'          => $where['fx_rate'],
            'withholding_rate' => $where['withholding_rate'],
            'withheld'         => $split['withheld'],
            'net_amount'       => $split['net_amount'],
            'net_fx'           => $split['net_fx'],
            'status'           => 'pending',
            'failure_reason'   => null,
        ], ['id' => $lineId]);

        self::refresh((int) $line['run_id']);

        return true;
    }

    /** Take a line back out of the run, releasing what it was going to pay. */
    public static function hold(int $lineId, string $reason): bool
    {
        $line = Database::first('SELECT * FROM payout_lines WHERE id = :id', ['id' => $lineId]);

        if (!$line || !in_array($line['status'], self::LIVE, true)) {
            return false;
        }

        self::release($lineId);

        Database::update('payout_lines', [
            'status'         => 'held',
            'failure_reason' => $reason !== '' ? $reason : 'Held back',
        ], ['id' => $lineId]);

        self::refresh((int) $line['run_id']);

        return true;
    }

    // -- Signing it off and sending it -------------------------------------

    public static function approve(int $runId, int $userId): bool
    {
        $run = Database::first('SELECT * FROM payout_runs WHERE id = :id', ['id' => $runId]);

        if (!$run || $run['status'] !== 'draft' || (int) $run['line_count'] === 0) {
            return false;
        }

        Database::update('payout_runs', [
            'status'      => 'approved',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ], ['id' => $runId]);

        return true;
    }

    /**
     * Note that the file has gone out.
     *
     * Exporting is what turns "we mean to pay this" into "this is with the
     * bank", so the lines move to sent and the run stops being a draft.
     */
    public static function markExported(int $runId): void
    {
        $run = Database::first('SELECT * FROM payout_runs WHERE id = :id', ['id' => $runId]);

        if (!$run || !in_array($run['status'], ['approved', 'sent'], true)) {
            return;
        }

        Database::run(
            "UPDATE payout_lines SET status = 'sent' WHERE run_id = :r AND status = 'pending'",
            ['r' => $runId]
        );

        Database::update('payout_runs', [
            'status'      => 'sent',
            'exported_at' => date('Y-m-d H:i:s'),
        ], ['id' => $runId]);
    }

    // -- Hearing back ------------------------------------------------------

    /** The money landed. This is the only place commission becomes paid. */
    public static function settle(int $lineId, string $ref): bool
    {
        $line = Database::first('SELECT * FROM payout_lines WHERE id = :id', ['id' => $lineId]);

        if (!$line || !in_array($line['status'], self::LIVE, true)) {
            return false;
        }

        return (bool) Database::transaction(static function () use ($line, $lineId, $ref): bool {
            Database::update('payout_lines', [
                'status'         => 'settled',
                'ref'            => $ref,
                'failure_reason' => null,
                'settled_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $lineId]);

            Database::run(
                "UPDATE commissions
                    SET status = 'paid', paid_at = NOW(), payout_ref = :ref
                  WHERE payout_line_id = :l AND status = 'earned'",
                ['ref' => $ref, 'l' => $lineId]
            );

            self::refresh((int) $line['run_id']);

            return true;
        });
    }

    /**
     * It bounced.
     *
     * The commission goes back to being owed and unattached, so the next
     * run picks it up without anybody having to remember. That is the
     * whole reason the link exists.
     */
    public static function fail(int $lineId, string $reason): bool
    {
        $line = Database::first('SELECT * FROM payout_lines WHERE id = :id', ['id' => $lineId]);

        if (!$line || !in_array($line['status'], self::LIVE, true)) {
            return false;
        }

        return (bool) Database::transaction(static function () use ($line, $lineId, $reason): bool {
            self::release($lineId);

            Database::update('payout_lines', [
                'status'         => 'failed',
                'failure_reason' => $reason !== '' ? $reason : 'The transfer did not go through',
            ], ['id' => $lineId]);

            self::refresh((int) $line['run_id']);

            return true;
        });
    }

    /**
     * Settle everything still outstanding with one reference.
     *
     * What a bulk transfer looks like when it works: one batch reference
     * covering the lot.
     */
    public static function settleRemaining(int $runId, string $ref): int
    {
        $lines = Database::all(
            "SELECT id FROM payout_lines
              WHERE run_id = :r AND status IN ('pending', 'sent')",
            ['r' => $runId]
        );

        $n = 0;

        foreach ($lines as $line) {
            if (self::settle((int) $line['id'], $ref)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Pay one partner outside the monthly run, and still record it properly.
     *
     * There is a real use for this — somebody was handed cash, or paid off
     * the books of the run — and the old version of it simply flipped every
     * owed row to 'paid'. That had two faults: it left no trace of where the
     * money went, and it could not see that a row was already committed to
     * an open run, so the same commission could go out twice.
     *
     * So this walks the same road as everything else, only quickly: a run
     * per month involved, one line in each, settled on the spot. Missing
     * payment details do not block it, because the money has already gone
     * by some means the system did not arrange.
     *
     * @return array{paid: float, runs: list<int>, months: int}
     */
    public static function payDirect(int $partnerId, ?string $period, string $ref, ?int $userId = null): array
    {
        // A row with no month cannot be gathered into anything, and would
        // sit there owed forever. Stamp it from when it was written.
        Database::run(
            "UPDATE commissions
                SET period = DATE_FORMAT(created_at, '%Y-%m')
              WHERE partner_id = :p AND period IS NULL AND status = 'earned'",
            ['p' => $partnerId]
        );

        $params = ['p' => $partnerId];
        $clause = '';

        if ($period !== null && $period !== '') {
            $clause          = ' AND period = :period';
            $params['period'] = $period;
        }

        $months = Database::all(
            "SELECT period, SUM(amount) AS amount, COUNT(*) AS entries
               FROM commissions
              WHERE partner_id = :p AND status = 'earned'
                AND payout_line_id IS NULL" . $clause . "
           GROUP BY period
             HAVING SUM(amount) > 0.009
           ORDER BY period",
            $params
        );

        if (!$months) {
            return ['paid' => 0.0, 'runs' => [], 'months' => 0];
        }

        $partner = Database::first('SELECT * FROM partners WHERE id = :id', ['id' => $partnerId]);
        $where   = $partner ? self::destinationFor($partner) : [
            'method'           => null,
            'destination'      => null,
            'name'             => null,
            'currency'         => Partners::baseCurrency(),
            'fx_rate'          => 1.0,
            'withholding_rate' => 0.0,
        ];

        $paid = 0.0;
        $runs = [];

        foreach ($months as $month) {
            $gross = round((float) $month['amount'], 2);
            $split = self::amountsFor($gross, $where);

            $runId = Database::insert('payout_runs', [
                'period'      => (string) $month['period'],
                // Whoever pressed the button both authorised it and knows
                // the money has gone, so there is nothing left to approve.
                'status'      => 'approved',
                'created_by'  => $userId,
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'note'        => 'Paid directly, outside the monthly run',
            ]);

            $lineId = Database::insert('payout_lines', [
                'run_id'           => $runId,
                'partner_id'       => $partnerId,
                'amount'           => $gross,
                'entries'          => (int) $month['entries'],
                'method'           => $where['method'],
                'destination'      => $where['destination'],
                'account_name'     => $where['name'],
                'currency'         => $where['currency'],
                'fx_rate'          => $where['fx_rate'],
                'withholding_rate' => $where['withholding_rate'],
                'withheld'         => $split['withheld'],
                'net_amount'       => $split['net_amount'],
                'net_fx'           => $split['net_fx'],
                'status'           => 'pending',
            ]);

            self::attach($lineId, $partnerId, (string) $month['period']);
            self::settle($lineId, $ref);

            $paid  += (float) $month['amount'];
            $runs[] = $runId;
        }

        return ['paid' => round($paid, 2), 'runs' => $runs, 'months' => count($months)];
    }

    /** Let go of the commission a line was going to pay. */
    private static function release(int $lineId): void
    {
        Database::run(
            'UPDATE commissions SET payout_line_id = NULL WHERE payout_line_id = :l',
            ['l' => $lineId]
        );
    }

    /**
     * Bring a run's totals back in line with its lines, and close it when
     * there is nothing left waiting.
     */
    public static function refresh(int $runId): void
    {
        $run = Database::first('SELECT * FROM payout_runs WHERE id = :id', ['id' => $runId]);

        if (!$run) {
            return;
        }

        $live = "status IN ('pending', 'sent', 'settled')";

        $total = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payout_lines WHERE run_id = :r AND " . $live,
            ['r' => $runId],
            0
        );

        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM payout_lines WHERE run_id = :r AND ' . $live,
            ['r' => $runId],
            0
        );

        // What the run holds back, for the return that follows it. Held
        // and failed lines withhold nothing because they send nothing.
        $withheld = (float) Database::scalar(
            "SELECT COALESCE(SUM(withheld), 0) FROM payout_lines WHERE run_id = :r AND " . $live,
            ['r' => $runId],
            0
        );

        $waiting = (int) Database::scalar(
            "SELECT COUNT(*) FROM payout_lines
              WHERE run_id = :r AND status IN ('pending', 'sent')",
            ['r' => $runId],
            0
        );

        $data = [
            'total'          => round($total, 2),
            'withheld_total' => round($withheld, 2),
            'line_count'     => $count,
        ];

        // A run closes when nothing is still waiting to be heard about.
        // Held lines do not hold it open: they are work for next month,
        // not money in flight. A draft stays a draft — it has not been
        // sent anywhere, so there is nothing to have finished.
        if ($waiting === 0 && !in_array($run['status'], ['draft', 'closed'], true)) {
            $data['status']    = 'closed';
            $data['closed_at'] = date('Y-m-d H:i:s');
        }

        Database::update('payout_runs', $data, ['id' => $runId]);
    }

    // -- Reading -----------------------------------------------------------

    public static function find(int $runId): ?array
    {
        return Database::first(
            'SELECT r.*, u.name AS created_name, a.name AS approved_name
               FROM payout_runs r
          LEFT JOIN users u ON u.id = r.created_by
          LEFT JOIN users a ON a.id = r.approved_by
              WHERE r.id = :id',
            ['id' => $runId]
        );
    }

    public static function lines(int $runId): array
    {
        return Database::all(
            "SELECT l.*, p.name, p.company, p.partner_code, p.email, p.phone, p.kra_pin
               FROM payout_lines l
               JOIN partners p ON p.id = l.partner_id
              WHERE l.run_id = :r
           ORDER BY FIELD(l.status, 'pending', 'sent', 'settled', 'failed', 'held'), p.name",
            ['r' => $runId]
        );
    }

    public static function recent(int $limit = 30): array
    {
        return Database::all(
            'SELECT r.*, u.name AS created_name
               FROM payout_runs r
          LEFT JOIN users u ON u.id = r.created_by
           ORDER BY r.id DESC LIMIT ' . max(1, $limit)
        );
    }

    /** Everything ever paid to one partner, newest first. */
    public static function historyFor(int $partnerId, int $limit = 24): array
    {
        return Database::all(
            'SELECT l.*, r.period, r.status AS run_status
               FROM payout_lines l
               JOIN payout_runs r ON r.id = l.run_id
              WHERE l.partner_id = :p
           ORDER BY l.id DESC LIMIT ' . max(1, $limit),
            ['p' => $partnerId]
        );
    }

    /**
     * The payout line covering one partner's one month, if there is one.
     *
     * For the partner's own statement. With anything withheld or
     * converted, what they earned and what reached their account are two
     * different numbers, and a statement that gives only the first is a
     * statement they will ring us about.
     */
    public static function lineForPeriod(int $partnerId, string $period): ?array
    {
        return Database::first(
            "SELECT l.*, r.period, r.status AS run_status, r.exported_at
               FROM payout_lines l
               JOIN payout_runs r ON r.id = l.run_id
              WHERE l.partner_id = :p AND r.period = :period
           ORDER BY l.id DESC LIMIT 1",
            ['p' => $partnerId, 'period' => $period]
        );
    }

    /** How many lines of each method are still waiting to go out. */
    public static function pendingByMethod(int $runId): array
    {
        $rows = Database::all(
            "SELECT method, COUNT(*) AS n FROM payout_lines
              WHERE run_id = :r AND status IN ('pending', 'sent') AND method IS NOT NULL
           GROUP BY method",
            ['r' => $runId]
        );

        // Every method we can pay by, so a run with nothing but wires in
        // it still reports them rather than silently showing two zeroes.
        $out = array_fill_keys(array_keys(Partners::PAY_METHODS), 0);

        foreach ($rows as $row) {
            $out[(string) $row['method']] = (int) $row['n'];
        }

        return $out;
    }

    // -- The file the bank gets --------------------------------------------

    /**
     * A payment file for one method.
     *
     * One format per method rather than one for all of them, because the
     * things they need are genuinely different: M-Pesa wants a phone
     * number, a local bank wants an account and a branch, a wire wants a
     * SWIFT code and a beneficiary address, and a wallet wants an email.
     * A single file with two-thirds of its columns empty gets rejected by
     * all four.
     *
     * Every file carries NET, not gross. The gross is in the ledger and
     * on the partner's statement; the number on the payment instruction
     * has to be the number we are actually sending, or the file and the
     * books disagree the moment anything is withheld.
     *
     * @param  string $kind one of Partners::PAY_METHODS
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public static function csvParts(int $runId, string $kind): array
    {
        $run = Database::first('SELECT * FROM payout_runs WHERE id = :id', ['id' => $runId]);

        if (!$run || !isset(Partners::PAY_METHODS[$kind])) {
            return ['headers' => [], 'rows' => []];
        }

        $narration = 'Commission ' . $run['period'];

        $lines = Database::all(
            "SELECT l.*, p.name, p.company, p.partner_code, p.kra_pin, p.tax_id,
                    p.bank_name, p.bank_branch, p.bank_swift, p.bank_iban,
                    p.bank_routing, p.bank_country, p.bank_address,
                    p.country, p.city, p.office_location
               FROM payout_lines l
               JOIN partners p ON p.id = l.partner_id
              WHERE l.run_id = :r AND l.method = :m
                AND l.status IN ('pending', 'sent')
           ORDER BY p.name",
            ['r' => $runId, 'm' => $kind]
        );

        /** A line's own reference, which is what a settlement is matched on. */
        $ref = static fn (array $l): string
            => (string) ($l['partner_code'] ?: 'P' . $l['partner_id']);

        /** Net in KES, to two places, no thousands separator. */
        $kes = static fn (array $l): string
            => number_format((float) $l['net_amount'], 2, '.', '');

        /** Net in the currency it leaves in. */
        $fx = static fn (array $l): string
            => number_format((float) $l['net_fx'], 2, '.', '');

        $rows    = [];
        $headers = [];

        switch ($kind) {
            case 'mpesa':
                $headers = ['Phone', 'Amount', 'Name', 'Reference', 'Narration'];

                foreach ($lines as $line) {
                    $rows[] = [
                        self::msisdn((string) $line['destination']),
                        $kes($line),
                        $line['account_name'] ?: $line['name'],
                        $ref($line),
                        $narration,
                    ];
                }
                break;

            case 'bank':
                $headers = ['Account Name', 'Account Number', 'Bank', 'Branch',
                            'Amount', 'Reference', 'Narration'];

                foreach ($lines as $line) {
                    $rows[] = [
                        $line['account_name'] ?: $line['name'],
                        (string) $line['destination'],
                        (string) $line['bank_name'],
                        (string) $line['bank_branch'],
                        $kes($line),
                        $ref($line),
                        $narration,
                    ];
                }
                break;

            case 'wire':
                // The columns a bank asks for on an outward SWIFT
                // instruction. Both amounts are given: the currency and
                // figure to send, and the KES the account is debited by,
                // which is what reconciles against our own books.
                $headers = ['Beneficiary Name', 'IBAN / Account', 'SWIFT / BIC',
                            'Routing Code', 'Bank Name', 'Bank Country',
                            'Bank Address', 'Beneficiary Country',
                            'Beneficiary Address', 'Currency', 'Amount',
                            'Amount KES', 'FX Rate', 'Reference', 'Narration'];

                foreach ($lines as $line) {
                    $rows[] = [
                        $line['account_name'] ?: $line['name'],
                        (string) $line['destination'],
                        (string) $line['bank_swift'],
                        (string) $line['bank_routing'],
                        (string) $line['bank_name'],
                        Partners::countryName((string) ($line['bank_country'] ?: $line['country'])),
                        (string) $line['bank_address'],
                        Partners::countryName((string) $line['country']),
                        trim((string) ($line['office_location'] ?: $line['city'])),
                        (string) $line['currency'],
                        $fx($line),
                        $kes($line),
                        rtrim(rtrim(number_format((float) $line['fx_rate'], 6, '.', ''), '0'), '.'),
                        $ref($line),
                        $narration,
                    ];
                }
                break;

            case 'wise':
            case 'paypal':
                // Both take a batch keyed on the recipient's address.
                $headers = ['Email', 'Recipient Name', 'Currency', 'Amount',
                            'Amount KES', 'FX Rate', 'Country', 'Reference', 'Narration'];

                foreach ($lines as $line) {
                    $rows[] = [
                        (string) $line['destination'],
                        $line['account_name'] ?: $line['name'],
                        (string) $line['currency'],
                        $fx($line),
                        $kes($line),
                        rtrim(rtrim(number_format((float) $line['fx_rate'], 6, '.', ''), '0'), '.'),
                        Partners::countryName((string) $line['country']),
                        $ref($line),
                        $narration,
                    ];
                }
                break;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * What the run withheld, per partner, for the tax return.
     *
     * KRA wants the partner identified and the deduction stated, so this
     * is the shape of a withholding certificate schedule rather than of
     * a payment file: who, what number they are known by, what they
     * earned and what was held back.
     *
     * Only live lines. A held or failed line sent nothing, so it withheld
     * nothing, and listing it would overstate the return.
     *
     * @return array{headers: list<string>, rows: list<list<string>>, total: float}
     */
    public static function withholdingParts(int $runId): array
    {
        $run = Database::first('SELECT * FROM payout_runs WHERE id = :id', ['id' => $runId]);

        if (!$run) {
            return ['headers' => [], 'rows' => [], 'total' => 0.0];
        }

        $lines = Database::all(
            "SELECT l.*, p.name, p.company, p.partner_code, p.kra_pin, p.tax_id,
                    p.country, p.tax_residency
               FROM payout_lines l
               JOIN partners p ON p.id = l.partner_id
              WHERE l.run_id = :r
                AND l.status IN ('pending', 'sent', 'settled')
                AND l.withheld > 0
           ORDER BY p.name",
            ['r' => $runId]
        );

        $rows  = [];
        $total = 0.0;

        foreach ($lines as $line) {
            $rows[] = [
                $line['partner_code'] ?: ('P' . $line['partner_id']),
                $line['company'] ?: $line['name'],
                (string) ($line['kra_pin'] ?: $line['tax_id']),
                Partners::countryName((string) $line['country']),
                $line['tax_residency'] === 'non_resident' ? 'Non-resident' : 'Resident',
                number_format((float) $line['amount'], 2, '.', ''),
                rtrim(rtrim(number_format((float) $line['withholding_rate'], 2, '.', ''), '0'), '.'),
                number_format((float) $line['withheld'], 2, '.', ''),
                number_format((float) $line['net_amount'], 2, '.', ''),
                (string) $run['period'],
            ];

            $total += (float) $line['withheld'];
        }

        return [
            'headers' => ['Reference', 'Payee', 'Tax PIN / ID', 'Country', 'Residency',
                          'Gross KES', 'Rate %', 'Withheld KES', 'Net KES', 'Period'],
            'rows'    => $rows,
            'total'   => round($total, 2),
        ];
    }

    /** The same file as one string, for anything that is not a download. */
    public static function csv(int $runId, string $kind): string
    {
        $parts = self::csvParts($runId, $kind);

        if (!$parts['headers']) {
            return '';
        }

        $out = fopen('php://temp', 'r+');

        foreach (array_merge([$parts['headers']], $parts['rows']) as $row) {
            fputcsv($out, $row);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * A Kenyan mobile number in the form a payment gateway wants.
     *
     * People give us 07…, +254…, 254… and 7… interchangeably, and a file
     * with four spellings of the same thing in it fails on whichever ones
     * the bank does not recognise.
     */
    public static function msisdn(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($digits, '254')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }

        // A bare 7xxxxxxxx or 1xxxxxxxx, which is how people write it once
        // they are already thinking in international terms.
        if (strlen($digits) === 9 && (str_starts_with($digits, '7') || str_starts_with($digits, '1'))) {
            return '254' . $digits;
        }

        return $digits;
    }
}
