<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\Mailbox\AuthFailed;
use App\Services\Mailbox\HtmlSanitizer;
use App\Services\Mailbox\Mailbox;

/**
 * Each member of staff's own email, inside the system.
 *
 * Privacy is enforced by construction rather than by checks: every
 * action opens Mailbox::for(Auth::id()) — the signed-in person's own
 * account — and nothing in any request can name another. There is no
 * administrator view, no "open as", and no user id parameter anywhere in
 * these routes. An administrator can reset a mailbox's password in
 * cPanel, which its owner will notice; this system offers no quiet way in.
 *
 * Mail stays on the cPanel server. This reads and writes it there over
 * IMAP and SMTP, so a message read here is read in Outlook or on a phone
 * too, and nothing is copied into the system's own database.
 */
class MailController extends Controller
{
    private const PER_PAGE = 30;

    // -----------------------------------------------------------------
    // Connecting a mailbox
    // -----------------------------------------------------------------

    public function setup(Request $request): void
    {
        $this->view('mail/setup', [
            'title'       => 'Connect your mailbox',
            'account'     => Mailbox::accountFor((int) Auth::id()),
            'serverReady' => Mailbox::serverReady(),
            'me'          => Auth::user(),
        ]);
    }

    public function connect(Request $request): void
    {
        if (!Mailbox::serverReady()) {
            Session::error('The mail server has not been set up yet. Ask an administrator to fill in Settings → Email server.');
            Response::to('/mail/setup');
        }

        $result = Mailbox::connectAccount(
            (int) Auth::id(),
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            (string) $request->input('display_name', ''),
            (string) $request->input('signature', '')
        );

        if (!$result['ok']) {
            Session::error($result['error']);
            Response::to('/mail/setup');
        }

        Session::success('Your mailbox is connected.');
        Response::to('/mail');
    }

    public function disconnect(Request $request): void
    {
        Mailbox::disconnect((int) Auth::id());
        Session::success('Your mailbox is disconnected. Its saved password has been deleted from the system; your mail is untouched on the server.');
        Response::to('/mail/setup');
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public function index(Request $request): void
    {
        $box = $this->box();
        $folder = $this->folderParam($request);
        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $uid = (int) $request->query('uid', 0);

        try {
            $folders = $box->folders();
            $list = $box->messages($folder, $page, self::PER_PAGE, $search);
            $message = $uid > 0 ? $box->message($folder, $uid) : null;
        } catch (AuthFailed) {
            $this->serverRefused();
        } catch (\Throwable $e) {
            $this->view('mail/unavailable', ['title' => 'Email', 'error' => $e->getMessage()]);
            return;
        }

        // Opening a message changed its unread state after the list and
        // folder counts were read; reflect that without another round trip.
        if ($message) {
            foreach ($list['messages'] as &$m) {
                if ($m['uid'] === $uid && !$m['seen']) {
                    $m['seen'] = true;
                    foreach ($folders as &$f) {
                        if ($f['name'] === $folder && $f['unseen'] > 0) {
                            $f['unseen']--;
                        }
                    }
                    unset($f);
                }
            }
            unset($m);
        }

        $box->close();

        $this->view('mail/index', [
            'title'    => 'Email',
            'box'      => $box,
            'folders'  => $folders,
            'folder'   => $folder,
            'search'   => $search,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'total'    => $list['total'],
            'messages' => $list['messages'],
            'message'  => $message,
            'uid'      => $uid,
        ]);
    }

    /**
     * The body of one message, as its own page, for the reading pane's
     * iframe.
     *
     * Served with a policy that allows no script and no network request
     * but the images the reader has chosen to load, and shown in a
     * sandboxed frame. The sanitizer is the first defence; these are the
     * second and third.
     */
    public function body(Request $request): void
    {
        $box = $this->box();
        $allowImages = $request->query('images') === '1';

        try {
            $m = $box->message($this->folderParam($request), $request->paramInt('uid'));
        } catch (\Throwable $e) {
            $m = null;
        }

        $box->close();

        $inner = '<p style="color:#888">This message could not be loaded.</p>';
        $blocked = 0;

        if ($m) {
            if ($m['html'] !== '') {
                $cids = [];
                foreach ($m['attachments'] as $a) {
                    // Inline images travel inside the message; showing them
                    // makes no request to anybody.
                    if ($a['cid'] !== '' && str_starts_with($a['mime'], 'image/') && $a['size'] < 2_000_000) {
                        $cids[strtolower($a['cid'])] = 'data:' . $a['mime'] . ';base64,' . base64_encode($a['data']);
                    }
                }

                $clean = HtmlSanitizer::clean($m['html'], $allowImages, $cids);
                $inner = $clean['html'];
                $blocked = $clean['blocked'];
            } else {
                $inner = HtmlSanitizer::fromText($m['text']);
            }
        }

        header_remove('Content-Security-Policy');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; font-src data:; "
            . 'img-src data:' . ($allowImages ? ' https: http:' : '') . "; base-uri 'none'; form-action 'none'");
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');
        header('X-Blocked-Images: ' . $blocked);

        echo '<!doctype html><html><head><meta charset="utf-8"><base target="_blank">'
            . '<style>body{margin:0;padding:18px 20px;font:14px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;'
            . 'color:#1f2937;background:#fff;word-wrap:break-word}img{max-width:100%;height:auto}'
            . 'blockquote{margin:0 0 0 .6em;padding-left:.8em;border-left:3px solid #d1d5db;color:#4b5563}'
            . 'pre{white-space:pre-wrap}table{max-width:100%}</style></head><body>'
            . $inner
            . '</body></html>';
        exit;
    }

