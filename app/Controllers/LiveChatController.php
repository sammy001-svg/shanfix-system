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
use App\Services\LiveChat\Conversations;
use App\Services\LiveChat\Departments;
use App\Services\LiveChat\Transcripts;

/**
 * The desk: answering people who are on the website right now.
 *
 * What somebody sees here is decided by which departments they are in,
 * not by their role. The permission opens the door; live_department_staff
 * decides which conversations are behind it. Somebody in Accounts does
 * not read the sales queue, and that is the point of having departments
 * at all — a stranger's question should reach a person who can answer it.
 *
 * Managers hold livechat.view_all and see every department, because
 * somebody has to be able to watch the whole queue.
 *
 * The queue is sorted by how long people have been waiting, oldest
 * first. Nothing else is a sensible default: the person who has waited
 * eleven minutes is the person about to give up on us.
 */
class LiveChatController extends Controller
{
    // -----------------------------------------------------------------
    // The inbox
    // -----------------------------------------------------------------

    public function index(Request $request): void
    {
        $this->desk($request, null);
    }

    /**
     * The same page with one conversation open.
     *
     * One page rather than two: whoever is working the queue needs to see
     * what else is waiting while they type, and a reply that costs a page
     * of context to write is a reply that arrives late.
     */
    public function show(Request $request): void
    {
        $conversation = $this->reachable($request->paramInt('id'));

        Conversations::markReadByStaff((int) $conversation['id']);

        $this->desk($request, $conversation);
    }

    /** The desk itself: the queue, and whatever is open beside it. */
    private function desk(Request $request, ?array $conversation): void
    {
        $me    = Auth::user();
        $scope = $this->scope();

        $show = (string) $request->query('show', 'active');
        $show = in_array($show, ['active', 'waiting', 'mine', 'closed'], true) ? $show : 'active';

        $deptFilter = (int) $request->query('department', 0);
        $deptId     = $conversation ? (int) $conversation['department_id'] : 0;

        $this->view('livechat/index', [
            'title'         => 'Live chat',
            'me'            => $me,
            'show'          => $show,
            'conversations' => $this->queue($scope, $show, $deptFilter),
            'departments'   => $this->visibleDepartments($scope),
            'deptFilter'    => $deptFilter,
            'counts'        => $this->counts($scope, (int) $me['id']),
            'atTheDesk'     => Conversations::atTheDesk(),
            'canManage'     => Auth::can('livechat.manage'),

            'conversation'  => $conversation ? $this->decorate($conversation) : null,
            'messages'      => $conversation
                ? Conversations::messagesSince((int) $conversation['id'], 0, true) : [],
            'allDepartments' => Departments::forVisitor(),
            'colleagues'    => $conversation ? $this->colleagues($deptId) : [],
            'canned'        => $conversation ? $this->canned($deptId) : [],
        ]);
    }

    /**
     * New messages since $after, for the open thread.
     *
     * Also returns the queue counts, so one request keeps both the thread
     * and the badges current rather than two polling loops.
     */
    public function poll(Request $request): void
    {
        $me    = Auth::user();
        $scope = $this->scope();

        $out = ['ok' => true, 'counts' => $this->counts($scope, (int) $me['id'])];

        $id = (int) $request->query('conversation', 0);

        if ($id > 0) {
            $conversation = $this->reachable($id);
            $after        = max(0, (int) $request->query('after', 0));

            $messages = Conversations::messagesSince($id, $after, true);

            if ($messages) {
                Conversations::markReadByStaff($id);
            }

            $out['status']   = $conversation['status'];
            $out['assigned'] = $conversation['assigned_user_id'] !== null
                ? (int) $conversation['assigned_user_id'] : null;
            $out['messages'] = array_map(
                static fn(array $m): array => [
                    'id'     => (int) $m['id'],
                    'sender' => $m['sender'],
                    'who'    => $m['sender_name'],
                    'body'   => $m['body'],
                    'note'   => (int) $m['is_note'] === 1,
                    'at'     => date('H:i', strtotime($m['created_at'])),
                ],
                $messages
            );
        }

        Response::json($out);
    }

