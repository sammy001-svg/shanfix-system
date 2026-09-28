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
use App\Services\ImageLibrary;
use App\Services\Social\Posts;
use App\Services\Social\Reports;

/**
 * The company's own social media.
 *
 * Three screens that answer three questions somebody asks every week:
 * what is going out (the calendar), what is it (the post), and was any
 * of it worth it (the report).
 *
 * Nothing here posts to a network. Facebook and Instagram will not
 * accept a post from an application they have not reviewed, and that
 * review is business paperwork rather than programming — so the person
 * who writes a post also posts it, and comes back to paste in the link.
 * That is a minute of work, and it leaves the planning, the approval
 * and the record every bit as useful.
 */
class SocialController extends Controller
{
    // -----------------------------------------------------------------
    // Planning
    // -----------------------------------------------------------------

    /**
     * The month, as a calendar.
     *
     * The planning surface. Somebody opens this to see whether next week
     * is empty, which is a question a list sorted by date answers badly.
     */
    public function calendar(Request $request): void
    {
        $month = (string) $request->query('month', date('Y-m'));

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $first = $month . '-01';
        $from  = date('Y-m-d 00:00:00', strtotime($first));
        $to    = date('Y-m-t 23:59:59', strtotime($first));

        $posts = Database::all(
            "SELECT p.id, p.ref, p.title, p.status, p.post_type, p.scheduled_for,
                    p.published_at, u.name AS owner, c.name AS campaign,
                    (SELECT GROUP_CONCAT(DISTINCT a.network ORDER BY a.network SEPARATOR ',')
                       FROM social_post_targets t
                       JOIN social_accounts a ON a.id = t.account_id
                      WHERE t.post_id = p.id) AS networks
               FROM social_posts p
          LEFT JOIN users u            ON u.id = p.owner_id
          LEFT JOIN social_campaigns c ON c.id = p.campaign_id
              WHERE p.scheduled_for BETWEEN :from AND :to
           ORDER BY p.scheduled_for",
            ['from' => $from, 'to' => $to]
        );

        $byDay = [];

        foreach ($posts as $post) {
            $byDay[date('Y-m-d', strtotime((string) $post['scheduled_for']))][] = $post;
        }

        $this->view('social/calendar', [
            'title'     => 'Social calendar',
            'month'     => $month,
            'byDay'     => $byDay,
            // Ideas with no date yet. They sit beside the calendar
            // rather than in it, because a post with no slot is exactly
            // what somebody is looking for when the week is empty.
            'waiting'   => Database::all(
                "SELECT p.id, p.ref, p.title, p.status, p.post_type, u.name AS owner
                   FROM social_posts p
              LEFT JOIN users u ON u.id = p.owner_id
                  WHERE p.scheduled_for IS NULL
                    AND p.status IN ('idea','draft','awaiting','approved')
               ORDER BY p.created_at DESC
                  LIMIT 30"
            ),
            'canManage' => Auth::can('social.manage'),
        ]);
    }

    /** Everything, as a list, for looking things up rather than planning. */
    public function index(Request $request): void
    {
        $show = (string) $request->query('status', 'all');
        $show = isset(Posts::STATUSES[$show]) ? $show : 'all';

        $where  = $show === 'all' ? '1=1' : 'p.status = :status';
        $params = $show === 'all' ? [] : ['status' => $show];

        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $where          .= ' AND (p.title LIKE :q OR p.caption LIKE :q OR p.ref LIKE :q)';
            $params['q']     = '%' . $search . '%';
        }

