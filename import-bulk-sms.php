<?php
/**
 * Bring the old Bulk SMS platform's accounts into the system.
 *
 * The old platform (sms.shanfixtechnology.com, database bulk_sms_system)
 * has live customers with balances they paid for, API keys wired into
 * their own software, and passwords they know. All of that has to arrive
 * here intact, because the alternative is ringing every customer to
 * apologise.
 *
 *   php import-bulk-sms.php --from=bulk_sms_system            look, change nothing
 *   php import-bulk-sms.php --from=bulk_sms_system --commit   actually do it
 *
 * Other options:
 *   --host= --port= --user= --pass=   where the old database lives, when it
 *                                     is not the same server as this one
 *
 * It changes nothing unless --commit is given. Read the dry run first: it
 * prints every account it would create, every one it would attach to a
 * company already in the system, and every case it refuses to guess at.
 *
 * Running it twice is safe. Each old account is remembered by its old id
 * in bulk_accounts.legacy_user_id, so a second run finds its own work and
 * leaves it alone. That also means a half-finished run can be finished by
 * running it again.
 *
 * What it will NOT do, deliberately:
 *   - Move a client who already belongs to a partner here to a different
 *     partner. Partners are paid commission on their clients, so quietly
 *     reassigning one is a money question, not a data question. It reports
 *     the disagreement and leaves the client alone.
 *   - Invent a login it cannot honour. Old passwords are bcrypt and carry
 *     over untouched, but anything unreadable is reported and skipped
 *     rather than replaced with something the customer does not know.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("import-bulk-sms.php can only be run from the command line.\n");
}

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Numbering;
use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Wallet;

// ── Options ──────────────────────────────────────────────────────────────
$opts = getopt('', ['from:', 'host::', 'port::', 'user::', 'pass::', 'commit']);

if (!isset($opts['from'])) {
    fwrite(STDERR, "Which database holds the old platform? Use --from=bulk_sms_system\n");
    exit(1);
}

$commit = isset($opts['commit']);

try {
    Config::load(CONFIG_PATH . '/config.php');
    Database::connect(Config::get('db'));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not start: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

date_default_timezone_set(Config::get('app.timezone', 'Africa/Nairobi'));

// The old database is usually on the same server with the same credentials
// — but not always, so each part can be overridden.
$db = Config::get('db');

try {
    $old = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $opts['host'] ?? $db['host'],
            $opts['port'] ?? $db['port'],
            $opts['from']
        ),
        $opts['user'] ?? $db['username'],
        $opts['pass'] ?? $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not open the old database: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Guard against being pointed at the wrong database — this script creates
// clients and partners, which is not something to do on a hunch.
try {
    $old->query('SELECT id, role, sms_units FROM users LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "'{$opts['from']}' does not look like the old platform: no usable users table.\n");
    exit(1);
}

// ── Reporting ────────────────────────────────────────────────────────────
$plan   = [];   // every decision, printed at the end
$counts = ['create' => 0, 'attach' => 0, 'done' => 0, 'refuse' => 0];

/** Record one decision. $how is create|attach|done|refuse. */
function note(string $how, string $who, string $what): void
{
    global $plan, $counts;
    $plan[] = [$how, $who, $what];
    $counts[$how]++;
}

echo "\n";
echo $commit
    ? "APPLYING the migration from '{$opts['from']}'.\n"
    : "DRY RUN against '{$opts['from']}'. Nothing will be written. Add --commit to apply.\n";
echo str_repeat('─', 72) . "\n\n";

// ── The house account ────────────────────────────────────────────────────
// The old admin holds the units bought from Onfon. That is the house
// balance here; the admin itself does not become a login, because admin
// work happens in the staff system where those people already have one.
$house = Accounts::house();

$oldAdmin = $old->query(
    "SELECT * FROM users WHERE role = 'admin' ORDER BY sms_units DESC LIMIT 1"
)->fetch();

if ($oldAdmin) {
    $units = (float) $oldAdmin['sms_units'];
    $here  = (float) $house['sms_units'];

    if (abs($units - $here) < 0.0001) {
        note('done', 'House account', sprintf('already holds %s units', number_format($units, 2)));
    } elseif ($units > $here) {
        $add = $units - $here;
        note('create', 'House account', sprintf(
            'add %s units to reach the old balance of %s',
            number_format($add, 2),
            number_format($units, 2)
        ));

        if ($commit) {
            Wallet::credit($house['id'], $add, 'migration', [
                'note'       => 'Opening balance from the old platform',
                'actor_type' => 'system',
            ]);
        }
    } else {
        note('refuse', 'House account', sprintf(
            'holds %s here but only %s on the old platform — checked by hand, not adjusted',
            number_format($here, 2),
            number_format($units, 2)
        ));
    }
}

// ── Resellers become partners ────────────────────────────────────────────
$legacyToAccount = [];   // old user id => new bulk_accounts row id

$resellers = $old->query("SELECT * FROM users WHERE role = 'reseller' ORDER BY id")->fetchAll();

