<?php
namespace App\Services\BulkSms;

use App\Core\Database;
use App\Core\Settings;
use RuntimeException;

/**
 * The SMS accounts: whose they are, where their units come from, and
 * what they pay.
 *
 * An account is opened the first time it is needed rather than for every
 * client up front. Most clients will never send a text, and an account
 * row for each of them would make "who uses SMS" a harder question.
 */
final class Accounts
{
    /** The house account — Shanfix's own, the one every unit is sold from. */
    public static function house(): array
    {
        static $house = null;

        if ($house !== null) {
            return $house;
        }

        $row = Database::first("SELECT * FROM bulk_accounts WHERE owner_type = 'house' LIMIT 1");

        if ($row === null) {
            // The migration creates it. Getting here means the migration
            // has not run, which is worth saying plainly rather than
            // failing somewhere later with a foreign key error.
            throw new RuntimeException('The SMS house account is missing. Run the database upgrade.');
        }

        return $house = $row;
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM bulk_accounts WHERE id = :id', ['id' => $id]);
    }

    /**
     * A client's account, opening it if $open.
     *
     * A client introduced by a partner buys from that partner: that is
     * what makes every partner a reseller. Everybody else buys from us.
     * The supplier is fixed when the account opens, not re-read from the
     * client on every purchase — a client whose partner link changes
     * later keeps their existing supplier until the office moves them,
     * because units and money already in flight belong to the old one.
     */
    public static function forClient(int $clientId, bool $open = true): ?array
    {
        $row = Database::first(
            "SELECT * FROM bulk_accounts WHERE owner_type = 'client' AND owner_id = :id",
            ['id' => $clientId]
        );

        if ($row !== null || !$open) {
            return $row;
        }

        $client = Database::first('SELECT id, partner_id FROM clients WHERE id = :id', ['id' => $clientId]);

        if ($client === null) {
            throw new RuntimeException('There is no client #' . $clientId . '.');
        }

        $parent = self::house();

        if (!empty($client['partner_id'])) {
            $partnerAccount = self::forPartner((int) $client['partner_id']);

            if ($partnerAccount !== null) {
                $parent = $partnerAccount;
            }
        }

        return self::open('client', $clientId, (int) $parent['id']);
    }

    /** A partner's account, opening it if $open. Partners buy from us. */
    public static function forPartner(int $partnerId, bool $open = true): ?array
    {
        $row = Database::first(
            "SELECT * FROM bulk_accounts WHERE owner_type = 'partner' AND owner_id = :id",
            ['id' => $partnerId]
        );

        if ($row !== null || !$open) {
            return $row;
        }

        $exists = Database::scalar('SELECT 1 FROM partners WHERE id = :id', ['id' => $partnerId]);

        if (!$exists) {
            throw new RuntimeException('There is no partner #' . $partnerId . '.');
        }

        return self::open('partner', $partnerId, (int) self::house()['id']);
    }

