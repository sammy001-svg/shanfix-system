<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * WhatsApp through Meta's official Business Cloud API.
 *
 * Sending is an HTTPS call and receiving is a webhook, which is why this
 * works on ordinary hosting where a QR-scanned browser session could not:
 * nothing has to stay connected between requests.
 *
 * The one rule that shapes everything here is Meta's customer service
 * window. A business may only send a freely typed message within 24 hours
 * of the customer's last message. Outside that window Meta rejects the
 * text and will only accept a pre-approved template. The inbox therefore
 * has to know the state of that window before anyone types, not after.
 */
class WhatsApp
{
    /**
     * Graph API version.
     *
     * Pinned rather than floating: Meta changes behaviour between versions
     * and an unannounced upgrade underneath a working integration is not a
     * surprise anyone wants on a Friday.
     */
    public const API_VERSION = 'v21.0';

    private const ENDPOINT = 'https://graph.facebook.com';

    /** How long Meta allows a freely typed reply, in hours. */
    public const SERVICE_WINDOW_HOURS = 24;

    public static function enabled(): bool
    {
        return Settings::bool('whatsapp_enabled')
            && trim((string) Settings::get('whatsapp_phone_number_id', '')) !== ''
            && trim((string) Settings::get('whatsapp_access_token', '')) !== '';
    }

    /**
     * Whether a plain message can still be sent on this conversation.
     *
     * Erring on the side of "no" when we have never heard from them: a
     * number we have not been written to first is outside the window by
     * definition.
     */
    public static function windowOpen(?string $lastInboundAt): bool
    {
        if (!$lastInboundAt) {
            return false;
        }

        return (time() - strtotime($lastInboundAt)) < self::SERVICE_WINDOW_HOURS * 3600;
    }

    /** Minutes left in the window, or 0 when it has closed. */
    public static function windowRemaining(?string $lastInboundAt): int
    {
        if (!self::windowOpen($lastInboundAt)) {
            return 0;
        }

        return (int) max(0, floor(
            (self::SERVICE_WINDOW_HOURS * 3600 - (time() - strtotime($lastInboundAt))) / 60
        ));
    }

    /**
     * Find or start the thread for a number.
     *
     * Matching to a client on the way in is what makes a WhatsApp chat
     * useful later — it lands on their profile rather than being a loose
     * conversation with a phone number.
     */
    public static function conversationFor(string $waId, ?string $name = null): array
    {
        $waId = preg_replace('/\D+/', '', $waId);

        $existing = Database::first(
            'SELECT * FROM whatsapp_conversations WHERE wa_id = :w LIMIT 1',
            ['w' => $waId]
        );

        if ($existing) {
            // A name only arrives with an inbound message, so fill it in
            // the first time we learn it rather than overwriting later.
            if ($name && !$existing['display_name']) {
                Database::update('whatsapp_conversations', ['display_name' => mb_substr($name, 0, 160)],
                    ['id' => $existing['id']]);
                $existing['display_name'] = $name;
            }

            return $existing;
        }

        $clientId = self::matchClient($waId);

        $id = Database::insert('whatsapp_conversations', [
            'wa_id'        => $waId,
            'display_name' => $name ? mb_substr($name, 0, 160) : null,
            'client_id'    => $clientId,
        ]);

        return Database::first('SELECT * FROM whatsapp_conversations WHERE id = :id', ['id' => $id]);
    }

    /**
     * The client this number belongs to, if any.
     *
     * Numbers are stored inconsistently by people typing them — 0712…,
     * +254712…, 254 712… — so both sides are reduced to digits and
     * compared on the last nine, which is the part that identifies a
     * Kenyan subscriber regardless of how the prefix was written.
     */
    private static function matchClient(string $waId): ?int
    {
        $tail = substr(preg_replace('/\D+/', '', $waId), -9);

        if (strlen($tail) < 9) {
            return null;
        }

        // Compared in PHP rather than SQL on purpose. The obvious query
        // uses REGEXP_REPLACE to strip punctuation from the stored number,
        // and that function does not exist before MySQL 8 — plenty of
        // cPanel accounts still run 5.7, where it would fail outright.
        // This runs only when a number writes in for the first time, over
        // a table of hundreds, so the cost is irrelevant.
        $rows = Database::all(
            "SELECT id, phone FROM clients WHERE phone IS NOT NULL AND phone <> ''"
        );

        foreach ($rows as $row) {
            $stored = substr(preg_replace('/\D+/', '', (string) $row['phone']), -9);

            if ($stored !== '' && $stored === $tail) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * Send a plain text message.
     *
     * @return array{ok:bool, id:?string, error:?string}
     */
    public static function sendText(string $waId, string $body): array
    {
        return self::call('messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => preg_replace('/\D+/', '', $waId),
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $body],
        ]);
    }

