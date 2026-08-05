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

class ReminderController extends Controller
{
    public function index(Request $request): void
    {
        $filter = (string) $request->query('filter', 'pending');
        $mine   = $request->query('scope', 'mine') !== 'all';

        $where  = ['1=1'];
        $params = [];

        if ($mine) {
            $where[] = 'r.user_id = :uid';
            $params['uid'] = Auth::id();
        }

        switch ($filter) {
            case 'overdue':
                $where[] = 'r.is_done = 0 AND r.remind_at < NOW()';
                break;
            case 'today':
                $where[] = 'r.is_done = 0 AND DATE(r.remind_at) = CURDATE()';
                break;
            case 'upcoming':
                $where[] = 'r.is_done = 0 AND r.remind_at > NOW()';
                break;
            case 'done':
                $where[] = 'r.is_done = 1';
                break;
            default:
                $filter  = 'pending';
                $where[] = 'r.is_done = 0';
        }

        $clause = implode(' AND ', $where);

        $reminders = Database::all(
            "SELECT r.*, l.name AS lead_name, l.lead_number, l.stage,
                    c.name AS client_name, u.name AS user_name
               FROM reminders r
          LEFT JOIN leads l ON l.id = r.lead_id
          LEFT JOIN clients c ON c.id = r.client_id
          LEFT JOIN users u ON u.id = r.user_id
              WHERE {$clause}
           ORDER BY r.is_done ASC, r.remind_at ASC
              LIMIT 200",
            $params
        );

        $scopeSql = $mine ? 'AND user_id = ' . (int) Auth::id() : '';

        $counts = Database::first(
            "SELECT
                COUNT(CASE WHEN is_done = 0 THEN 1 END) AS pending,
                COUNT(CASE WHEN is_done = 0 AND remind_at < NOW() THEN 1 END) AS overdue,
                COUNT(CASE WHEN is_done = 0 AND DATE(remind_at) = CURDATE() THEN 1 END) AS today,
                COUNT(CASE WHEN is_done = 1 THEN 1 END) AS done
               FROM reminders
              WHERE 1=1 {$scopeSql}"
        );

        $this->view('reminders/index', [
            'title'     => 'My Reminders',
            'reminders' => $reminders,
            'counts'    => $counts,
            'filter'    => $filter,
            'mine'      => $mine,
        ]);
    }

    public function store(Request $request): void
    {
        $v = new Validator($request->all());
        $v->require('title', 'Reminder')
          ->maxLen('title', 200, 'Reminder')
          ->require('remind_at', 'Date and time');

        if (!strtotime((string) $request->input('remind_at', ''))) {
            $v->custom('remind_at', false, 'Enter a valid date and time.');
        }

        if ($v->fails()) {
            $v->redirectBack('/reminders');
        }

        Database::insert('reminders', [
            'user_id'    => Auth::id(),
            'lead_id'    => $request->int('lead_id') ?: null,
            'client_id'  => $request->int('client_id') ?: null,
            'title'      => (string) $request->input('title'),
            'notes'      => $request->input('notes') ?: null,
            'remind_at'  => date('Y-m-d H:i:s', strtotime((string) $request->input('remind_at'))),
            'created_by' => Auth::id(),
        ]);

        Session::success('Reminder added.');
        Response::back('/reminders');
    }

    public function complete(Request $request): void
    {
        $reminder = $this->findOrFail($request->paramInt('id'));

        Database::update('reminders', [
            'is_done'      => 1,
            'completed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $reminder['id']]);

        Session::success('Reminder marked as done.');
        Response::back('/reminders');
    }

    public function reopen(Request $request): void
    {
        $reminder = $this->findOrFail($request->paramInt('id'));

        Database::update('reminders', [
            'is_done'      => 0,
            'completed_at' => null,
        ], ['id' => $reminder['id']]);

        Session::info('Reminder reopened.');
        Response::back('/reminders');
    }

    public function destroy(Request $request): void
    {
        $reminder = $this->findOrFail($request->paramInt('id'));

        Database::delete('reminders', ['id' => $reminder['id']]);

        Session::success('Reminder deleted.');
        Response::back('/reminders');
    }

    /**
     * A reminder is editable by its owner, whoever created it, or an admin.
     */
    private function findOrFail(int $id): array
    {
        $reminder = Database::first('SELECT * FROM reminders WHERE id = :id', ['id' => $id]);

        if (!$reminder) {
            throw new HttpException(404, 'That reminder does not exist.');
        }

        $mine = (int) $reminder['user_id'] === Auth::id()
             || (int) $reminder['created_by'] === Auth::id();

        if (!$mine && !Auth::is('admin', 'manager')) {
            throw new HttpException(403, 'That reminder belongs to another team member.');
        }

        return $reminder;
    }
}