    /**
     * Open an account, tolerating a race with another request doing the
     * same. The unique key on (owner_type, owner_id) decides the winner;
     * the loser reads the winner's row.
     */
    private static function open(string $type, int $ownerId, int $parentId): array
    {
        try {
            Database::insert('bulk_accounts', [
                'owner_type' => $type,
                'owner_id'   => $ownerId,
                'parent_id'  => $parentId,
            ]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }

        return Database::first(
            'SELECT * FROM bulk_accounts WHERE owner_type = :t AND owner_id = :o',
            ['t' => $type, 'o' => $ownerId]
        ) ?? throw new RuntimeException('Could not open the SMS account.');
    }

    /**
     * What this account pays per unit for a custom amount.
     *
     * Its own negotiated price first; then whatever its supplier charges
     * by default — a partner's resale price, or the house price.
     */
    public static function unitPrice(array $account): float
    {
        if ($account['unit_price'] !== null && (float) $account['unit_price'] > 0) {
            return (float) $account['unit_price'];
        }

        $parent = $account['parent_id'] ? self::find((int) $account['parent_id']) : null;

        if ($parent !== null && $parent['owner_type'] === 'partner'
            && $parent['resale_unit_price'] !== null && (float) $parent['resale_unit_price'] > 0) {
            return (float) $parent['resale_unit_price'];
        }

        $price = (float) Settings::get('bulk_sms_unit_price', '1.00');

        return $price > 0 ? $price : 1.00;
    }

    /**
     * The name to show for an account, and where it lives in the system.
     *
     * @return array{name:string, kind:string, url:?string}
     */
    public static function describe(array $account): array
    {
        return match ($account['owner_type']) {
            'house' => ['name' => (string) Settings::get('company_name', 'Shanfix Technology'),
                        'kind' => 'House', 'url' => null],
            'client' => [
                'name' => (string) (Database::scalar('SELECT name FROM clients WHERE id = :id',
                    ['id' => $account['owner_id']]) ?? 'Client #' . $account['owner_id']),
                'kind' => 'Client',
                'url'  => '/clients/' . $account['owner_id'],
            ],
            'partner' => [
                'name' => (string) (Database::scalar(
                    "SELECT COALESCE(NULLIF(company, ''), name) FROM partners WHERE id = :id",
                    ['id' => $account['owner_id']]) ?? 'Partner #' . $account['owner_id']),
                'kind' => 'Partner',
                'url'  => '/partners-admin/' . $account['owner_id'],
            ],
            default => ['name' => 'Account #' . $account['id'], 'kind' => '', 'url' => null],
        };
    }

    /** SQL fragment naming an account's owner, for list queries. */
    public const OWNER_NAME_SQL = "CASE a.owner_type
            WHEN 'client'  THEN (SELECT c.name FROM clients c WHERE c.id = a.owner_id)
            WHEN 'partner' THEN (SELECT COALESCE(NULLIF(p.company, ''), p.name) FROM partners p WHERE p.id = a.owner_id)
            ELSE 'Shanfix (house)' END";

    // -----------------------------------------------------------------
    // The developer API
    // -----------------------------------------------------------------

    /**
     * Issue a fresh client id and key, replacing any before them.
     *
     * The same shape as the old platform's — SHX00042AB12 and sk_live_… —
     * so documentation and code customers already have still describe
     * what they will see. The key is returned here once and only its hash
     * is kept: it cannot be shown again, only replaced.
     *
     * @return array{client_id:string, api_key:string}
     */
    public static function issueApiKey(int $accountId): array
    {
        $account = self::find($accountId) ?? throw new RuntimeException('There is no such SMS account.');

        // Keep the client id if there is one: a customer rotating a leaked
        // key should only have to change the key in their code.
        $clientId = $account['api_client_id']
            ?: 'SHX' . str_pad((string) $accountId, 5, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(2)));
        $key = 'sk_live_' . bin2hex(random_bytes(16));

        Database::run(
            'UPDATE bulk_accounts
                SET api_client_id = :c, api_key_hash = :h, api_key_hint = :hint,
                    api_key_created_at = NOW()
              WHERE id = :id',
            ['c' => $clientId, 'h' => hash('sha256', $key), 'hint' => substr($key, -4), 'id' => $accountId]
        );

        return ['client_id' => $clientId, 'api_key' => $key];
    }

    public static function revokeApiKey(int $accountId): void
    {
        Database::run(
            'UPDATE bulk_accounts SET api_key_hash = NULL, api_key_hint = NULL, api_key_created_at = NULL WHERE id = :id',
            ['id' => $accountId]
        );
    }

    /**
     * The account a client id and key belong to, or null.
     *
     * Compared as hashes with hash_equals, so how long the comparison
     * takes says nothing about how much of the key was right.
     */
    public static function byApiKey(string $clientId, string $key): ?array
    {
        if ($clientId === '' || $key === '') {
            return null;
        }

        $account = Database::first(
            "SELECT * FROM bulk_accounts WHERE api_client_id = :c AND api_key_hash IS NOT NULL AND status = 'active'",
            ['c' => $clientId]
        );

        if ($account === null || !hash_equals((string) $account['api_key_hash'], hash('sha256', $key))) {
            return null;
        }

        return $account;
    }

    /**
     * Is $childId one of $parentId's own accounts?
     *
     * The question every partner screen asks before showing or touching a
     * client's account.
     */
    public static function isChildOf(int $childId, int $parentId): bool
    {
        return (bool) Database::scalar(
            'SELECT 1 FROM bulk_accounts WHERE id = :c AND parent_id = :p',
            ['c' => $childId, 'p' => $parentId]
        );
    }
}