foreach ($resellers as $u) {
    $who = sprintf('Reseller %s <%s>', $u['name'], $u['email']);

    $existingAccount = Database::run(
        'SELECT * FROM bulk_accounts WHERE legacy_user_id = :id LIMIT 1',
        ['id' => $u['id']]
    )->fetch();

    if ($existingAccount) {
        $legacyToAccount[$u['id']] = (int) $existingAccount['id'];
        note('done', $who, 'already brought over');
        continue;
    }

    // A partner may already exist here — the email column is unique, so
    // this is the same person, not a lookalike.
    $partner = Database::run(
        'SELECT * FROM partners WHERE email = :email LIMIT 1',
        ['email' => $u['email']]
    )->fetch();

    if ($partner) {
        note('attach', $who, sprintf('is partner %s already in the system', $partner['partner_code']));
    } else {
        note('create', $who, sprintf(
            'new partner%s, holding %s units',
            $u['company'] ? ' for ' . $u['company'] : '',
            number_format((float) $u['sms_units'], 2)
        ));
    }

    if (!$commit) {
        continue;
    }

    if (!$partner) {
        $usable = usablePassword($u['password_hash']);

        Database::run(
            'INSERT INTO partners (partner_code, name, company, email, phone, password_hash,
                                   status, default_rate, created_at, updated_at)
             VALUES (:code, :name, :company, :email, :phone, :hash, :status, :rate, NOW(), NOW())',
            [
                'code'    => Numbering::next('partner'),
                'name'    => $u['name'],
                'company' => $u['company'] ?: $u['name'],
                'email'   => $u['email'],
                'phone'   => $u['phone'] ?: '',
                'hash'    => $usable ? $u['password_hash'] : '',
                'status'  => $u['status'] === 'suspended' ? 'suspended' : 'active',
                'rate'    => 0,
            ]
        );

        $partner = Database::run(
            'SELECT * FROM partners WHERE email = :email LIMIT 1',
            ['email' => $u['email']]
        )->fetch();
    }

    $account = Accounts::forPartner((int) $partner['id']);
    $legacyToAccount[$u['id']] = (int) $account['id'];

    carryOver($account, $u);
}

// ── Clients ──────────────────────────────────────────────────────────────
$clients = $old->query("SELECT * FROM users WHERE role = 'client' ORDER BY id")->fetchAll();

foreach ($clients as $u) {
    $who = sprintf('Client %s <%s>', $u['name'], $u['email']);

    $existingAccount = Database::run(
        'SELECT * FROM bulk_accounts WHERE legacy_user_id = :id LIMIT 1',
        ['id' => $u['id']]
    )->fetch();

    if ($existingAccount) {
        note('done', $who, 'already brought over');
        continue;
    }

    // Who supplies this account's units: their reseller, or the house.
    $parentAccountId = $u['parent_id'] && isset($legacyToAccount[$u['parent_id']])
        ? $legacyToAccount[$u['parent_id']]
        : (int) $house['id'];

    // Find the company they belong to here. The login email is the
    // strongest signal — it is unique and it is a person. Failing that,
    // the company's own address.
    $login = Database::run(
        'SELECT * FROM client_users WHERE email = :email LIMIT 1',
        ['email' => $u['email']]
    )->fetch();

    $client = null;

    if ($login) {
        $client = Database::run(
            'SELECT * FROM clients WHERE id = :id LIMIT 1',
            ['id' => $login['client_id']]
        )->fetch();
    } elseif ($u['email'] !== '') {
        $client = Database::run(
            'SELECT * FROM clients WHERE email = :email LIMIT 1',
            ['email' => $u['email']]
        )->fetch();
    }

    // A client already tied to a partner here must not be moved to the one
    // the old platform recorded. Commission follows that link.
    if ($client && $u['parent_id'] && isset($legacyToAccount[$u['parent_id']])) {
        $wanted = Database::run(
            'SELECT owner_id FROM bulk_accounts WHERE id = :id LIMIT 1',
            ['id' => $legacyToAccount[$u['parent_id']]]
        )->fetchColumn();

        if ($client['partner_id'] && (int) $client['partner_id'] !== (int) $wanted) {
            note('refuse', $who, sprintf(
                'belongs to partner #%d here but to a different one on the old platform — left alone, decide by hand',
                $client['partner_id']
            ));
            continue;
        }
    }

    if ($client) {
        note('attach', $who, sprintf(
            'is %s (%s) already in the system, holding %s units',
            $client['name'],
            $client['client_code'],
            number_format((float) $u['sms_units'], 2)
        ));
    } else {
        note('create', $who, sprintf(
            'new client%s, holding %s units',
            $u['company'] ? ' ' . $u['company'] : '',
            number_format((float) $u['sms_units'], 2)
        ));
    }

    if (!$commit) {
        continue;
    }

    if (!$client) {
        Database::run(
            'INSERT INTO clients (client_code, client_type, name, contact_person, email, phone,
                                  status, public_token, created_at, updated_at)
             VALUES (:code, :type, :name, :person, :email, :phone, :status, :token, NOW(), NOW())',
            [
                'code'   => Numbering::next('client'),
                'type'   => $u['company'] ? 'company' : 'individual',
                'name'   => $u['company'] ?: $u['name'],
                'person' => $u['name'],
                'email'  => $u['email'],
                'phone'  => $u['phone'] ?: '',
                'status' => $u['status'] === 'suspended' ? 'inactive' : 'active',
                'token'  => bin2hex(random_bytes(24)),
            ]
        );

        $client = Database::run(
            'SELECT * FROM clients ORDER BY id DESC LIMIT 1'
        )->fetch();
    }

    // Their portal login. Only created when the old password can actually
    // be honoured — a login nobody can use is worse than none, because it
    // blocks the address for a proper invitation later.
    if (!$login && usablePassword($u['password_hash'])) {
        Database::run(
            'INSERT INTO client_users (client_id, name, email, phone, password_hash,
                                       status, created_at, updated_at)
             VALUES (:client, :name, :email, :phone, :hash, :status, NOW(), NOW())',
            [
                'client' => $client['id'],
                'name'   => $u['name'],
                'email'  => $u['email'],
                'phone'  => $u['phone'] ?: '',
                'hash'   => $u['password_hash'],
                'status' => $u['status'] === 'suspended' ? 'suspended' : 'active',
            ]
        );
    } elseif (!$login) {
        note('refuse', $who, 'password cannot be read — no portal login made, they will need an invitation');
    }

    $account = Accounts::forClient((int) $client['id']);

    if ($parentAccountId !== (int) $account['parent_id']) {
        Database::run(
            'UPDATE bulk_accounts SET parent_id = :parent WHERE id = :id',
            ['parent' => $parentAccountId, 'id' => $account['id']]
        );
        $account['parent_id'] = $parentAccountId;
    }

    carryOver($account, $u);
}

