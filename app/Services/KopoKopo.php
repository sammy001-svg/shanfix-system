<?php
namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;

/**
 * KopoKopo API client — M-Pesa STK Push (Incoming Payments).
 *
 * Flow:
 *   1. token()            OAuth2 client-credentials access token
 *   2. stkPush()          POST /api/v1/incoming_payments — Safaricom prompts the customer
 *   3. KopoKopo calls our callback URL when the customer accepts or declines
 *   4. verifySignature()  confirms the callback really came from KopoKopo
 *   5. pollStatus()       optional fallback if the callback never arrives
 *
 * Credentials live in Settings (kopokopo_*) and are entered in the UI.
 * Docs: https://api-docs.kopokopo.com
 */
class KopoKopo
{
    private const BASE_SANDBOX    = 'https://sandbox.kopokopo.com';
    private const BASE_PRODUCTION = 'https://api.kopokopo.com';

    private const TOKEN_CACHE_KEY = 'kopokopo_token_cache';

    public function __construct(
        private ?string $clientId = null,
        private ?string $clientSecret = null,
        private ?string $tillNumber = null,
        private ?string $env = null
    ) {
        $this->clientId     ??= (string) Settings::get('kopokopo_client_id', '');
        $this->clientSecret ??= (string) Settings::get('kopokopo_client_secret', '');
        $this->tillNumber   ??= (string) Settings::get('kopokopo_till_number', '');
        $this->env          ??= (string) Settings::get('kopokopo_env', 'sandbox');
    }

    public function baseUrl(): string
    {
        return $this->env === 'production' ? self::BASE_PRODUCTION : self::BASE_SANDBOX;
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->tillNumber !== '';
    }

    // -- OAuth ---------------------------------------------------------

