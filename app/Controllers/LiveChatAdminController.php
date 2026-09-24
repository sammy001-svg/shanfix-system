<?php
namespace App\Controllers;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
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

    /**
     * The hours, the greeting, and how loudly to be told.
     *
     * These were seeded by migration 045 and, until now, changeable only
     * with a MySQL client. Everything here is a setting the office owns,
     * so it is written whole: an unticked box is a real answer, and the
     * form always sends every field.
     */
    public function saveSettings(Request $request): void
    {
        // An empty greeting is not a choice anybody makes on purpose —
        // the widget opens with it, so blank means a chat window that
        // says nothing at all. An empty out-of-hours message is fine:
        // it means say nothing extra, which is a real preference.
        $greeting = trim((string) $request->input('livechat_greeting', ''))
                 ?: 'Hello. How can we help you today?';
        $offline  = trim((string) $request->input('livechat_offline_message', ''));

        // Days arrive as a list of ticked numbers. An empty list means
        // the desk is never open, which is a strange thing to want but
        // not a thing to silently override — the widget then always says
        // we are away, which is at least true.
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('days', [])),
            static fn(int $d): bool => $d >= 0 && $d <= 6
        )));
        sort($days);

        $minutes = (int) $request->input('livechat_alert_after', 5);

        Settings::setMany([
            'livechat_greeting'        => mb_substr($greeting, 0, 200),
            'livechat_offline_message' => mb_substr($offline, 0, 400),
            'livechat_hours_from'      => $this->clock((string) $request->input('livechat_hours_from', ''), '08:00'),
            'livechat_hours_to'        => $this->clock((string) $request->input('livechat_hours_to', ''), '17:30'),
            'livechat_hours_days'      => implode(',', $days),
            // Clamped rather than rejected: a 0 here would mean escalating
            // a conversation the instant it arrives, which would make the
            // second-line alarm indistinguishable from the first.
            'livechat_alert_after'     => (string) max(1, min(240, $minutes)),
            'livechat_alert_email'     => $request->input('livechat_alert_email') ? '1' : '0',
            'livechat_alert_sms'       => $request->input('livechat_alert_sms') ? '1' : '0',
            'livechat_alert_sound'     => $request->input('livechat_alert_sound') ? '1' : '0',
            'livechat_ask_department'  => $request->input('livechat_ask_department') ? '1' : '0',
            'livechat_enabled'         => $request->input('livechat_enabled') ? '1' : '0',
        ]);

        ActivityLog::record('livechat_settings', 'setting', null, 'Changed the live chat settings');

        Session::success(
            $days === []
                ? 'Saved — but no days are ticked, so visitors will always be told we are away.'
                : 'Saved.'
        );

        Response::to('/livechat/departments');
    }

    /** A HH:MM from a time field, or the default if it is not one. */
    private function clock(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
    }

    // -----------------------------------------------------------------

    /**
     * Everybody who could answer a chat, whether or not they do yet.
     *
     * Asked of who actually holds livechat.use, not of which roles do:
     * somebody granted it by hand belongs in this list, and somebody
     * whose role allows it but who has had it taken away does not.
     * Ticking either into a department would put conversations in an
     * inbox they cannot open.
     */
    private function answerers(): array
    {
        $able = Auth::usersWith('livechat.use');

        if ($able === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($able), '?'));

        return Database::all(
            "SELECT id, name, email, role
               FROM users WHERE id IN ({$slots}) ORDER BY name",
            array_column($able, 'id')
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
