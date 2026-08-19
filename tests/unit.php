<?php
/** Unit checks for the money maths, phone normalisation, crypto and KopoKopo parsing. */

require_once __DIR__ . "/../app/bootstrap.php";

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Settings;
use App\Services\DocumentCalculator;
use App\Services\KopoKopo;

Config::load(CONFIG_PATH . '/config.php');
\App\Core\Database::connect(Config::get('db'));

$pass = 0; $fail = 0;

function is_eq($label, $actual, $expected) {
    global $pass, $fail;
    $ok = is_float($expected) || is_float($actual)
        ? abs((float)$actual - (float)$expected) < 0.005
        : $actual === $expected;
    if ($ok) { printf("  \033[32mPASS\033[0m %-52s %s\n", $label, var_export($actual, true)); $pass++; }
    else     { printf("  \033[31mFAIL\033[0m %-52s got %s want %s\n", $label, var_export($actual, true), var_export($expected, true)); $fail++; }
}

echo "\n=== VAT: exclusive (add on top) ===\n";
$r = DocumentCalculator::compute([['quantity'=>2,'unit_price'=>6500],['quantity'=>1,'unit_price'=>45000]], 'none', 0, 'exclusive', 16);
is_eq('subtotal', $r['subtotal'], 58000.0);
is_eq('vat', $r['vat_amount'], 9280.0);
is_eq('total', $r['total'], 67280.0);

echo "\n=== VAT: inclusive (price already contains VAT) ===\n";
$r = DocumentCalculator::compute([['quantity'=>1,'unit_price'=>11600]], 'none', 0, 'inclusive', 16);
is_eq('total unchanged', $r['total'], 11600.0);
is_eq('vat backed out (11600/1.16=10000, vat=1600)', $r['vat_amount'], 1600.0);

echo "\n=== VAT: exempt ===\n";
$r = DocumentCalculator::compute([['quantity'=>3,'unit_price'=>1000]], 'none', 0, 'exempt', 16);
is_eq('vat is zero', $r['vat_amount'], 0.0);
is_eq('total = subtotal', $r['total'], 3000.0);

echo "\n=== Discounts ===\n";
$r = DocumentCalculator::compute([['quantity'=>1,'unit_price'=>10000]], 'percent', 10, 'exclusive', 16);
is_eq('10% off 10000 = 1000', $r['discount_amount'], 1000.0);
is_eq('VAT charged on 9000 net = 1440', $r['vat_amount'], 1440.0);
is_eq('total 10440', $r['total'], 10440.0);

$r = DocumentCalculator::compute([['quantity'=>1,'unit_price'=>10000]], 'amount', 2500, 'exempt', 16);
is_eq('fixed 2500 discount', $r['discount_amount'], 2500.0);
is_eq('total 7500', $r['total'], 7500.0);

$r = DocumentCalculator::compute([['quantity'=>1,'unit_price'=>5000]], 'amount', 99999, 'exempt', 16);
is_eq('discount capped at subtotal', $r['discount_amount'], 5000.0);
is_eq('total never negative', $r['total'], 0.0);

echo "\n=== Rounding ===\n";
$r = DocumentCalculator::compute([['quantity'=>3,'unit_price'=>333.33]], 'none', 0, 'exclusive', 16);
is_eq('3 x 333.33 = 999.99', $r['subtotal'], 999.99);
is_eq('VAT 16% = 160.00', $r['vat_amount'], 160.0);
is_eq('total 1159.99', $r['total'], 1159.99);

echo "\n=== Invoice status derivation ===\n";
is_eq('nothing paid, not due -> unpaid',  DocumentCalculator::invoiceStatus(1000, 0, date('Y-m-d', strtotime('+5 days')), 'unpaid'), 'unpaid');
is_eq('nothing paid, past due -> overdue', DocumentCalculator::invoiceStatus(1000, 0, date('Y-m-d', strtotime('-5 days')), 'unpaid'), 'overdue');
is_eq('part paid -> partial',              DocumentCalculator::invoiceStatus(1000, 400, date('Y-m-d'), 'unpaid'), 'partial');
is_eq('paid in full -> paid',              DocumentCalculator::invoiceStatus(1000, 1000, date('Y-m-d'), 'partial'), 'paid');
is_eq('overpaid -> paid',                  DocumentCalculator::invoiceStatus(1000, 1200, date('Y-m-d'), 'partial'), 'paid');
is_eq('cancelled is never overwritten',    DocumentCalculator::invoiceStatus(1000, 1000, date('Y-m-d'), 'cancelled'), 'cancelled');

echo "\n=== Phone normalisation (KopoKopo needs 2547XXXXXXXX) ===\n";
is_eq('0712345678',    normalize_phone('0712345678'), '254712345678');
is_eq('0110123456',    normalize_phone('0110123456'), '254110123456');
is_eq('+254 712 345 678', normalize_phone('+254 712 345 678'), '254712345678');
is_eq('254712345678',  normalize_phone('254712345678'), '254712345678');
is_eq('712345678',     normalize_phone('712345678'), '254712345678');
is_eq('garbage -> null', normalize_phone('hello'), null);
is_eq('empty -> null',   normalize_phone(''), null);