        $this->view('social/index', [
            'title'    => 'Social posts',
            'show'     => $show,
            'search'   => $search,
            'counts'   => $this->counts(),
            'posts'    => Database::all(
                "SELECT p.*, u.name AS owner, c.name AS campaign,
                        (SELECT GROUP_CONCAT(DISTINCT a.network ORDER BY a.network SEPARATOR ',')
                           FROM social_post_targets t
                           JOIN social_accounts a ON a.id = t.account_id
                          WHERE t.post_id = p.id) AS networks,
                        (SELECT COALESCE(SUM(t.likes + t.comments + t.shares + t.saves), 0)
                           FROM social_post_targets t WHERE t.post_id = p.id) AS engagement
                   FROM social_posts p
              LEFT JOIN users u            ON u.id = p.owner_id
              LEFT JOIN social_campaigns c ON c.id = p.campaign_id
                  WHERE " . $where . "
               ORDER BY COALESCE(p.published_at, p.scheduled_for, p.created_at) DESC
                  LIMIT 120",
                $params
            ),
            'canManage' => Auth::can('social.manage'),
        ]);
    }

    // -----------------------------------------------------------------
    // One post
    // -----------------------------------------------------------------

    public function show(Request $request): void
    {
        $post = Posts::find($request->paramInt('id'));

        if (!$post) {
            throw new HttpException(404, 'No such post.');
        }

        $this->view('social/show', [
            'title'      => $post['title'],
            'post'       => $post,
            'targets'    => Posts::targetsOf((int) $post['id']),
            'images'     => ImageLibrary::all('social', (int) $post['id']),
            'accounts'   => $this->accounts(),
            'campaigns'  => $this->campaigns(),
            'people'     => Auth::usersWith('social.manage'),
            'canManage'  => Auth::can('social.manage'),
            'canApprove' => Auth::can('social.approve'),
            'needsYes'   => Posts::needsApproval(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->authorize('social.manage');

        $this->view('social/form', [
            'title'     => 'New post',
            'post'      => null,
            'accounts'  => $this->accounts(),
            'campaigns' => $this->campaigns(),
            'people'    => Auth::usersWith('social.manage'),
            'targets'   => [],
        ]);
    }

    public function store(Request $request): void
    {
        $this->authorize('social.manage');

        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            Session::error('A post needs something to call it.');
            Response::to('/social/new');
        }

        $id = Posts::create([
            'title'         => $title,
            'caption'       => (string) $request->input('caption', ''),
            'hashtags'      => (string) $request->input('hashtags', ''),
            'link_url'      => (string) $request->input('link_url', ''),
            'first_comment' => (string) $request->input('first_comment', ''),
            'post_type'     => (string) $request->input('post_type', 'post'),
            'scheduled_for' => (string) $request->input('scheduled_for', ''),
            'campaign_id'   => $request->int('campaign_id') ?: null,
            'owner_id'      => $request->int('owner_id') ?: Auth::id(),
            'notes'         => (string) $request->input('notes', ''),
        ]);

        Posts::target($id, (array) $request->input('accounts', []));
        $this->takeImages($id);

        ActivityLog::record('social_post', 'social_post', $id, 'Started the post "' . $title . '"');

        Session::success('Started. Add the picture and put it up for approval when it is ready.');
        Response::to('/social/' . $id);
    }

    public function edit(Request $request): void
    {
        $this->authorize('social.manage');

        $post = $this->reachable($request->paramInt('id'));

        // A published post is the record of what went out. Editing its
        // caption would quietly make the record disagree with the thing
        // the public actually saw, so there is no way in at all.
        if ($post['status'] === 'published') {
            Session::error('This has already gone out. Record the numbers instead.');
            Response::to('/social/' . $post['id']);
        }

        $this->view('social/form', [
            'title'     => 'Edit ' . $post['ref'],
            'post'      => $post,
            'accounts'  => $this->accounts(),
            'campaigns' => $this->campaigns(),
            'people'    => Auth::usersWith('social.manage'),
            'targets'   => Posts::targetsOf((int) $post['id']),
        ]);
    }

    public function update(Request $request): void
    {
        $this->authorize('social.manage');

        $post = $this->reachable($request->paramInt('id'));

        // A published post is the record of what went out. Its caption
        // is not a draft any more, and editing it would quietly make
        // the record disagree with the thing itself.
        if ($post['status'] === 'published') {
            Session::error('This has already gone out. Record the numbers instead.');
            Response::to('/social/' . $post['id']);
        }

        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            Session::error('A post needs something to call it.');
            Response::to('/social/' . $post['id']);
        }

        Posts::update((int) $post['id'], [
            'title'         => $title,
            'caption'       => (string) $request->input('caption', ''),
            'hashtags'      => (string) $request->input('hashtags', ''),
            'link_url'      => (string) $request->input('link_url', ''),
            'first_comment' => (string) $request->input('first_comment', ''),
            'post_type'     => (string) $request->input('post_type', 'post'),
            'scheduled_for' => (string) $request->input('scheduled_for', ''),
            'campaign_id'   => $request->int('campaign_id') ?: null,
            'owner_id'      => $request->int('owner_id') ?: null,
            'notes'         => (string) $request->input('notes', ''),
        ]);

        Posts::target((int) $post['id'], (array) $request->input('accounts', []));
        $this->takeImages((int) $post['id']);

        Session::success('Saved.');
        Response::to('/social/' . $post['id']);
    }

    /** Move it along: up for approval, approved, sent back, out, dropped. */
    public function move(Request $request): void
    {
        $post = $this->reachable($request->paramInt('id'));
        $to   = (string) $request->input('to', '');

        // Approving is its own permission; everything else is the
        // writer's own work on their own post.
        if ($to !== 'approved') {
            $this->authorize('social.manage');
        }

        $result = Posts::move((int) $post['id'], $to, (string) $request->input('note', ''));

        if (!$result['ok']) {
            Session::error($result['error']);
            Response::to('/social/' . $post['id']);
        }

        ActivityLog::record('social_post_move', 'social_post', (int) $post['id'],
            'Moved "' . $post['title'] . '" to ' . (Posts::STATUSES[$to] ?? $to));

        Session::success(match ($to) {
            'awaiting'  => 'Up for approval. Whoever can approve it has been told.',
            'approved'  => 'Approved.',
            'published' => 'Marked as out. Paste in the links and the numbers when you have them.',
            'cancelled' => 'Dropped.',
            default     => 'Saved.',
        });

        Response::to('/social/' . $post['id']);
    }

    /** Where it landed, and how it did there. */
    public function record(Request $request): void
    {
        $this->authorize('social.manage');

        $post    = $this->reachable($request->paramInt('id'));
        $figures = (array) $request->input('t', []);

        foreach ($figures as $targetId => $in) {
            $target = Database::first(
                'SELECT id FROM social_post_targets WHERE id = :id AND post_id = :p',
                ['id' => (int) $targetId, 'p' => $post['id']]
            );

            if ($target && is_array($in)) {
                Posts::record((int) $target['id'], $in);
            }
        }

        Session::success('Written down.');
        Response::to('/social/' . $post['id']);
    }

    public function destroy(Request $request): void
    {
        $this->authorize('social.manage');

        $post = $this->reachable($request->paramInt('id'));

        // What went out is a record. Dropping a plan is fine; deleting
        // the note of something the public has already seen is not.
        if ($post['status'] === 'published') {
            Session::error('This has already gone out and is part of the record.');
            Response::to('/social/' . $post['id']);
        }

        Database::delete('social_posts', ['id' => $post['id']]);

        ActivityLog::record('social_post_delete', 'social_post', (int) $post['id'],
            'Removed the post "' . $post['title'] . '"');

        Session::success('Removed.');
        Response::to('/social');
    }

    public function deleteImage(Request $request): void
    {
        $this->authorize('social.manage');

        $post = $this->reachable($request->paramInt('id'));

        ImageLibrary::delete('social', (int) $post['id'], $request->paramInt('image'));

        Session::success('Picture removed.');
        Response::to('/social/' . $post['id']);
    }

    /**
     * Every published post that still owes numbers, on one page.
     *
     * The weak point of the whole module. Recording a month one post at
     * a time is a dozen page loads, and whether the reports ever have
     * anything in them turns on this being quick — a report nobody
     * feeds is a report nobody trusts, and then nobody opens.
     *
     * Oldest first, because those are the ones about to be forgotten.
     */
    public function catchUp(Request $request): void
    {
        $this->authorize('social.manage');

        $all = $request->query('all') === '1';

        $rows = Database::all(
            "SELECT t.*, a.network, a.name AS account,
                    p.id AS post_id, p.ref, p.title, p.post_type, p.published_at
               FROM social_post_targets t
               JOIN social_posts p    ON p.id = t.post_id
               JOIN social_accounts a ON a.id = t.account_id
              WHERE p.status = 'published' "
            . ($all ? '' : 'AND t.metrics_at IS NULL ') .
            "  AND p.published_at > DATE_SUB(NOW(), INTERVAL 120 DAY)
           ORDER BY p.published_at ASC, a.position, a.name
              LIMIT 200"
        );

        // Grouped by post, because that is how somebody works through
        // them: open the app, find the post, read off three numbers.
        $byPost = [];

        foreach ($rows as $row) {
            $byPost[(int) $row['post_id']]['post'] ??= [
                'id'           => (int) $row['post_id'],
                'ref'          => $row['ref'],
                'title'        => $row['title'],
                'post_type'    => $row['post_type'],
                'published_at' => $row['published_at'],
            ];
            $byPost[(int) $row['post_id']]['targets'][] = $row;
        }

        $this->view('social/catchup', [
            'title'  => 'Record the numbers',
            'byPost' => $byPost,
            'all'    => $all,
            'owed'   => (int) Database::scalar(
                "SELECT COUNT(*) FROM social_post_targets t
                   JOIN social_posts p ON p.id = t.post_id
                  WHERE p.status = 'published' AND t.metrics_at IS NULL
                    AND p.published_at > DATE_SUB(NOW(), INTERVAL 120 DAY)",
                [],
                0
            ),
        ]);
    }

    /** Save whatever was filled in across however many posts. */
    public function catchUpSave(Request $request): void
    {
        $this->authorize('social.manage');

        $figures = (array) $request->input('t', []);
        $saved   = 0;

        foreach ($figures as $targetId => $in) {
            if (!is_array($in)) {
                continue;
            }

            // Every row on this page belongs to a published post, but
            // the ids come off a form — so each one is checked against
            // that same condition rather than trusted.
            $ok = Database::scalar(
                "SELECT 1 FROM social_post_targets t
                   JOIN social_posts p ON p.id = t.post_id
                  WHERE t.id = :id AND p.status = 'published'",
                ['id' => (int) $targetId]
            );

            if (!$ok) {
                continue;
            }

            $before = Database::scalar(
                'SELECT metrics_at FROM social_post_targets WHERE id = :id',
                ['id' => (int) $targetId]
            );

            Posts::record((int) $targetId, $in);

            $after = Database::scalar(
                'SELECT metrics_at FROM social_post_targets WHERE id = :id',
                ['id' => (int) $targetId]
            );

            if ($before !== $after) {
                $saved++;
            }
        }

        Session::success($saved === 0
            ? 'Nothing was filled in, so nothing was saved.'
            : $saved . ' ' . ($saved === 1 ? 'entry' : 'entries') . ' written down.');

        Response::to('/social/catch-up');
    }

    // -----------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------

    public function reports(Request $request): void
    {
        $this->authorize('social.view');

        // A month at a time by default, because that is the unit
        // somebody reports in.
        $from = (string) $request->query('from', date('Y-m-01'));
        $to   = (string) $request->query('to', date('Y-m-t'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-01');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-t');
        }

        $fromAt = $from . ' 00:00:00';
        $toAt   = $to . ' 23:59:59';

        $this->view('social/reports', [
            'title'     => 'Social media report',
            'from'      => $from,
            'to'        => $to,
            'summary'   => Reports::summary($fromAt, $toAt),
            'networks'  => Reports::byNetwork($fromAt, $toAt),
            'campaigns' => Reports::byCampaign($fromAt, $toAt),
            'best'      => Reports::best($fromAt, $toAt),
            'timing'    => Reports::timing($fromAt, $toAt),
            'people'    => Reports::byPerson($fromAt, $toAt),
            'following' => Reports::following(),
        ]);
    }

    // -----------------------------------------------------------------
    // The profiles we post from
    // -----------------------------------------------------------------

    public function accountsPage(Request $request): void
    {
        $this->authorize('social.accounts');

        $this->view('social/accounts', [
            'title'     => 'Social accounts',
            'accounts'  => Database::all(
                'SELECT a.*,
                        (SELECT COUNT(*) FROM social_post_targets t WHERE t.account_id = a.id) AS posts
                   FROM social_accounts a
               ORDER BY a.position, a.name'
            ),
            'networks'  => Settings::SOCIAL,
            'campaigns' => $this->campaigns(true),
        ]);
    }

    public function saveAccount(Request $request): void
    {
        $this->authorize('social.accounts');

        $id      = $request->int('id');
        $name    = trim((string) $request->input('name', ''));
        $network = (string) $request->input('network', '');

        if ($name === '' || !isset(Settings::SOCIAL[$network])) {
            Session::error('A profile needs a name and a network.');
            Response::to('/social/accounts');
        }

        $data = [
            'network'     => $network,
            'name'        => mb_substr($name, 0, 80),
            'handle'      => mb_substr(trim((string) $request->input('handle', '')), 0, 80) ?: null,
            'profile_url' => mb_substr(trim((string) $request->input('profile_url', '')), 0, 255) ?: null,
            'notes'       => mb_substr(trim((string) $request->input('notes', '')), 0, 255) ?: null,
            'position'    => max(0, $request->int('position')),
            'status'      => $request->input('status') === 'inactive' ? 'inactive' : 'active',
        ];

        // The follower count is only written when a figure is actually
        // given, and stamped when it is — a count with no date on it is
        // worse than none, because nobody can tell how old it is.
        $followers = trim((string) $request->input('followers', ''));

        if ($followers !== '' && ctype_digit($followers)) {
            $data['followers']    = (int) $followers;
            $data['followers_at'] = date('Y-m-d H:i:s');
        }

        if ($id > 0) {
            Database::update('social_accounts', $data, ['id' => $id]);
        } else {
            $id = Database::insert('social_accounts', $data);
        }

        ActivityLog::record('social_account', 'social_account', $id, 'Saved the profile ' . $name);

        Session::success('Saved.');
        Response::to('/social/accounts');
    }

    public function deleteAccount(Request $request): void
    {
        $this->authorize('social.accounts');

        $id      = $request->paramInt('id');
        $account = Database::first('SELECT * FROM social_accounts WHERE id = :id', ['id' => $id]);

        if (!$account) {
            Response::to('/social/accounts');
        }

        // Its rows in social_post_targets carry the links and the
        // numbers for everything ever posted there. Switching it off
        // keeps the history; deleting it would take the record of
        // months of work with it.
        $posted = (int) Database::scalar(
            'SELECT COUNT(*) FROM social_post_targets WHERE account_id = :a', ['a' => $id], 0
        );

        if ($posted > 0) {
            Database::update('social_accounts', ['status' => 'inactive'], ['id' => $id]);
            Session::info($account['name'] . ' has ' . $posted . ' posts against it, so it was '
                . 'switched off rather than removed — the record of what went there is kept.');
            Response::to('/social/accounts');
        }

        Database::delete('social_accounts', ['id' => $id]);
        ActivityLog::record('social_account_delete', 'social_account', $id,
            'Removed the profile ' . $account['name']);

        Session::success('Removed.');
        Response::to('/social/accounts');
    }

    public function saveCampaign(Request $request): void
    {
        $this->authorize('social.accounts');

        $id   = $request->int('id');
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            Session::error('A campaign needs a name.');
            Response::to('/social/accounts');
        }

        $data = [
            'name'      => mb_substr($name, 0, 120),
            'goal'      => mb_substr(trim((string) $request->input('goal', '')), 0, 255) ?: null,
            'starts_on' => $this->date((string) $request->input('starts_on', '')),
            'ends_on'   => $this->date((string) $request->input('ends_on', '')),
            'spend'     => max(0, (float) $request->input('spend', 0)),
            'status'    => in_array($request->input('status'), ['planned', 'running', 'done', 'cancelled'], true)
                ? $request->input('status') : 'planned',
        ];

        if ($id > 0) {
            Database::update('social_campaigns', $data, ['id' => $id]);
        } else {
            $data['created_by'] = Auth::id();
            $id = Database::insert('social_campaigns', $data);
        }

        ActivityLog::record('social_campaign', 'social_campaign', $id, 'Saved the campaign ' . $name);

        Session::success('Saved.');
        Response::to('/social/accounts');
    }

    // -----------------------------------------------------------------

    private function reachable(int $id): array
    {
        $post = Database::first('SELECT * FROM social_posts WHERE id = :id', ['id' => $id]);

        if (!$post) {
            throw new HttpException(404, 'No such post.');
        }

        return $post;
    }

    /** Pictures arrive with the form, so they are taken with it. */
    private function takeImages(int $postId): void
    {
        if (empty($_FILES['images']['name'][0] ?? null)) {
            return;
        }

        $result = ImageLibrary::store('social', $postId, 'images');

        foreach ($result['errors'] as $error) {
            Session::error($error);
        }
    }

    private function accounts(): array
    {
        return Database::all(
            "SELECT * FROM social_accounts WHERE status = 'active' ORDER BY position, name"
        );
    }

    private function campaigns(bool $all = false): array
    {
        return Database::all(
            'SELECT * FROM social_campaigns'
            . ($all ? '' : " WHERE status IN ('planned','running')")
            . ' ORDER BY starts_on DESC, name'
        );
    }

    /** @return array<string,int> how many posts sit in each state */
    private function counts(): array
    {
        $out = ['all' => 0];

        foreach (array_keys(Posts::STATUSES) as $status) {
            $out[$status] = 0;
        }

        foreach (Database::all('SELECT status, COUNT(*) AS n FROM social_posts GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['n'];
            $out['all']         += (int) $row['n'];
        }

        return $out;
    }

    private function date(string $raw): ?string
    {
        $raw = trim($raw);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }
}
