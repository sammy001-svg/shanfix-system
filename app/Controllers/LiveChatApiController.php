<?php
namespace App\Controllers;

use App\Core\ClientAuth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\PartnerAuth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\LiveChat\Conversations;
use App\Services\LiveChat\Departments;

/**
 * The live chat endpoint the website widget talks to.
 *
 * Public: no session and no CSRF token, because the caller is a stranger
 * on a marketing page who has not signed in to anything. What stands in
 * for a session is the conversation's own token, which the visitor's
 * browser holds and we store only as a hash.
 *
 * That makes this one of the few genuinely open doors in the system, so
 * everything here is written on the assumption that it will be poked at:
 * every input is capped and cleaned, every action is rate limited per
 * address, and no answer ever says more about a conversation than the
 * person holding its token is entitled to know.
 *
 * It lives in the system rather than in site/ because that is where the
 * data and the staff inbox are. The website reaches it at the same
 * origin — the root .htaccess sends anything that is not a file in
 * site/ through to the system — so there is no CORS to arrange and no
 * second copy of the rules.
 */
class LiveChatApiController extends Controller
{
    /**
     * Requests a minute, per action.
     *
     * Counted per conversation wherever there is a token, and only per
     * address for 'start', which is the one action with no token yet.
     *
     * That distinction matters here more than it would elsewhere. Kenyan
     * mobile networks put very many subscribers behind one public
     * address, so a per-address budget is really a budget shared by
     * every Safaricom customer reading the site at that moment. An open
     * widget polls twenty times a minute; a handful of visitors on the
     * same carrier would have exhausted a per-address poll limit between
     * them and started seeing failures none of them caused.
     *
     * So 'start' is deliberately generous — enough that a busy office or
     * a whole carrier is never mistaken for an attacker, while still
     * stopping a script opening thousands.
     */
    private const LIMITS = [
        'start' => 40,     // per address: shared by everyone behind it
        'send'  => 30,     // per conversation: a fast typist sends six
        'poll'  => 60,     // per conversation: it polls 20 times a minute
        'other' => 20,     // per conversation
    ];

    // -----------------------------------------------------------------
    // What the widget needs before anybody types
    // -----------------------------------------------------------------

    /**
     * Departments, greeting, and whether anybody is about.
     *
     * Also the cheapest possible answer to "is chat switched on", which
     * is what the widget asks before drawing itself.
     */
    public function hello(Request $request): void
    {
        if (!Settings::bool('livechat_enabled', true)) {
            Response::json(['ok' => false, 'enabled' => false]);
        }

        $this->limitByIp('other');

        $ask = Settings::bool('livechat_ask_department', true);

        Response::json([
            'ok'          => true,
            'enabled'     => true,
            'greeting'    => (string) Settings::get('livechat_greeting', 'Hello. How can we help you today?'),
            'open'        => Conversations::atTheDesk(),
            'offline'     => (string) Settings::get('livechat_offline_message', ''),
            'ask'         => $ask,
            'departments' => $ask ? Departments::forVisitor() : [],
            'company'     => Settings::company()['name'] ?? '',
        ]);
    }

    // -----------------------------------------------------------------
    // Talking
    // -----------------------------------------------------------------

