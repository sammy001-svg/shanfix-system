<?php
/**
 * Bring the old Bulk SMS platform across.
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
 *   --skip-history                    accounts and what they own, but none
 *                                     of the messages. Useful for a first
 *                                     pass that gets people working.
 *   --since=2025-01-01                only campaigns and messages from this
 *                                     date on. The rest can follow on a
 *                                     later run.
 *
 * It changes nothing unless --commit is given. Read the dry run first: it
 * prints every account it would create, every one it would attach to a
 * company already in the system, and every case it refuses to guess at.
 *
 * It moves the accounts and everything hanging off them: contact groups,
 * contacts, sender IDs with the decisions already made on them,
 * campaigns and the message history. A customer signing in afterwards
 * should find what they had.
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
$opts = getopt('', ['from:', 'host::', 'port::', 'user::', 'pass::', 'commit',
                    'skip-history', 'since::']);

if (!isset($opts['from'])) {
    fwrite(STDERR, "Which database holds the old platform? Use --from=bulk_sms_system\n");
    exit(1);
}

$commit      = isset($opts['commit']);
$skipHistory = isset($opts['skip-history']);

// Messages are committed in runs of this many. One transaction around a
// million rows is a lock nobody else can work around, and a crash at the
// end of it throws away the whole night's work.
const BATCH = 2000;

// Only what was sent on or after this date. Contacts and sender IDs always
// come across whole — those are what a customer owns. This is for the
// history, where five years of delivery reports can be more rows than the
// rest of the system put together.
$since = isset($opts['since']) ? trim((string) $opts['since']) : '';

if ($since !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $since)) {
    fwrite(STDERR, "--since wants a date like 2025-01-01\n");
    exit(1);
}

/**
 * The date filter, as SQL, or nothing at all.
 *
 * Built rather than bound because these go into queries against the old
 * database, which this script reads with its own PDO and no prepared
 * statements. The value is checked against a date pattern above, so there
 * is nothing left to inject with.
 */
$sinceClause = static function (string $column) use ($since): string {
    return $since === '' ? '' : " AND {$column} >= '{$since} 00:00:00'";
};

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

// Old user id => the bulk_accounts row it became. Built as the accounts are
// walked, and read by everything that hangs off an account.
$legacyToAccount = [];

// ── The house account ────────────────────────────────────────────────────
// The old admin holds the units bought from Onfon. That is the house
// balance here; the admin itself does not become a login, because admin
// work happens in the staff system where those people already have one.
$house = Accounts::house();

$oldAdmin = $old->query(
    "SELECT * FROM users WHERE role = 'admin' ORDER BY sms_units DESC LIMIT 1"
)->fetch();

