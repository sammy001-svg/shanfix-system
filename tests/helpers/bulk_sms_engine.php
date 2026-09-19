<?php
/**
 * Bulk SMS engine, proved against a fake gateway.
 *
 * Runs in-process against the test database, with Onfon pointed at
 * tests/helpers/fake_onfon.php. Prints one line per check and a final
 * "passed N failed M" line the shell suite reads.
 *
 * What it defends, in order of how much it would cost to get wrong:
 *
 *   - units are never created or lost: every balance equals the sum of
 *     its ledger, and every unit an account holds came out of another
 *   - a refused message is refunded and costs 0
 *   - an account cannot spend what it does not hold, even racing itself
 *   - a campaign that dies resumes where it stopped, without resending
 *   - a purchase confirmed twice credits once
 *
 * Usage:  php tests/helpers/bulk_sms_engine.php <fake-gateway-url>
 *
 * The test database holds a real SMS key and a live gateway address.
 * Every setting this touches is put back in a finally block, and every
 * notification it causes is deleted before it can be sent.
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Engine;
use App\Services\BulkSms\Purchases;
use App\Services\BulkSms\Wallet;
use App\Services\BulkSms\Worker;

define('BULK_SMS_NO_SPAWN', true);

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$dbName = (string) Database::scalar('SELECT DATABASE()');

if (!str_contains($dbName, 'test')) {
    echo "refusing: {$dbName} is not a test database\npassed 0 failed 1\n";
    exit(1);
}

$fake = rtrim($argv[1] ?? 'http://127.0.0.1:8098', '/');

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : '  — ' . $detail);
}

function setting(string $key, ?string $value): void
{
    if ($value === null) {
        Database::run('DELETE FROM settings WHERE setting_key = :k', ['k' => $key]);
    } else {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['k' => $key, 'v' => $value]
        );
    }
}

/** Clear Settings' per-request cache after changing the table under it. */
function fresh(): void
{
    $r = new ReflectionProperty(Settings::class, 'cache');
    $r->setValue(null, null);
}

/** Every balance equals the sum of its ledger. */
function ledgerBalances(int $accountId): bool
{
    $row = Database::first(
        'SELECT a.sms_units AS balance, COALESCE(SUM(l.amount), 0) AS ledger
           FROM bulk_accounts a
           LEFT JOIN bulk_ledger l ON l.account_id = a.id AND l.channel = \'sms\'
          WHERE a.id = :id GROUP BY a.id',
        ['id' => $accountId]
    );

    return $row !== null && abs((float) $row['balance'] - (float) $row['ledger']) < 0.0001;
}

$touched = ['onfon_base_url', 'onfon_client_id', 'onfon_api_key', 'onfon_access_key',
            'onfon_batch_delay_ms', 'notify_bulk_topup_sms', 'notify_bulk_low_balance_sms',
            'notify_bulk_campaign_done_sms', 'bulk_sms_low_balance', 'bulk_sms_unit_price'];

$saved = [];
foreach ($touched as $k) {
    $saved[$k] = Database::scalar('SELECT setting_value FROM settings WHERE setting_key = :k', ['k' => $k]);
}

$log = sys_get_temp_dir() . '/fake_onfon.log';
@unlink($log);
@unlink(sys_get_temp_dir() . '/fake_onfon_503.txt');

$made = ['clients' => [], 'partners' => []];

