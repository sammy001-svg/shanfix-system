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
use App\Core\Validator;
use App\Services\Meetings;

/**
 * Scheduling meetings, running them, and keeping what was said.
 *
 * The room is reachable two ways: signed in, from the meeting page, and by
 * a link anyone can open. Both land in the same room; the guest route is
 * handled by PublicMeetingController, which shares the plumbing in the
 * Meetings service.
 */
class MeetingController extends Controller
{
    public function index(Request $request): void
    {
        $when   = (string) $request->query('when', 'upcoming');
        $mine   = $request->query('mine') === '1';
        $search = (string) $request->query('q', '');

        $where  = ['1=1'];
        $params = [];

        // Default view is what is still to come — a list led by last year's
        // meetings would need scrolling before it was any use.
        if ($when === 'upcoming') {
            $where[] = "m.status IN ('scheduled','in_progress')";
            $where[] = 'm.scheduled_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
        } elseif ($when === 'past') {
            $where[] = "(m.status = 'ended' OR m.scheduled_at < DATE_SUB(NOW(), INTERVAL 1 DAY))";
        } elseif ($when === 'cancelled') {
            $where[] = "m.status = 'cancelled'";
        }

        if ($search !== '') {
            $where[] = '(m.title LIKE :q1 OR m.agenda LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $search . '%';
        }

        if ($mine) {
            $where[] = '(m.host_id = :me1 OR EXISTS (
                            SELECT 1 FROM meeting_participants p
                             WHERE p.meeting_id = m.id AND p.user_id = :me2))';
            $params['me1'] = $params['me2'] = Auth::id();
        }

        $clause = implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM meetings m WHERE {$clause}", $params, 0);
        $pager = $this->paginate($total, 20);

        $order = $when === 'past' ? 'DESC' : 'ASC';

        $meetings = Database::all(
            "SELECT m.*, u.name AS host_name, c.name AS client_name,
                    (SELECT COUNT(*) FROM meeting_participants p WHERE p.meeting_id = m.id) AS invited,
                    (SELECT COUNT(*) FROM meeting_notes n WHERE n.meeting_id = m.id) AS note_count
               FROM meetings m
          LEFT JOIN users u   ON u.id = m.host_id
          LEFT JOIN clients c ON c.id = m.client_id
              WHERE {$clause}
           ORDER BY m.scheduled_at {$order}
              LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
            $params
        );

        $this->view('meetings/index', [
            'title'    => 'Meetings',
            'meetings' => $meetings,
            'pager'    => $pager,
            'filters'  => ['when' => $when, 'mine' => $mine, 'search' => $search],
            'summary'  => Meetings::summary((int) Auth::id()),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('meetings.manage');
        $this->form(null);
    }

    public function edit(Request $request): void
    {
        $this->authorize('meetings.manage');
        $this->form($this->findOrFail($request->paramInt('id')));
    }

    private function form(?array $meeting): void
    {
        $this->view('meetings/form', [
            'title'    => $meeting ? 'Edit ' . $meeting['title'] : 'Schedule a meeting',
            'meeting'  => $meeting,
            'staff'    => Database::all('SELECT id, name, email, phone FROM users WHERE is_active = 1 ORDER BY name'),
            'clients'  => Database::all("SELECT id, name FROM clients WHERE status <> 'inactive' ORDER BY name"),
            'invited'  => $meeting ? Meetings::participants((int) $meeting['id']) : [],
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('meetings.manage');

        $data = $this->validated($request, null);

        $data['public_token'] = bin2hex(random_bytes(24));
        $data['host_id']      = Auth::id();
        $data['created_by']   = Auth::id();

        $id = Database::insert('meetings', $data);

        // The person calling the meeting is in it.
        Meetings::addUser($id, (int) Auth::id(), 'host');
        $this->syncInvitees($request, $id);

        ActivityLog::record('meeting_created', 'meeting', $id, 'Scheduled ' . $data['title']);
        Session::success('Meeting scheduled. Share the link with anyone outside the company.');
        Response::to('/meetings/' . $id);
    }

    public function update(Request $request): void
    {
        $this->authorize('meetings.manage');

        $meeting = $this->findOrFail($request->paramInt('id'));
        $data    = $this->validated($request, (int) $meeting['id']);

        Database::update('meetings', $data, ['id' => $meeting['id']]);
        $this->syncInvitees($request, (int) $meeting['id']);

        ActivityLog::record('meeting_updated', 'meeting', (int) $meeting['id'], 'Updated ' . $data['title']);
        Session::success('Meeting updated.');
        Response::to('/meetings/' . $meeting['id']);
    }

    private function validated(Request $request, ?int $existingId): array
    {
        $v = new Validator($request->all());
        $v->require('title', 'Title')
          ->maxLen('title', 200, 'Title')
          ->require('scheduled_at', 'Date and time')
          ->numeric('duration_mins', 'Duration')
          ->min('duration_mins', 5, 'Duration');

        if ($v->fails()) {
            $v->redirectBack($existingId ? '/meetings/' . $existingId . '/edit' : '/meetings/create');
        }

        // A datetime-local field posts "2026-08-20T14:30"; MySQL wants a space.
        $when = str_replace('T', ' ', (string) $request->input('scheduled_at'));
        $when = strlen($when) === 16 ? $when . ':00' : $when;

        return [
            'title'         => (string) $request->input('title'),
            'agenda'        => $request->input('agenda') ?: null,
            'scheduled_at'  => $when,
            'duration_mins' => max(5, (int) $request->input('duration_mins', 30)),
            'client_id'     => $request->input('client_id') ? (int) $request->input('client_id') : null,
            'allow_guests'  => $request->bool('allow_guests') ? 1 : 0,
            'reminder_mins' => trim((string) $request->input('reminder_mins', '')),
        ];
    }

    /**
     * Bring the invitation list in line with what was submitted.
     *
     * Anyone removed from the form is removed from the meeting, except the
     * host — losing the host would leave a meeting nobody owns.
     */
    /**
     * Tell colleagues they have been put in a meeting.
     *
     * Only the people newly added: re-saving a meeting to fix a typo in
     * the agenda should not alert everybody a second time.
     *
     * @param array<int,int> $userIds everyone now invited
     */
    private function notifyInvitees(int $meetingId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $meeting = Database::first(
            'SELECT title, scheduled_at, public_token FROM meetings WHERE id = :id',
            ['id' => $meetingId]
        );

        if (!$meeting) {
            return;
        }

        // Anyone already told about this meeting is skipped, so editing
        // it does not alert the same people again.
        $already = array_map('intval', array_column(Database::all(
            "SELECT DISTINCT user_id FROM staff_notifications
              WHERE entity_type = 'meeting' AND entity_id = :id",
            ['id' => $meetingId]
        ), 'user_id'));

        $fresh = array_values(array_diff($userIds, $already));

        if ($fresh === []) {
            return;
        }

        \App\Services\StaffNotifier::notify($fresh, [
            'event'       => 'meeting_invite',
            'title'       => 'Meeting: ' . $meeting['title'],
            'body'        => 'You have been invited. '
                             . fdate($meeting['scheduled_at'], 'D d M Y \a\t H:i'),
            'link'        => '/meetings/' . $meetingId,
            'entity_type' => 'meeting',
            'entity_id'   => $meetingId,
        ], ['email' => true, 'sms' => true]);
    }

    private function syncInvitees(Request $request, int $meetingId): void
    {
        $userIds = $request->input('user_ids', []);
        $userIds = is_array($userIds) ? array_map('intval', $userIds) : [];

        $keep = [];

        foreach ($userIds as $uid) {
            if ($uid > 0) {
                Meetings::addUser($meetingId, $uid);
                $keep[] = $uid;
            }
        }

        // Guests arrive as parallel arrays of name/email/phone.
        $names  = (array) $request->input('guest_name', []);
        $emails = (array) $request->input('guest_email', []);
        $phones = (array) $request->input('guest_phone', []);

        $guestEmails = [];

        foreach ($names as $i => $name) {
            $name  = trim((string) $name);
            $email = trim((string) ($emails[$i] ?? ''));
            $phone = trim((string) ($phones[$i] ?? ''));

            if ($name === '' && $email === '') {
                continue;
            }

            // Somewhere to send the reminder, or there is no point inviting.
            if ($email === '' && $phone === '') {
                continue;
            }

            Meetings::addGuest($meetingId, $name ?: $email, $email ?: null, $phone ?: null);

            if ($email !== '') {
                $guestEmails[] = $email;
            }
        }

        Meetings::pruneMissing($meetingId, $keep, $guestEmails);

        $this->notifyInvitees($meetingId, $keep);
    }

    public function show(Request $request): void
    {
        $meeting = $this->findOrFail($request->paramInt('id'));

        $this->view('meetings/show', [
            'title'        => $meeting['title'],
            'meeting'      => $meeting,
            'participants' => Meetings::participants((int) $meeting['id']),
            'notes'        => Meetings::notes((int) $meeting['id']),
            'host'         => Database::first('SELECT name FROM users WHERE id = :id', ['id' => $meeting['host_id']]),
            'client'       => $meeting['client_id']
                ? Database::first('SELECT id, name FROM clients WHERE id = :id', ['id' => $meeting['client_id']])
                : null,
            'joinUrl'      => Meetings::joinUrl($meeting['public_token']),
        ]);
    }

    /** The room itself, for someone signed in. */
    public function room(Request $request): void
    {
        $meeting = $this->findOrFail($request->paramInt('id'));

        if ($meeting['status'] === 'cancelled') {
            Session::error('That meeting was cancelled.');
            Response::to('/meetings/' . $meeting['id']);
        }

        Meetings::markJoined((int) $meeting['id'], (int) Auth::id());

        $me = Auth::user();

        $this->view('meetings/room', [
            'title'    => $meeting['title'],
            'meeting'  => $meeting,
            'notes'    => Meetings::notes((int) $meeting['id']),
            'meName'   => $me['name'] ?? 'Someone',
            'isHost'   => (int) $meeting['host_id'] === (int) Auth::id(),
            'canWrite' => true,
            'ice'      => Meetings::iceServers(),
            'base'     => url('/meetings/' . $meeting['id']),
        ], 'blank');
    }

    /** Open the room for real — what the host presses to begin. */
    public function start(Request $request): void
    {
        $this->authorize('meetings.manage');
        $meeting = $this->findOrFail($request->paramInt('id'));

        if ($meeting['status'] === 'scheduled') {
            Database::update('meetings', [
                'status'     => 'in_progress',
                'started_at' => date('Y-m-d H:i:s'),
            ], ['id' => $meeting['id']]);
        }

        Response::to('/meetings/' . $meeting['id'] . '/room');
    }

    public function end(Request $request): void
    {
        $this->authorize('meetings.manage');
        $meeting = $this->findOrFail($request->paramInt('id'));

        Database::update('meetings', [
            'status'   => 'ended',
            'ended_at' => date('Y-m-d H:i:s'),
        ], ['id' => $meeting['id']]);

        // The room is over; the postbox it used is of no further interest.
        Database::run('DELETE FROM meeting_signals WHERE meeting_id = :id', ['id' => $meeting['id']]);

        ActivityLog::record('meeting_ended', 'meeting', (int) $meeting['id'], 'Ended ' . $meeting['title']);
        Session::success('Meeting closed. The notes taken are on its page.');
        Response::to('/meetings/' . $meeting['id']);
    }

    public function cancel(Request $request): void
    {
        $this->authorize('meetings.manage');
        $meeting = $this->findOrFail($request->paramInt('id'));

        Database::update('meetings', ['status' => 'cancelled'], ['id' => $meeting['id']]);

        ActivityLog::record('meeting_cancelled', 'meeting', (int) $meeting['id'], 'Cancelled ' . $meeting['title']);
        Session::warning('Meeting cancelled. Let the people invited know.');
        Response::to('/meetings/' . $meeting['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('meetings.delete');
        $meeting = $this->findOrFail($request->paramInt('id'));

        Database::delete('meetings', ['id' => $meeting['id']]);

        ActivityLog::record('meeting_deleted', 'meeting', (int) $meeting['id'], 'Deleted ' . $meeting['title']);
        Session::success('Meeting deleted.');
        Response::to('/meetings');
    }

    /** Save the tidied-up minutes. */
    public function saveMinutes(Request $request): void
    {
        $this->authorize('meetings.manage');
        $meeting = $this->findOrFail($request->paramInt('id'));

        Database::update('meetings', [
            'minutes'            => (string) $request->input('minutes', ''),
            'minutes_updated_at' => date('Y-m-d H:i:s'),
            'minutes_updated_by' => Auth::id(),
        ], ['id' => $meeting['id']]);

        Session::success('Minutes saved.');
        Response::to('/meetings/' . $meeting['id']);
    }

    // -- Things the room calls while it is running ---------------------

    public function postNote(Request $request): void
    {
        $meeting = $this->findOrFail($request->paramInt('id'));
        $me      = Auth::user();

        $note = Meetings::addNote(
            (int) $meeting['id'],
            trim((string) $request->input('body', '')),
            (string) ($me['name'] ?? 'Someone'),
            (int) Auth::id(),
            (string) $request->input('kind', 'note')
        );

        Response::json(['ok' => $note !== null, 'note' => $note]);
    }

    public function pollNotes(Request $request): void
    {
        $meeting = $this->findOrFail($request->paramInt('id'));

        Response::json([
            'ok'    => true,
            'notes' => Meetings::notes((int) $meeting['id'], (int) $request->query('since', 0)),
        ]);
    }

    public function signal(Request $request): void
    {
        $meeting = $this->findOrFail($request->paramInt('id'));
        Meetings::handleSignal($request, (int) $meeting['id']);
    }

    public function signals(Request $request): void
    {
        $meeting = $this->findOrFail($request->paramInt('id'));
        Meetings::deliverSignals($request, (int) $meeting['id']);
    }

    private function findOrFail(int $id): array
    {
        $meeting = Database::first('SELECT * FROM meetings WHERE id = :id', ['id' => $id]);

        if (!$meeting) {
            throw new HttpException(404, 'That meeting does not exist.');
        }

        return $meeting;
    }
}
