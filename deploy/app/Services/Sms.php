<?php
namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;

/**
 * SMS via Africa's Talking — the usual gateway for Kenyan numbers.
 *
 * Docs: https://developers.africastalking.com/docs/sms/sending/bulk
 */
class Sms
{
    private const LIVE_URL    = 'https://api.africastalking.com/version1/messaging';
    private const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    private string $username;
    private string $apiKey;
    private string $senderId;

    public function __construct(?string $username = null, ?string $apiKey = null, ?string $senderId = null)
    {
        $this->username = $username ?? (string) Settings::get('sms_username', '');
        $this->apiKey   = $apiKey   ?? (string) Settings::get('sms_api_key', '');
        $this->senderId = $senderId ?? (string) Settings::get('sms_sender_id', '');
    }

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->apiKey !== '';
    }

    /** Africa's Talking routes the "sandbox" username to its test endpoint. */
    private function endpoint(): string
    {
        return strtolower($this->username) === 'sandbox' ? self::SANDBOX_URL : self::LIVE_URL;
    }

    /**
     * @return array{ok:bool, error?:string, ref?:string, cost?:float, status?:string}
     */
    public function send(string $phone, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMS is not configured. Add your gateway credentials in Settings.'];
        }

        $normalised = normalize_phone($phone);

        if ($normalised === null) {
            return ['ok' => false, 'error' => 'Invalid phone number: ' . $phone];
        }

        $message = trim($message);

        if ($message === '') {
            return ['ok' => false, 'error' => 'Message body is empty.'];
        }

        // Long messages are billed per 160-char part; warn rather than truncate.
        if (mb_strlen($message) > 918) {
            $message = mb_substr($message, 0, 915) . '...';
        }

        $fields = [
            'username' => $this->username,
            'to'       => '+' . $normalised,
            'message'  => $message,
        ];

        if ($this->senderId !== '') {
            $fields['from'] = $this->senderId;
        }

        $response = $this->post($this->endpoint(), $fields);

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $recipients = $response['json']['SMSMessageData']['Recipients'] ?? [];

        if ($recipients === []) {
            $summary = $response['json']['SMSMessageData']['Message'] ?? 'No recipients accepted.';
            Logger::error('SMS rejected by gateway', ['phone' => $normalised, 'summary' => $summary]);
            return ['ok' => false, 'error' => (string) $summary];
        }

        $first  = $recipients[0];
        $status = (string) ($first['status'] ?? 'Unknown');

        // "Success" means queued for delivery; anything else is a failure now.
        if (stripos($status, 'success') === false) {
            return [
                'ok'     => false,
                'error'  => $status . ' (' . ($first['statusCode'] ?? '?') . ')',
                'status' => $status,
            ];
        }

        return [
            'ok'     => true,
            'ref'    => (string) ($first['messageId'] ?? ''),
            'cost'   => $this->parseCost((string) ($first['cost'] ?? '')),
            'status' => $status,
        ];
    }

    /** Check the credentials without spending money on a message. */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMS is not configured.'];
        }

        $url = (strtolower($this->username) === 'sandbox'
                ? 'https://api.sandbox.africastalking.com'
                : 'https://api.africastalking.com')
             . '/version1/user?username=' . urlencode($this->username);

        $response = $this->post($url, null, 'GET');

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $balance = $response['json']['UserData']['balance'] ?? null;

        return [
            'ok'      => true,
            'balance' => $balance,
            'message' => $balance !== null
                ? 'Connected. Account balance: ' . $balance
                : 'Connected to Africa\'s Talking.',
        ];
    }

    private function parseCost(string $cost): ?float
    {
        // Gateway returns e.g. "KES 0.8000"
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $cost, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * @return array{ok:bool, json?:array, body?:string, error?:string}
     */
    private function post(string $url, ?array $fields, string $method = 'POST'): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP cURL is not available on this server.'];
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'apiKey: ' . $this->apiKey,
            ],
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields ?? []);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($ch, $options);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::error('SMS gateway unreachable', ['error' => $err]);
            return ['ok' => false, 'error' => 'Could not reach the SMS gateway: ' . $err];
        }

        $json = json_decode((string) $body, true);

        if ($status < 200 || $status >= 300) {
            Logger::error('SMS gateway error', ['status' => $status, 'body' => mb_substr((string) $body, 0, 400)]);

            $message = is_array($json)
                ? ($json['SMSMessageData']['Message'] ?? $json['message'] ?? '')
                : '';

            if ($status === 401) {
                $message = 'The gateway rejected your credentials. Check the username and API key.';
            }

            return [
                'ok'    => false,
                'error' => $message !== '' ? (string) $message : 'SMS gateway returned HTTP ' . $status . '.',
            ];
        }

        return ['ok' => true, 'json' => is_array($json) ? $json : [], 'body' => (string) $body];
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
