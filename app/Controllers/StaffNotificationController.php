<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * The bell: what the system needs to tell the person using it.
 *
 * Distinct from NotificationController, which is the log of what we sent
 * to clients. This is the other direction — messages addressed to us.
 */
class StaffNotificationController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();
        $filter = (string) $request->query('filter', '');
        $unread = $filter === 'unread';
        $mentions = $filter === 'mentions';

        $where  = ['user_id = :me'];
        $params = ['me' => $userId];

        if ($unread) {
            $where[] = 'read_at IS NULL';
        }

        // Being named in a conversation is the one alert people go looking
        // for afterwards, so it gets its own view.
        if ($mentions) {
            $where[] = "event = 'chat_mention'";
        }

        $clause = implode(' AND ', $where);
        $total  = (int) Database::scalar("SELECT COUNT(*) FROM staff_notifications WHERE {$clause}", $params, 0);
        $pager  = $this->paginate($total, 40);

        $this->view('alerts/index', [
            'title'  => 'My alerts',
            'alerts' => Database::all(
                "SELECT * FROM staff_notifications
                  WHERE {$clause}
               ORDER BY id DESC
                  LIMIT {$pager['perPage']} OFFSET {$pager['offset']}",
                $params
            ),
            'pager'  => $pager,
            'unread'   => $unread,
            'mentions' => $mentions,
        ]);
    }

    /**
     * Open one and go where it points.
     *
     * Marking it read here rather than on the list means the badge only
     * clears for things actually looked at.
     */
    public function open(Request $request): void
    {
        $id = $request->paramInt('id');

        $alert = Database::first(
            'SELECT * FROM staff_notifications WHERE id = :id AND user_id = :me',
            ['id' => $id, 'me' => Auth::id()]
        );

        if (!$alert) {
            Session::error('That alert is no longer there.');
            Response::to('/alerts');
        }

        if ($alert['read_at'] === null) {
            Database::update('staff_notifications', ['read_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        }

        Response::to($alert['link'] ?: '/alerts');
    }

    public function readAll(Request $request): void
    {
        $count = Database::run(
            'UPDATE staff_notifications SET read_at = NOW()
              WHERE user_id = :me AND read_at IS NULL',
            ['me' => Auth::id()]
        )->rowCount();

        Session::success($count > 0 ? 'Marked ' . $count . ' as read.' : 'Nothing was unread.');
        Response::back('/alerts');
    }
}
