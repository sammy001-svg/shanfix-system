<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * The discussion attached to a job, client, invoice or artwork request.
 *
 * A thread is an ordinary chat conversation carrying an entity, so it
 * gets messages, attachments, mentions, unread counts and search for
 * free. Nothing here re-implements chat; it only decides which
 * conversation belongs to which record, and who is in it.
 */
class RecordThread
{
    /** What a thread may be attached to, and where it lives. */
    public const ENTITIES = [
        'job'      => ['label' => 'Job card',         'path' => '/jobs/'],
        'client'   => ['label' => 'Client',           'path' => '/clients/'],
        'document' => ['label' => 'Document',         'path' => '/invoices/'],
        'artwork'  => ['label' => 'Artwork request',  'path' => '/artwork/'],
        'lead'     => ['label' => 'Lead',             'path' => '/leads/'],
    ];

    /**
     * The conversation for this record, created on first use.
     *
     * Created lazily rather than alongside every job, so the chat tables
     * do not fill with empty threads nobody ever posted in.
     */
    public static function findOrCreate(string $entityType, int $entityId, string $title): int
    {
        if (!isset(self::ENTITIES[$entityType])) {
            throw new \InvalidArgumentException('Unknown thread type: ' . $entityType);
        }

        $existing = Database::scalar(
            'SELECT id FROM chat_conversations
              WHERE entity_type = :t AND entity_id = :i LIMIT 1',
            ['t' => $entityType, 'i' => $entityId]
        );

        if ($existing) {
            return (int) $existing;
        }

        try {
            return (int) Database::insert('chat_conversations', [
                'type'        => 'record',
                'name'        => mb_substr($title, 0, 120),
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'created_by'  => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Two people opening the record at the same instant both try to
            // create it; the unique index settles it and the loser reads
            // back the winner's row.
            return (int) Database::scalar(
                'SELECT id FROM chat_conversations
                  WHERE entity_type = :t AND entity_id = :i LIMIT 1',
                ['t' => $entityType, 'i' => $entityId],
                0
            );
        }
    }

    /**
     * Put someone in the thread so they see replies and can be mentioned.
     *
     * Joining on posting rather than on opening: reading a job card should
     * not sign you up to every message about it afterwards.
     */
    public static function join(int $conversationId, ?int $userId = null): void
    {
        $userId ??= (int) Auth::id();

        if ($userId <= 0) {
            return;
        }

        try {
            Database::insert('chat_participants', [
                'conversation_id' => $conversationId,
                'user_id'         => $userId,
            ]);
        } catch (\Throwable) {
            // Already a participant.
        }
    }

    /**
     * The thread and its messages for rendering on a record page.
     *
     * Returns null when nobody has posted yet, so the caller can show the
     * empty state without creating a conversation just because a page was
     * opened.
     *
     * @return array{id:int, messages:array, members:array}|null
     */
    public static function load(string $entityType, int $entityId, int $limit = 30): ?array
    {
        $id = Database::scalar(
            'SELECT id FROM chat_conversations
              WHERE entity_type = :t AND entity_id = :i LIMIT 1',
            ['t' => $entityType, 'i' => $entityId]
        );

        if (!$id) {
            return null;
        }

        $id = (int) $id;

        $messages = Database::all(
            'SELECT m.*, u.name AS author, u.avatar_color
               FROM chat_messages m
               JOIN users u ON u.id = m.user_id
              WHERE m.conversation_id = :id AND m.deleted_at IS NULL
           ORDER BY m.id DESC
              LIMIT ' . max(1, $limit),
            ['id' => $id]
        );

        return [
            'id'       => $id,
            'messages' => array_reverse($messages),
            'members'  => Mentions::membersOf($id),
        ];
    }
}
