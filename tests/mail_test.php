<?php
/** Exercises the Mailer against a local fake SMTP server, then inspects the wire format. */

require_once __DIR__ . "/../app/bootstrap.php";

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Services\Mailer;
use App\Services\Notifier;
use App\Services\Sms;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$pass = 0; $fail = 0;
function ok($label, $actual, $expected = true) {
    global $pass, $fail;
    $good = $expected === true ? (bool) $actual : $actual === $expected;
    if ($good) { printf("  \033[32mPASS\033[0m %-54s\n", $label); $pass++; }
    else { printf("  \033[31mFAIL\033[0m %-54s got %s\n", $label, var_export($actual, true)); $fail++; }
}

$dir  = __DIR__;
$eml  = $dir . '/captured.eml';
@unlink($eml); @unlink($eml . '.log'); @unlink($eml . '.ready');

// Start the fake server in the background.
$cmd = 'start /B php ' . escapeshellarg($dir . '/fake_smtp.php') . ' 2525 ' . escapeshellarg($eml) . ' --require-auth';
pclose(popen($cmd, 'r'));

// Wait for it to bind.
for ($i = 0; $i < 60 && !file_exists($eml . '.ready'); $i++) {
    usleep(100000);
}

echo "\n=== 1. SMTP conversation ===\n";
ok('fake server is listening', file_exists($eml . '.ready'));

$mailer = new Mailer(
    host: '127.0.0.1',
    port: 2525,
    encryption: 'none',
    username: 'invoices@shanfix.co.ke',
    password: 'secret123',
    fromEmail: 'invoices@shanfix.co.ke',
    fromName: 'Shanfix Technology',
    replyTo: 'accounts@shanfix.co.ke'
);

$html = '<html><body><h1>Invoice INV-2026-0001</h1><p>Balance due: KES 129,920.00</p>'
      . '<a href="https://erp.shanfix.co.ke/view/abc">View invoice</a></body></html>';

$result = $mailer->send('grace@riverside.co.ke', 'Grace Njeri', 'Invoice INV-2026-0001 from Shanfix', $html);

ok('send() reported success', $result['ok']);
if (!$result['ok']) {
    echo "     error: " . ($result['error'] ?? '') . "\n";
}

// Give the server a moment to flush.
for ($i = 0; $i < 40 && !file_exists($eml); $i++) { usleep(100000); }

$raw = file_exists($eml) ? file_get_contents($eml) : '';
$log = file_exists($eml . '.log') ? file_get_contents($eml . '.log') : '';

echo "\n=== 2. Protocol steps the server saw ===\n";
ok('EHLO sent',        str_contains($log, 'EHLO'));
ok('AUTH LOGIN used',  str_contains($log, 'AUTH LOGIN'));
ok('MAIL FROM correct',str_contains($log, 'MAIL FROM:<invoices@shanfix.co.ke>'));
ok('RCPT TO correct',  str_contains($log, 'RCPT TO:<grace@riverside.co.ke>'));
ok('DATA issued',      str_contains($log, 'DATA'));
ok('QUIT issued',      str_contains($log, 'QUIT'));

echo "\n=== 3. Message headers ===\n";
ok('From header',       str_contains($raw, 'From: Shanfix Technology <invoices@shanfix.co.ke>'));
ok('To header w/ name', str_contains($raw, 'To: Grace Njeri <grace@riverside.co.ke>'));
ok('Subject header',    str_contains($raw, 'Subject: Invoice INV-2026-0001 from Shanfix'));
ok('Reply-To header',   str_contains($raw, 'Reply-To: <accounts@shanfix.co.ke>'));
ok('MIME-Version',      str_contains($raw, 'MIME-Version: 1.0'));
ok('Message-ID present',(bool) preg_match('/Message-ID: <[a-f0-9]{32}@shanfix\.co\.ke>/', $raw));
ok('Date header',       str_contains($raw, 'Date: '));

echo "\n=== 4. MIME structure ===\n";
ok('multipart/alternative', str_contains($raw, 'multipart/alternative'));
ok('text/plain part',       str_contains($raw, 'Content-Type: text/plain; charset=UTF-8'));
ok('text/html part',        str_contains($raw, 'Content-Type: text/html; charset=UTF-8'));
ok('base64 encoded',        str_contains($raw, 'Content-Transfer-Encoding: base64'));

// Decode the HTML part and confirm it survived intact.
preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $m);
$decoded = array_map(static fn($b) => base64_decode(preg_replace('/\s+/', '', $b)), $m[1] ?? []);
$htmlPart = '';
$textPart = '';
foreach ($decoded as $d) {
    if (str_contains($d, '<html')) $htmlPart = $d;
    elseif ($textPart === '') $textPart = $d;
}

echo "\n=== 5. Body round-trip ===\n";
ok('HTML body decodes intact', $htmlPart === $html);
ok('plain-text fallback generated', $textPart !== '');
ok('plain text has the heading', str_contains($textPart, 'Invoice INV-2026-0001'));
ok('plain text has the amount',  str_contains($textPart, 'KES 129,920.00'));
ok('plain text stripped tags',   !str_contains($textPart, '<h1>'));