echo "\n=== Secret encryption at rest ===\n";
$secret = 'kk_live_secret_abc123XYZ';
$cipher = Crypto::encrypt($secret);
is_eq('ciphertext differs from plaintext', $cipher !== $secret, true);
is_eq('ciphertext is tagged', str_starts_with($cipher, 'enc:v1:'), true);
is_eq('round-trips correctly', Crypto::decrypt($cipher), $secret);
is_eq('tampered ciphertext rejected', Crypto::decrypt('enc:v1:' . base64_encode('garbagegarbagegarbagegarbage')), null);
is_eq('legacy plaintext passes through', Crypto::decrypt('plain-value'), 'plain-value');

echo "\n=== Settings encrypt secrets, not ordinary values ===\n";
Settings::set('kopokopo_client_secret', 'my-super-secret');
$raw = \App\Core\Database::scalar("SELECT setting_value FROM settings WHERE setting_key='kopokopo_client_secret'");
is_eq('stored encrypted in DB', str_starts_with((string)$raw, 'enc:v1:'), true);
is_eq('reads back decrypted', Settings::get('kopokopo_client_secret'), 'my-super-secret');
Settings::set('company_phone', '+254700111222');
$raw2 = \App\Core\Database::scalar("SELECT setting_value FROM settings WHERE setting_key='company_phone'");
is_eq('non-secret stored plainly', $raw2, '+254700111222');

echo "\n=== KopoKopo webhook signature ===\n";
Settings::set('kopokopo_api_key', 'test_api_key_123');
Settings::flush();
$body = '{"topic":"buygoods_transaction_received","data":{"id":"abc"}}';
$goodSig = hash_hmac('sha256', $body, 'test_api_key_123');
is_eq('valid signature accepted', KopoKopo::verifySignature($body, $goodSig), true);
is_eq('wrong signature rejected', KopoKopo::verifySignature($body, 'deadbeef'), false);
is_eq('missing signature rejected', KopoKopo::verifySignature($body, null), false);
is_eq('tampered body rejected', KopoKopo::verifySignature($body . 'x', $goodSig), false);

echo "\n=== KopoKopo callback parsing ===\n";
$success = json_decode('{
  "topic":"buygoods_transaction_received",
  "id":"evt_1",
  "data":{
    "id":"c4f2a1b0-1111-2222-3333-444455556666",
    "type":"incoming_payment",
    "attributes":{
      "status":"Success",
      "event":{
        "type":"Incoming Payment Request",
        "resource":{
          "id":"res_1",
          "reference":"SFX7HG12KL",
          "sender_phone_number":"+254712345678",
          "amount":"67280.0",
          "currency":"KES",
          "status":"Received"
        },
        "errors":null
      },
      "metadata":{"stk_id":"7","document_id":"2"}
    }
  }
}', true);
$p = KopoKopo::parseCallback($success);
is_eq('status mapped to success', $p['status'], 'success');
is_eq('M-Pesa receipt extracted', $p['receipt'], 'SFX7HG12KL');
is_eq('amount extracted as float', $p['amount'], 67280.0);
is_eq('phone stripped of +', $p['phone'], '254712345678');
is_eq('kopokopo id extracted', $p['kopokopo_id'], 'c4f2a1b0-1111-2222-3333-444455556666');

$failed = json_decode('{
  "data":{"id":"evt_2","attributes":{
    "status":"Failed",
    "event":{"resource":{"status":"Failed"},"errors":["Request cancelled by user"]}
  }}
}', true);
$p2 = KopoKopo::parseCallback($failed);
is_eq('failed status mapped', $p2['status'], 'failed');
is_eq('error message extracted', $p2['description'], 'Request cancelled by user');

$pending = KopoKopo::parseCallback(['data' => ['attributes' => ['status' => 'Pending']]]);
is_eq('pending status mapped', $pending['status'], 'pending');
is_eq('empty payload does not crash', KopoKopo::parseCallback([])['status'], 'pending');

echo "\n=== Money formatting ===\n";
is_eq('money()', money(67280), 'KES 67,280.00');
is_eq('money() no currency', money(1250.5, false), '1,250.50');
is_eq('money_short() millions', money_short(1250000), 'KES 1.25M');
is_eq('money_short() thousands', money_short(67280), 'KES 67.3K');
is_eq('qty() drops trailing zeros', qty(12.00), '12');
is_eq('qty() keeps decimals', qty(12.50), '12.5');

echo "\n===================================================\n";
printf("  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n", $pass, $fail);
echo "===================================================\n";
exit($fail > 0 ? 1 : 0);
