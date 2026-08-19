<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

/**
 * Internal team chat: direct messages and named channels.
 *
 * Delivery is AJAX polling rather than websockets — shared cPanel hosting
 * cannot hold long-lived socket connections.
 */
class ChatController extends Controller
{
    public function index(Request $request): void
    {
        $conversationId = $request->paramInt('id');

        $conversations = $this->conversationsFor(Auth::id());

        // Default to the most recently active conversation.
        if ($conversationId === 0 && $conversations !== []) {
            $conversationId = (int) $conversations[0]['id'];
        }

        $conversation = null;
        $messages     = [];
        $members      = [];

        if ($conversationId > 0) {
            $conversation = $this->assertMember($conversationId);

            $messages = array_reverse(Database::all(
                'SELECT m.*, u.name AS author, u.avatar_color
                   FROM chat_messages m
                   JOIN users u ON u.id = m.user_id
                  WHERE m.conversation_id = :cid AND m.deleted_at IS NULL
               ORDER BY m.id DESC
                  LIMIT 80',
                ['cid' => $conversationId]
            ));

            $members = Database::all(
                'SELECT u.id, u.name, u.role, u.avatar_color, u.last_seen_at
                   FROM chat_participants p
                   JOIN users u ON u.id = p.user_id
                  WHERE p.conversation_id = :cid
               ORDER BY u.name',
                ['cid' => $conversationId]
            );

            $this->markRead($conversationId);
        }

        // Colleagues without an existing DM, for the "start a chat" list.
        $colleagues = Database::all(
            "SELECT u.id, u.name, u.role, u.job_title, u.avatar_color, u.last_seen_at
               FROM users u
              WHERE u.is_active = 1 AND u.id <> :me
           ORDER BY u.name",
            ['me' => Auth::id()]
        );

        $this->view('chat/index', [
            'title'          => 'Team Chat',
            'conversations'  => $conversations,
            'conversation'   => $conversation,
            'conversationId' => $conversationId,
            'messages'       => $messages,
            'members'        => $members,
            'colleagues'     => $colleagues,
            'lastId'         => $messages ? (int) end($messages)['id'] : 0,
        ]);
    }

    /** Open (or create) a one-to-one conversation with another user. */
    public function openDirect(Request $request): void
    {
        $otherId = $request->paramInt('userId');
        $meId    = (int) Auth::id();

        if ($otherId === $meId) {
            Session::error('You cannot start a chat with yourself.');
            Response::to('/chat');
        }

        $other = Database::first(
            'SELECT id, name FROM users WHERE id = :id AND is_active = 1',
            ['id' => $otherId]
        );

        if (!$other) {
            throw new HttpException(404, 'That team member no longer exists.');
        }

        // Stable key so the pair always maps to one conversation.
        $dmKey = min($meId, $otherId) . ':' . max($meId, $otherId);

        $existing = Database::first(
            'SELECT id FROM chat_conversations WHERE dm_key = :key',
            ['key' => $dmKey]
        );

        if ($existing) {
            Response::to('/chat/' . $existing['id']);
        }

        $conversationId = Database::transaction(static function () use ($dmKey, $meId, $otherId) {
            $id = Database::insert('chat_conversations', [
                'type'       => 'dm',
                'dm_key'     => $dmKey,
                'created_by' => $meId,
            ]);

            foreach ([$meId, $otherId] as $uid) {
                Database::insert('chat_participants', [
                    'conversation_id' => $id,
                    'user_id'         => $uid,
                ]);
            }

            return $id;
        });

        Response::to('/chat/' . $conversationId);
    }

