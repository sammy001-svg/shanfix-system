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
use App\Services\ClientOtp;

/**
 * Vouching for a client who wants portal access.
 *
 * They gave a name and a phone number and were told nothing. Here an
 * administrator sees the request beside whatever the client list actually
 * holds, and decides.
 *
 * The match is checked here rather than when the request was made. Doing
 * it at submission and saying so would let a stranger test names and
 * numbers until one landed; doing it here means the only person who ever
 * learns whether it matched is somebody who already has the client list
 * in front of them.
 */
class PortalRequestController extends Controller
{
    public function index(Request $request): void
    {
        $this->authorize('clients.manage');

        $status = (string) $request->query('status', 'pending');

        if (!in_array($status, ['pending', 'approved', 'rejected', ''], true)) {
            $status = 'pending';
        }

        $where  = $status !== '' ? 'WHERE r.status = :status' : '';
        $params = $status !== '' ? ['status' => $status] : [];

        $requests = Database::all(
            "SELECT r.*, c.name AS matched_name, c.client_code, u.name AS decided_by_name
               FROM client_access_requests r
          LEFT JOIN clients c ON c.id = r.matched_client_id
          LEFT JOIN users u ON u.id = r.decided_by
              {$where}
           ORDER BY r.created_at DESC",
            $params
        );

        // What each pending request would match, worked out now so the
        // administrator sees the evidence rather than having to go and
        // look it up.
        foreach ($requests as $i => $row) {
            $requests[$i]['candidates'] = $row['status'] === 'pending'
                ? $this->candidatesFor($row)
                : [];
        }

        $this->view('portal/requests', [
            'title'    => 'Portal access requests',
            'requests' => $requests,
            'status'   => $status,
            'counts'   => $this->counts(),
        ]);
    }

    /**
     * Approve one: match it, make the account, and text the code.
     *
     * The administrator has to pick which client it is. Guessing on their
     * behalf is how somebody ends up seeing another company's invoices.
     */
    public function approve(Request $request): void
    {
        $this->authorize('clients.manage');

        $row      = $this->findOrFail($request->paramInt('id'));
        $clientId = $request->int('client_id');

        if ($row['status'] !== 'pending') {
            Session::error('That request has already been decided.');
            Response::to('/portal-requests');
        }

        $client = Database::first('SELECT * FROM clients WHERE id = :id', ['id' => $clientId]);

        if (!$client) {
            Session::error('Choose which client this person belongs to before approving.');
            Response::to('/portal-requests');
        }

        // The phone number is the thing being vouched for: the code goes
        // to the number they gave, so it must be a number we already hold
        // for that client. Otherwise approving would hand access to
        // whoever typed the number.
        if (!$this->phoneMatches($client, $row['phone'])) {
            Session::error(
                'That phone number is not on ' . $client['name'] . "'s record. "
                . 'Add it to the client first if it is genuinely theirs — approving '
                . 'would otherwise send the code to a number we cannot vouch for.'
            );
            Response::to('/portal-requests');
        }

        $email = $row['email'] ?: $client['email'];

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::error(
                'There is no email address to attach the account to. Ask them for one, '
                . 'or add it to the client record first.'
            );
            Response::to('/portal-requests');
        }

        $email = strtolower(trim($email));

        $existing = Database::first('SELECT id FROM client_users WHERE email = :e LIMIT 1', ['e' => $email]);

        $fields = [
            'client_id' => (int) $client['id'],
            'name'      => $row['full_name'],
            'email'     => $email,
            'phone'     => $row['phone'],
            'status'    => 'pending',
        ];

        if ($existing) {
            Database::update('client_users', $fields, ['id' => $existing['id']]);
        } else {
            Database::insert('client_users', $fields);
        }

        // The code is what they will use to set a password. It goes to the
        // number on the client record, which is the whole point of the
        // administrator having checked.
        $issued = ClientOtp::issue($email, 'verify_email');

