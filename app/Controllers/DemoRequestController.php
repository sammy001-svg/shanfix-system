<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\DemoRequests;

/**
 * People asking to see one of the systems we sell.
 *
 * Reading them is sales work. Accepting one is not: it sends somebody
 * outside the company the address of a running system and a working
 * login to it, so that sits with an administrator — which is both the
 * right place for it and what was asked for.
 */
class DemoRequestController extends Controller
{
    public function index(Request $request): void
    {
        // Anything whose time is up stops saying it is live. Done on the
        // way in rather than by the nightly sweep alone, so the list is
        // right the moment somebody opens it.
        DemoRequests::sweepExpired();

        $status  = (string) $request->query('status', '');
        $product = (string) $request->query('product', '');

        $where = [];
        $args  = [];

        if (isset(DemoRequests::STATUSES[$status])) {
            $where[] = 'd.status = :status';
            $args['status'] = $status;
        }

        if (isset(DemoRequests::PRODUCTS[$product])) {
            $where[] = 'd.product = :product';
            $args['product'] = $product;
        }

        $sql = 'SELECT d.*, u.name AS decided_by_name
                  FROM demo_requests d
             LEFT JOIN users u ON u.id = d.decided_by';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY FIELD(d.status, 'new', 'accepted', 'expired', 'declined'),
                           d.created_at DESC, d.id DESC
                  LIMIT 200";

        $this->view('demos/index', [
            'title'    => 'Demo requests',
            'requests' => Database::all($sql, $args),
            'statuses' => DemoRequests::STATUSES,
            'products' => DemoRequests::PRODUCTS,
            'filters'  => ['status' => $status, 'product' => $product],
            'waiting'  => DemoRequests::waiting(),
            'canSend'  => \App\Core\Auth::can('demos.manage'),
            'counts'   => $this->counts(),
        ]);
    }

    public function show(Request $request): void
    {
        $demo = DemoRequests::find($request->paramInt('id'));

        if (!$demo) {
            throw new HttpException(404, 'That demo request does not exist.');
        }

        $this->view('demos/show', [
            'title'    => $demo['reference'],
            'demo'     => $demo,
            'statuses' => DemoRequests::STATUSES,
            'products' => DemoRequests::PRODUCTS,
            'canSend'  => \App\Core\Auth::can('demos.manage'),
            'lead'     => $demo['lead_id']
                ? Database::first('SELECT id, lead_number, stage FROM leads WHERE id = :id',
                                  ['id' => $demo['lead_id']])
                : null,
            'decidedBy' => $demo['decided_by']
                ? Database::scalar('SELECT name FROM users WHERE id = :id', ['id' => $demo['decided_by']])
                : null,
        ]);
    }

    /** Accept it, and send them the login. */
    public function accept(Request $request): void
    {
        $this->authorize('demos.manage');

        $id = $request->paramInt('id');

        $result = DemoRequests::accept($id, [
            'demo_url'      => (string) $request->input('demo_url', ''),
            'demo_username' => (string) $request->input('demo_username', ''),
            'demo_password' => (string) $request->input('demo_password', ''),
            'days'          => $request->int('days'),
        ]);

        if (!($result['ok'] ?? false)) {
            Session::error((string) ($result['error'] ?? 'That could not be sent.'));
            Response::to('/demos/' . $id);
        }

        // Said separately rather than folded into one line: "sent by
        // e-mail but not by text" is the thing somebody needs to know,
        // and a cheerful "sent" would hide it.
        foreach (($result['skipped'] ?? []) as $why) {
            Session::warning($why);
        }

        Session::success('Accepted. The details are on their way.');
        Response::to('/demos/' . $id);
    }

    public function resend(Request $request): void
    {
        $this->authorize('demos.manage');

        $id     = $request->paramInt('id');
        $result = DemoRequests::resend($id);

        if (!($result['ok'] ?? false)) {
            Session::error((string) ($result['error'] ?? 'That could not be sent again.'));
        } else {
            foreach (($result['skipped'] ?? []) as $why) {
                Session::warning($why);
            }

            Session::success('Sent again.');
        }

        Response::to('/demos/' . $id);
    }

    public function decline(Request $request): void
    {
        $this->authorize('demos.manage');

        $id     = $request->paramInt('id');
        $result = DemoRequests::decline($id, (string) $request->input('reason', ''));

        if (!($result['ok'] ?? false)) {
            Session::error((string) ($result['error'] ?? 'That could not be declined.'));
        } else {
            Session::success('Marked as declined. Nothing was sent to them — give them a ring.');
        }

        Response::to('/demos/' . $id);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $out = array_fill_keys(array_keys(DemoRequests::STATUSES), 0);

        foreach (Database::all('SELECT status, COUNT(*) AS n FROM demo_requests GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['n'];
        }

        return $out;
    }
}