// ── What we found ────────────────────────────────────────────────────────
$labels = [
    'create' => 'NEW   ',
    'attach' => 'ATTACH',
    'done'   => 'DONE  ',
    'refuse' => 'LEFT  ',
];

foreach ($plan as [$how, $who, $what]) {
    printf("  %s  %-44s %s\n", $labels[$how], $who, $what);
}

echo "\n" . str_repeat('─', 72) . "\n";
printf(
    "  %d to create, %d to attach to companies already here, %d already done, %d left alone\n",
    $counts['create'],
    $counts['attach'],
    $counts['done'],
    $counts['refuse']
);

if (!$commit) {
    echo "\n  Nothing was written. Run again with --commit to apply.\n";
}

echo "\n";

// ── Helpers ──────────────────────────────────────────────────────────────

/**
 * Can this stored hash still authenticate anybody?
 *
 * The old platform is mostly bcrypt, which password_verify() reads as-is,
 * so those customers keep the password they know. A few rows hold
 * something older that nothing here can check.
 */
function usablePassword(?string $hash): bool
{
    if ($hash === null || $hash === '') {
        return false;
    }

    return password_get_info($hash)['algo'] !== null
        && password_get_info($hash)['algoName'] !== 'unknown';
}

/**
 * Move the parts of an old account that are not the login: its balance,
 * its price, and the API credentials its owner has already wired into
 * their own software.
 */
function carryOver(array $account, array $u): void
{
    $fields = [];
    $params = ['id' => $account['id'], 'legacy' => $u['id']];

    if ($u['custom_unit_price'] !== null) {
        $fields[]             = 'unit_price = :price';
        $params['price']      = $u['custom_unit_price'];
    }

    if ($u['status'] === 'suspended') {
        $fields[] = "status = 'suspended'";
    }

    // The customer's integration sends this key. Only the hash is kept, so
    // the key itself stays as secret here as it was meant to be there.
    if (!empty($u['api_key']) && !empty($u['api_client_id'])) {
        $fields[]              = 'api_client_id = :client_id, api_key_hash = :hash, '
                               . 'api_key_hint = :hint, api_key_created_at = COALESCE(api_key_created_at, NOW())';
        $params['client_id']   = $u['api_client_id'];
        $params['hash']        = hash('sha256', $u['api_key']);
        $params['hint']        = substr($u['api_key'], -4);
    }

    $fields[] = 'legacy_user_id = :legacy';

    Database::run(
        'UPDATE bulk_accounts SET ' . implode(', ', $fields) . ' WHERE id = :id',
        $params
    );

    // The balance goes through the wallet so the ledger explains it. A
    // balance with no ledger row behind it is exactly the thing the
    // ledger exists to make impossible.
    $units = (float) $u['sms_units'];

    if ($units > 0) {
        Wallet::credit((int) $account['id'], $units, 'migration', [
            'note'       => 'Balance carried over from the old platform',
            'actor_type' => 'system',
        ]);
    }
}
