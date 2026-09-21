<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Newsletter;

/**
 * The newsletter list, from both sides.
 *
 * The public side is what the website footer posts to, and the page a
 * subscriber lands on when they want to leave. Neither has a session:
 * subscribing needs only an address, and leaving needs only the token
 * from their own link — so there is no CSRF token to ask for, and none
 * would help. What protects the subscribe endpoint instead is a rate
 * limit, so it cannot be used to fill the list with junk.
 *
 * The staff side is the list itself and an export, for whoever sends the
 * newsletter.
 */
class NewsletterController extends Controller
{
    /** Sign-ups a minute from one address. Generous: offices share one. */
    private const PER_MINUTE = 10;

    private const PER_PAGE = 50;

    // -----------------------------------------------------------------
    // Public
    // -----------------------------------------------------------------

    /** The footer form, posted by fetch. Always answers JSON. */
    public function subscribe(Request $request): void
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // A hidden field real visitors never see or fill. A bot filling
        // every input it finds is told it succeeded and nothing is saved,
        // so it has no reason to try harder.
        if (trim((string) $request->input('website', '')) !== '') {
            Response::json(['ok' => true, 'message' => 'Thank you — you are subscribed.']);
        }

        if ($this->overLimit($ip)) {
            Response::json(['ok' => false, 'error' => 'Too many attempts. Please try again in a minute.'], 429);
        }

        $result = Newsletter::subscribe(
            (string) $request->input('email', ''),
            (string) $request->input('page', ''),
            $ip
        );

        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => $result['error']], 422);
        }

        // The same answer whether they were new or already on the list,
        // so the form cannot be used to find out who else is.
        Response::json(['ok' => true, 'message' => 'Thank you — you are subscribed.']);
    }

    /**
     * The page the unsubscribe link opens.
     *
     * It asks for a click rather than unsubscribing on sight, because
     * mail scanners and link previews open every link in a message, and
     * would otherwise take people off the list who never asked.
     */
    public function showUnsubscribe(Request $request): void
    {
        $token      = (string) $request->query('t', '');
        $subscriber = Newsletter::byToken($token);

        $this->view('newsletter/unsubscribe', [
            'title'      => 'Unsubscribe',
            'token'      => $token,
            'subscriber' => $subscriber,
            'done'       => false,
            'authKind'   => 'none',
        ], 'auth');
    }

    public function unsubscribe(Request $request): void
    {
        $token = (string) $request->input('t', '');
        $ok    = Newsletter::unsubscribe($token);

        $this->view('newsletter/unsubscribe', [
            'title'      => 'Unsubscribed',
            'token'      => $token,
            'subscriber' => $ok ? Newsletter::byToken($token) : null,
            'done'       => $ok,
            'authKind'   => 'none',
        ], 'auth');
    }

    // -----------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------

    public function index(Request $request): void
    {
        $show   = $request->query('show') === 'unsubscribed' ? 'unsubscribed' : 'subscribed';
        $search = trim((string) $request->query('q', ''));

        [$where, $params] = $this->filter($show, $search);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM newsletter_subscribers WHERE {$where}", $params, 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($pages, (int) $request->query('page', 1)));

        $this->view('newsletter/index', [
            'title'       => 'Newsletter',
            'show'        => $show,
            'search'      => $search,
            'counts'      => Newsletter::counts(),
            'subscribers' => Database::all(
                "SELECT id, email, status, source_page, subscribed_at, unsubscribed_at
                   FROM newsletter_subscribers WHERE {$where}
                  ORDER BY subscribed_at DESC
                  LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
                $params
            ),
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    /**
     * Everybody currently subscribed, as CSV, for whatever sends the mail.
     *
     * Only the subscribed — exporting people who withdrew consent is how
     * they end up mailed again by accident. Each row carries its own
     * unsubscribe link, ready to paste into the mailing.
     */
    public function export(Request $request): void
    {
        $rows = Database::all(
            "SELECT email, subscribed_at, source_page, unsubscribe_token
               FROM newsletter_subscribers WHERE status = 'subscribed'
              ORDER BY subscribed_at"
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="newsletter-subscribers-' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // so Excel reads it as UTF-8
        fputcsv($out, ['Email', 'Subscribed', 'Signed up on', 'Unsubscribe link']);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['email'],
                $r['subscribed_at'],
                $r['source_page'] ?? '',
                Newsletter::unsubscribeUrl($r),
            ]);
        }

        fclose($out);
        exit;
    }

    /** Take somebody off by hand — they rang and asked. */
    public function remove(Request $request): void
    {
        Database::run(
            "UPDATE newsletter_subscribers
                SET status = 'unsubscribed', unsubscribed_at = COALESCE(unsubscribed_at, NOW())
              WHERE id = :id",
            ['id' => $request->paramInt('id')]
        );

        Session::success('Unsubscribed. They will not appear in the next export.');
        Response::to('/newsletter');
    }

    // -----------------------------------------------------------------

    private function filter(string $show, string $search): array
    {
        $where  = 'status = :s';
        $params = ['s' => $show];

        if ($search !== '') {
            $where          .= ' AND email LIKE :q';
            $params['q']     = '%' . $search . '%';
        }

        return [$where, $params];
    }

    private function overLimit(string $ip): bool
    {
        $bucket = 'newsletter:' . substr(hash('sha256', $ip), 0, 24);
        $window = date('Y-m-d H:i:00');

        Database::run(
            'INSERT INTO bulk_rate_counters (bucket, window_start, hits) VALUES (:b, :w, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['b' => $bucket, 'w' => $window]
        );

        return (int) Database::scalar(
            'SELECT hits FROM bulk_rate_counters WHERE bucket = :b AND window_start = :w',
            ['b' => $bucket, 'w' => $window]
        ) > self::PER_MINUTE;
    }
}
