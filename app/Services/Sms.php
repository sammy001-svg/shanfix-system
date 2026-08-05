<?php
namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;

/**
 * SMS via Shanfix Bulk SMS (https://sms.shanfixtechnology.com).
 *
 * Authentication is a Client ID + API key pair from the portal's
 * Developer/API page, sent as X-Client-Id / X-Api-Key headers. Messages are
 * charged in SMS units against the account balance, and the sender ID must
 * already be approved on that account.
 *
 * Endpoints used (api/v1):
 *   sendsms.php   one recipient          60 requests/minute
 *   bulksend.php  up to 1 000 recipients 10 requests/minute
 *   balance.php   remaining SMS units
 */
class Sms
{
    public const DEFAULT_BASE_URL = 'https://sms.shanfixtechnology.com';

    /** The gateway rejects anything longer — six 160-character segments. */
    private const MAX_LENGTH = 918;

    /** One bulksend.php call carries at most this many recipients. */
    public const BULK_LIMIT = 1000;

    private string $baseUrl;
    private string $clientId;
    private string $apiKey;
    private string $senderId;

    public function __construct(
        ?string $clientId = null,
        ?string $apiKey = null,
        ?string $senderId = null,
        ?string $baseUrl = null
    ) {
        $this->clientId = $clientId ?? (string) Settings::get('sms_client_id', '');
        $this->apiKey   = $apiKey   ?? (string) Settings::get('sms_api_key', '');
        $this->senderId = $senderId ?? (string) Settings::get('sms_sender_id', '');
        $this->baseUrl  = rtrim($baseUrl ?? (string) Settings::get('sms_base_url', self::DEFAULT_BASE_URL), '/');

        if ($this->baseUrl === '') {
            $this->baseUrl = self::DEFAULT_BASE_URL;
        }
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->apiKey !== '';
    }

    private function endpoint(string $script): string
    {
        return $this->baseUrl . '/api/v1/' . $script;
    }

    /**
     * Send to one recipient.
     *
     * @return array{ok:bool, error?:string, ref?:string, cost?:float, status?:string, balance?:string}
     */
    public function send(string $phone, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMS is not configured. Add your Shanfix Bulk SMS credentials in Settings.'];
        }

        $normalised = normalize_phone($phone);

        if ($normalised === null) {
            return ['ok' => false, 'error' => 'Invalid phone number: ' . $phone];
        }

        $message = $this->prepare($message);

        if ($message === '') {
            return ['ok' => false, 'error' => 'Message body is empty.'];
        }

        $response = $this->post('sendsms.php', [
            'to'      => $normalised,
            'message' => $message,
        ]);

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $json = $response['json'];