    /**
     * Send an approved template — the only thing Meta accepts once the
     * 24-hour window has closed.
     */
    public static function sendTemplate(string $waId, string $template, string $language = 'en', array $params = []): array
    {
        $components = [];

        if ($params !== []) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    static fn($p): array => ['type' => 'text', 'text' => (string) $p],
                    $params
                ),
            ];
        }

        return self::call('messages', [
            'messaging_product' => 'whatsapp',
            'to'                => preg_replace('/\D+/', '', $waId),
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    /**
     * Record a message we sent and keep the thread's timestamps current.
     */
    public static function recordOutbound(int $conversationId, string $body, ?string $waMessageId, ?int $userId, array $result): int
    {
        $id = Database::insert('whatsapp_messages', [
            'conversation_id' => $conversationId,
            'wa_message_id'   => $waMessageId,
            'direction'       => 'out',
            'msg_type'        => 'text',
            'body'            => $body,
            'status'          => $result['ok'] ? 'sent' : 'failed',
            'error'           => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 400),
            'sent_by'         => $userId,
            'wa_timestamp'    => date('Y-m-d H:i:s'),
        ]);

        Database::update('whatsapp_conversations', [
            'last_message_at' => date('Y-m-d H:i:s'),
        ], ['id' => $conversationId]);

        return $id;
    }

    /**
     * Store an inbound message.
     *
     * Returns false when this one has been seen before — Meta delivers
     * webhooks at least once, so the same message legitimately arrives
     * twice and must not appear twice in the thread.
     */
    public static function recordInbound(array $conversation, array $message, ?string $name = null): bool
    {
        $waMessageId = (string) ($message['id'] ?? '');

        if ($waMessageId !== '' && Database::first(
            'SELECT id FROM whatsapp_messages WHERE wa_message_id = :m LIMIT 1',
            ['m' => $waMessageId]
        )) {
            return false;
        }

        $type = (string) ($message['type'] ?? 'unknown');

        // Each media kind nests its details under its own key, but they
        // share a shape, so one branch handles all of them.
        $body = null;
        $mediaId = $mime = $filename = null;

        if ($type === 'text') {
            $body = (string) ($message['text']['body'] ?? '');
        } elseif (isset($message[$type]) && is_array($message[$type])) {
            $part     = $message[$type];
            $mediaId  = $part['id']       ?? null;
            $mime     = $part['mime_type'] ?? null;
            $filename = $part['filename'] ?? null;
            $body     = $part['caption']  ?? null;
        }

        $when = isset($message['timestamp'])
            ? date('Y-m-d H:i:s', (int) $message['timestamp'])
            : date('Y-m-d H:i:s');

        Database::insert('whatsapp_messages', [
            'conversation_id' => (int) $conversation['id'],
            'wa_message_id'   => $waMessageId ?: null,
            'direction'       => 'in',
            'msg_type'        => $type,
            'body'            => $body,
            'media_id'        => $mediaId,
            'media_mime'      => $mime,
            'media_name'      => $filename,
            'status'          => 'delivered',
            'wa_timestamp'    => $when,
        ]);

        // An inbound message reopens the 24-hour window, which is the
        // single most consequential field in this table.
        Database::run(
            'UPDATE whatsapp_conversations
                SET last_message_at = :now,
                    last_inbound_at = :now2,
                    unread_count    = unread_count + 1,
                    status          = :open
              WHERE id = :id',
            [
                'now'  => $when,
                'now2' => $when,
                'open' => 'open',
                'id'   => $conversation['id'],
            ]
        );

        if ($name && !$conversation['display_name']) {
            Database::update('whatsapp_conversations',
                ['display_name' => mb_substr($name, 0, 160)],
                ['id' => $conversation['id']]);
        }

        return true;
    }

    /** Apply a delivery receipt from Meta to the message it refers to. */
    public static function applyStatus(array $status): void
    {
        $id    = (string) ($status['id'] ?? '');
        $state = (string) ($status['status'] ?? '');

        if ($id === '' || !in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        Database::run(
            'UPDATE whatsapp_messages SET status = :s WHERE wa_message_id = :m',
            ['s' => $state, 'm' => $id]
        );
    }

    /**
     * Confirm a webhook really came from Meta.
     *
     * Meta signs the raw body with the app secret. Compared with
     * hash_equals so the check does not leak the answer through timing,
     * and computed over the body exactly as received — re-encoding it
     * first would change the bytes and never match.
     */
    public static function signatureValid(string $rawBody, ?string $header): bool
    {
        $secret = trim((string) Settings::get('whatsapp_app_secret', ''));

        if ($secret === '' || !$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }

    /**
     * One call to the Graph API.
     *
     * @return array{ok:bool, id:?string, error:?string, raw:?string}
     */
    private static function call(string $path, array $payload): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'id' => null, 'error' => 'WhatsApp is not connected. An administrator sets it up in Settings.', 'raw' => null];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'id' => null, 'error' => 'This server has no cURL, which is needed to reach WhatsApp.', 'raw' => null];
        }

        $phoneId = trim((string) Settings::get('whatsapp_phone_number_id', ''));
        $token   = trim((string) Settings::get('whatsapp_access_token', ''));

        $url = self::ENDPOINT . '/' . self::API_VERSION . '/' . $phoneId . '/' . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::warning('WhatsApp request failed: ' . $curlErr);
            return ['ok' => false, 'id' => null, 'error' => 'Could not reach WhatsApp: ' . $curlErr, 'raw' => null];
        }

        $decoded = json_decode((string) $body, true);

        if ($status >= 200 && $status < 300) {
            return [
                'ok'    => true,
                'id'    => $decoded['messages'][0]['id'] ?? null,
                'error' => null,
                'raw'   => (string) $body,
            ];
        }

        // Meta's errors are genuinely useful — pass the message through
        // rather than replacing it with something vaguer.
        $message = $decoded['error']['message'] ?? ('WhatsApp returned HTTP ' . $status);

        Logger::warning('WhatsApp API error: ' . $message);

        return ['ok' => false, 'id' => null, 'error' => $message, 'raw' => (string) $body];
    }
}
