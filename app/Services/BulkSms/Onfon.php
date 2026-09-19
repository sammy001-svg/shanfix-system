<?php
namespace App\Services\BulkSms;

use App\Core\Logger;
use App\Core\Settings;

/**
 * Onfon Media, the gateway every customer's messages go out through.
 *
 * Ported from the old platform with its behaviour intact, because that
 * behaviour was learned the hard way:
 *
 *   - Onfon takes at most 20 recipients per SendBulkSMS call, so batches
 *     are 20 and several are fired in parallel.
 *   - A transient failure (no response, 429, 5xx) is retried three times
 *     with 1s/2s/4s back-off. Without it a burst of API sends hit Onfon's
 *     concurrency limit, were recorded as failed and refunded — while
 *     Onfon had queued them and delivered them an hour later, free.
 *   - A rejection from Onfon itself (bad sender ID, bad number) is final
 *     and is not retried.
 *
 * Two things are settings here that were hard-coded there. The address,
 * so the test suite can point it at a fake gateway and never send a real
 * text. And the pinned IP addresses: the old host's DNS could not always
 * resolve Onfon, so their addresses were written into the code, which
 * meant that if Onfon ever moved, every message on the platform would
 * fail until somebody edited a file.
 */
final class Onfon
{
    public const DEFAULT_BASE_URL = 'https://api.onfonmedia.co.ke';
    public const DEFAULT_PINNED   = '104.20.9.168,104.20.8.168';

    /** Onfon's own limit per SendBulkSMS call. */
    public const BATCH_SIZE = 20;

    private const MAX_ATTEMPTS = 3;

    /** Onfon's per-message error codes, for when it sends no description. */
    private const ERROR_CODES = [
        1   => 'Invalid or unregistered mobile number',
        2   => 'Sender ID not approved for this account',
        3   => 'Insufficient Onfon account credits',
        4   => 'Mobile network not supported',
        5   => 'Message text too long',
        100 => 'Invalid Onfon API key',
        101 => 'Invalid Onfon Client ID',
        102 => 'Invalid Onfon access key',
        103 => 'Onfon account inactive or suspended',
        104 => 'Sending IP address not whitelisted',
        200 => 'Onfon gateway internal server error',
    ];

    public static function isConfigured(): bool
    {
        return Settings::get('onfon_api_key', '') !== '' && Settings::get('onfon_client_id', '') !== '';
    }