try {
    setting('onfon_base_url', $fake);
    setting('onfon_client_id', 'test-client');
    setting('onfon_api_key', 'test-key');
    setting('onfon_access_key', 'test-access');
    setting('onfon_batch_delay_ms', '0');
    setting('bulk_sms_unit_price', '0.80');
    // No texts about texts while testing — the notification queue would
    // otherwise hand them to the real SMS account.
    setting('notify_bulk_topup_sms', '0');
    setting('notify_bulk_low_balance_sms', '0');
    setting('notify_bulk_campaign_done_sms', '0');
    fresh();

    // --- The cast -----------------------------------------------------
    $tag = 'BULKTEST ' . bin2hex(random_bytes(3));

    $partnerId = Database::insert('partners', [
        'partner_code' => 'BT' . strtoupper(bin2hex(random_bytes(3))),
        'first_name' => 'Bulk', 'last_name' => 'Reseller', 'name' => $tag . ' Reseller',
        'email' => strtolower(str_replace(' ', '', $tag)) . '@example.test',
        'phone' => '254700000010', 'status' => 'active',
    ]);
    $made['partners'][] = $partnerId;

    $direct = Database::insert('clients', [
        'client_code' => 'BT-' . strtoupper(bin2hex(random_bytes(3))),
        'client_type' => 'company', 'name' => $tag . ' Direct', 'email' => 'direct@example.test',
        'phone' => '254700000011', 'status' => 'active',
    ]);
    $viaPartner = Database::insert('clients', [
        'client_code' => 'BT-' . strtoupper(bin2hex(random_bytes(3))),
        'client_type' => 'company', 'name' => $tag . ' Partner Client', 'email' => 'viap@example.test',
        'phone' => '254700000012', 'status' => 'active', 'partner_id' => $partnerId,
    ]);
    $made['clients'] = [$direct, $viaPartner];

    $house = Accounts::house();
    $houseStart = (float) $house['sms_units'];

    echo "\n-- Accounts open where their units come from\n";
    $pa = Accounts::forPartner($partnerId);
    $da = Accounts::forClient($direct);
    $va = Accounts::forClient($viaPartner);

    check('a partner buys from the house', (int) $pa['parent_id'] === (int) $house['id']);
    check('a direct client buys from the house', (int) $da['parent_id'] === (int) $house['id']);
    check("a partner's client buys from the partner", (int) $va['parent_id'] === (int) $pa['id']);
    check('opening again returns the same account', (int) Accounts::forClient($direct)['id'] === (int) $da['id']);

    echo "\n-- The house is topped up from the gateway, and sells\n";
    file_put_contents(sys_get_temp_dir() . '/fake_onfon_balance.txt', (string) ($houseStart + 10000));
    $synced = Wallet::syncHouse((float) \App\Services\BulkSms\Onfon::balance());
    check('syncing sets the house to the gateway balance', abs($synced - ($houseStart + 10000)) < 0.001, (string) $synced);
    check('and writes the difference to the ledger',
        (float) Database::scalar("SELECT amount FROM bulk_ledger WHERE account_id = :a ORDER BY id DESC LIMIT 1", ['a' => $house['id']]) === 10000.0);

    $buy = Purchases::create((int) $pa['id'], ['units' => 2000, 'method' => 'bank', 'transaction_ref' => 'bank1']);
    check('a partner purchase is recorded pending', $buy['ok'] && Database::scalar('SELECT status FROM bulk_purchases WHERE id = :id', ['id' => $buy['id']]) === 'pending');
    check('priced at the house unit price', abs($buy['amount'] - 1600.00) < 0.001, (string) $buy['amount']);

    $done  = Purchases::complete($buy['id'], ['type' => 'staff', 'id' => null]);
    $again = Purchases::complete($buy['id'], ['type' => 'staff', 'id' => null]);
    check('completing hands the units over', $done['ok'] && abs(Wallet::balance((int) $pa['id']) - 2000) < 0.001);
    check('completing twice credits once', !empty($again['already']) && abs(Wallet::balance((int) $pa['id']) - 2000) < 0.001);
    check('and the house gave exactly what the partner got',
        abs(Wallet::balance((int) $house['id']) - ($houseStart + 10000 - 2000)) < 0.001);

    echo "\n-- A partner sells on to their own client, at their own price\n";
    Database::run('UPDATE bulk_accounts SET resale_unit_price = 1.50 WHERE id = :id', ['id' => $pa['id']]);
    $va = Accounts::find((int) $va['id']);
    check("the partner's client pays the partner's price", abs(Accounts::unitPrice($va) - 1.50) < 0.001);

    $tooMuch = Purchases::create((int) $va['id'], ['units' => 5000, 'method' => 'manual_mpesa']);
    check('refused when the partner cannot supply that many', !$tooMuch['ok']);

    $sale = Purchases::create((int) $va['id'], ['units' => 300, 'method' => 'manual_mpesa', 'transaction_ref' => 'qwe123']);
    check('priced at 1.50 a unit', abs($sale['amount'] - 450.0) < 0.001, (string) $sale['amount']);
    Purchases::complete($sale['id'], ['type' => 'partner', 'id' => $partnerId]);
    check('the units come out of the partner', abs(Wallet::balance((int) $pa['id']) - 1700) < 0.001);
    check('and into their client', abs(Wallet::balance((int) $va['id']) - 300) < 0.001);

    $direct500 = Purchases::create((int) $da['id'], ['units' => 1000, 'method' => 'complimentary']);
    Purchases::complete($direct500['id']);
    check('a complimentary top-up costs nothing', (float) Database::scalar('SELECT amount FROM bulk_purchases WHERE id = :id', ['id' => $direct500['id']]) === 0.0);

    echo "\n-- Nobody sends from a sender ID that is not approved\n";
    $r = Engine::sendNow((int) $da['id'], ['0700000021'], 'Hello', 'NOTMINE');
    check('an unregistered sender is refused', !$r['ok'] && str_contains((string) $r['error'], 'not approved'));

    Database::insert('bulk_sender_ids', ['account_id' => $da['id'], 'sender_id' => 'SHANFIX', 'status' => 'approved']);
    $r = Engine::sendNow((int) $da['id'], ['0700000021'], 'Hello', 'shanfix');
    check('the sender ID is matched exactly, case and all', !$r['ok']);

    echo "\n-- Sending charges, refunds, and records\n";
    $before = Wallet::balance((int) $da['id']);
    $r = Engine::sendNow((int) $da['id'], ['0700000021', '0700000099', 'not-a-number', '254700000021'], 'Hello there', 'SHANFIX');
    check('good numbers go', $r['ok'] && $r['sent'] === 1, json_encode($r));
    check('a refused number fails', $r['failed'] === 1);
    check('an invalid number is reported, not sent', $r['invalid'] === ['not-a-number']);
    check('a repeated number is sent once', $r['submitted'] === 2);
    check('only what went is paid for', abs($before - Wallet::balance((int) $da['id']) - 1) < 0.001,
        (string) ($before - Wallet::balance((int) $da['id'])));
    check('the refused message cost nothing',
        (float) Database::scalar("SELECT units_charged FROM bulk_messages WHERE account_id = :a AND recipient = '+254700000099' ORDER BY id DESC LIMIT 1", ['a' => $da['id']]) === 0.0);
    check('the sent one carries the gateway id',
        str_starts_with((string) Database::scalar("SELECT gateway_msg_id FROM bulk_messages WHERE account_id = :a AND recipient = '+254700000021' ORDER BY id DESC LIMIT 1", ['a' => $da['id']]), 'fake-'));

    $long = str_repeat('a', 161);
    $emoji = 'Karibu 😊';
    check('161 characters is two parts', Engine::measure($long)['parts'] === 2);
    check('an emoji makes it Unicode', Engine::measure($emoji)['unicode'] === true);
    check('71 Unicode characters is two parts', Engine::measure(str_repeat('é', 1) . str_repeat('😊', 70))['parts'] === 2);

    $before = Wallet::balance((int) $da['id']);
    Engine::sendNow((int) $da['id'], ['0700000022'], $long, 'SHANFIX');
    check('a two-part message costs two units', abs($before - Wallet::balance((int) $da['id']) - 2) < 0.001);

    echo "\n-- The gateway stumbling is retried, not refunded\n";
    $before = Wallet::balance((int) $da['id']);
    $r = Engine::sendNow((int) $da['id'], ['0700000055'], 'Retry me', 'SHANFIX');
    check('a 503 twice then success is sent', $r['ok'] && $r['sent'] === 1, json_encode($r['results'] ?? []));
    check('and charged once', abs($before - Wallet::balance((int) $da['id']) - 1) < 0.001);

    echo "\n-- A whole-call refusal refunds everything\n";
    Database::insert('bulk_sender_ids', ['account_id' => $da['id'], 'sender_id' => 'BADSENDER', 'status' => 'approved']);
    $before = Wallet::balance((int) $da['id']);
    $r = Engine::sendNow((int) $da['id'], ['0700000023', '0700000024'], 'Nope', 'BADSENDER');
    check('nothing is sent', !$r['ok'] && $r['sent'] === 0);
    check('and nothing is charged', abs($before - Wallet::balance((int) $da['id'])) < 0.001);
    check('with Onfon\'s reason', str_contains((string) $r['error'], 'Invalid Sender'), (string) $r['error']);

    echo "\n-- Nobody spends what they do not hold\n";
    $poor = Accounts::forClient($viaPartner);
    Database::insert('bulk_sender_ids', ['account_id' => $poor['id'], 'sender_id' => 'POOR', 'status' => 'approved']);
    $r = Engine::sendNow((int) $poor['id'], array_map(static fn($i) => '07000001' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(0, 50)), str_repeat('x', 900), 'POOR');
    check('a send bigger than the balance is refused whole', !$r['ok'] && str_contains((string) $r['error'], 'Not enough'));
    check('without touching the balance', abs(Wallet::balance((int) $poor['id']) - 300) < 0.001);
    check('a debit larger than the balance takes nothing', Wallet::debit((int) $poor['id'], 301, 'send') === null
        && abs(Wallet::balance((int) $poor['id']) - 300) < 0.001);

    echo "\n-- Campaigns: queued, sent in batches, resumed, cancelled\n";
    $groupId = Database::insert('bulk_contact_groups', ['account_id' => $da['id'], 'name' => $tag . ' group']);
    for ($i = 0; $i < 250; $i++) {
        Database::insert('bulk_contacts', [
            'account_id' => $da['id'], 'group_id' => $groupId,
            'name' => 'Person ' . $i, // Every number ends in 1, clear of the fake gateway's 99 and 55 rules.
            'phone' => '0711' . str_pad((string) ($i * 10 + 1), 6, '0', STR_PAD_LEFT),
            'metadata' => json_encode(['balance' => 'KES ' . $i]),
        ]);
    }

    $q = Engine::queueCampaign((int) $da['id'], ['name' => 'Group test', 'sender_id' => 'SHANFIX',
        'message' => 'Hi {name}, you owe {balance}', 'group_id' => $groupId]);
    check('a campaign is queued', $q['ok'] && Database::scalar('SELECT status FROM bulk_campaigns WHERE id = :id', ['id' => $q['id']]) === 'queued');
    check('with the group counted up front', (int) Database::scalar('SELECT total_count FROM bulk_campaigns WHERE id = :id', ['id' => $q['id']]) === 250);

    // Pretend a worker died after 100: counts and cursor saved, status
    // left 'sending' with a stale heartbeat.
    $claim = Database::run("UPDATE bulk_campaigns SET status = 'queued' WHERE id = :id", ['id' => $q['id']]);
    $lineCount = static fn(): int => is_file($log) ? count(file($log)) : 0;
    $calls0 = $lineCount();

    // First run: send everything.
    $before = Wallet::balance((int) $da['id']);
    Engine::runCampaign($q['id']);
    $c = Database::first('SELECT * FROM bulk_campaigns WHERE id = :id', ['id' => $q['id']]);
    check('it completes', $c['status'] === 'completed', $c['status'] . ' ' . $c['failure_reason']);
    check('everybody in the group got it', (int) $c['sent_count'] === 250, (string) $c['sent_count']);
    check('in batches of twenty', $lineCount() - $calls0 === 13, (string) ($lineCount() - $calls0));
    check('it is paid for exactly', abs($before - Wallet::balance((int) $da['id']) - 250) < 0.001);
    check('each message is personalised',
        (string) Database::scalar("SELECT message FROM bulk_messages WHERE campaign_id = :c AND recipient = '+254711000071'", ['c' => $q['id']]) === 'Hi Person 7, you owe KES 7');
    check('it has recorded how far it got', (int) $c['resume_contact_id'] > 0);

    // A second campaign: the worker "dies" after 100 recipients.
    $q2 = Engine::queueCampaign((int) $da['id'], ['name' => 'Crash test', 'sender_id' => 'SHANFIX',
        'message' => 'Resume {name}', 'group_id' => $groupId]);
    $firstIds = Database::all('SELECT id FROM bulk_contacts WHERE group_id = :g ORDER BY id LIMIT 100', ['g' => $groupId]);
    $cursor = (int) end($firstIds)['id'];
    foreach (array_slice(Database::all('SELECT phone FROM bulk_contacts WHERE group_id = :g ORDER BY id LIMIT 100', ['g' => $groupId]), 0, 100) as $row) {
        Database::insert('bulk_messages', ['campaign_id' => $q2['id'], 'account_id' => $da['id'], 'sender_id' => 'SHANFIX',
            'recipient' => Engine::normalizePhone($row['phone']), 'message' => 'Resume', 'status' => 'sent']);
    }
    Database::run("UPDATE bulk_campaigns SET status = 'sending', sent_count = 100, resume_contact_id = :c,
                   last_heartbeat_at = NOW() - INTERVAL 10 MINUTE WHERE id = :id", ['c' => $cursor, 'id' => $q2['id']]);

    $rescue = Worker::tick(true);
    $c2 = Database::first('SELECT * FROM bulk_campaigns WHERE id = :id', ['id' => $q2['id']]);
    check('a campaign with a dead worker is rescued', $rescue['rescued'] >= 1);
    check('and finishes', $c2['status'] === 'completed', $c2['status']);
    check('reaching the other 150 once each',
        (int) Database::scalar('SELECT COUNT(*) FROM bulk_messages WHERE campaign_id = :c', ['c' => $q2['id']]) === 250
        && (int) Database::scalar('SELECT COUNT(DISTINCT recipient) FROM bulk_messages WHERE campaign_id = :c', ['c' => $q2['id']]) === 250);

    $q3 = Engine::queueCampaign((int) $da['id'], ['name' => 'Cancel me', 'sender_id' => 'SHANFIX',
        'message' => 'x', 'recipients' => '0700000031, 0700000032']);
    check('a queued campaign can be cancelled', Engine::cancelCampaign($q3['id'], (int) $da['id']));
    Worker::tick(true);
    check('and is then never sent', (int) Database::scalar('SELECT COUNT(*) FROM bulk_messages WHERE campaign_id = :c', ['c' => $q3['id']]) === 0);
    check("another account cannot cancel it", !Engine::cancelCampaign($q2['id'], (int) $va['id']));

    $q4 = Engine::queueCampaign((int) $da['id'], ['name' => 'Later', 'sender_id' => 'SHANFIX',
        'message' => 'x', 'recipients' => '0700000033', 'scheduled_at' => date('Y-m-d H:i', time() + 3600)]);
    Worker::tick(true);
    check('a scheduled campaign waits for its time',
        Database::scalar('SELECT status FROM bulk_campaigns WHERE id = :id', ['id' => $q4['id']]) === 'scheduled');
    Database::run('UPDATE bulk_campaigns SET scheduled_at = NOW() - INTERVAL 1 MINUTE WHERE id = :id', ['id' => $q4['id']]);
    Worker::tick(true);
    check('and goes when it comes', Database::scalar('SELECT status FROM bulk_campaigns WHERE id = :id', ['id' => $q4['id']]) === 'completed');

    echo "\n-- A file campaign, with the old platform's ##placeholders##\n";
    $csv = STORAGE_PATH . '/uploads/bulktest_' . bin2hex(random_bytes(4)) . '.csv';
    @mkdir(dirname($csv), 0775, true);
    file_put_contents($csv, "Name,Mobile Phone,Amount\nAmina,0722000001,500\nBrian,0722000002,750\n,,\nNobody,12345,0\n");
    $q5 = Engine::queueCampaign((int) $da['id'], ['name' => 'File', 'sender_id' => 'SHANFIX',
        'message' => 'Dear ##Name##, pay {amount}', 'file_path' => $csv]);
    Engine::runCampaign($q5['id']);
    $c5 = Database::first('SELECT * FROM bulk_campaigns WHERE id = :id', ['id' => $q5['id']]);
    check('a file campaign completes', $c5['status'] === 'completed', (string) $c5['failure_reason']);
    check('the phone column is found by name', (int) $c5['sent_count'] === 2);
    check('a bad number in the file counts as failed', (int) $c5['failed_count'] === 1, (string) $c5['failed_count']);
    check('##Name## and {amount} are filled in',
        (string) Database::scalar("SELECT message FROM bulk_messages WHERE campaign_id = :c AND recipient = '+254722000002'", ['c' => $q5['id']]) === 'Dear Brian, pay 750');
    check('the file is removed once sent', !is_file($csv));

    echo "\n-- Out of units part-way\n";
    $tiny = Accounts::forPartner($partnerId);
    Database::insert('bulk_sender_ids', ['account_id' => $tiny['id'], 'sender_id' => 'TINY', 'status' => 'approved']);
    // Leave the partner 150 units: enough to queue 120 recipients of one
    // part, but drained to 50 before the send.
    $drain = Wallet::balance((int) $tiny['id']) - 150;
    Wallet::debit((int) $tiny['id'], $drain, 'adjustment', ['note' => 'test drain']);
    $nums = implode(',', array_map(static fn($i) => '0733' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, 120)));
    $q6 = Engine::queueCampaign((int) $tiny['id'], ['name' => 'Drained', 'sender_id' => 'TINY', 'message' => 'x', 'recipients' => $nums]);
    Wallet::debit((int) $tiny['id'], 100, 'adjustment', ['note' => 'spent elsewhere']);
    Engine::runCampaign($q6['id']);
    $c6 = Database::first('SELECT * FROM bulk_campaigns WHERE id = :id', ['id' => $q6['id']]);
    check('it stops rather than failing every recipient', $c6['status'] === 'failed' && (int) $c6['sent_count'] === 0
        && (int) $c6['failed_count'] === 100, $c6['status'] . ' sent=' . $c6['sent_count'] . ' failed=' . $c6['failed_count']);
    check('saying why', str_contains((string) $c6['failure_reason'], 'ran out of SMS units'));
    check('and the balance never goes below zero', Wallet::balance((int) $tiny['id']) >= 0);

    echo "\n-- Units are never created or lost\n";
    foreach ([$house['id'], $pa['id'], $da['id'], $va['id']] as $acc) {
        check('account #' . $acc . ' balance equals its ledger', ledgerBalances((int) $acc));
    }

    $sales = (float) Database::scalar("SELECT COALESCE(SUM(-amount),0) FROM bulk_ledger WHERE kind = 'sale' AND ref_type = 'purchase' AND ref_id IN (:a,:b)", ['a' => $buy['id'], 'b' => $sale['id']]);
    $bought = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM bulk_ledger WHERE kind = 'purchase' AND ref_type = 'purchase' AND ref_id IN (:a,:b)", ['a' => $buy['id'], 'b' => $sale['id']]);
    check('every unit sold is a unit bought', abs($sales - $bought) < 0.001 && $sales === 2300.0, "$sales vs $bought");
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($saved as $k => $v) {
        setting($k, $v === null ? null : (string) $v);
    }

    // Anything this caused to be queued is deleted before cron can send it.
    Database::run("DELETE FROM notifications WHERE event LIKE 'bulk\\_%' AND (recipient LIKE '%example.test' OR recipient LIKE '25470000001%')");

    foreach ($made['clients'] as $id) {
        $acc = Database::scalar("SELECT id FROM bulk_accounts WHERE owner_type = 'client' AND owner_id = :id", ['id' => $id]);
        if ($acc) {
            Database::run('DELETE FROM bulk_purchases WHERE account_id = :a', ['a' => $acc]);
            Database::run('DELETE FROM bulk_accounts WHERE id = :a', ['a' => $acc]);
        }
        Database::run('DELETE FROM clients WHERE id = :id', ['id' => $id]);
    }

    foreach ($made['partners'] as $id) {
        $acc = Database::scalar("SELECT id FROM bulk_accounts WHERE owner_type = 'partner' AND owner_id = :id", ['id' => $id]);
        if ($acc) {
            Database::run('DELETE FROM bulk_purchases WHERE account_id = :a OR seller_account_id = :a2', ['a' => $acc, 'a2' => $acc]);
            Database::run('DELETE FROM bulk_accounts WHERE id = :a', ['a' => $acc]);
        }
        Database::run('DELETE FROM partners WHERE id = :id', ['id' => $id]);
    }

    // The house keeps its own ledger rows from the test; put its balance
    // back where it started and say so in the ledger, so it still adds up.
    if (isset($house, $houseStart)) {
        Database::run('DELETE FROM bulk_purchases WHERE seller_account_id = :h AND legacy_id IS NULL AND transaction_ref IN (\'BANK1\')', ['h' => $house['id']]);
        $now = Wallet::balance((int) $house['id']);
        if (abs($now - $houseStart) > 0.0001) {
            $now > $houseStart
                ? Wallet::debit((int) $house['id'], $now - $houseStart, 'adjustment', ['note' => 'test run reversed'])
                : Wallet::credit((int) $house['id'], $houseStart - $now, 'adjustment', ['note' => 'test run reversed']);
        }
    }

    @unlink(sys_get_temp_dir() . '/fake_onfon_balance.txt');
}

printf("\npassed %d failed %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