    public function attachment(Request $request): void
    {
        $box = $this->box();

        try {
            $m = $box->message($this->folderParam($request), $request->paramInt('uid'));
        } catch (\Throwable) {
            $m = null;
        }

        $box->close();

        $a = $m['attachments'][$request->paramInt('index')] ?? null;

        if (!$a) {
            Session::error('That attachment could not be found.');
            Response::to('/mail');
        }

        // Always a download, never shown in the page: an attachment is a
        // stranger's file, and an HTML or SVG one opened inline would run
        // with this system's origin.
        $name = preg_replace('/[^\w.\- ()]+/u', '_', $a['name']) ?: 'attachment';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($a['name']));
        header('Content-Length: ' . strlen($a['data']));
        header('X-Content-Type-Options: nosniff');
        echo $a['data'];
        exit;
    }

    /** For the sidebar badge. Cached briefly: it is asked on every page. */
    public function unread(Request $request): void
    {
        $cached = Session::get('mail_unread');

        if (is_array($cached) && ($cached['at'] ?? 0) > time() - 60) {
            Response::json(['ok' => true, 'unread' => $cached['n']]);
        }

        $box = Mailbox::for((int) Auth::id());

        if (!$box || !Mailbox::serverReady()) {
            Response::json(['ok' => true, 'unread' => 0]);
        }

        try {
            $n = $box->unreadInInbox();
            $box->close();
        } catch (\Throwable) {
            Response::json(['ok' => false, 'unread' => 0]);
        }

        Session::put('mail_unread', ['n' => $n, 'at' => time()]);
        Response::json(['ok' => true, 'unread' => $n]);
    }

    // -----------------------------------------------------------------
    // Acting on messages
    // -----------------------------------------------------------------

    public function action(Request $request): void
    {
        $box = $this->box();
        $folder = $this->folderParam($request);
        $uids = array_values(array_filter(array_map('intval', (array) $request->input('uids', []))));
        $do = (string) $request->input('do', '');
        $back = '/mail?folder=' . rawurlencode($folder);

        if (!$uids) {
            Response::to($back);
        }

        try {
            match ($do) {
                'read'    => $box->setSeen($folder, $uids, true),
                'unread'  => $box->setSeen($folder, $uids, false),
                'flag'    => $box->setFlagged($folder, $uids, true),
                'unflag'  => $box->setFlagged($folder, $uids, false),
                'delete'  => $box->delete($folder, $uids),
                'move'    => $box->move($folder, $uids, (string) $request->input('to', '')),
                default   => null,
            };
            $box->close();
        } catch (\Throwable $e) {
            Session::error('That did not work: ' . $e->getMessage());
            Response::to($back);
        }

        Session::forget('mail_unread');

        $done = [
            'read' => 'Marked read.', 'unread' => 'Marked unread.', 'flag' => 'Flagged.', 'unflag' => 'Flag removed.',
            'delete' => count($uids) === 1 ? 'Deleted.' : count($uids) . ' deleted.', 'move' => 'Moved.',
        ];
        Session::success($done[$do] ?? 'Done.');

        // Flagging from the reading pane leaves the message open. Marking it
        // unread does not: reopening it would mark it read again.
        if (in_array($do, ['flag', 'unflag'], true) && count($uids) === 1 && $request->input('stay')) {
            $back .= '&uid=' . $uids[0];
        }

        Response::to($back);
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    public function compose(Request $request): void
    {
        $box = $this->box();
        $mode = (string) $request->query('mode', '');
        $draft = ['to' => (string) $request->query('to', ''), 'cc' => '', 'bcc' => '', 'subject' => '', 'text' => '',
                  'in_reply_to' => '', 'references' => '', 'fwd_uid' => 0, 'fwd_folder' => '', 'fwd_files' => []];

        if (in_array($mode, ['reply', 'all', 'forward'], true)) {
            $folder = $this->folderParam($request);
            $uid = (int) $request->query('uid', 0);

            try {
                $orig = $box->message($folder, $uid);
            } catch (\Throwable) {
                $orig = null;
            }

            if ($orig) {
                $draft = $this->prefill($draft, $orig, $mode, $box->email(), $folder, $uid);
            }
        }

        $box->close();

        // What was typed before a failed send comes back, rather than being
        // lost — nobody should have to write an email twice.
        $old = Session::get('mail_draft');
        if (is_array($old)) {
            $draft = array_merge($draft, $old);
            Session::forget('mail_draft');
        }

        $this->view('mail/compose', [
            'title'    => 'New message',
            'draft'    => $draft,
            'mode'     => $mode,
            'box'      => $box,
            'maxMb'    => Settings::int('mail_max_attach_mb', 20),
        ]);
    }

    public function send(Request $request): void
    {
        $box = $this->box();

        $in = [
            'to'          => (string) $request->input('to', ''),
            'cc'          => (string) $request->input('cc', ''),
            'bcc'         => (string) $request->input('bcc', ''),
            'subject'     => (string) $request->input('subject', ''),
            'text'        => (string) $request->input('text', ''),
            'in_reply_to' => (string) $request->input('in_reply_to', ''),
            'references'  => (string) $request->input('references', ''),
        ];

        $attachments = [];
        $total = 0;
        $maxBytes = Settings::int('mail_max_attach_mb', 20) * 1024 * 1024;

        // Files chosen on the form.
        $files = $_FILES['attachments'] ?? null;

        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) {
                    $this->keepDraft($in, 'The file "' . $name . '" did not upload. It may be larger than the server allows.');
                }

                $data = (string) file_get_contents($files['tmp_name'][$i]);
                $total += strlen($data);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';
                $attachments[] = ['name' => basename((string) $name), 'mime' => $mime, 'data' => $data];
            }
        }

        // Attachments carried over when forwarding, fetched fresh from the
        // original rather than trusted from the form.
        $fwdUid = (int) $request->input('fwd_uid', 0);
        $keep = array_map('intval', (array) $request->input('fwd_keep', []));

        if ($fwdUid > 0 && $keep) {
            try {
                $orig = $box->message((string) $request->input('fwd_folder', 'INBOX'), $fwdUid);
                foreach ($orig['attachments'] ?? [] as $i => $a) {
                    if (in_array($i, $keep, true) && !$a['inline']) {
                        $attachments[] = ['name' => $a['name'], 'mime' => $a['mime'], 'data' => $a['data']];
                        $total += $a['size'];
                    }
                }
            } catch (\Throwable) {
                $this->keepDraft($in, 'The original message could not be read to forward its attachments.');
            }
        }

        if ($total > $maxBytes) {
            $this->keepDraft($in, 'Attachments come to ' . round($total / 1048576, 1) . 'MB; the limit is '
                . Settings::int('mail_max_attach_mb', 20) . 'MB.');
        }

        $in['attachments'] = $attachments;
        $result = $box->send($in);
        $box->close();

        if (!$result['ok']) {
            $this->keepDraft($in, $result['error']);
        }

        isset($result['warning']) ? Session::warning($result['warning']) : Session::success('Sent.');
        Response::to('/mail');
    }

    // -----------------------------------------------------------------

    /** The signed-in person's own mailbox — or the setup page. */
    private function box(): Mailbox
    {
        if (!Mailbox::serverReady()) {
            Response::to('/mail/setup');
        }

        $box = Mailbox::for((int) Auth::id());

        if (!$box) {
            Response::to('/mail/setup');
        }

        return $box;
    }

    private function folderParam(Request $request): string
    {
        $f = trim((string) ($request->input('folder', '') ?: $request->query('folder', 'INBOX')));

        // A folder name is sent to the server quoted or as a literal, so it
        // cannot inject a command; this only keeps it sensible.
        return $f !== '' && mb_strlen($f) <= 190 ? $f : 'INBOX';
    }

    private function serverRefused(): never
    {
        Database::update('mail_accounts', ['last_error' => 'Password refused'], ['user_id' => (int) Auth::id()]);
        Session::error('The mail server no longer accepts your saved password — it may have been changed. Enter it again.');
        Response::to('/mail/setup');
    }

    private function keepDraft(array $in, string $error): never
    {
        unset($in['attachments']);
        Session::put('mail_draft', $in);
        Session::error($error . (str_contains($error, 'attach') ? '' : ' Your message has been kept below.'));
        Response::to('/mail/compose');
    }

    /** Fill a reply or forward from the message being answered. */
    private function prefill(array $d, array $o, string $mode, string $me, string $folder, int $uid): array
    {
        $subject = $o['subject'];
        $fromLine = trim(($o['from']['name'] ?? '') . ' <' . ($o['from']['email'] ?? '') . '>');
        $when = $o['date'] ? date('D, j M Y \a\t H:i', $o['date']) : '';

        if ($mode === 'forward') {
            $d['subject'] = preg_match('/^fwd?:/i', $subject) ? $subject : 'Fwd: ' . $subject;
            $d['text'] = "\n\n---------- Forwarded message ----------\n"
                . 'From: ' . $fromLine . "\n"
                . ($when ? 'Date: ' . $when . "\n" : '')
                . 'Subject: ' . $subject . "\n"
                . 'To: ' . implode(', ', array_map(fn($a) => $a['email'], $o['to'])) . "\n\n"
                . $o['text'];
            $d['fwd_uid'] = $uid;
            $d['fwd_folder'] = $folder;
            $d['fwd_files'] = array_values(array_filter(array_map(
                fn($a, $i) => $a['inline'] ? null : ['index' => $i, 'name' => $a['name'], 'size' => $a['size']],
                $o['attachments'], array_keys($o['attachments'])
            )));

            return $d;
        }

        $d['subject'] = preg_match('/^re:/i', $subject) ? $subject : 'Re: ' . $subject;
        $d['in_reply_to'] = $o['message_id'];
        $d['references'] = $o['references'];

        $replyTo = $o['reply_to'][0] ?? $o['from'];
        $to = [$replyTo];

        if ($mode === 'all') {
            foreach (array_merge($o['to'], $o['cc']) as $a) {
                $to[] = $a;
            }
        }

        // Nobody replies to themselves, and nobody twice.
        $seen = [];
        $d['to'] = implode(', ', array_filter(array_map(function ($a) use ($me, &$seen) {
            if (!$a || strcasecmp($a['email'], $me) === 0 || isset($seen[strtolower($a['email'])])) {
                return null;
            }
            $seen[strtolower($a['email'])] = true;
            return $a['name'] !== '' ? $a['name'] . ' <' . $a['email'] . '>' : $a['email'];
        }, $to)));

        $quoted = implode("\n", array_map(fn($l) => '> ' . $l, explode("\n", rtrim($o['text']))));
        $d['text'] = "\n\nOn " . $when . ', ' . ($o['from']['name'] ?: $o['from']['email'] ?? '') . " wrote:\n" . $quoted;

        return $d;
    }
}
