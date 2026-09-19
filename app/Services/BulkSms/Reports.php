<?php
namespace App\Services\BulkSms;

use App\Core\Database;

/**
 * The numbers behind every Bulk SMS screen.
 *
 * One place, so the office's view of a customer, the customer's own
 * view and their partner's view are the same query and cannot disagree.
 * Every function takes the accounts to look at — null for all of them,
 * which only the office ever passes.
 */
final class Reports
{
    /**
     * The carrier label for every message, in SQL.
     *
     * The receipt's own word when there is one. Otherwise worked out from
     * what we know — our status, and for a message refused at send time,
     * the reason Onfon gave — mirroring DlrStatus::fromEnum() and
     * fromFailureReason(). Without it every message that never got a
     * receipt would be one grey "Unknown" column.
     */
    public const LABEL_SQL = "COALESCE(NULLIF(m.dlr_status, ''), CASE m.status
            WHEN 'delivered'   THEN 'DELIVRD'
            WHEN 'sent'        THEN 'Submitted'
            WHEN 'queued'      THEN 'Submitted'
            WHEN 'undelivered' THEN 'DeliveryImpossible'
            WHEN 'failed' THEN CASE
                WHEN LOWER(m.failed_reason) REGEXP 'unregistered|invalid ?number|invalid ?mobile|absent|unrecognised|unrecognized' THEN 'AbsentSubscriber'
                WHEN LOWER(m.failed_reason) REGEXP 'sender ?id|sender not approved|blacklist' THEN 'Sendername blacklisted'
                WHEN LOWER(m.failed_reason) REGEXP 'expired|timed ?out' THEN 'Expired'
                WHEN LOWER(m.failed_reason) LIKE '%reject%' THEN 'REJECTD'
                ELSE 'DeliveryImpossible' END
            ELSE 'Unknown' END)";

    /**
     * Totals for a period.
     *
     * @param list<int>|null $accounts
     * @return array{messages:int, sent:int, delivered:int, failed:int, pending:int, units:float, rate:?float}
     */
    public static function totals(?array $accounts, string $from, string $to): array
    {
        [$where, $params] = self::scope($accounts, $from, $to);

        $row = Database::first(
            "SELECT COUNT(*) AS messages,
                    SUM(m.status IN ('sent','delivered','undelivered')) AS sent,
                    SUM(m.status = 'delivered') AS delivered,
                    SUM(m.status IN ('failed','undelivered')) AS failed,
                    SUM(m.status IN ('queued','sent')) AS pending,
                    COALESCE(SUM(m.units_charged), 0) AS units
               FROM bulk_messages m
              WHERE {$where}",
            $params
        ) ?? [];

        $sent = (int) ($row['sent'] ?? 0);

        return [
            'messages'  => (int) ($row['messages'] ?? 0),
            'sent'      => $sent,
            'delivered' => (int) ($row['delivered'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'pending'   => (int) ($row['pending'] ?? 0),
            'units'     => (float) ($row['units'] ?? 0),
            // Delivered out of those the gateway took. Null rather than 0%
            // when nothing was sent, which is a different fact.
            'rate'      => $sent > 0 ? round(100 * (int) $row['delivered'] / $sent, 1) : null,
        ];
    }

    /**
     * Messages per carrier status — the delivery report.
     *
     * Every canonical column comes back even at zero, in Onfon's order,
     * which is how their report is laid out and what lets ours be checked
     * against theirs. Anything new the carrier said is added after.
     *
     * @return array<string,int>
     */
    public static function byCarrierStatus(?array $accounts, string $from, string $to): array
    {
        [$where, $params] = self::scope($accounts, $from, $to);

        $rows = Database::all(
            'SELECT ' . self::LABEL_SQL . " AS label, COUNT(*) AS n
               FROM bulk_messages m
              WHERE {$where}
              GROUP BY label",
            $params
        );

        $out = array_fill_keys(DlrStatus::CANONICAL, 0);

        foreach ($rows as $r) {
            $out[(string) $r['label']] = ($out[(string) $r['label']] ?? 0) + (int) $r['n'];
        }

        return $out;
    }

    /**
     * One row per day: sent, delivered, failed. Days with nothing on them
     * are included, so a chart has no gaps pretending to be trends.
     *
     * @return list<array{day:string, sent:int, delivered:int, failed:int}>
     */
    public static function daily(?array $accounts, string $from, string $to): array
    {
        [$where, $params] = self::scope($accounts, $from, $to);

        $rows = Database::all(
            "SELECT DATE(m.created_at) AS day,
                    SUM(m.status IN ('sent','delivered','undelivered')) AS sent,
                    SUM(m.status = 'delivered') AS delivered,
                    SUM(m.status IN ('failed','undelivered')) AS failed
               FROM bulk_messages m
              WHERE {$where}
              GROUP BY DATE(m.created_at)",
            $params
        );

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['day']] = $r;
        }

        $out = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $out[] = [
                'day'       => $d,
                'sent'      => (int) ($byDay[$d]['sent'] ?? 0),
                'delivered' => (int) ($byDay[$d]['delivered'] ?? 0),
                'failed'    => (int) ($byDay[$d]['failed'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The message log, filtered and paged.
     *
     * @param array{q?:string, status?:string, label?:string, sender?:string, campaign?:int} $filters
     * @return array{rows:list<array>, total:int}
     */
    public static function messages(?array $accounts, string $from, string $to, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::scope($accounts, $from, $to);

        if (!empty($filters['q'])) {
            $where .= ' AND (m.recipient LIKE :q OR m.message LIKE :q2)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['status'])) {
            $where .= ' AND m.status = :st';
            $params['st'] = $filters['status'];
        }

        if (!empty($filters['label'])) {
            $where .= ' AND ' . self::LABEL_SQL . ' = :label';
            $params['label'] = $filters['label'];
        }

        if (!empty($filters['sender'])) {
            $where .= ' AND m.sender_id = :sender';
            $params['sender'] = $filters['sender'];
        }

        if (!empty($filters['campaign'])) {
            $where .= ' AND m.campaign_id = :camp';
            $params['camp'] = (int) $filters['campaign'];
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM bulk_messages m WHERE {$where}", $params);

        $rows = Database::all(
            'SELECT m.*, ' . self::LABEL_SQL . " AS label, c.name AS campaign_name
               FROM bulk_messages m
               LEFT JOIN bulk_campaigns c ON c.id = m.campaign_id
              WHERE {$where}
              ORDER BY m.id DESC
              LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Accounts plus their whole subtree — a partner and all its clients. */
    public static function withChildren(int $accountId): array
    {
        $ids = [$accountId];

        foreach (Database::all('SELECT id FROM bulk_accounts WHERE parent_id = :p', ['p' => $accountId]) as $r) {
            $ids[] = (int) $r['id'];
        }

        return $ids;
    }

    /**
     * A sane date range from a query string: defaults to the last 30
     * days, and never runs backwards.
     *
     * @return array{0:string, 1:string} Y-m-d
     */
    public static function range(?string $from, ?string $to, int $days = 30): array
    {
        $valid = static fn(?string $d): bool => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;

        $to   = $valid($to) ? $to : date('Y-m-d');
        $from = $valid($from) ? $from : date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days'));

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /** @return array{0:string, 1:array} */
    private static function scope(?array $accounts, string $from, string $to): array
    {
        $where  = 'm.created_at >= :from AND m.created_at < :to';
        $params = ['from' => $from . ' 00:00:00', 'to' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];

        if ($accounts !== null) {
            $accounts = array_values(array_unique(array_map('intval', $accounts)));

            if ($accounts === []) {
                return ['1 = 0', []];
            }

            $keys = [];
            foreach ($accounts as $i => $id) {
                $keys[] = ':a' . $i;
                $params['a' . $i] = $id;
            }

            $where .= ' AND m.account_id IN (' . implode(',', $keys) . ')';
        }

        return [$where, $params];
    }
}
