<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
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
            // Whether this person may change who is in the channel.
            'canModerate'    => $conversation !== null && $this->canModerate($conversation),
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

        // Everyone else in the conversation, throttled — see the method.
        $this->alertParticipants(
            $conversationId,
            $conversation,
            $body,
            $storedName,
            $mentioned ?? []
        );

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

    /**
     * Whether this person may change who is in a channel.
     *
     * Management can, anywhere. So can whoever created the channel — they
     * set it up, and needing an administrator to add one more person to
     * your own channel is the kind of friction that stops people using it.
     */
    private function canModerate(array $conversation): bool
    {
        return Auth::can('chat.moderate')
            || (int) ($conversation['created_by'] ?? 0) === (int) Auth::id();
    }

    public function addMember(Request $request): void
    {
        $conversationId = $request->paramInt('id');
        $conversation   = $this->assertMember($conversationId);

        if ($conversation['type'] !== 'channel') {
            Session::error('Only channels have a member list.');
            Response::to('/chat/' . $conversationId);
        }

        if (!$this->canModerate($conversation)) {
            Session::error('Only an administrator or the channel creator can add people.');
            Response::to('/chat/' . $conversationId);
        }

        $userIds = array_filter(array_map('intval', (array) $request->input('user_ids', [])));
        $added   = 0;

        foreach ($userIds as $userId) {
            $user = Database::first(
                'SELECT id, name FROM users WHERE id = :id AND is_active = 1',
                ['id' => $userId]
            );

            if (!$user) {
                continue;
            }

            try {
                Database::insert('chat_participants', [
                    'conversation_id' => $conversationId,
                    'user_id'         => $userId,
                ]);
                $added++;
            } catch (\Throwable) {
                continue;
            }

            \App\Services\StaffNotifier::notify([$userId], [
                'event'       => 'chat_added',
                'title'       => Auth::user()['name'] . ' added you to #' . $conversation['name'],
                'body'        => $conversation['description'] ?: null,
                'link'        => '/chat/' . $conversationId,
                'entity_type' => 'chat',
                'entity_id'   => $conversationId,
            ], ['email' => true, 'sms' => false]);
        }

        if ($added === 0) {
            Session::error('Nobody was added — pick at least one person who is not already in the channel.');
            Response::to('/chat/' . $conversationId);
        }

        ActivityLog::record('chat_member_added', 'chat', $conversationId,
            $added . ' person(s) added to #' . $conversation['name']);

        Session::success($added === 1 ? 'One person added to the channel.' : $added . ' people added to the channel.');
        Response::to('/chat/' . $conversationId);
    }

    public function removeMember(Request $request): void
    {
        $conversationId = $request->paramInt('id');
        $conversation   = $this->assertMember($conversationId);

        if ($conversation['type'] !== 'channel') {
            Session::error('Only channels have a member list.');
            Response::to('/chat/' . $conversationId);
        }

        if (!$this->canModerate($conversation)) {
            Session::error('Only an administrator or the channel creator can remove people.');
            Response::to('/chat/' . $conversationId);
        }

        $userId = $request->paramInt('userId');

        if ($userId === (int) $conversation['created_by']) {
            Session::error('The person who created the channel cannot be removed from it.');
            Response::to('/chat/' . $conversationId);
        }

        $user = Database::first('SELECT name FROM users WHERE id = :id', ['id' => $userId]);

        Database::delete('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
        ]);

        ActivityLog::record('chat_member_removed', 'chat', $conversationId,
            ($user['name'] ?? 'Someone') . ' removed from #' . $conversation['name']);

        Session::success(($user['name'] ?? 'They') . ' has been removed from the channel.');
        Response::to('/chat/' . $conversationId);
    }

    /**
     * Tell the people in a conversation that a message is waiting.
     *
     * The hard part is not sending; it is not sending too much. A chat is a
     * back-and-forth, and alerting on every message would text somebody a
     * dozen times about one exchange — each costing an SMS unit, and none of
     * them saying anything the first did not. So a person is told only when
     * all of this holds:
     *
     *   - it is not their own message
     *   - they were not named in it (a mention already alerted them, louder)
     *   - they are not looking at the conversation right now
     *   - they have not already been told about it inside the cooldown
     *
     * @param array<int,int> $mentioned already alerted by name
     */
    private function alertParticipants(
        int $conversationId,
        array $conversation,
        string $body,
        ?string $attachmentName,
        array $mentioned
    ): void {
        if (!Settings::bool('chat_alerts_enabled', true)) {
            return;
        }

        $cooldown = max(1, Settings::int('chat_alert_cooldown', 15));
        $active   = max(1, Settings::int('chat_alert_active_mins', 3));

        $candidates = Database::all(
            'SELECT p.user_id, p.last_read_at
               FROM chat_participants p
              WHERE p.conversation_id = :c AND p.user_id <> :me',
            ['c' => $conversationId, 'me' => Auth::id()]
        );

        $mentionedIds = array_map('intval', $mentioned);
        $tell         = [];

        foreach ($candidates as $row) {
            $userId = (int) $row['user_id'];

            if (in_array($userId, $mentionedIds, true)) {
                continue;
            }

            // Reading it as we speak: the message is already in front of them.
            if ($row['last_read_at'] !== null
                && (time() - strtotime((string) $row['last_read_at'])) < $active * 60) {
                continue;
            }

            $recent = Database::first(
                "SELECT id FROM staff_notifications
                  WHERE user_id = :u AND event = 'chat_message'
                    AND entity_type = 'chat' AND entity_id = :c
                    AND created_at > DATE_SUB(NOW(), INTERVAL {$cooldown} MINUTE)
                  LIMIT 1",
                ['u' => $userId, 'c' => $conversationId]
            );

            if ($recent) {
                continue;
            }

            $tell[] = $userId;
        }

        if ($tell === []) {
            return;
        }

        $me    = Auth::user();
        $where = $conversation['type'] === 'channel'
            ? '#' . ($conversation['name'] ?? 'a channel')
            : 'a direct message';

        // A line of what was actually said, so the alert is worth opening.
        $excerpt = $body !== ''
            ? mb_substr($body, 0, 160) . (mb_strlen($body) > 160 ? '…' : '')
            : ('sent an attachment: ' . ($attachmentName ?: 'a file'));

        \App\Services\StaffNotifier::notify($tell, [
            'event'       => 'chat_message',
            'title'       => $me['name'] . ' messaged you in ' . $where,
            'body'        => $excerpt,
            'link'        => '/chat/' . $conversationId,
            'entity_type' => 'chat',
            'entity_id'   => $conversationId,
        ], [
            'email' => Settings::bool('notify_chat_message_email', true),
            'sms'   => Settings::bool('notify_chat_message_sms', true),
        ]);
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
