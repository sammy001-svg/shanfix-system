<?php
/**
 * Throwaway SMTP server for testing the Mailer.
 * Speaks just enough of the protocol, then dumps the raw message to a file.
 *
 *   php fake_smtp.php <port> <outfile> [--require-auth]
 */

$port       = (int) ($argv[1] ?? 2525);
$outFile    = $argv[2] ?? __DIR__ . '/captured.eml';
$requireAuth = in_array('--require-auth', $argv, true);

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

if (!$server) {
    fwrite(STDERR, "Could not bind port {$port}: {$errstr}\n");
    exit(1);
}

// Signal readiness so the test harness knows it can connect.
file_put_contents($outFile . '.ready', '1');

$conn = @stream_socket_accept($server, 25);

if (!$conn) {
    fwrite(STDERR, "No connection within 25s\n");
    exit(1);
}

stream_set_timeout($conn, 10);

$log      = [];
$data     = '';
$inData   = false;
$authSeen = false;
$authStep = 0;

function reply($conn, string $line): void
{
    fwrite($conn, $line . "\r\n");
}

reply($conn, '220 fake.smtp.test ESMTP ready');

while (($line = fgets($conn, 4096)) !== false) {
    if ($inData) {
        if (rtrim($line, "\r\n") === '.') {
            $inData = false;
            reply($conn, '250 2.0.0 Message accepted');
            continue;
        }
        $data .= $line;
        continue;
    }

    $trimmed = trim($line);
    $log[]   = $trimmed;
    $upper   = strtoupper($trimmed);

    if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
        reply($conn, '250-fake.smtp.test');
        reply($conn, '250-AUTH LOGIN PLAIN');
        reply($conn, '250 SIZE 35882577');
    } elseif ($upper === 'AUTH LOGIN') {
        $authSeen = true;
        $authStep = 1;
        reply($conn, '334 VXNlcm5hbWU6');       // "Username:"
    } elseif ($authStep === 1) {
        $authStep = 2;
        reply($conn, '334 UGFzc3dvcmQ6');       // "Password:"
    } elseif ($authStep === 2) {
        $authStep = 3;
        reply($conn, '235 2.7.0 Authentication successful');
    } elseif (str_starts_with($upper, 'AUTH PLAIN')) {
        $authSeen = true;
        reply($conn, '235 2.7.0 Authentication successful');
    } elseif (str_starts_with($upper, 'MAIL FROM')) {
        if ($requireAuth && !$authSeen) {
            reply($conn, '530 5.7.0 Authentication required');
        } else {
            reply($conn, '250 2.1.0 Sender ok');
        }
    } elseif (str_starts_with($upper, 'RCPT TO')) {
        reply($conn, '250 2.1.5 Recipient ok');
    } elseif ($upper === 'DATA') {
        $inData = true;
        reply($conn, '354 End data with <CR><LF>.<CR><LF>');
    } elseif ($upper === 'QUIT') {
        reply($conn, '221 2.0.0 Bye');
        break;
    } elseif ($upper === 'RSET') {
        reply($conn, '250 2.0.0 Ok');
    } else {
        reply($conn, '250 2.0.0 Ok');
    }
}

fclose($conn);
fclose($server);

file_put_contents($outFile, $data);
file_put_contents($outFile . '.log', implode("\n", $log));
@unlink($outFile . '.ready');