    /** Just the waiting count, for the badge in the sidebar. */
    public function waitingCount(Request $request): void
    {
        Response::json(['ok' => true] + $this->counts($this->scope(), (int) Auth::id()));
    }

    // -----------------------------------------------------------------
    // Answering
    // -----------------------------------------------------------------

    public function reply(Request $request): void
    {
        $me           = Auth::user();
        $conversation = $this->reachable($request->paramInt('id'));

        $isNote = (bool) $request->input('note', false);

        $result = Conversations::staffSays(
            $conversation,
            (int) $me['id'],
            (string) $me['name'],
            (string) $request->input('message', ''),
            $isNote
        );

        // Count the saved reply this was started from. The desk orders
        // the list by it, so without this the ordering never changes and
        // a reply nobody uses sits at the top for ever.
        //
        // Counted even if the agent rewrote it before sending: what the
        // number answers is "which of these is worth keeping", and one
        // that got somebody most of the way there is worth keeping.
        $from = $request->int('canned_id');

        if ($result['ok'] && $from > 0) {
            Database::run(
                'UPDATE live_canned SET uses = uses + 1 WHERE id = :id',
                ['id' => $from]
            );
        }

        if ($request->wantsJson()) {
            Response::json($result);
        }

        if (!$result['ok']) {
            Session::error($result['error']);
        }

        Response::to('/livechat/' . $conversation['id']);
    }

    /** Take it, so colleagues can see somebody is on it. */
    public function claim(Request $request): void
    {
        $conversation = $this->reachable($request->paramInt('id'));
        $took         = Conversations::claim((int) $conversation['id'], (int) Auth::id());

        if (!$took) {
            $who = Database::scalar(
                'SELECT u.name FROM live_conversations c JOIN users u ON u.id = c.assigned_user_id
                  WHERE c.id = :id',
                ['id' => $conversation['id']]
            );

            Session::info(($who ? $who . ' is' : 'Somebody else is') . ' already handling this one.');
        }

        Response::to('/livechat/' . $conversation['id']);
    }

    /** Put it back, when it turns out not to be yours. */
    public function release(Request $request): void
    {
        $conversation = $this->reachable($request->paramInt('id'));

        Conversations::assign((int) $conversation['id'], null);
        Session::success('Back in the queue.');

        Response::to('/livechat/' . $conversation['id']);
    }

    /** Hand it to a named colleague in the same department. */
    public function handTo(Request $request): void
    {
        $conversation = $this->reachable($request->paramInt('id'));
        $userId       = $request->int('user_id');

        $inDepartment = Database::scalar(
            'SELECT 1 FROM live_department_staff WHERE department_id = :d AND user_id = :u',
            ['d' => $conversation['department_id'], 'u' => $userId]
        );

        if (!$inDepartment) {
            Session::error('That person is not in this department.');
            Response::to('/livechat/' . $conversation['id']);
        }

        Conversations::assign((int) $conversation['id'], $userId);
        Session::success('Handed over.');

        Response::to('/livechat/' . $conversation['id']);
    }

    /** Move it to a department that can actually answer it. */
    public function transfer(Request $request): void
    {
        $me           = Auth::user();
        $conversation = $this->reachable($request->paramInt('id'));
        $to           = $request->int('department_id');

        if ($to === (int) $conversation['department_id']) {
            Response::to('/livechat/' . $conversation['id']);
        }

        if (!Conversations::transfer((int) $conversation['id'], $to, (string) $me['name'])) {
            Session::error('No such department.');
            Response::to('/livechat/' . $conversation['id']);
        }

        ActivityLog::record('livechat_transfer', 'live_conversation', (int) $conversation['id'],
            'Moved chat ' . $conversation['ref'] . ' to another department');

        Session::success('Moved. Whoever is in that department will see it.');

        // Back to the inbox, not the thread: it may no longer be theirs
        // to read, and bouncing off a 403 would be a poor way to find out.
        Response::to('/livechat');
    }