    public function start(Request $request): void
    {
        $this->on();
        $this->limitByIp('start');

        $result = Conversations::start([
            'department' => $request->input('department'),
            'name'       => (string) $request->input('name', ''),
            'email'      => (string) $request->input('email', ''),
            'phone'      => (string) $request->input('phone', ''),
            'message'    => (string) $request->input('message', ''),
            'page_url'   => (string) $request->input('page_url', ''),
            'page_title' => (string) $request->input('page_title', ''),
            'referrer'   => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip'         => $this->ip(),
        ] + $this->whoIsThis());

        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => $result['error']], 400);
        }

        // Out of hours, say so in the thread itself rather than only in
        // the widget's furniture, so it is still there when they scroll
        // back and wonder whether anybody saw it.
        if (!Conversations::atTheDesk()) {
            $offline = trim((string) Settings::get('livechat_offline_message', ''));

            if ($offline !== '') {
                Conversations::systemSays($result['id'], $offline);
            }
        }

        Response::json([
            'ok'    => true,
            'token' => $result['token'],
            'ref'   => $result['ref'],
            // So the widget can recognise the message it has already
            // drawn when the next poll hands it back.
            'id'    => $result['message_id'],
        ]);
    }

    public function send(Request $request): void
    {
        $this->on();

        $conversation = $this->mine($request);
        $this->limitByConversation($conversation, 'send');
        $result       = Conversations::visitorSays($conversation, (string) $request->input('message', ''));

        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => $result['error']], 400);
        }

        Response::json(['ok' => true, 'id' => $result['id']]);
    }

    /**
     * Anything said since the last message the widget drew.
     *
     * Returns the visitor's own messages too, so a second tab or a
     * reloaded page catches up without a separate history call.
     */
    public function poll(Request $request): void
    {
        $this->on();

        $conversation = $this->mine($request);
        $this->limitByConversation($conversation, 'poll');
        $after        = max(0, (int) $request->query('after', 0));

        $messages = array_map(
            static fn(array $m): array => [
                'id'     => (int) $m['id'],
                'sender' => $m['sender'],
                'who'    => $m['sender_name'],
                'body'   => $m['body'],
                'at'     => date('H:i', strtotime($m['created_at'])),
            ],
            Conversations::messagesSince((int) $conversation['id'], $after)
        );

        Response::json([
            'ok'       => true,
            'status'   => $conversation['status'],
            'open'     => Conversations::atTheDesk(),
            'rated'    => $conversation['rating'] !== null,
            'messages' => $messages,
        ]);
    }

    /** The visitor is done. */
    public function close(Request $request): void
    {
        $this->on();

        $conversation = $this->mine($request);
        $this->limitByConversation($conversation, 'other');

        Conversations::close((int) $conversation['id'], null, 'Ended by the visitor');

        Response::json(['ok' => true]);
    }

    /** How did we do? */
    public function rate(Request $request): void
    {
        $this->on();

        $conversation = $this->mine($request);
        $this->limitByConversation($conversation, 'other');

        $done = Conversations::rate(
            (int) $conversation['id'],
            (int) $request->input('score', 0),
            (string) $request->input('comment', '')
        );

        Response::json(['ok' => $done]);
    }

    // -----------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------

    /** Refuse everything when chat is switched off. */
    private function on(): void
    {
        if (!Settings::bool('livechat_enabled', true)) {
            Response::json(['ok' => false, 'error' => 'Chat is not available.'], 503);
        }
    }

    /**
     * The conversation this caller's token opens.
     *
     * A bad token gets the same answer as a missing conversation, so that
     * guessing tokens cannot be used to learn which ones exist.
     */
    private function mine(Request $request): array
    {
        $token = (string) ($request->input('token', '') ?: $request->query('token', ''));
        $found = Conversations::byToken(trim($token));

        if (!$found) {
            Response::json(['ok' => false, 'error' => 'This conversation has expired. Start a new one.'], 404);
        }

        return $found;
    }

    /**
     * Whether this visitor is somebody we already know.
     *
     * A client or partner reading the website while signed in to their
     * portal should not have to introduce themselves, and whoever answers
     * ought to know who they are talking to. Sessions here are read-only
     * and entirely optional — a stranger simply has none.
     */
    private function whoIsThis(): array
    {
        $who = [];

        if ($client = ClientAuth::user()) {
            $who['client_id']      = (int) $client['client_id'];
            $who['client_user_id'] = (int) $client['id'];
        }

        if ($partner = PartnerAuth::user()) {
            $who['partner_id'] = (int) $partner['id'];
        }

        return $who;
    }

    /**
     * Counted on the address. Only for actions with no token yet.
     *
     * Blunt by necessity: everyone behind one address shares this
     * budget, which on a mobile network can be a great many people.
     */
    private function limitByIp(string $action): void
    {
        $this->countAgainst($action, 'ip:' . hash('sha256', $this->ip()));
    }

    /**
     * Counted on the conversation, which is one visitor by construction.
     *
     * Unaffected by how many other people share their address, so a busy
     * carrier cannot make one customer's chat stop working.
     */
    private function limitByConversation(array $conversation, string $action): void
    {
        $this->countAgainst($action, 'c:' . $conversation['id']);
    }

    private function countAgainst(string $action, string $who): void
    {
        $limit  = self::LIMITS[$action] ?? self::LIMITS['other'];
        $window = date('Y-m-d H:i:00');
        $bucket = 'livechat:' . $action . ':' . substr(hash('sha256', $who), 0, 24);

        Database::run(
            'INSERT INTO bulk_rate_counters (bucket, window_start, hits) VALUES (:b, :w, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['b' => $bucket, 'w' => $window]
        );

        $hits = (int) Database::scalar(
            'SELECT hits FROM bulk_rate_counters WHERE bucket = :b AND window_start = :w',
            ['b' => $bucket, 'w' => $window]
        );

        if ($hits > $limit) {
            header('Retry-After: ' . (60 - (int) date('s')));
            Response::json(['ok' => false, 'error' => 'Too many requests. Wait a moment.'], 429);
        }
    }

    private function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
