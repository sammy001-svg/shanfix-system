<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Engine;
use App\Services\BulkSms\Reports;
use App\Services\BulkSms\Wallet;

/**
 * The developer API — customers' own software sending through us.
 *
 * Answers at the old platform's addresses (/api/v1/sendsms.php and the
 * rest) with the old platform's field names, because live customers have
 * this in their code and a switchover that silently changed a response
 * would break systems we cannot see. The .php in the address is not a
 * file; the router answers it.
 *
 * Authentication is a client id and key in X-Client-Id / X-Api-Key
 * headers, or in the body for callers whose HTTP library makes headers
 * awkward — as before. Keys are checked against a stored hash.
 *
 * No session, no CSRF: the caller is a program with a key.
 */
class BulkSmsApiController extends Controller
{
    /** Requests a minute, as on the old platform. */
    private const LIMITS = ['send' => 60, 'bulk' => 10, 'read' => 120];

    public const BULK_LIMIT = 1000;

    // =================================================================

    /** One message to one number. */
    public function send(Request $request): void
    {
        $account = $this->authenticate($request, 'send');
        $params  = $this->params($request);

        $to      = trim((string) ($params['to'] ?? ''));
        $message = (string) ($params['message'] ?? '');

        if ($to === '' || $message === '') {
            $this->fail('Missing required fields: to, message', 400);
        }

        if (mb_strlen($message) > Engine::MAX_LENGTH) {
            $this->fail('Message exceeds ' . Engine::MAX_LENGTH . ' characters (max 6 SMS segments).', 400);
        }

        if (Engine::normalizePhone($to) === null) {
            $this->fail('Invalid phone number: ' . $to, 400);
        }

        $sender = $this->sender($account, (string) ($params['sender_id'] ?? ''));

        $result = Engine::sendNow((int) $account['id'], [$to], $message, $sender, [
            'source'     => 'api',
            'actor_type' => 'api',
            'actor_id'   => (int) $account['id'],
        ]);

        if (!$result['ok']) {
            $this->fail((string) ($result['error'] ?? 'The message could not be sent.'), 400);
        }

        Response::json([
            'success'         => true,
            'message_id'      => $result['message_ids'][0] ?? null,
            'units_charged'   => round((float) $result['units'], 4),
            'remaining_units' => number_format((float) $result['balance'], 2, '.', ''),
        ]);
    }

    /** One message to many numbers — up to a thousand a call. */
    public function bulk(Request $request): void
    {
        $account = $this->authenticate($request, 'bulk');
        $params  = $this->params($request);

        $message = trim((string) ($params['message'] ?? ''));
        $toRaw   = $params['to'] ?? null;

        if ($message === '') {
            $this->fail('Missing required field: message', 400);
        }

        if (mb_strlen($message) > Engine::MAX_LENGTH) {
            $this->fail('Message exceeds ' . Engine::MAX_LENGTH . ' characters (max 6 SMS segments).', 400);
        }

        if ($toRaw === null) {
            $this->fail('Missing required field: to', 400);
        }

        $phones = is_array($toRaw) ? $toRaw : Engine::splitNumbers((string) $toRaw);
        $phones = array_values(array_filter(array_map(static fn($p): string => trim((string) $p), $phones),
            static fn(string $p): bool => $p !== ''));

        if ($phones === []) {
            $this->fail('No recipients provided in to field', 400);
        }

        if (count($phones) > self::BULK_LIMIT) {
            $this->fail('Maximum ' . self::BULK_LIMIT . ' recipients per request', 400);
        }

        $sender = $this->sender($account, (string) ($params['sender_id'] ?? ''));

        $result = Engine::sendNow((int) $account['id'], $phones, $message, $sender, [
            'source'     => 'api',
            'actor_type' => 'api',
            'actor_id'   => (int) $account['id'],
        ]);

        if (!$result['ok'] && ($result['sent'] ?? 0) === 0) {
            Response::json(['success' => false, 'error' => $result['error'] ?? 'Nothing was sent.'], 400);
        }

        // The old platform's field names, exactly.
        Response::json([
            'success'         => true,
            'total_submitted' => $result['submitted'],
            'sent'            => $result['sent'],
            'failed'          => $result['failed'],
            'invalid_numbers' => $result['invalid'],
            'units_charged'   => round((float) $result['units'], 4),
            'remaining_units' => number_format((float) $result['balance'], 2, '.', ''),
        ]);
    }

    /** What the account holds. */
    public function balance(Request $request): void
    {
        $account = $this->authenticate($request, 'read');

        Response::json([
            'success'     => true,
            'client_name' => Accounts::describe($account)['name'],
            'sms_units'   => round(Wallet::balance((int) $account['id']), 4),
            'currency'    => Settings::currency(),
        ]);
    }

    /** What happened to one message, or to several. */
    public function status(Request $request): void
    {
        $account = $this->authenticate($request, 'read');
        $params  = $this->params($request);

        $many = $params['message_ids'] ?? null;

        if ($many !== null) {
            $ids = array_slice(array_filter(array_map('intval',
                is_array($many) ? $many : explode(',', (string) $many))), 0, 200);

            if ($ids === []) {
                $this->fail('No message ids given', 400);
            }

            $in   = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::all(
                "SELECT id, recipient, status, units_charged, gateway_msg_id, dlr_status,
                        created_at, sent_at, delivered_at, failed_reason
                   FROM bulk_messages WHERE account_id = ? AND id IN ({$in})",
                array_merge([(int) $account['id']], $ids)
            );

            Response::json([
                'success'  => true,
                'messages' => array_map([$this, 'messageShape'], $rows),
            ]);
        }

        $id = (int) ($params['message_id'] ?? 0);

        if ($id < 1) {
            $this->fail('Missing required field: message_id or message_ids', 400);
        }

        $row = Database::first(
            'SELECT id, recipient, status, units_charged, gateway_msg_id, dlr_status,
                    created_at, sent_at, delivered_at, failed_reason
               FROM bulk_messages WHERE id = :id AND account_id = :a',
            ['id' => $id, 'a' => $account['id']]
        );

        if ($row === null) {
            $this->fail('Message not found', 404);
        }

        Response::json(['success' => true] + $this->messageShape($row));
    }

