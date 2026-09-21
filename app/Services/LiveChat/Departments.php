<?php

namespace App\Services\LiveChat;

use App\Core\Database;
use App\Core\Settings;

/**
 * The queues a visitor can arrive in, and who answers them.
 *
 * A department is not a role. The same person may cover sales and
 * accounts, and two people covering sales is the normal case, so
 * membership is a table — live_department_staff — and that table is what
 * decides whose inbox a conversation lands in.
 *
 * One department is always the default. It catches anybody who does not
 * choose, anybody arriving while the picker is switched off, and any
 * conversation whose department is later deleted. Without one, a visitor
 * could ask a question that belongs to nobody, which is the one outcome
 * worse than a slow reply.
 */
final class Departments
{
    /**
     * The departments a visitor may choose from, in order.
     *
     * Inactive ones are left out: turning a department off should stop
     * new conversations arriving in it without deleting its history.
     *
     * @return list<array{id:int, name:string, slug:string, blurb:?string}>
     */
    public static function forVisitor(): array
    {
        $rows = Database::all(
            "SELECT id, name, slug, blurb
               FROM live_departments
              WHERE status = 'active'
              ORDER BY position, name"
        );

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }

        return $rows;
    }

    /** Everything, including the switched-off ones, for the office. */
    public static function all(): array
    {
        return Database::all(
            "SELECT d.*,
                    (SELECT COUNT(*) FROM live_department_staff s WHERE s.department_id = d.id) AS staff_count,
                    (SELECT COUNT(*) FROM live_conversations c
                      WHERE c.department_id = d.id AND c.status = 'waiting') AS waiting
               FROM live_departments d
              ORDER BY d.position, d.name"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM live_departments WHERE id = :id', ['id' => $id]);
    }

    /**
     * The department that catches everything else.
     *
     * Falls back to the first active one, and then to the first one at
     * all, rather than returning null: a conversation with no department
     * appears in nobody's inbox, so anything is better than nothing.
     */
    public static function default(): ?array
    {
        return Database::first(
            "SELECT * FROM live_departments
              WHERE is_default = 1 AND status = 'active'
              ORDER BY position LIMIT 1"
        ) ?? Database::first(
            "SELECT * FROM live_departments WHERE status = 'active' ORDER BY position LIMIT 1"
        ) ?? Database::first(
            'SELECT * FROM live_departments ORDER BY position LIMIT 1'
        );
    }

    /**
     * Work out where a conversation should go.
     *
     * Takes whatever the visitor asked for — which arrives over a public
     * endpoint and is therefore not to be trusted — and returns a real,
     * active department, or the default.
     */
    public static function route(int|string|null $wanted): ?array
    {
        if (!Settings::bool('livechat_ask_department', true)) {
            return self::default();
        }

        if ($wanted === null || $wanted === '' || $wanted === 0) {
            return self::default();
        }

        $row = ctype_digit((string) $wanted)
            ? Database::first(
                "SELECT * FROM live_departments WHERE id = :id AND status = 'active'",
                ['id' => (int) $wanted])
            : Database::first(
                "SELECT * FROM live_departments WHERE slug = :slug AND status = 'active'",
                ['slug' => (string) $wanted]);

        return $row ?? self::default();
    }

    /**
     * Make sure exactly one department is the default.
     *
     * Called after any change that could leave none or several. Two
     * defaults is not an error anybody would notice until a conversation
     * went to the wrong one, so it is settled here rather than trusted to
     * whoever last edited a form.
     */
    public static function settleDefault(?int $preferred = null): void
    {
        $keep = $preferred;

        if ($keep === null) {
            $keep = Database::scalar(
                "SELECT id FROM live_departments
                  WHERE is_default = 1 AND status = 'active'
                  ORDER BY position LIMIT 1"
            );
        }

        if ($keep === null) {
            $keep = Database::scalar(
                "SELECT id FROM live_departments WHERE status = 'active' ORDER BY position LIMIT 1"
            );
        }

        if ($keep === null) {
            return;   // nothing to be default
        }

        Database::run(
            'UPDATE live_departments SET is_default = IF(id = :keep, 1, 0)',
            ['keep' => (int) $keep]
        );
    }

    // -----------------------------------------------------------------
    // Who answers
    // -----------------------------------------------------------------

    /** @return list<array{user_id:int, name:string, is_lead:int}> */
    public static function staff(int $departmentId): array
    {
        return Database::all(
            "SELECT s.user_id, s.is_lead, u.name, u.email, u.is_active
               FROM live_department_staff s
               JOIN users u ON u.id = s.user_id
              WHERE s.department_id = :d
              ORDER BY s.is_lead DESC, u.name",
            ['d' => $departmentId]
        );
    }

    /** The department ids this member of staff answers for. */
    public static function forUser(int $userId): array
    {
        return array_map('intval', array_column(
            Database::all(
                'SELECT department_id FROM live_department_staff WHERE user_id = :u',
                ['u' => $userId]
            ),
            'department_id'
        ));
    }

    public static function addStaff(int $departmentId, int $userId, bool $lead = false): void
    {
        Database::run(
            'INSERT INTO live_department_staff (department_id, user_id, is_lead)
             VALUES (:d, :u, :l)
             ON DUPLICATE KEY UPDATE is_lead = :l2',
            ['d' => $departmentId, 'u' => $userId, 'l' => $lead ? 1 : 0, 'l2' => $lead ? 1 : 0]
        );
    }

    public static function removeStaff(int $departmentId, int $userId): void
    {
        Database::delete('live_department_staff', [
            'department_id' => $departmentId,
            'user_id'       => $userId,
        ]);
    }

    /**
     * The leads to tell when a conversation has been sitting unanswered.
     *
     * Everybody in the department when no lead has been named — better
     * that several people hear about it than nobody.
     *
     * @return list<array{id:int, name:string, email:string}>
     */
    public static function escalateTo(int $departmentId): array
    {
        $leads = Database::all(
            "SELECT u.id, u.name, u.email
               FROM live_department_staff s
               JOIN users u ON u.id = s.user_id
              WHERE s.department_id = :d AND s.is_lead = 1 AND u.is_active = 1",
            ['d' => $departmentId]
        );

        if ($leads) {
            return $leads;
        }

        return Database::all(
            "SELECT u.id, u.name, u.email
               FROM live_department_staff s
               JOIN users u ON u.id = s.user_id
              WHERE s.department_id = :d AND u.is_active = 1",
            ['d' => $departmentId]
        );
    }
}