if ($oldAdmin) {
    // The house sends campaigns of its own, so the admin's contacts and
    // its history belong to the house account rather than to nobody.
    $legacyToAccount[(int) $oldAdmin['id']] = (int) $house['id'];

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

    // A dry run creates nothing, so there is no account id to record.
    // The old id is still noted, against a placeholder, because the
    // count of what would be carried across is the main thing anybody
    // reads a dry run for — and without this it would report only the
    // house's own contacts and call that the whole migration.
    if (!$commit) {
        $legacyToAccount[$u['id']] = 0;
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
        // Recorded even though there is nothing to do for the account
        // itself. A second run is normally a run finishing what the
        // first one started, and what it has left to do is usually the
        // contacts and the history — which need this id to attach to.
        $legacyToAccount[$u['id']] = (int) $existingAccount['id'];
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

    // A dry run creates nothing, so there is no account id to record.
    // The old id is still noted, against a placeholder, because the
    // count of what would be carried across is the main thing anybody
    // reads a dry run for — and without this it would report only the
    // house's own contacts and call that the whole migration.
    if (!$commit) {
        $legacyToAccount[$u['id']] = 0;
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

    // Clients are most of the platform, and everything below hangs off
    // this. Without it the contacts, sender IDs and history of every
    // ordinary customer are silently left behind — only the resellers'
    // and the house's come across, which looks like a successful run.
    $legacyToAccount[$u['id']] = (int) $account['id'];

    if ($parentAccountId !== (int) $account['parent_id']) {
        Database::run(
            'UPDATE bulk_accounts SET parent_id = :parent WHERE id = :id',
            ['parent' => $parentAccountId, 'id' => $account['id']]
        );
        $account['parent_id'] = $parentAccountId;
    }

    carryOver($account, $u);
}

// ── What each customer owns ──────────────────────────────────────────────
//
// The accounts above are the people. This is everything they built up on
// the old platform and would notice the loss of within a day: the
// contacts they spent years collecting, the groups those sit in, the
// sender IDs registered under their name with the networks, and the
// history of what they have sent.
//
// Ordered by dependency — groups before contacts, campaigns before the
// messages that belong to them — because each pass needs the ids the one
// before it produced.
//
// Every row remembers where it came from in legacy_id, which is UNIQUE
// (migration 051). That is what makes this safe to stop and start again:
// a second insert of the same old row is refused by the database, not by
// this script remembering. A cutover is exactly when a run gets
// interrupted.

$carried = [];   // what was brought across, per table

function carriedOne(string $what): void
{
    global $carried;
    $carried[$what] = ($carried[$what] ?? 0) + 1;
}

/** The accounts we know about, as "old id => new id", for an IN clause. */
$mappedOldIds = array_keys($legacyToAccount);

if ($mappedOldIds === []) {
    note('refuse', 'belongings', 'no accounts were mapped, so there is nothing to attach them to');
} else {
    $inOldIds = implode(',', array_map('intval', $mappedOldIds));

    // ── Groups ───────────────────────────────────────────────────────────
    $groupMap = [];   // old group id => new group id

    foreach (Database::all(
        'SELECT id, legacy_id FROM bulk_contact_groups WHERE legacy_id IS NOT NULL'
    ) as $row) {
        $groupMap[(int) $row['legacy_id']] = (int) $row['id'];
    }

    foreach ($old->query(
        "SELECT * FROM contact_groups WHERE user_id IN ({$inOldIds}) ORDER BY id"
    ) as $g) {
        if (isset($groupMap[(int) $g['id']])) {
            continue;                                   // a previous run did this one
        }

        if (!$commit) {
            carriedOne('groups');
            continue;
        }

        $groupMap[(int) $g['id']] = Database::insert('bulk_contact_groups', [
            'account_id'  => $legacyToAccount[(int) $g['user_id']],
            'name'        => mb_substr((string) $g['name'], 0, 120),
            'description' => $g['description'] !== null ? mb_substr((string) $g['description'], 0, 255) : null,
            'legacy_id'   => (int) $g['id'],
            'created_at'  => $g['created_at'],
        ]);

        carriedOne('groups');
    }

    // ── Contacts ─────────────────────────────────────────────────────────
    //
    // Read and written in batches. A customer with a few years of
    // marketing behind them has tens of thousands of these, and holding
    // the lot in memory to insert one at a time is how an import dies on
    // a shared host.
    $seenContacts = [];

    foreach (Database::all(
        'SELECT legacy_id FROM bulk_contacts WHERE legacy_id IS NOT NULL'
    ) as $row) {
        $seenContacts[(int) $row['legacy_id']] = true;
    }

    $rows = $old->query("SELECT * FROM contacts WHERE user_id IN ({$inOldIds}) ORDER BY id");

    foreach ($rows as $c) {
        if (isset($seenContacts[(int) $c['id']])) {
            continue;
        }

        if (!$commit) {
            carriedOne('contacts');
            continue;
        }

        // A group that was deleted on the old platform leaves its
        // contacts pointing at nothing. They are still the customer's
        // contacts, so they come across ungrouped rather than dropped.
        $group = $c['group_id'] !== null ? ($groupMap[(int) $c['group_id']] ?? null) : null;

        Database::insert('bulk_contacts', [
            'account_id' => $legacyToAccount[(int) $c['user_id']],
            'group_id'   => $group,
            'name'       => $c['name'] !== null ? mb_substr((string) $c['name'], 0, 120) : null,
            'phone'      => mb_substr((string) $c['phone'], 0, 20),
            'email'      => $c['email'] !== null ? mb_substr((string) $c['email'], 0, 120) : null,
            'metadata'   => $c['metadata'],
            'legacy_id'  => (int) $c['id'],
            'created_at' => $c['created_at'],
        ]);

        carriedOne('contacts');
    }

    // ── Sender IDs ───────────────────────────────────────────────────────
    //
    // These matter more than their number suggests. A sender ID is
    // registered with the networks under the customer's own name and
    // takes days to approve, so one arriving here as "pending" would
    // take a working sender away from somebody who already has one. The
    // decision comes across with it.
    $seenSenders = [];

    foreach (Database::all(
        'SELECT legacy_id FROM bulk_sender_ids WHERE legacy_id IS NOT NULL'
    ) as $row) {
        $seenSenders[(int) $row['legacy_id']] = true;
    }

    foreach ($old->query(
        "SELECT * FROM sender_ids WHERE user_id IN ({$inOldIds}) ORDER BY id"
    ) as $s) {
        if (isset($seenSenders[(int) $s['id']])) {
            continue;
        }

        if (!$commit) {
            carriedOne('sender IDs');
            continue;
        }

        Database::insert('bulk_sender_ids', [
            'account_id'    => $legacyToAccount[(int) $s['user_id']],
            'sender_id'     => mb_substr((string) $s['sender_id'], 0, 20),
            'purpose'       => $s['purpose'],
            'status'        => in_array($s['status'], ['pending', 'approved', 'rejected'], true)
                ? $s['status'] : 'pending',
            'source'        => 'customer',
            'reject_reason' => $s['reject_reason'] !== null
                ? mb_substr((string) $s['reject_reason'], 0, 255) : null,
            // When it was decided carries over; who decided does not.
            // decided_by points at a member of staff here, and the old
            // approver is an id in a table that is not this one — writing
            // it would name whichever colleague happens to hold that id.
            'decided_by'    => null,
            'decided_at'    => $s['approved_at'],
            'legacy_id'     => (int) $s['id'],
            'created_at'    => $s['created_at'],
        ]);

        carriedOne('sender IDs');
    }

    // ── Campaigns ────────────────────────────────────────────────────────
    $campaignMap = [];   // old campaign id => new campaign id

    foreach (Database::all(
        'SELECT id, legacy_id FROM bulk_campaigns WHERE legacy_id IS NOT NULL'
    ) as $row) {
        $campaignMap[(int) $row['legacy_id']] = (int) $row['id'];
    }

    foreach ($old->query(
        "SELECT * FROM campaigns WHERE user_id IN ({$inOldIds})" . $sinceClause('created_at') . ' ORDER BY id'
    ) as $c) {
        if (isset($campaignMap[(int) $c['id']])) {
            continue;
        }

        if (!$commit) {
            carriedOne('campaigns');
            continue;
        }

        // A campaign that was mid-flight when the old platform stopped
        // arrives as failed rather than as running. Nothing here is going
        // to pick it up and finish it, and leaving it looking live would
        // have somebody waiting for messages that will never go.
        $status = match ($c['status']) {
            'running', 'sending' => 'failed',
            'queued'             => 'failed',
            'draft', 'scheduled', 'completed', 'failed' => $c['status'],
            default              => 'failed',
        };

        $campaignMap[(int) $c['id']] = Database::insert('bulk_campaigns', [
            'account_id'     => $legacyToAccount[(int) $c['user_id']],
            'name'           => mb_substr((string) $c['name'], 0, 180),
            'sender_id'      => mb_substr((string) $c['sender_id'], 0, 20),
            'message'        => $c['message'],
            'group_id'       => $c['group_id'] !== null ? ($groupMap[(int) $c['group_id']] ?? null) : null,
            'recipients'     => $c['recipients'],
            // Deliberately not carried: it names a file in the old
            // platform's uploads directory, which is not here. The
            // campaign's own counts say what it did.
            'file_path'      => null,
            'total_count'    => (int) $c['total_count'],
            'sent_count'     => (int) $c['sent_count'],
            'failed_count'   => (int) $c['failed_count'],
            'units_used'     => $c['units_used'],
            'status'         => $status,
            'failure_reason' => in_array($c['status'], ['running', 'sending', 'queued'], true)
                ? 'Was still sending when the old platform was retired' : null,
            'scheduled_at'   => $c['scheduled_at'],
            'sent_at'        => $c['sent_at'],
            'source'         => 'portal',
            'actor_type'     => 'system',
            'legacy_id'      => (int) $c['id'],
            'created_at'     => $c['created_at'],
        ]);

        carriedOne('campaigns');
    }

    // ── Messages ─────────────────────────────────────────────────────────
    //
    // The big one: millions of rows on a busy platform. Streamed rather
    // than fetched into memory, and committed in batches, so a run that
    // is interrupted has kept everything up to its last batch.
    //
    // Skipping what is already here is left to the UNIQUE legacy_id and
    // INSERT IGNORE, rather than to anything this script remembers. Two
    // tidier-looking approaches are both wrong at this size: holding
    // every imported id in memory is tens of megabytes on a shared
    // host, and resuming from MAX(legacy_id) quietly loses rows —
    // messages are read in id order across all accounts at once, so a
    // second run that picks up a customer the first one had not created
    // yet would start above that customer's oldest messages and never
    // come back for them. That mistake reports success.
    if ($skipHistory) {
        note('refuse', 'history', 'skipped: --skip-history was given');
    } else {
        $done = 0;

        $rows = $old->query(
            "SELECT * FROM messages WHERE user_id IN ({$inOldIds})"
            . $sinceClause('created_at') . ' ORDER BY id'
        );

        if ($commit) {
            Database::begin();
        }

        foreach ($rows as $m) {
            if (!$commit) {
                carriedOne('messages');
                continue;
            }

            $kept = insertIgnore('bulk_messages', [
                'campaign_id'    => $m['campaign_id'] !== null
                    ? ($campaignMap[(int) $m['campaign_id']] ?? null) : null,
                'account_id'     => $legacyToAccount[(int) $m['user_id']],
                'sender_id'      => mb_substr((string) $m['sender_id'], 0, 20),
                'recipient'      => mb_substr((string) $m['recipient'], 0, 20),
                'message'        => $m['message'],
                'units_charged'  => $m['units_charged'],
                'status'         => in_array($m['status'],
                    ['queued', 'sent', 'delivered', 'failed', 'undelivered'], true)
                    ? $m['status'] : 'failed',
                'gateway_msg_id' => $m['gateway_msg_id'],
                'sent_at'        => $m['sent_at'],
                'delivered_at'   => $m['delivered_at'],
                'failed_reason'  => $m['failed_reason'] !== null
                    ? mb_substr((string) $m['failed_reason'], 0, 500) : null,
                'dlr_status'     => $m['dlr_status'],
                'source'         => 'portal',
                'legacy_id'      => (int) $m['id'],
                'created_at'     => $m['created_at'],
            ]);

            if (!$kept) {
                continue;            // a previous run already has this one
            }

            carriedOne('messages');
            $done++;

            // Committed in batches. One transaction around a million rows
            // is a lock nobody else can work around, and a crash at the
            // end of it throws away the whole night's work.
            if ($done % BATCH === 0) {
                Database::commit();
                Database::begin();
                echo "  … {$done} messages\n";
            }
        }

        if ($commit) {
            Database::commit();
        }
    }
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

if ($carried !== []) {
    // On a commit these are what was written. On a dry run they are what
    // was found on the old platform in range, which is the same number on
    // a first migration and an over-count on a repeat — the history pass
    // leaves "have I got this one already?" to the database, so nothing
    // here knows the answer until it tries.
    echo "\n  " . ($commit ? 'Carried across' : 'Found to carry across') . ":\n";

    foreach ($carried as $what => $n) {
        printf("    %-12s %s\n", $what, number_format($n));
    }

    if (!$commit) {
        echo "    (anything a previous run already brought over is counted here too)\n";
    }
}

if (!$commit) {
    echo "\n  Nothing was written. Run again with --commit to apply.\n";
}

echo "\n";

// ── Helpers ──────────────────────────────────────────────────────────────

/**
 * Insert a row, or do nothing if it is already here.
 *
 * Used for the message history, where "already here" is decided by the
 * UNIQUE legacy_id rather than by a lookup first. One statement instead
 * of two, and correct however many times the import is restarted.
 *
 * @return bool whether this call is what put the row there
 */
function insertIgnore(string $table, array $data): bool
{
    $columns = array_keys($data);
    $holders = array_map(static fn(string $c): string => ':' . $c, $columns);

    $sql = sprintf(
        'INSERT IGNORE INTO `%s` (`%s`) VALUES (%s)',
        $table,
        implode('`, `', $columns),
        implode(', ', $holders)
    );

    return Database::run($sql, $data)->rowCount() > 0;
}

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