    /**
     * Access token, reused from the settings cache until shortly before it expires.
     *
     * @return array{ok:bool, token?:string, error?:string}
     */
    public function token(bool $forceRefresh = false): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'KopoKopo credentials are not configured.'];
        }

        if (!$forceRefresh) {
            $cached = json_decode((string) Settings::get(self::TOKEN_CACHE_KEY, ''), true);
            if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60 && !empty($cached['token'])) {
                return ['ok' => true, 'token' => $cached['token']];
            }
        }

        $response = $this->request(
            'POST',
            $this->baseUrl() . '/oauth/token',
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
            ['Content-Type: application/json', 'Accept: application/json']
        );

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $body = $response['json'] ?? [];

        if (empty($body['access_token'])) {
            Logger::error('KopoKopo token response missing access_token', ['body' => $response['body']]);
            return ['ok' => false, 'error' => 'KopoKopo did not return an access token. Check your Client ID and Secret.'];
        }

        Settings::set(self::TOKEN_CACHE_KEY, json_encode([
            'token'      => $body['access_token'],
            'expires_at' => time() + (int) ($body['expires_in'] ?? 3600),
        ]));

        return ['ok' => true, 'token' => $body['access_token']];
    }

    // -- STK Push ------------------------------------------------------

    /**
     * Ask KopoKopo to prompt $phone for $amount.
     *
     * @param string $phone       Normalised 2547XXXXXXXX
     * @param float  $amount      KES; KopoKopo expects whole shillings
     * @param string $callbackUrl Publicly reachable HTTPS endpoint
     *
     * @return array{ok:bool, location?:string, id?:string, error?:string, request:array, response:string}
     */
    public function stkPush(
        string $phone,
        float $amount,
        string $callbackUrl,
        string $reference,
        string $firstName = 'Client',
        string $lastName = '',
        ?string $email = null,
        array $metadata = []
    ): array {
        $token = $this->token();

        if (!$token['ok']) {
            return ['ok' => false, 'error' => $token['error'], 'request' => [], 'response' => ''];
        }

        $payload = [
            'payment_channel' => 'M-PESA STK Push',
            'till_number'     => $this->tillNumber,
            'subscriber'      => [
                'first_name'   => $firstName !== '' ? $firstName : 'Client',
                'last_name'    => $lastName !== '' ? $lastName : '-',
                'phone_number' => '+' . $phone,
            ],
            'amount' => [
                'currency' => 'KES',
                // KopoKopo rejects fractional shillings on STK Push.
                'value'    => (int) round($amount),
            ],
            'metadata' => array_merge([
                'reference' => $reference,
            ], $metadata),
            '_links' => [
                'callback_url' => $callbackUrl,
            ],
        ];

        if ($email) {
            $payload['subscriber']['email'] = $email;
        }

        $response = $this->request(
            'POST',
            $this->baseUrl() . '/api/v1/incoming_payments',
            $payload,
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token['token'],
            ]
        );

        $result = [
            'request'  => $payload,
            'response' => $response['body'] ?? '',
        ];

        if (!$response['ok']) {
            return array_merge($result, ['ok' => false, 'error' => $response['error']]);
        }

        // A successful request returns 201 with the resource URL in Location.
        $location = $response['headers']['location'] ?? '';

        if ($location === '') {
            $apiError = $this->extractError($response['json'] ?? []);
            Logger::error('KopoKopo STK push missing Location header', ['body' => $response['body']]);

            return array_merge($result, [
                'ok'    => false,
                'error' => $apiError ?: 'KopoKopo accepted the request but returned no payment reference.',
            ]);
        }

        return array_merge($result, [
            'ok'       => true,
            'location' => $location,
            'id'       => basename(parse_url($location, PHP_URL_PATH) ?: ''),
        ]);
    }

    /**
     * Query a payment request when the callback has not arrived.
     *
     * @return array{ok:bool, status?:string, receipt?:string, body?:array, error?:string}
     */
    public function pollStatus(string $locationUrl): array
    {
        $token = $this->token();

        if (!$token['ok']) {
            return ['ok' => false, 'error' => $token['error']];
        }

        $response = $this->request('GET', $locationUrl, null, [
            'Accept: application/json',
            'Authorization: Bearer ' . $token['token'],
        ]);

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $data     = $response['json']['data']['attributes'] ?? [];
        $resource = $data['event']['resource'] ?? [];

        return [
            'ok'      => true,
            'status'  => (string) ($data['status'] ?? 'Pending'),
            'receipt' => $resource['reference'] ?? null,
            'body'    => $response['json'] ?? [],
        ];
    }

    /**
     * Register the callback URL with KopoKopo so it starts sending webhooks.
     * Run once from Settings after the credentials are saved.
     *
     * @return array{ok:bool, error?:string, body?:string}
     */
    public function subscribeWebhook(string $eventType, string $callbackUrl): array
    {
        $token = $this->token();

        if (!$token['ok']) {
            return ['ok' => false, 'error' => $token['error']];
        }

        $response = $this->request(
            'POST',
            $this->baseUrl() . '/api/v1/webhook_subscriptions',
            [
                'event_type' => $eventType,
                'url'        => $callbackUrl,
                'scope'      => 'till',
                'scope_reference' => $this->tillNumber,
            ],
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token['token'],
            ]
        );

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'], 'body' => $response['body'] ?? ''];
        }

        return ['ok' => true, 'body' => $response['body'] ?? ''];
    }

    // -- Webhook security ----------------------------------------------

    /**
     * KopoKopo signs each webhook with HMAC-SHA256 over the raw body,
     * keyed by your API key. Reject anything that does not match.
     */
    public static function verifySignature(string $rawBody, ?string $signature): bool
    {
        $apiKey = (string) Settings::get('kopokopo_api_key', '');

        if ($apiKey === '' || !is_string($signature) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $apiKey);

        return hash_equals($expected, $signature);
    }

    /**
     * Flatten a callback body into the fields we store.
     *
     * @return array{status:string, receipt:?string, amount:?float, phone:?string,
     *               kopokopo_id:?string, description:?string}
     */
    public static function parseCallback(array $body): array
    {
        $data       = $body['data'] ?? [];
        $attributes = $data['attributes'] ?? [];
        $event      = $attributes['event'] ?? [];
        $resource   = $event['resource'] ?? [];

        // KopoKopo uses "Success"/"Failed" on the request and "Received" on the resource.
        $rawStatus = strtolower((string) ($attributes['status'] ?? $resource['status'] ?? ''));

        $status = match (true) {
            in_array($rawStatus, ['success', 'received', 'settled'], true) => 'success',
            in_array($rawStatus, ['failed', 'rejected'], true)             => 'failed',
            $rawStatus === 'cancelled'                                     => 'cancelled',
            default                                                        => 'pending',
        };

        $errors = $event['errors'] ?? null;
        $description = null;

        if (is_array($errors) && $errors !== []) {
            $description = is_string($errors[0] ?? null)
                ? $errors[0]
                : json_encode($errors);
        } elseif (is_string($errors) && $errors !== '') {
            $description = $errors;
        }

        $phone = $resource['sender_phone_number'] ?? null;
        if (is_string($phone)) {
            $phone = ltrim($phone, '+');
        }

        return [
            'status'      => $status,
            'receipt'     => $resource['reference'] ?? null,
            'amount'      => isset($resource['amount']) ? (float) $resource['amount'] : null,
            'phone'       => $phone,
            'kopokopo_id' => $data['id'] ?? ($resource['id'] ?? null),
            'description' => $description,
        ];
    }

    // -- HTTP ----------------------------------------------------------

    /**
     * @return array{ok:bool, status:int, body:string, json:?array, headers:array, error:string}
     */
    private function request(string $method, string $url, ?array $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'headers' => [],
                'error' => 'PHP cURL extension is not available on this server. Ask your host to enable it.',
            ];
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);

        $raw      = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('KopoKopo cURL failure', ['url' => $url, 'error' => $curlErr]);
            return [
                'ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'headers' => [],
                'error' => 'Could not reach KopoKopo: ' . $curlErr,
            ];
        }

        $rawHeaders = substr($raw, 0, $headSize);
        $body       = substr($raw, $headSize);
        $json       = json_decode($body, true);

        $parsedHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        if ($status < 200 || $status >= 300) {
            $apiError = $this->extractError(is_array($json) ? $json : []);
            Logger::error('KopoKopo API error', ['url' => $url, 'status' => $status, 'body' => mb_substr($body, 0, 800)]);

            return [
                'ok' => false, 'status' => $status, 'body' => $body,
                'json' => is_array($json) ? $json : null, 'headers' => $parsedHeaders,
                'error' => $apiError ?: ('KopoKopo returned HTTP ' . $status . '.'),
            ];
        }

        return [
            'ok' => true, 'status' => $status, 'body' => $body,
            'json' => is_array($json) ? $json : null, 'headers' => $parsedHeaders, 'error' => '',
        ];
    }

    /** Pull a human-readable message out of a KopoKopo error body. */
    private function extractError(array $json): string
    {
        if (!empty($json['error_description'])) {
            return (string) $json['error_description'];
        }

        if (!empty($json['error']) && is_string($json['error'])) {
            return $json['error'];
        }

        if (!empty($json['errors'])) {
            $errors = $json['errors'];

            if (is_string($errors)) {
                return $errors;
            }

            if (is_array($errors)) {
                $parts = [];
                foreach ($errors as $key => $val) {
                    $parts[] = is_array($val)
                        ? (is_string($key) ? $key . ': ' : '') . implode(', ', array_map('strval', $val))
                        : (string) $val;
                }
                return implode('; ', $parts);
            }
        }

        return '';
    }
}