    public function createChannel(Request $request): void
    {
        $v = new Validator($request->all());
        $v->require('name', 'Channel name')
          ->maxLen('name', 60, 'Channel name')
          ->maxLen('description', 255, 'Description');

        if ($v->fails()) {
            $v->redirectBack('/chat');
        }

        // #production, #sales — normalise to a slug so mentions stay predictable.
        $name = strtolower(trim(preg_replace('/[^a-zA-Z0-9\- ]/', '', (string) $request->input('name'))));
        $name = trim(preg_replace('/[\s\-]+/', '-', $name), '-');

        if ($name === '') {
            Session::error('Use letters and numbers for the channel name.');
            Response::to('/chat');
        }

        $duplicate = Database::first(
            "SELECT id FROM chat_conversations WHERE type = 'channel' AND name = :name",
            ['name' => $name]
        );

        if ($duplicate) {
            Session::info('#' . $name . ' already exists.');
            Response::to('/chat/' . $duplicate['id']);
        }

        $memberIds = array_map('intval', $request->array('members'));
        $memberIds[] = (int) Auth::id();
        $memberIds = array_values(array_unique(array_filter($memberIds)));

        $conversationId = Database::transaction(static function () use ($name, $request, $memberIds) {
            $id = Database::insert('chat_conversations', [
                'type'        => 'channel',
                'name'        => $name,
                'description' => $request->input('description') ?: null,
                'created_by'  => Auth::id(),
            ]);

            foreach ($memberIds as $uid) {
                Database::insert('chat_participants', [
                    'conversation_id' => $id,
                    'user_id'         => $uid,
                ]);
            }

            return $id;
        });

        Session::success('#' . $name . ' created.');
        Response::to('/chat/' . $conversationId);
    }

