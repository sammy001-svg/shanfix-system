<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\ClientNotifier;

/**
 * Staff side of the portal support message thread.
 *
 * Admins and managers see all client threads, sorted by the most recently
 * received message. Clicking one shows the full history and allows a reply.
 * Replying marks the client's side as having an unread message (the badge
 * on their Support nav link).
 */
class PortalSupportController extends Controller
{
    /** All client threads, most recently active first. */
    public function index(Request $request): void
    {
        $threads = Database::all(
            "SELECT c.id AS client_id, c.name AS client_name,
                    COUNT(m.id)                          AS total,
                    SUM(m.sender = 'client' AND m.read_at IS NULL) AS unread_client,
                    SUM(m.sender = 'staff'  AND m.read_at IS NULL) AS unread_staff,
                    MAX(m.created_at)                    AS last_at,
                    (SELECT body FROM portal_messages
                      WHERE client_id = c.id
                   ORDER BY id DESC LIMIT 1)             AS last_body
               FROM portal_messages m
               JOIN clients c ON c.id = m.client_id
           GROUP BY c.id, c.name
           ORDER BY last_at DESC"
        );

        $this->view('support/index', [
            'title'   => 'Portal support',
            'threads' => $threads,
        ]);
    }

    /** One client's thread. */
    public function thread(Request $request): void
    {
        $clientId = (int) $request->param('id');

        $client = Database::first('SELECT id, name FROM clients WHERE id = :id', ['id' => $clientId]);

        if (!$client) {
            throw new HttpException(404, 'Client not found.');
        }

        // Mark all client messages as read (staff opened it).
        Database::query(
            "UPDATE portal_messages SET read_at = NOW()
              WHERE client_id = :c AND sender = 'client' AND read_at IS NULL",
            ['c' => $clientId]
        );

        $messages = Database::all(
            'SELECT m.*, u.name AS staff_name
               FROM portal_messages m
          LEFT JOIN users u ON u.id = m.staff_user_id
             WHERE m.client_id = :c
          ORDER BY m.created_at ASC',
            ['c' => $clientId]
        );

        $this->view('support/thread', [
            'title'    => 'Support — ' . $client['name'],
            'client'   => $client,
            'messages' => $messages,
        ]);
    }

    /** Staff sends a reply. */
    public function reply(Request $request): void
    {
        $clientId = (int) $request->param('id');

        $client = Database::first('SELECT id FROM clients WHERE id = :id', ['id' => $clientId]);

        if (!$client) {
            throw new HttpException(404, 'Client not found.');
        }

        $body = mb_substr(trim((string) $request->input('body', '')), 0, 3000);

        if ($body === '') {
            Session::error('Please write a reply before sending.');
            Response::to('/portal-support/' . $clientId);
        }

        $user = auth_user();

        Database::insert('portal_messages', [
            'client_id'     => $clientId,
            'sender'        => 'staff',
            'body'          => $body,
            'staff_user_id' => $user['id'] ?? null,
        ]);

        // The client's Support badge will show 1 unread when they next load
        // any portal page, because ClientNotifier::unreadMessages() counts
        // portal_messages where sender='staff' and read_at IS NULL.

        // Also put a portal notification so the client's bell fires too.
        ClientNotifier::notify(
            $clientId,
            'support_reply',
            'Reply from our team',
            mb_substr($body, 0, 120),
            '/portal/support'
        );

        Session::success('Reply sent.');
        Response::to('/portal-support/' . $clientId);
    }
}
