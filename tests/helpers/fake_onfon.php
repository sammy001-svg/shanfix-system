<?php
/**
 * A stand-in for Onfon Media, so the Bulk SMS tests never send a text.
 *
 * Run with:  php -S 127.0.0.1:8098 tests/helpers/fake_onfon.php
 *
 * Behaves like the real SendBulkSMS and Balance calls, with a few rules
 * the tests lean on:
 *
 *   - a number ending 99 is refused (MessageErrorCode 1, like an
 *     unregistered number);
 *   - sender ID "BADSENDER" gets the whole call refused (ErrorCode 2);
 *   - a number ending 55 makes the whole call answer HTTP 503, to prove
 *     the retry — the first two times only, then it succeeds;
 *   - Balance answers whatever is in fake_onfon_balance.txt, default 5000.
 *
 * Every request body is appended to fake_onfon.log beside this file, so
 * a test can count what actually reached the "gateway".
 */

$dir = sys_get_temp_dir();
$log = $dir . '/fake_onfon.log';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('Content-Type: application/json');

if (str_ends_with($path, '/Balance')) {
    $balance = @file_get_contents($dir . '/fake_onfon_balance.txt');
    echo json_encode(['ErrorCode' => 0, 'Data' => [['PluginType' => 'SMS', 'Credits' => 'KSh' . ($balance !== false ? trim($balance) : '5000')]]]);
    exit;
}

$body = file_get_contents('php://input');
$req  = json_decode($body, true) ?: [];

file_put_contents($log, $body . "\n", FILE_APPEND | LOCK_EX);

if (($req['SenderId'] ?? '') === 'BADSENDER') {
    echo json_encode(['ErrorCode' => 2, 'ErrorDescription' => 'Invalid Sender Id', 'Data' => null]);
    exit;
}

foreach ($req['MessageParameters'] ?? [] as $m) {
    if (str_ends_with((string) $m['Number'], '55')) {
        $counter = $dir . '/fake_onfon_503.txt';
        $n = (int) @file_get_contents($counter);
        file_put_contents($counter, (string) ($n + 1));
        if ($n < 2) {
            http_response_code(503);
            echo '{}';
            exit;
        }
    }
}

$data = [];
foreach ($req['MessageParameters'] ?? [] as $m) {
    if (str_ends_with((string) $m['Number'], '99')) {
        $data[] = ['MessageErrorCode' => 1, 'MessageErrorDescription' => 'Invalid mobile number', 'MobileNumber' => $m['Number'], 'MessageId' => null];
    } else {
        $data[] = ['MessageErrorCode' => 0, 'MessageErrorDescription' => 'Success', 'MobileNumber' => $m['Number'],
                   'MessageId' => 'fake-' . bin2hex(random_bytes(6))];
    }
}

echo json_encode(['ErrorCode' => 0, 'ErrorDescription' => 'Success', 'Data' => $data]);