    public function close(Request $request): void
    {
        $conversation = $this->reachable($request->paramInt('id'));

        Conversations::close(
            (int) $conversation['id'],
            (int) Auth::id(),
            (string) $request->input('reason', '')
        );

        // Post them the conversation, if we know where to. A chat window
        // closes and takes the price they were quoted with it; an email
        // in their inbox is the only copy the customer ends up with.
        $sent = Transcripts::send((int) $conversation['id']);

        Session::success($sent
            ? 'Closed. A copy has been sent to ' . $conversation['visitor_email'] . '.'
            : 'Closed.');
        Response::to('/livechat');
    }

    public function reopen(Request $request): void
    {
        $conversation = $this->reachable($request->paramInt('id'));

        Conversations::reopen((int) $conversation['id']);
        Session::success('Open again.');

        Response::to('/livechat/' . $conversation['id']);
    }

    // -----------------------------------------------------------------
    // Scope: what this person may see
    // -----------------------------------------------------------------

    /**
     * The department ids this user may read, or null for all of them.
     *
     * null means "no restriction" and is not the same as an empty list,
     * which means "in no departments, so sees nothing". Conflating those
     * two is how somebody ends up reading a queue they were deliberately
     * kept out of.
     */
    private function scope(): ?array
    {
        if (Auth::can('livechat.view_all')) {
            return null;
        }

        return Departments::forUser((int) Auth::id());
    }

    /**
     * Fetch a conversation this user is allowed to open, or refuse.
     *
     * The same 404 for "does not exist" and "not yours", so that the
     * inbox cannot be used to count other departments' conversations.
     */
    private function reachable(int $id): array
    {
        $conversation = Conversations::find($id);

        if (!$conversation) {
            throw new HttpException(404, 'No such conversation.');
        }

        $scope = $this->scope();

        if ($scope !== null && !in_array((int) $conversation['department_id'], $scope, true)) {
            throw new HttpException(404, 'No such conversation.');
        }

        return $conversation;
    }

