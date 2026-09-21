<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\LiveChat\Departments;

/**
 * Setting up the queues and deciding who answers them.
 *
 * Kept apart from the desk itself: working a queue and deciding who is
 * in it are different jobs, and the second one is management's.
 *
 * The rule this screen exists to protect is that there is always exactly
 * one default department. A visitor who does not choose, or who arrives
 * while the picker is off, has to land somewhere — and a conversation
 * that lands nowhere is a customer talking to an empty room.
 */
class LiveChatAdminController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('livechat/departments', [
            'title'       => 'Chat departments',
            'departments' => Departments::all(),
            'staff'       => $this->staffByDepartment(),
            'people'      => $this->answerers(),
        ]);
    }

    /** Create one, or change one. */
    public function save(Request $request): void
    {
        $id   = $request->int('id');
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            Session::error('A department needs a name.');
            Response::to('/livechat/departments');
        }

        $data = [
            'name'           => mb_substr($name, 0, 80),
            'blurb'          => mb_substr(trim((string) $request->input('blurb', '')), 0, 160) ?: null,
            'fallback_email' => $this->email((string) $request->input('fallback_email', '')),
            'position'       => max(0, $request->int('position')),
            'status'         => $request->input('status') === 'inactive' ? 'inactive' : 'active',
        ];

        if ($id > 0) {
            Database::update('live_departments', $data, ['id' => $id]);
            $saved = $id;
        } else {
            $data['slug'] = $this->slug($name);
            $saved        = Database::insert('live_departments', $data);
        }

        // Only ever promote to default here, never demote: unticking the
        // box on the one default would leave none at all.
        if ($request->input('is_default')) {
            Departments::settleDefault($saved);
        } else {
            Departments::settleDefault();
        }

        ActivityLog::record('livechat_department', 'live_department', $saved, 'Saved chat department ' . $name);

        Session::success('Saved.');
        Response::to('/livechat/departments');
    }

    /**
     * Replace a department's staff with exactly who was ticked.
     *
     * Sent as a whole list rather than add/remove, because that is how
     * the form presents it — and because it makes "nobody" expressible,
     * which a series of removals would not.
     */
    public function setStaff(Request $request): void
    {
        $departmentId = $request->paramInt('id');

        if (!Departments::find($departmentId)) {
            Session::error('No such department.');
            Response::to('/livechat/departments');
        }

        $wanted = array_map('intval', (array) $request->input('user_ids', []));
        $leads  = array_map('intval', (array) $request->input('lead_ids', []));

        // Only people who could actually use the desk. Ticking somebody
        // without the permission would put conversations in an inbox
        // they cannot open.
        $allowed = array_column($this->answerers(), 'id');
        $wanted  = array_values(array_intersect($wanted, array_map('intval', $allowed)));

        Database::transaction(static function () use ($departmentId, $wanted, $leads): void {
            Database::delete('live_department_staff', ['department_id' => $departmentId]);

            foreach ($wanted as $userId) {
                Departments::addStaff($departmentId, $userId, in_array($userId, $leads, true));
            }
        });

        Session::success(
            $wanted
                ? count($wanted) . ' ' . (count($wanted) === 1 ? 'person' : 'people') . ' answering this one.'
                : 'Nobody is answering this department now — conversations in it will sit unread.'
        );

        Response::to('/livechat/departments');
    }

    /**
     * Remove a department.
     *
     * Its conversations survive: the foreign key sets their department to
     * null rather than deleting them, because a customer's questions and
     * our answers are a record and not a setting. They are then put into
     * the default department so that somebody can still see them.
     */
    public function delete(Request $request): void
    {
        $id = $request->paramInt('id');

        if (Database::scalar('SELECT COUNT(*) FROM live_departments') <= 1) {
            Session::error('There has to be one department left for visitors to arrive in.');
            Response::to('/livechat/departments');
        }

        $department = Departments::find($id);

        if (!$department) {
            Response::to('/livechat/departments');
        }

        Database::transaction(static function () use ($id): void {
            Database::delete('live_departments', ['id' => $id]);

            // The FK has just nulled these. Give them a home.
            $home = Departments::default();

            if ($home) {
                Database::run(
                    'UPDATE live_conversations SET department_id = :d WHERE department_id IS NULL',
                    ['d' => $home['id']]
                );
            }
        });

        Departments::settleDefault();

        ActivityLog::record('livechat_department_delete', 'live_department', $id,
            'Removed chat department ' . $department['name']);

        Session::success('Removed. Its conversations were moved to the default department.');
        Response::to('/livechat/departments');
    }

    // -----------------------------------------------------------------

    /** Everybody who could answer a chat, whether or not they do yet. */
    private function answerers(): array
    {
        $roles = Auth::rolesWith('livechat.use');

        if ($roles === []) {
            return [];
        }

        // Roles live in user_roles, one row each, so somebody holding
        // several is matched by any one of them — hence DISTINCT.
        $slots = implode(',', array_fill(0, count($roles), '?'));

        return Database::all(
            "SELECT DISTINCT u.id, u.name, u.email, u.role
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
              WHERE u.is_active = 1 AND ur.role IN ({$slots})
           ORDER BY u.name",
            $roles
        );
    }

    /** @return array<int, list<array{user_id:int, is_lead:int}>> */
    private function staffByDepartment(): array
    {
        $out = [];

        foreach (Database::all('SELECT department_id, user_id, is_lead FROM live_department_staff') as $row) {
            $out[(int) $row['department_id']][(int) $row['user_id']] = (int) $row['is_lead'];
        }

        return $out;
    }

    private function slug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '', '-') ?: 'department';
        $slug = $base;

        for ($i = 2; Database::scalar('SELECT 1 FROM live_departments WHERE slug = :s', ['s' => $slug]); $i++) {
            $slug = $base . '-' . $i;
        }

        return mb_substr($slug, 0, 80);
    }

    private function email(string $v): ?string
    {
        $v = trim($v);

        return $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) ? mb_substr($v, 0, 160) : null;
    }
}
