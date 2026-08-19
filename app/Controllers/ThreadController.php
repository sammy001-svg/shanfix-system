<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Mentions;
use App\Services\RecordThread;
use App\Services\StaffNotifier;

/**
 * Posting into the discussion attached to a record.
 *
 * Writes to the same chat tables as everything else, so a note left on a
 * job card is a chat message: it appears in search, it can name people,
 * and it carries an attachment the same way.
 */
class ThreadController extends Controller
{
    public function post(Request $request): void
    {
        $this->authorize('chat.use');

        $type  = (string) $request->input('entity_type', '');
        $id    = $request->int('entity_id', 0);
        $title = trim((string) $request->input('title', ''));

        if (!isset(RecordThread::ENTITIES[$type]) || $id <= 0) {
            throw new HttpException(422, 'That is not something a discussion can attach to.');
        }

        $back = RecordThread::ENTITIES[$type]['path'] . $id;

        $body       = trim((string) $request->input('body', ''));
        $attachment = $request->file('attachment');

        if ($body === '' && $attachment === null) {
            Session::error('Write something first.');
            Response::to($back);
        }

        if (mb_strlen($body) > 5000) {
            Session::error('That note is too long.');
            Response::to($back);
        }

        // Only reachable for a record the person can already open, because
        // the form is rendered on that page. Confirm the record is real all
        // the same — the endpoint takes an id from the request.
        if (!$this->recordExists($type, $id)) {
            throw new HttpException(404, 'That record does not exist.');
        }

        $conversationId = RecordThread::findOrCreate($type, $id, $title !== '' ? $title : ucfirst($type) . ' #' . $id);

        // Posting is what puts you in the thread, so you see the replies.
        RecordThread::join($conversationId);

        $storedPath = null;
        $storedName = null;

        if ($attachment !== null) {
            $storedPath = $this->storeUpload($attachment, 'chat');
            $storedName = mb_substr((string) ($attachment['name'] ?? 'attachment'), 0, 180);
        }

        Database::insert('chat_messages', [
            'conversation_id' => $conversationId,
            'user_id'         => Auth::id(),
            'body'            => $body !== '' ? $body : null,
            'attachment_path' => $storedPath,
            'attachment_name' => $storedName,
        ]);

        // Mentions work exactly as they do in chat, and for the same reason:
        // only people already in the thread can be named.
        if ($body !== '') {
            $mentioned = Mentions::find($body, Mentions::membersOf($conversationId));

            if ($mentioned !== []) {
                $me = Auth::user();

                StaffNotifier::notify($mentioned, [
                    'event'       => 'chat_mention',
                    'title'       => $me['name'] . ' mentioned you on ' . ($title !== '' ? $title : $type),
                    'body'        => mb_substr($body, 0, 300),
                    'link'        => $back,
                    'entity_type' => $type,
                    'entity_id'   => $id,
                ], ['email' => true, 'sms' => true]);
            }
        }

        Session::success('Added to the discussion.');
        Response::to($back);
    }

    /** The record a thread is being attached to actually exists. */
    private function recordExists(string $type, int $id): bool
    {
        $table = match ($type) {
            'job'      => 'jobs',
            'client'   => 'clients',
            'document' => 'documents',
            'artwork'  => 'artwork_requests',
            'lead'     => 'leads',
            default    => null,
        };

        if ($table === null) {
            return false;
        }

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM {$table} WHERE id = :id",
            ['id' => $id],
            0
        ) > 0;
    }
}
