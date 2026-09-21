<?php

namespace App\Services\BulkSms;

use App\Core\Database;

/**
 * What a reseller actually makes on SMS.
 *
 * This is deliberately kept apart from commissions, because the two are
 * different kinds of money and confusing them would cost somebody real
 * shillings.
 *
 * Commission is a debt: a partner's client pays us for printing, and we
 * then owe the partner a share. It belongs in the commissions ledger and
 * goes out in a payout run.
 *
 * SMS is a trade. A reseller buys units from us at their price and sells
 * them on at whatever price they set. Their client pays THEM, not us —
 * see SmsPortalBase, where M-Pesa is offered only when the house is the
 * seller and a reseller's client instead submits a request their reseller
 * confirms by hand. So the margin is already in the reseller's pocket the
 * moment their client pays. We owe them nothing for it.
 *
 * Writing that margin into the commissions table would therefore invent a
 * payable for money we never received, and a payout run would hand the
 * reseller a second copy of what they had already collected.
 *
 * What was actually missing is plainer: nobody was subtracting what the
 * units cost. The sales page called gross takings "earned", which is not
 * earnings, it is turnover. This works out the difference.
 *
 * Cost is the reseller's own weighted average buying price across every
 * top-up they have completed — the ordinary average-cost method. It is
 * used rather than today's price so that a change to their rate does not
 * silently rewrite what last month's trade looks like.
 */
final class ResellerEarnings
{
    /**
     * What one unit has cost this reseller on average, across everything
     * they have ever bought from us.
     *
     * Falls back to the price they would pay today when they have not
     * bought anything yet, so a reseller who was handed opening units
     * still sees a sensible figure rather than a margin of 100%.
     */
    public static function costPerUnit(array $account): float
    {
        $bought = Database::first(
            "SELECT COALESCE(SUM(units), 0) AS units, COALESCE(SUM(amount), 0) AS spent
               FROM bulk_purchases
              WHERE account_id = :a AND status = 'completed' AND channel = 'sms'",
            ['a' => $account['id']]
        );

        $units = (float) ($bought['units'] ?? 0);

        return $units > 0.0001
            ? round(((float) $bought['spent']) / $units, 4)
            : Accounts::unitPrice($account);
    }

    /**
     * Turnover, cost and margin on units sold to their own clients.
     *
     * @param string|null $since 'Y-m-d H:i:s', or null for everything
     * @return array{units:float, revenue:float, cost:float, margin:float,
     *               sales:int, cost_per_unit:float}
     */
    public static function summaryFor(array $account, ?string $since = null): array
    {
        $params = ['s' => $account['id']];
        $when   = '';

        if ($since !== null) {
            $when             = ' AND p.completed_at >= :since';
            $params['since']  = $since;
        }

        $sold = Database::first(
            "SELECT COALESCE(SUM(p.units), 0)  AS units,
                    COALESCE(SUM(p.amount), 0) AS revenue,
                    COUNT(*)                   AS sales
               FROM bulk_purchases p
              WHERE p.seller_account_id = :s
                AND p.status = 'completed'
                AND p.channel = 'sms'" . $when,
            $params
        );

        $units   = (float) ($sold['units'] ?? 0);
        $revenue = (float) ($sold['revenue'] ?? 0);
        $per     = self::costPerUnit($account);
        $cost    = round($units * $per, 2);

        return [
            'units'         => $units,
            'revenue'       => $revenue,
            'cost'          => $cost,
            'margin'        => round($revenue - $cost, 2),
            'sales'         => (int) ($sold['sales'] ?? 0),
            'cost_per_unit' => $per,
        ];
    }

    /**
     * The same figures month by month, newest first, for a reseller
     * looking at how the trade has gone.
     *
     * @return list<array{period:string, units:float, revenue:float,
     *                    cost:float, margin:float, sales:int}>
     */
    public static function byMonth(array $account, int $limit = 12): array
    {
        $rows = Database::all(
            "SELECT DATE_FORMAT(p.completed_at, '%Y-%m') AS period,
                    COALESCE(SUM(p.units), 0)  AS units,
                    COALESCE(SUM(p.amount), 0) AS revenue,
                    COUNT(*)                   AS sales
               FROM bulk_purchases p
              WHERE p.seller_account_id = :s
                AND p.status = 'completed'
                AND p.channel = 'sms'
                AND p.completed_at IS NOT NULL
              GROUP BY period
              ORDER BY period DESC
              LIMIT " . max(1, $limit),
            ['s' => $account['id']]
        );

        $per = self::costPerUnit($account);

        foreach ($rows as &$r) {
            $r['units']   = (float) $r['units'];
            $r['revenue'] = (float) $r['revenue'];
            $r['sales']   = (int) $r['sales'];
            $r['cost']    = round($r['units'] * $per, 2);
            $r['margin']  = round($r['revenue'] - $r['cost'], 2);
        }

        return $rows;
    }

    /**
     * What the house has taken from this reseller — our side of the same
     * trade, for the staff view. Their cost is our revenue.
     *
     * @return array{units:float, revenue:float, purchases:int}
     */
    public static function houseTakeFrom(int $accountId, ?string $since = null): array
    {
        $params = ['a' => $accountId];
        $when   = '';

        if ($since !== null) {
            $when            = ' AND completed_at >= :since';
            $params['since'] = $since;
        }

        $row = Database::first(
            "SELECT COALESCE(SUM(units), 0)  AS units,
                    COALESCE(SUM(amount), 0) AS revenue,
                    COUNT(*)                 AS purchases
               FROM bulk_purchases
              WHERE account_id = :a AND status = 'completed' AND channel = 'sms'" . $when,
            $params
        );

        return [
            'units'     => (float) ($row['units'] ?? 0),
            'revenue'   => (float) ($row['revenue'] ?? 0),
            'purchases' => (int) ($row['purchases'] ?? 0),
        ];
    }
}