    public function send(Request $request): void
    {
        $conversationId = $request->int('conversation_id');
        $conversation   = $this->assertMember($conversationId);

        $body       = trim((string) $request->input('body', ''));
        $attachment = $request->file('attachment');

        if ($body === '' && $attachment === null) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Type a message first.'], 422);
            }
            Session::error('Type a message first.');
            Response::to('/chat/' . $conversationId);
        }

        if (mb_strlen($body) > 5000) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Message is too long (5000 characters max).'], 422);
            }
            Session::error('That message is too long.');
            Response::to('/chat/' . $conversationId);
        }

        $storedPath = null;
        $storedName = null;

        if ($attachment !== null) {
            $storedPath = $this->storeUpload($attachment, 'chat');
            $storedName = mb_substr((string) ($attachment['name'] ?? 'attachment'), 0, 180);
        }

        $messageId = Database::insert('chat_messages', [
            'conversation_id' => $conversationId,
            'user_id'         => Auth::id(),
            'body'            => $body !== '' ? $body : null,
            'attachment_path' => $storedPath,
            'attachment_name' => $storedName,
        ]);

        $this->markRead($conversationId);

        // Anyone named in the message is told, through the bell and out to
        // their phone. Only people already in the conversation can be named,
        // so a mention can never point somebody at a channel they cannot open.
        if ($body !== '') {
            $mentioned = \App\Services\Mentions::find(
                $body,
                \App\Services\Mentions::membersOf($conversationId)
            );

            if ($mentioned !== []) {
                $me    = Auth::user();
                $where = !empty($conversation['is_group'])
                    ? '#' . ($conversation['name'] ?? 'a channel')
                    : 'a direct message';

                \App\Services\StaffNotifier::notify($mentioned, [
                    'event'       => 'chat_mention',
                    'title'       => $me['name'] . ' mentioned you in ' . $where,
                    'body'        => mb_substr($body, 0, 300),
                    'link'        => '/chat/' . $conversationId,
                    'entity_type' => 'chat',
                    'entity_id'   => $conversationId,
                ], ['email' => true, 'sms' => true]);
            }
        }

        if ($request->wantsJson()) {
            $me = Auth::user();

            Response::json([
                'ok'      => true,
                'message' => [
                    'id'             => $messageId,
                    'body'           => $body,
                    'author'         => $me['name'],
                    'initials'       => initials($me['name']),
                    'color'          => $me['avatar_color'],
                    'time'           => date('H:i'),
                    'is_mine'        => true,
                    'attachment_url' => $storedPath ? url('files/' . $storedPath) : null,
                    'attachment_name'=> $storedName,
                ],
            ]);
        }

        Response::to('/chat/' . $conversationId);
    }

    /**
     * Search everything this person can already see.
     *
     * Scoped through chat_participants rather than filtered afterwards, so
     * a channel somebody left, or was never in, cannot surface here — the
     * search must not become a way to read a private conversation.
     */
    public function search(Request $request): void
    {
        $q = trim((string) $request->query('q', ''));

        $results = [];

        // Two characters would match most of the alphabet and return
        // everything, which is no more useful than nothing.
        if (mb_strlen($q) >= 3) {
            $results = Database::all(
                "SELECT m.id, m.body, m.created_at, m.conversation_id,
                        u.name AS author, u.avatar_color,
                        c.name AS channel_name, c.is_group
                   FROM chat_messages m
                   JOIN chat_conversations c ON c.id = m.conversation_id
                   JOIN chat_participants p ON p.conversation_id = m.conversation_id
                                           AND p.user_id = :me
                   JOIN users u ON u.id = m.user_id
                  WHERE m.deleted_at IS NULL
                    AND m.body LIKE :q
               ORDER BY m.id DESC
                  LIMIT 60",
                ['me' => Auth::id(), 'q' => '%' . $q . '%']
            );
        }

        $this->view('chat/search', [
            'title'   => 'Search chat',
            'q'       => $q,
            'results' => $results,
            'tooShort' => $q !== '' && mb_strlen($q) < 3,
        ]);
    }

    /** Polled by the browser for messages newer than the last one it has. */
    public function poll(Request $request): void
    {
        $conversationId = (int) $request->query('conversation_id', 0);
        $after          = (int) $request->query('after', 0);

        $this->assertMember($conversationId);

        $rows = Database::all(
            'SELECT m.*, u.name AS author, u.avatar_color
               FROM chat_messages m
               JOIN users u ON u.id = m.user_id
              WHERE m.conversation_id = :cid AND m.id > :after AND m.deleted_at IS NULL
           ORDER BY m.id ASC
              LIMIT 60',
            ['cid' => $conversationId, 'after' => $after]
        );

        $meId = (int) Auth::id();

        $messages = array_map(static fn($m) => [
            'id'              => (int) $m['id'],
            'body'            => $m['body'] ?? '',
            'author'          => $m['author'],
            'initials'        => initials($m['author']),
            'color'           => $m['avatar_color'],
            'time'            => date('H:i', strtotime($m['created_at'])),
            'is_mine'         => (int) $m['user_id'] === $meId,
            'attachment_url'  => $m['attachment_path'] ? url('files/' . $m['attachment_path']) : null,
            'attachment_name' => $m['attachment_name'],
        ], $rows);

        if ($rows !== []) {
            $this->markRead($conversationId);
        }

        Response::json(['ok' => true, 'messages' => $messages]);
    }

    public function unreadCount(Request $request): void
    {
        $unread = (int) Database::scalar(
            'SELECT COUNT(*)
               FROM chat_messages m
               JOIN chat_participants p ON p.conversation_id = m.conversation_id
              WHERE p.user_id = :uid
                AND m.user_id <> :uid2
                AND m.deleted_at IS NULL
                AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)',
            ['uid' => Auth::id(), 'uid2' => Auth::id()],
            0
        );

        Response::json(['ok' => true, 'unread' => $unread]);
    }

    public function deleteMessage(Request $request): void
    {
        $id = $request->paramInt('id');

        $message = Database::first('SELECT * FROM chat_messages WHERE id = :id', ['id' => $id]);

        if (!$message) {
            throw new HttpException(404, 'Message not found.');
        }

        if ((int) $message['user_id'] !== (int) Auth::id() && !Auth::is('admin')) {
            throw new HttpException(403, 'You can only delete your own messages.');
        }

        // Soft delete keeps the thread readable and the audit trail intact.
        Database::update('chat_messages', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);

        Response::to('/chat/' . $message['conversation_id']);
    }

    public function leaveChannel(Request $request): void
    {
        $conversationId = $request->paramInt('id');
        $conversation   = $this->assertMember($conversationId);

        if ($conversation['type'] !== 'channel') {
            Session::error('You cannot leave a direct message.');
            Response::to('/chat/' . $conversationId);
        }

        Database::delete('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id'         => Auth::id(),
        ]);

        Session::info('You left #' . $conversation['name'] . '.');
        Response::to('/chat');
    }

    // -- Internals -----------------------------------------------------

    /**
     * Conversations the user belongs to, newest activity first, with the
     * unread count and a preview of the last message.
     */
    private function conversationsFor(int $userId): array
    {
        return Database::all(
            "SELECT cc.id, cc.type, cc.name, cc.description,
                    lm.body AS last_body,
                    lm.attachment_name AS last_attachment,
                    lm.created_at AS last_at,
                    lu.name AS last_author,
                    other.name AS other_name,
                    other.avatar_color AS other_color,
                    other.last_seen_at AS other_seen,
                    (SELECT COUNT(*) FROM chat_messages m2
                      WHERE m2.conversation_id = cc.id
                        AND m2.user_id <> :uid3
                        AND m2.deleted_at IS NULL
                        AND (p.last_read_at IS NULL OR m2.created_at > p.last_read_at)) AS unread
               FROM chat_participants p
               JOIN chat_conversations cc ON cc.id = p.conversation_id
          LEFT JOIN chat_messages lm ON lm.id = (
                    SELECT MAX(m3.id) FROM chat_messages m3
                     WHERE m3.conversation_id = cc.id AND m3.deleted_at IS NULL)
          LEFT JOIN users lu ON lu.id = lm.user_id
          LEFT JOIN chat_participants op ON op.conversation_id = cc.id
                    AND op.user_id <> :uid2 AND cc.type = 'dm'
          LEFT JOIN users other ON other.id = op.user_id
              WHERE p.user_id = :uid
           ORDER BY COALESCE(lm.created_at, cc.created_at) DESC",
            ['uid' => $userId, 'uid2' => $userId, 'uid3' => $userId]
        );
    }

    /** Load a conversation, or 403 if the current user is not in it. */
    private function assertMember(int $conversationId): array
    {
        if ($conversationId <= 0) {
            throw new HttpException(404, 'Conversation not found.');
        }

        $conversation = Database::first(
            'SELECT cc.*
               FROM chat_conversations cc
               JOIN chat_participants p
                 ON p.conversation_id = cc.id AND p.user_id = :uid
              WHERE cc.id = :cid',
            ['cid' => $conversationId, 'uid' => Auth::id()]
        );

        if (!$conversation) {
            throw new HttpException(403, 'You are not a member of that conversation.');
        }

        // A DM shows the other person's name rather than a channel name.
        if ($conversation['type'] === 'dm') {
            $other = Database::first(
                'SELECT u.name, u.avatar_color, u.job_title, u.role, u.last_seen_at
                   FROM chat_participants p
                   JOIN users u ON u.id = p.user_id
                  WHERE p.conversation_id = :cid AND p.user_id <> :uid
                  LIMIT 1',
                ['cid' => $conversationId, 'uid' => Auth::id()]
            );

            $conversation['display_name'] = $other['name'] ?? 'Direct message';
            $conversation['display_sub']  = $other['job_title'] ?? label_of($other['role'] ?? '');
            $conversation['other_color']  = $other['avatar_color'] ?? '#0C2B4A';
            $conversation['other_seen']   = $other['last_seen_at'] ?? null;
        } else {
            $conversation['display_name'] = '#' . $conversation['name'];
            $conversation['display_sub']  = $conversation['description'] ?: 'Team channel';
        }

        return $conversation;
    }

    private function markRead(int $conversationId): void
    {
        Database::update(
            'chat_participants',
            ['last_read_at' => date('Y-m-d H:i:s')],
            ['conversation_id' => $conversationId, 'user_id' => Auth::id()]
        );
    }
}