    /**
     * Send several batches at once.
     *
     * @param list<list<array{phone:string, message:string}>> $batches
     * @return array<int, array{sent: list<array{idx:int, msg_id:?string}>,
     *                          failed: list<int>, reasons: array<int,string>}>
     *         keyed like $batches; indexes inside are positions in each batch
     */
    public static function sendBatches(array $batches, string $senderId, bool $unicode = false): array
    {
        $results = [];

        if (!self::isConfigured()) {
            foreach ($batches as $i => $batch) {
                $results[$i] = self::allFailed($batch, 'The SMS gateway is not configured');
            }
            return $results;
        }

        $apiKey    = (string) Settings::get('onfon_api_key', '');
        $clientId  = (string) Settings::get('onfon_client_id', '');
        $accessKey = (string) Settings::get('onfon_access_key', '');

        $pending  = array_keys($batches);
        $attempts = array_fill_keys($pending, 0);

        while ($pending !== []) {
            $mh      = curl_multi_init();
            $handles = [];

            foreach ($pending as $i) {
                $parameters = [];

                foreach ($batches[$i] as $r) {
                    $parameters[] = ['Number' => self::gatewayNumber($r['phone']), 'Text' => $r['message']];
                }

                $ch = self::handle('/v1/sms/SendBulkSMS', json_encode([
                    'SenderId'          => trim($senderId),
                    'IsUnicode'         => $unicode,
                    'IsFlash'           => false,
                    'MessageParameters' => $parameters,
                    'ApiKey'            => $apiKey,
                    'ClientId'          => $clientId,
                    'AccessKey'         => $accessKey,
                ]));

                curl_multi_add_handle($mh, $ch);
                $handles[$i] = $ch;
            }

            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running > 0);

            $retry = [];

            foreach ($handles as $i => $ch) {
                $body      = (string) curl_multi_getcontent($ch);
                $curlError = curl_error($ch);
                $http      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $attempts[$i]++;

                self::log(sprintf('batch=%d attempt=%d sender=%s count=%d http=%d curl=%s | %s',
                    $i, $attempts[$i], $senderId, count($batches[$i]), $http,
                    $curlError ?: 'ok', substr($body, 0, 300)));

                // Transient: try again, up to the limit.
                if ($body === '' || $http === 0 || $http === 429 || $http >= 500) {
                    if ($attempts[$i] < self::MAX_ATTEMPTS) {
                        $retry[] = $i;
                        continue;
                    }

                    $results[$i] = self::allFailed($batches[$i],
                        $curlError ?: 'The gateway did not answer (HTTP ' . $http . ') after ' . self::MAX_ATTEMPTS . ' attempts');
                    continue;
                }

                $results[$i] = self::interpret($batches[$i], $body);
            }

            curl_multi_close($mh);

            $pending = $retry;

            if ($pending !== []) {
                // 1s after the first attempt, 2s after the second.
                usleep((int) (1_000_000 * (2 ** min($attempts[reset($pending)] - 1, 2))));
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * What Onfon says we hold, in units. Null when it cannot be asked.
     */
    public static function balance(): ?float
    {
        if (!self::isConfigured()) {
            return null;
        }

        $ch = self::handle('/v1/sms/Balance?' . http_build_query([
            'ApiKey'   => (string) Settings::get('onfon_api_key', ''),
            'ClientId' => (string) Settings::get('onfon_client_id', ''),
        ]));

        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $body = (string) curl_exec($ch);
        curl_close($ch);

        $data = json_decode($body, true);

        if (!isset($data['Data'][0]['Credits'])) {
            return null;
        }

        // Onfon sometimes answers with a currency prefix: "KSh12,345.00".
        $credits = preg_replace('/[^0-9.\-]/', '', (string) $data['Data'][0]['Credits']);

        return is_numeric($credits) ? (float) $credits : null;
    }

    // -----------------------------------------------------------------

    /** Turn one answered call into per-recipient outcomes. */
    private static function interpret(array $batch, string $body): array
    {
        $parsed = json_decode($body, true);

        // A refusal of the whole call — bad credentials, unapproved sender.
        if (!is_array($parsed) || !isset($parsed['ErrorCode'])
            || (int) $parsed['ErrorCode'] !== 0 || empty($parsed['Data'])) {
            $reason = $parsed['Data'][0]['MessageErrorDescription']
                ?? $parsed['ErrorDescription']
                ?? $parsed['Description']
                ?? $parsed['Message']
                ?? 'Onfon error ' . ($parsed['ErrorCode'] ?? 'unknown');

            Logger::warning('Onfon refused a batch: ' . $reason);

            return self::allFailed($batch, (string) $reason);
        }

        $sent = [];
        $failed = [];
        $reasons = [];

        foreach (array_values($parsed['Data']) as $idx => $item) {
            if ($idx >= count($batch)) {
                break;
            }

            $code = $item['MessageErrorCode'] ?? -1;

            if ((string) $code === '0') {
                $sent[] = ['idx' => $idx, 'msg_id' => isset($item['MessageId']) ? (string) $item['MessageId'] : null];
            } else {
                $failed[]      = $idx;
                $reasons[$idx] = !empty($item['MessageErrorDescription'])
                    ? (string) $item['MessageErrorDescription']
                    : (self::ERROR_CODES[(int) $code] ?? 'Onfon gateway error (code ' . $code . ')');
            }
        }

        // Fewer answers than recipients: the rest did not go.
        for ($j = count($parsed['Data']); $j < count($batch); $j++) {
            $failed[]    = $j;
            $reasons[$j] = 'No response from the gateway for this recipient';
        }

        return ['sent' => $sent, 'failed' => $failed, 'reasons' => $reasons];
    }

    private static function allFailed(array $batch, string $reason): array
    {
        $all = $batch === [] ? [] : range(0, count($batch) - 1);

        return ['sent' => [], 'failed' => $all, 'reasons' => array_fill_keys($all, $reason)];
    }

    /** 254… with no plus, which is what Onfon wants. */
    private static function gatewayNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '254' . $digits;
        }

        return $digits;
    }

    private static function handle(string $path, ?string $json = null): \CurlHandle
    {
        $base = rtrim((string) Settings::get('onfon_base_url', self::DEFAULT_BASE_URL), '/');

        if ($base === '') {
            $base = self::DEFAULT_BASE_URL;
        }

        $ch = curl_init($base . $path);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        if ($json !== null) {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $json;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }

        // Pinning only applies to Onfon's own host. A test gateway, or a
        // future address, resolves normally.
        $host = (string) parse_url($base, PHP_URL_HOST);

        if ($host === 'api.onfonmedia.co.ke') {
            $pinned = array_filter(array_map('trim',
                explode(',', (string) Settings::get('onfon_pinned_ips', self::DEFAULT_PINNED))));

            if ($pinned !== []) {
                $options[CURLOPT_RESOLVE] = array_map(
                    static fn(string $ip): string => $host . ':443:' . $ip,
                    array_values($pinned)
                );
            }
        }

        curl_setopt_array($ch, $options);

        return $ch;
    }

    private static function log(string $line): void
    {
        $dir = STORAGE_PATH . '/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($dir . '/onfon.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }
}