        return [
            'ok'      => true,
            'ref'     => (string) ($json['message_id'] ?? ''),
            'cost'    => isset($json['units_charged']) ? (float) $json['units_charged'] : null,
            'status'  => 'Submitted',
            'balance' => isset($json['remaining_units']) ? (string) $json['remaining_units'] : null,
        ];
    }

    /**
     * Send the same message to many recipients in one call. The gateway
     * de-duplicates numbers and reports invalid ones back rather than failing
     * the whole batch.
     *
     * @param array<int,string> $phones Raw numbers; normalised here
     *
     * @return array{ok:bool, error?:string, submitted?:int, sent?:int, failed?:int,
     *               invalid?:array<int,string>, cost?:float, balance?:string}
     */
    public function sendBulk(array $phones, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMS is not configured. Add your Shanfix Bulk SMS credentials in Settings.'];
        }

        $message = $this->prepare($message);

        if ($message === '') {
            return ['ok' => false, 'error' => 'Message body is empty.'];
        }

        $recipients = [];
        $invalid    = [];

        foreach ($phones as $phone) {
            $normalised = normalize_phone((string) $phone);

            if ($normalised === null) {
                $invalid[] = (string) $phone;
            } else {
                $recipients[$normalised] = true;   // key de-dupes
            }
        }

        $recipients = array_keys($recipients);

        if ($recipients === []) {
            return ['ok' => false, 'error' => 'No valid phone numbers to send to.', 'invalid' => $invalid];
        }

        $submitted = 0;
        $sent      = 0;
        $failed    = 0;
        $cost      = 0.0;
        $balance   = null;

        // Chunk so a list longer than the gateway's per-call ceiling still goes.
        foreach (array_chunk($recipients, self::BULK_LIMIT) as $chunk) {
            $response = $this->post('bulksend.php', [
                'to'      => $chunk,
                'message' => $message,
            ]);

            if (!$response['ok']) {
                // Anything already sent stands; report the failure with the tally.
                return [
                    'ok'        => false,
                    'error'     => $response['error'],
                    'submitted' => $submitted,
                    'sent'      => $sent,
                    'failed'    => $failed + count($chunk),
                    'invalid'   => $invalid,
                    'cost'      => $cost,
                ];
            }

            $json = $response['json'];

            $submitted += (int) ($json['total_submitted'] ?? count($chunk));
            $sent      += (int) ($json['sent'] ?? 0);
            $failed    += (int) ($json['failed'] ?? 0);
            $cost      += (float) ($json['units_charged'] ?? 0);
            $balance    = $json['remaining_units'] ?? $balance;

            foreach ((array) ($json['invalid_numbers'] ?? []) as $bad) {
                $invalid[] = (string) $bad;
            }
        }

        return [
            'ok'        => true,
            'submitted' => $submitted,
            'sent'      => $sent,
            'failed'    => $failed,
            'invalid'   => $invalid,
            'cost'      => round($cost, 4),
            'balance'   => $balance !== null ? (string) $balance : null,
        ];
    }

    /**
     * Remaining SMS units on the account. Doubles as the credentials check —
     * it costs nothing to call.
     *
     * @return array{ok:bool, error?:string, balance?:float, client_name?:string, message?:string}
     */
    public function balance(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMS is not configured.'];
        }

        $response = $this->post('balance.php', []);

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $json  = $response['json'];
        $units = isset($json['sms_units']) ? (float) $json['sms_units'] : null;
        $name  = (string) ($json['client_name'] ?? '');

        return [
            'ok'          => true,
            'balance'     => $units,
            'client_name' => $name,
            'message'     => 'Connected as ' . ($name !== '' ? $name : 'your account')
                           . ($units !== null ? '. Balance: ' . rtrim(rtrim(number_format($units, 2), '0'), '.') . ' SMS units' : '.'),
        ];
    }

    /** Trim, and keep the body inside the gateway's hard length limit. */
    private function prepare(string $message): string
    {
        $message = trim($message);

        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH - 3) . '...';
        }

        return $message;
    }

    /**
     * POST a JSON body to an api/v1 endpoint with the credential headers.
     *
     * @return array{ok:bool, json?:array, error?:string}
     */
    private function post(string $script, array $payload): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP cURL is not available on this server.'];
        }

        if ($this->senderId !== '' && !isset($payload['sender_id'])
            && in_array($script, ['sendsms.php', 'bulksend.php'], true)) {
            $payload['sender_id'] = $this->senderId;
        }

        $url = $this->endpoint($script);
        $ch  = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,      // a full bulk batch takes a while
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Client-Id: ' . $this->clientId,
                'X-Api-Key: ' . $this->apiKey,
            ],
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::error('SMS gateway unreachable', ['url' => $url, 'error' => $err]);
            return ['ok' => false, 'error' => 'Could not reach the SMS gateway: ' . $err];
        }

        $json = json_decode((string) $body, true);

        if (!is_array($json)) {
            Logger::error('SMS gateway returned non-JSON', [
                'url'    => $url,
                'status' => $status,
                'body'   => mb_substr((string) $body, 0, 400),
            ]);
            return ['ok' => false, 'error' => 'The SMS gateway returned an unreadable response (HTTP ' . $status . ').'];
        }

        // The API reports failure both by HTTP status and by success:false.
        if ($status < 200 || $status >= 300 || ($json['success'] ?? false) !== true) {
            $message = (string) ($json['error'] ?? '');

            if ($message === '') {
                $message = match ($status) {
                    401     => 'The gateway rejected your credentials. Check the Client ID and API key.',
                    403     => 'Your Shanfix Bulk SMS account is suspended.',
                    429     => 'Sending too fast — the gateway rate limit was hit. It will retry shortly.',
                    default => 'SMS gateway returned HTTP ' . $status . '.',
                };
            }

            Logger::error('SMS gateway error', ['url' => $url, 'status' => $status, 'error' => $message]);

            return ['ok' => false, 'error' => $message];
        }

        return ['ok' => true, 'json' => $json];
    }

    /**
     * How many 160-character parts a message will be billed as.
     * Unicode messages (any non-GSM character) drop to 70 per part.
     */
    public static function parts(string $message): int
    {
        $length  = mb_strlen($message);
        $unicode = preg_match('/[^\x20-\x7E\n\r]/', $message) === 1;

        $single = $unicode ? 70 : 160;
        $multi  = $unicode ? 67 : 153;

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }
}