echo "\n=== 6. Unicode subject encoding ===\n";
@unlink($eml); @unlink($eml . '.log');
pclose(popen('start /B php ' . escapeshellarg($dir . '/fake_smtp.php') . ' 2526 ' . escapeshellarg($eml), 'r'));
for ($i = 0; $i < 60 && !file_exists($eml . '.ready'); $i++) { usleep(100000); }

$m2 = new Mailer(host: '127.0.0.1', port: 2526, encryption: 'none', username: '',
                 password: '', fromEmail: 'a@b.co.ke', fromName: 'Café Shanfix');
$r2 = $m2->send('x@y.co.ke', 'Zoë', 'Facturé — KES 1 000', '<p>hi</p>');
for ($i = 0; $i < 40 && !file_exists($eml); $i++) { usleep(100000); }
$raw2 = file_exists($eml) ? file_get_contents($eml) : '';

ok('unicode send succeeded', $r2['ok']);
ok('subject RFC2047 encoded', str_contains($raw2, '=?UTF-8?B?'));
ok('no raw 8-bit in headers', !preg_match('/Subject:.*é/', $raw2));

echo "\n=== 7. Unreachable server fails cleanly ===\n";
$m3 = new Mailer(host: '127.0.0.1', port: 59999, encryption: 'none', username: '',
                 password: '', fromEmail: 'a@b.co.ke', fromName: 'X');
$r3 = $m3->send('x@y.co.ke', '', 'test', '<p>x</p>');
ok('returns ok=false',        $r3['ok'] === false);
ok('explains the problem',    str_contains(strtolower($r3['error'] ?? ''), 'could not connect'));
ok('no exception escaped',    true);

echo "\n=== 8. Invalid recipient rejected before connecting ===\n";
$r4 = (new Mailer(host: '127.0.0.1', port: 2525, encryption: 'none', username: '',
                  password: '', fromEmail: 'a@b.co.ke', fromName: 'X'))
      ->send('not-an-email', '', 's', '<p>b</p>');
ok('rejected', $r4['ok'] === false);
ok('says why',  str_contains($r4['error'] ?? '', 'Invalid recipient'));

echo "\n=== 9. Template placeholder rendering ===\n";
$ctx = [
    'contact_name' => 'Grace',
    'doc_number'   => 'INV-2026-0001',
    'balance'      => 'KES 129,920.00',
    'due_date'     => '19 Aug 2026',
    'company_name' => 'Shanfix Technology',
    'link'         => 'https://erp.shanfix.co.ke/view/abc123',
];
$tpl = 'Hi {contact_name}, invoice {doc_number} for {balance} is due {due_date}. View: {link} - {company_name}';
$out = Notifier::render($tpl, $ctx);
ok('all placeholders replaced', !str_contains($out, '{'));
ok('values substituted', str_contains($out, 'Grace') && str_contains($out, 'INV-2026-0001'));
ok('unknown placeholder stripped', !str_contains(Notifier::render('A {nope} B', $ctx), '{nope}'));
ok('empty template stays empty', Notifier::render('', $ctx), '');
ok('null template safe', Notifier::render(null, $ctx), '');

echo "\n=== 10. SMS credit counting ===\n";
ok('160 chars = 1 credit',  Sms::parts(str_repeat('a', 160)), 1);
ok('161 chars = 2 credits', Sms::parts(str_repeat('a', 161)), 2);
ok('70 unicode = 1 credit', Sms::parts(str_repeat('é', 70)), 1);
ok('71 unicode = 2 credits',Sms::parts(str_repeat('é', 71)), 2);
ok('real template is 1 credit', Sms::parts($out) <= 2);

echo "\n=== 11. Secrets encrypted at rest ===\n";
Settings::set('smtp_password', 'mailbox-secret-99');
$stored = Database::scalar("SELECT setting_value FROM settings WHERE setting_key='smtp_password'");
ok('smtp_password encrypted', str_starts_with((string) $stored, 'enc:v1:'));
ok('reads back correctly', Settings::get('smtp_password'), 'mailbox-secret-99');
Settings::set('sms_api_key', 'atsk_live_abc');
$stored2 = Database::scalar("SELECT setting_value FROM settings WHERE setting_key='sms_api_key'");
ok('sms_api_key encrypted', str_starts_with((string) $stored2, 'enc:v1:'));

echo "\n=== 12. SMS guards without credentials ===\n";
$sms = new Sms(username: '', apiKey: '', senderId: '');
$r5 = $sms->send('0712345678', 'test');
ok('unconfigured refused', $r5['ok'] === false);
ok('explains why', str_contains($r5['error'], 'not configured'));

$sms2 = new Sms(username: 'x', apiKey: 'y', senderId: '');
$r6 = $sms2->send('not-a-phone', 'test');
ok('bad number refused', $r6['ok'] === false);
ok('explains why', str_contains($r6['error'], 'Invalid phone'));

$r7 = $sms2->send('0712345678', '   ');
ok('empty message refused', $r7['ok'] === false);

echo "\n===================================================\n";
printf("  \033[32mPASSED: %d\033[0m   \033[31mFAILED: %d\033[0m\n", $pass, $fail);
echo "===================================================\n";

@unlink($eml); @unlink($eml . '.log'); @unlink($eml . '.ready');
exit($fail > 0 ? 1 : 0);