    /** The message log, paged. */
    public function messages(Request $request): void
    {
        $account = $this->authenticate($request, 'read');
        $params  = $this->params($request);

        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));

        [$from, $to] = Reports::range(
            isset($params['from']) ? (string) $params['from'] : null,
            isset($params['to']) ? (string) $params['to'] : null,
            30
        );

        $result = Reports::messages([(int) $account['id']], $from, $to, [
            'status' => (string) ($params['status'] ?? ''),
            'q'      => (string) ($params['recipient'] ?? ''),
        ], $perPage, ($page - 1) * $perPage);

        Response::json([
            'success'  => true,
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($result['total'] / $perPage),
            'messages' => array_map(function (array $m): array {
                return ['campaign_id' => $m['campaign_id'] ? (int) $m['campaign_id'] : null,
                        'sender_id'   => $m['sender_id'],
                        'message'     => $m['message']] + $this->messageShape($m);
            }, $result['rows']),
        ]);
    }

    // =================================================================

    /** @return array<string,mixed> */
    private function messageShape(array $m): array
    {
        return [
            'message_id'     => (int) $m['id'],
            'recipient'      => $m['recipient'],
            'status'         => $m['status'],
            'units_charged'  => (float) $m['units_charged'],
            'gateway_msg_id' => $m['gateway_msg_id'],
            'delivery_status'=> $m['dlr_status'],
            'created_at'     => $m['created_at'],
            'sent_at'        => $m['sent_at'],
            'delivered_at'   => $m['delivered_at'],
            'failed_reason'  => $m['failed_reason'],
        ];
    }

    /**
     * The account behind the key, or an answer and no return.
     *
     * Also applies the rate limit, counted per account per minute with a
     * single atomic statement — a read-then-write would let a burst of
     * simultaneous calls all see the same count and all pass.
     */
    private function authenticate(Request $request, string $bucket): array
    {
        $this->cors();

        if (!Settings::bool('bulk_sms_enabled', true)) {
            $this->fail('The SMS service is unavailable.', 503);
        }

        $params   = $this->params($request);
        $clientId = trim((string) ($_SERVER['HTTP_X_CLIENT_ID'] ?? ($params['client_id'] ?? '')));
        $key      = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ($params['api_key'] ?? '')));

        if ($clientId === '' || $key === '') {
            $this->fail('Unauthorized: Missing Client ID or API Key', 401);
        }

        $account = Accounts::byApiKey($clientId, $key);

        if ($account === null) {
            $this->fail('Unauthorized: Invalid credentials', 401);
        }

        if ($account['status'] !== 'active') {
            $this->fail('Account suspended', 403);
        }

        $limit  = self::LIMITS[$bucket] ?? 60;
        $window = date('Y-m-d H:i:00');
        $name   = $bucket . ':' . $account['id'];

        Database::run(
            'INSERT INTO bulk_rate_counters (bucket, window_start, hits) VALUES (:b, :w, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['b' => $name, 'w' => $window]
        );

        $hits = (int) Database::scalar(
            'SELECT hits FROM bulk_rate_counters WHERE bucket = :b AND window_start = :w',
            ['b' => $name, 'w' => $window]
        );

        if ($hits > $limit) {
            header('Retry-After: ' . (60 - (int) date('s')));
            $this->fail('Rate limit exceeded. Max ' . $limit . ' requests per minute.', 429);
        }

        Database::run('UPDATE bulk_accounts SET api_last_used_at = NOW() WHERE id = :id', ['id' => $account['id']]);

        return $account;
    }

    /**
     * The sender ID to send under: the one asked for, or the account's
     * first approved one when the caller did not say.
     */
    private function sender(array $account, string $asked): string
    {
        if ($asked !== '') {
            return $asked;
        }

        $first = Database::scalar(
            "SELECT sender_id FROM bulk_sender_ids
              WHERE account_id = :a AND status = 'approved' ORDER BY is_default DESC, id LIMIT 1",
            ['a' => $account['id']]
        );

        if ($first === null) {
            $this->fail('No approved sender ID on your account. Please specify sender_id.', 400);
        }

        return (string) $first;
    }

    /** POST fields and a JSON body both, as before. */
    private function params(Request $request): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $raw  = $request->rawBody();
        $json = $raw !== '' ? json_decode($raw, true) : null;

        return $cache = array_merge(is_array($json) ? $json : [], $_GET, $_POST);
    }

    /**
     * Browser callers.
     *
     * Any origin unless the office has named some, which is what the old
     * platform did — the key is what authorises a call, not where the
     * page came from.
     */
    private function cors(): void
    {
        $allowed = array_filter(array_map('trim',
            explode(',', (string) Settings::get('bulk_sms_cors_origins', ''))));
        $origin  = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        if ($allowed === []) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: ' . $allowed[0]);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Client-ID, X-Api-Key');
        header('Access-Control-Max-Age: 86400');
    }

    /** The preflight browsers send before a cross-origin POST. */
    public function preflight(Request $request): void
    {
        $this->cors();
        http_response_code(204);
        exit;
    }

    private function fail(string $error, int $status): never
    {
        Response::json(['success' => false, 'error' => $error], $status);
    }
}