        if (!$issued['ok']) {
            Session::error($issued['error']);
            Response::to('/portal-requests');
        }

        ClientOtp::send($email, $issued['code'], $row['phone']);

        Database::update('client_access_requests', [
            'status'            => 'approved',
            'matched_client_id' => (int) $client['id'],
            'decided_by'        => Auth::id(),
            'decided_at'        => date('Y-m-d H:i:s'),
        ], ['id' => $row['id']]);

        ActivityLog::record(
            'portal_access_approved',
            'client',
            (int) $client['id'],
            'Gave ' . $row['full_name'] . ' portal access to ' . $client['name']
        );

        Session::success(
            'Approved. A code has been texted to ' . $row['phone']
            . ' and emailed to ' . $email . '. They can set their password with it.'
        );

        Response::to('/portal-requests');
    }

    public function reject(Request $request): void
    {
        $this->authorize('clients.manage');

        $row = $this->findOrFail($request->paramInt('id'));

        if ($row['status'] !== 'pending') {
            Session::error('That request has already been decided.');
            Response::to('/portal-requests');
        }

        Database::update('client_access_requests', [
            'status'        => 'rejected',
            'decided_by'    => Auth::id(),
            'decided_at'    => date('Y-m-d H:i:s'),
            'decision_note' => mb_substr(trim((string) $request->input('note')), 0, 255) ?: null,
        ], ['id' => $row['id']]);

        // Nothing is sent back. Somebody who is not a client learns
        // nothing either way, which is the point.
        ActivityLog::record(
            'portal_access_rejected',
            'client_access_request',
            (int) $row['id'],
            'Turned down a portal request from ' . $row['full_name']
        );

        Session::success('Turned down. Nothing was sent to them.');
        Response::to('/portal-requests');
    }

    // -- Internals ---------------------------------------------------------

    /**
     * Clients this request might be. Matched on the number first, because
     * a phone number is a far better identifier than a name spelled
     * however somebody happened to type it.
     *
     * @return array<int,array<string,mixed>>
     */
    private function candidatesFor(array $row): array
    {
        $digits = preg_replace('/\D/', '', (string) $row['phone']);
        $tail   = $digits !== '' ? substr($digits, -9) : '~none~';

        return Database::all(
            "SELECT id, name, client_code, email, phone, alt_phone
               FROM clients
              WHERE status = 'active'
                AND (
                      REPLACE(REPLACE(REPLACE(IFNULL(phone,''), ' ', ''), '+', ''), '-', '') LIKE :tail
                   OR REPLACE(REPLACE(REPLACE(IFNULL(alt_phone,''), ' ', ''), '+', ''), '-', '') LIKE :tail2
                   OR name LIKE :name
                   OR contact_person LIKE :name2
                )
           ORDER BY name
              LIMIT 8",
            [
                'tail'  => '%' . $tail,
                'tail2' => '%' . $tail,
                'name'  => '%' . $row['full_name'] . '%',
                'name2' => '%' . $row['full_name'] . '%',
            ]
        );
    }

    /** Whether that number is one we already hold for this client. */
    private function phoneMatches(array $client, string $phone): bool
    {
        $wanted = substr(preg_replace('/\D/', '', $phone), -9);

        if ($wanted === '') {
            return false;
        }

        foreach (['phone', 'alt_phone'] as $field) {
            $held = substr(preg_replace('/\D/', '', (string) ($client[$field] ?? '')), -9);

            if ($held !== '' && hash_equals($held, $wanted)) {
                return true;
            }
        }

        return false;
    }

    private function findOrFail(int $id): array
    {
        $row = Database::first('SELECT * FROM client_access_requests WHERE id = :id', ['id' => $id]);

        if (!$row) {
            throw new HttpException(404, 'That request does not exist.');
        }

        return $row;
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM client_access_requests GROUP BY status');

        return array_map('intval', array_column($rows, 'n', 'status'));
    }
}