    private function visibleDepartments(?array $scope): array
    {
        $all = Departments::all();

        if ($scope === null) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn(array $d): bool => in_array((int) $d['id'], $scope, true)
        ));
    }

    // -----------------------------------------------------------------
    // Queries
    // -----------------------------------------------------------------

    /** The list down the left-hand side. */
    private function queue(?array $scope, string $show, int $departmentId): array
    {
        [$where, $params] = $this->scopeSql($scope);

        $where .= match ($show) {
            'waiting' => " AND c.status = 'waiting'",
            'mine'    => " AND c.assigned_user_id = :me AND c.status <> 'closed'",
            'closed'  => " AND c.status = 'closed'",
            default   => " AND c.status <> 'closed'",
        };

        if ($show === 'mine') {
            $params['me'] = (int) Auth::id();
        }

        if ($departmentId > 0) {
            $where            .= ' AND c.department_id = :dept';
            $params['dept']    = $departmentId;
        }

        return Database::all(
            "SELECT c.id, c.ref, c.status, c.visitor_name, c.visitor_email,
                    c.created_at, c.updated_at, c.last_visitor_at, c.first_reply_at,
                    c.assigned_user_id, c.page_title, c.page_url, c.rating,
                    d.name AS department,
                    u.name AS agent,
                    cl.name AS client_name,
                    (SELECT COUNT(*) FROM live_messages m
                      WHERE m.conversation_id = c.id AND m.sender = 'visitor'
                        AND m.read_by_staff_at IS NULL) AS unread,
                    (SELECT m.body FROM live_messages m
                      WHERE m.conversation_id = c.id AND m.is_note = 0
                      ORDER BY m.id DESC LIMIT 1) AS last_message,
                    TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) AS age_minutes
               FROM live_conversations c
          LEFT JOIN live_departments d ON d.id = c.department_id
          LEFT JOIN users u            ON u.id = c.assigned_user_id
          LEFT JOIN clients cl         ON cl.id = c.client_id
              WHERE " . $where . "
           ORDER BY CASE c.status WHEN 'waiting' THEN 0 WHEN 'open' THEN 1 ELSE 2 END,
                    c.status = 'waiting' DESC,
                    CASE WHEN c.status = 'waiting' THEN c.created_at END ASC,
                    c.updated_at DESC
              LIMIT 60",
            $params
        );
    }

    /** The numbers on the tabs. */
    private function counts(?array $scope, int $userId): array
    {
        [$where, $params] = $this->scopeSql($scope);
        $params['me']     = $userId;

        $row = Database::first(
            "SELECT
                SUM(c.status = 'waiting')                                    AS waiting,
                SUM(c.status <> 'closed')                                    AS active,
                SUM(c.assigned_user_id = :me AND c.status <> 'closed')       AS mine,
                SUM(c.status = 'waiting'
                    AND c.created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))   AS overdue
               FROM live_conversations c
              WHERE " . $where,
            $params
        );

        return [
            'waiting' => (int) ($row['waiting'] ?? 0),
            'active'  => (int) ($row['active'] ?? 0),
            'mine'    => (int) ($row['mine'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
        ];
    }

    /**
     * The WHERE fragment that keeps somebody inside their departments.
     *
     * An empty scope yields 0=1 rather than no condition at all. Somebody
     * in no department sees nothing, which is correct and is the opposite
     * of what dropping the clause would do.
     */
    private function scopeSql(?array $scope): array
    {
        if ($scope === null) {
            return ['1=1', []];
        }

        if (!$scope) {
            return ['0=1', []];
        }

        $names  = [];
        $params = [];

        foreach (array_values($scope) as $i => $id) {
            $names[]             = ':d' . $i;
            $params['d' . $i]    = $id;
        }

        return ['c.department_id IN (' . implode(', ', $names) . ')', $params];
    }

    /** Everything worth knowing about the person asking. */
    private function decorate(array $conversation): array
    {
        $conversation['client'] = $conversation['client_id']
            ? Database::first(
                'SELECT id, client_code, name, email, phone FROM clients WHERE id = :id',
                ['id' => $conversation['client_id']])
            : null;

        $conversation['department_name'] = Database::scalar(
            'SELECT name FROM live_departments WHERE id = :id',
            ['id' => $conversation['department_id']]
        );

        $conversation['agent_name'] = $conversation['assigned_user_id']
            ? Database::scalar('SELECT name FROM users WHERE id = :id',
                ['id' => $conversation['assigned_user_id']])
            : null;

        // How long they waited for a human. The single number that says
        // whether any of this is working.
        $conversation['waited'] = $conversation['first_reply_at']
            ? max(0, strtotime($conversation['first_reply_at']) - strtotime($conversation['created_at']))
            : null;

        return $conversation;
    }

    /** Who else could take this one. */
    private function colleagues(int $departmentId): array
    {
        return Database::all(
            "SELECT u.id, u.name
               FROM live_department_staff s
               JOIN users u ON u.id = s.user_id
              WHERE s.department_id = :d AND u.is_active = 1 AND u.id <> :me
              ORDER BY u.name",
            ['d' => $departmentId, 'me' => (int) Auth::id()]
        );
    }

    private function canned(int $departmentId): array
    {
        return Database::all(
            'SELECT id, title, body FROM live_canned
              WHERE department_id IS NULL OR department_id = :d
              ORDER BY uses DESC, title
              LIMIT 30',
            ['d' => $departmentId]
        );
    }
}
