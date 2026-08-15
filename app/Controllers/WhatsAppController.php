<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\WhatsApp;

/**
 * The company WhatsApp as a shared inbox.
 *
 * One number, many people answering it — so a conversation belongs to the
 * company rather than to whoever happened to reply, and every message is
 * recorded against the person who sent it.
 */
class WhatsAppController extends Controller
{
    public function index(Request $request): void
    {
        $search = (string) $request->query('q', '');
        $show   = (string) $request->query('show', 'open');

        $where  = ['1=1'];
        $params = [];

        if ($show === 'open')     { $where[] = "c.status = 'open'"; }
        if ($show === 'closed')   { $where[] = "c.status = 'closed'"; }
        if ($show === 'unread')   { $where[] = 'c.unread_count > 0'; }
        if ($show === 'mine')     { $where[] = 'c.assigned_to = :me'; $params['me'] = Auth::id(); }

        if ($search !== '') {
            $where[] = '(c.display_name LIKE :q1 OR c.wa_id LIKE :q2 OR cl.name LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }

        $clause = implode(' AND ', $where);

        $conversations = Database::all(
            "SELECT c.*, cl.name AS client_name,
                    (SELECT m.body FROM whatsapp_messages m
                      WHERE m.conversation_id = c.id
                   ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT m.direction FROM whatsapp_messages m
                      WHERE m.conversation_id = c.id
                   ORDER BY m.id DESC LIMIT 1) AS last_direction,
                    (SELECT m.msg_type FROM whatsapp_messages m
                      WHERE m.conversation_id = c.id
                   ORDER BY m.id DESC LIMIT 1) AS last_type
               FROM whatsapp_conversations c
          LEFT JOIN clients cl ON cl.id = c.client_id
              WHERE {$clause}
           ORDER BY c.last_message_at DESC, c.id DESC
              LIMIT 100",
            $params
        );

        $active = null;
        $messages = [];

        // Opening a specific thread from the list, WhatsApp-style: the
        // conversation list stays put and the thread fills the panel.
        $openId = (int) $request->query('c', 0);

        if ($openId > 0) {
            $active = $this->findOrFail($openId);
            $messages = $this->messagesFor($openId);
            $this->markRead($openId);
        } elseif ($conversations) {
            $active = $this->findOrFail((int) $conversations[0]['id']);
            $messages = $this->messagesFor((int) $active['id']);
            $this->markRead((int) $active['id']);
        }

        $this->view('whatsapp/index', [
            'title'         => 'WhatsApp',
            'conversations' => $conversations,
            'active'        => $active,
            'messages'      => $messages,
            'filters'       => ['search' => $search, 'show' => $show],
            'connected'     => WhatsApp::enabled(),
            'number'        => Settings::get('whatsapp_number_display', ''),
            'windowOpen'    => $active ? WhatsApp::windowOpen($active['last_inbound_at']) : false,
            'windowLeft'    => $active ? WhatsApp::windowRemaining($active['last_inbound_at']) : 0,
        ]);
    }

    /** Send a typed reply on an existing conversation. */
    public function send(Request $request): void
    {
        $this->authorize('whatsapp.send');

        $conversation = $this->findOrFail($request->paramInt('id'));
        $body         = trim((string) $request->input('body', ''));

        if ($body === '') {
            Response::json(['ok' => false, 'error' => 'Nothing to send.'], 400);
        }

        if (!WhatsApp::enabled()) {
            Response::json(['ok' => false, 'error' => 'WhatsApp is not connected yet.'], 400);
        }

        // Checked here and not only in the view: the window can close
        // between the page loading and someone pressing send, and Meta
        // would reject it with a message nobody would understand.
        if (!WhatsApp::windowOpen($conversation['last_inbound_at'])) {
            Response::json([
                'ok'    => false,
                'error' => 'More than 24 hours have passed since ' . ($conversation['display_name'] ?: 'this customer')
                         . ' last wrote. WhatsApp only allows an approved template until they reply again.',
            ], 409);
        }

        $result = WhatsApp::sendText($conversation['wa_id'], $body);

        $id = WhatsApp::recordOutbound(
            (int) $conversation['id'],
            $body,
            $result['id'],
            (int) Auth::id(),
            $result
        );

        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => $result['error']], 502);
        }

        ActivityLog::record('whatsapp_sent', 'whatsapp', (int) $conversation['id'],
            'Replied to ' . ($conversation['display_name'] ?: $conversation['wa_id']));

        Response::json([
            'ok'      => true,
            'message' => Database::first(
                'SELECT m.*, u.name AS sender FROM whatsapp_messages m
              LEFT JOIN users u ON u.id = m.sent_by
                  WHERE m.id = :id',
                ['id' => $id]
            ),
        ]);
    }

    /** Start a conversation with a number that has not written to us. */
    public function start(Request $request): void
    {
        $this->authorize('whatsapp.send');

        $phone = normalize_phone((string) $request->input('phone', ''));

        if ($phone === null) {
            Session::error('That does not look like a valid phone number.');
            Response::to('/whatsapp');
        }

        $conversation = WhatsApp::conversationFor($phone, trim((string) $request->input('name', '')) ?: null);

        Session::warning(
            'Thread opened. WhatsApp only allows an approved template until '
            . 'they message you first — a typed reply will be refused.'
        );

        Response::to('/whatsapp?c=' . $conversation['id']);
    }

    /** New messages since the browser last asked. */
    public function poll(Request $request): void
    {
        $conversation = $this->findOrFail($request->paramInt('id'));
        $since        = (int) $request->query('since', 0);

        $rows = $this->messagesFor((int) $conversation['id'], $since);

        if ($rows) {
            $this->markRead((int) $conversation['id']);
        }

        Response::json([
            'ok'         => true,
            'messages'   => $rows,
            'windowOpen' => WhatsApp::windowOpen(
                Database::first('SELECT last_inbound_at FROM whatsapp_conversations WHERE id = :id',
                    ['id' => $conversation['id']])['last_inbound_at'] ?? null
            ),
        ]);
    }

    /** Unread count for the sidebar badge. */
    public function unread(Request $request): void
    {
        Response::json([
            'ok'    => true,
            'count' => (int) Database::scalar(
                'SELECT COALESCE(SUM(unread_count), 0) FROM whatsapp_conversations', [], 0
            ),
        ]);
    }

    public function close(Request $request): void
    {
        $this->authorize('whatsapp.send');
        $conversation = $this->findOrFail($request->paramInt('id'));

        Database::update('whatsapp_conversations',
            ['status' => $conversation['status'] === 'open' ? 'closed' : 'open'],
            ['id' => $conversation['id']]);

        Response::to('/whatsapp?c=' . $conversation['id']);
    }

    /** Attach a conversation to a client, so it shows on their profile. */
    public function assignClient(Request $request): void
    {
        $this->authorize('whatsapp.send');
        $conversation = $this->findOrFail($request->paramInt('id'));

        $clientId = (int) $request->input('client_id', 0);

        Database::update('whatsapp_conversations',
            ['client_id' => $clientId > 0 ? $clientId : null],
            ['id' => $conversation['id']]);

        Session::success('Conversation linked.');
        Response::to('/whatsapp?c=' . $conversation['id']);
    }

    private function messagesFor(int $conversationId, int $sinceId = 0): array
    {
        // Ordered by when the message actually happened, not by when we
        // stored it. Usually the same, but a webhook delivered late — Meta
        // retries for hours — would otherwise drop an old message at the
        // bottom of the thread as though it had just been said.
        return Database::all(
            'SELECT m.*, u.name AS sender
               FROM whatsapp_messages m
          LEFT JOIN users u ON u.id = m.sent_by
              WHERE m.conversation_id = :id AND m.id > :since
           ORDER BY COALESCE(m.wa_timestamp, m.created_at) ASC, m.id ASC
              LIMIT 300',
            ['id' => $conversationId, 'since' => $sinceId]
        );
    }

    private function markRead(int $conversationId): void
    {
        Database::update('whatsapp_conversations', ['unread_count' => 0], ['id' => $conversationId]);
    }

    /**
     * The conversation, with the client's name alongside it.
     *
     * The join matters: the thread header offers a link through to the
     * client, and without it the view reaches for a key that is not there.
     */
    private function findOrFail(int $id): array
    {
        $row = Database::first(
            'SELECT c.*, cl.name AS client_name
               FROM whatsapp_conversations c
          LEFT JOIN clients cl ON cl.id = c.client_id
              WHERE c.id = :id',
            ['id' => $id]
        );

        if (!$row) {
            throw new HttpException(404, 'That conversation does not exist.');
        }

        return $row;
    }
}
