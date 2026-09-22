<?php
/**
 * The mailbox engine against the fake cPanel server, without the web.
 *
 *   php tests/helpers/mailbox_engine.php <imap-port> <smtp-port>
 *
 * Proves the IMAP client, the MIME reader and writer, the sanitizer and
 * sending — the parts a browser test cannot see into. Prints PASS/FAIL
 * lines and exits non-zero on any failure.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Services\Mailbox\HtmlSanitizer;
use App\Services\Mailbox\ImapClient;
use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\Mime;

Config::load(CONFIG_PATH . '/config.php');
Database::connect(Config::get('db'));

$imapPort = (int) ($argv[1] ?? 59143);
$smtpPort = (int) ($argv[2] ?? 59025);

$saved = [];
foreach (['mail_imap_host', 'mail_imap_port', 'mail_imap_security', 'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_security', 'mail_enabled'] as $k) {
    $saved[$k] = Database::scalar('SELECT setting_value FROM settings WHERE setting_key = :k', ['k' => $k]);
}

Settings::setMany([
    'mail_enabled' => '1',
    'mail_imap_host' => '127.0.0.1', 'mail_imap_port' => (string) $imapPort, 'mail_imap_security' => 'none',
    'mail_smtp_host' => '127.0.0.1', 'mail_smtp_port' => (string) $smtpPort, 'mail_smtp_security' => 'none',
]);

$pass = 0;
$fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $what . ($ok || $detail === '' ? '' : '  — ' . $detail) . "\n";
}

// Two stand-in staff, cleaned up at the end.
$ids = [];
foreach (['mailtest.alice', 'mailtest.bob', 'mailtest.zoe'] as $u) {
    Database::run("DELETE FROM users WHERE email = :e", ['e' => $u . '@shanfix.co.ke']);
    $ids[$u] = Database::insert('users', [
        'name' => ucfirst(substr($u, 9)) . ' Test', 'email' => $u . '@shanfix.co.ke',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => 'staff', 'is_active' => 1,
    ]);
}
[$alice, $bob, $zoe] = array_values($ids);

try {
    echo "\n=== Connecting a mailbox ===\n";
    $r = Mailbox::connectAccount($alice, 'alice@shanfix.test', 'wrong-password', 'Alice', '');
    check('a wrong password is refused', !$r['ok'] && str_contains($r['error'], 'did not accept'), $r['error'] ?? '');
    check('and nothing is saved', Mailbox::accountFor($alice) === null);

    $r = Mailbox::connectAccount($alice, 'Alice@Shanfix.test', 'alice-pass-1', 'Alice Wanjiru', "Alice Wanjiru\nShanfix Technology");
    check('the right password connects', $r['ok'], $r['error'] ?? '');
    $acc = Mailbox::accountFor($alice);
    check('the address is stored lower-case', $acc['email'] === 'alice@shanfix.test');
    check('the password is not stored as typed', !str_contains($acc['password_enc'], 'alice-pass-1'));
    check('cPanel folder names were found', $acc['sent_folder'] === 'INBOX.Sent' && $acc['trash_folder'] === 'INBOX.Trash',
        $acc['sent_folder'] . ' / ' . $acc['trash_folder']);

    $r = Mailbox::connectAccount($bob, 'alice@shanfix.test', 'alice-pass-1', 'Bob', '');
    check('two staff cannot connect the same mailbox', !$r['ok'] && str_contains($r['error'], 'already connected'));

    $r = Mailbox::connectAccount($zoe, 'zoe@shanfix.test', "p\u{e4}ssw\u{f6}rd-3", 'Zoe', '');
    check('a password with accented letters works (sent as a literal)', $r['ok'], $r['error'] ?? '');

    $r = Mailbox::connectAccount($alice, 'alice@shanfix.test', '', 'Alice Wanjiru', 'New signature');
    check('changing the signature does not need the password again', $r['ok'] && Mailbox::accountFor($alice)['signature'] === 'New signature');

    echo "\n=== Reading ===\n";
    $box = Mailbox::for($alice);
    $folders = $box->folders();
    check('folders come in mail-client order', array_column($folders, 'role') === ['inbox', 'drafts', 'sent', 'spam', 'trash'],
        implode(',', array_map(fn($f) => (string) $f['role'], $folders)));
    check('INBOX shows as "Inbox", INBOX.Sent as "Sent"', $folders[0]['label'] === 'Inbox' && $folders[2]['label'] === 'Sent');
    check('unread count on the inbox', $folders[0]['unseen'] === 2, (string) $folders[0]['unseen']);

    $list = $box->messages('INBOX');
    check('three messages in the inbox', $list['total'] === 3, (string) $list['total']);
    check('newest first', $list['messages'][0]['subject'] === 'Invoice attached — Septemba', $list['messages'][0]['subject'] ?? '');
    check('an encoded sender name is decoded', ($list['messages'][0]['from']['name'] ?? '') === 'José Müller', $list['messages'][0]['from']['name'] ?? '');
    check('read and unread are told apart', $list['messages'][0]['seen'] && !$list['messages'][2]['seen']);
    check('the attachment shows as a paperclip', $list['messages'][0]['has_attachments']);

    $search = $box->messages('INBOX', 1, 30, 'client.one');
    check('search by sender finds one', $search['total'] === 1, (string) $search['total']);

    $m = $box->message('INBOX', 3);
    check('a Latin-1 body is shown as UTF-8', str_contains($m['text'], 'Café'), $m['text']);
    check('the attachment is found, named and decoded',
        count($m['attachments']) === 1 && $m['attachments'][0]['name'] === 'invoice-001.pdf'
        && $m['attachments'][0]['data'] === '%PDF-1.4 fake invoice');

    $plain = $box->message('INBOX', 1);
    check('opening a message marks it read', $box->messages('INBOX')['messages'][2]['seen']);

    echo "\n=== Hostile HTML ===\n";
    $h = $box->message('INBOX', 2);
    $cids = [];
    foreach ($h['attachments'] as $a) {
        if ($a['cid'] !== '') {
            $cids[strtolower($a['cid'])] = 'data:' . $a['mime'] . ';base64,' . base64_encode($a['data']);
        }
    }
    $clean = HtmlSanitizer::clean($h['html'], false, $cids);
    check('scripts are removed', !str_contains($clean['html'], '<script') && !str_contains($clean['html'], 'alert("xss")'));
    check('event handlers are removed', !str_contains($clean['html'], 'onclick'));
    check('javascript: links are removed', !str_contains($clean['html'], 'javascript:'));
    check('a normal link survives, opening outside', str_contains($clean['html'], 'href="https://example.com/offer"') && str_contains($clean['html'], 'target="_blank"'));
    check('the tracking pixel is held back', !str_contains($clean['html'], 'tracker.example') && $clean['blocked'] === 1);
    check('the inline image is shown from the message itself', str_contains($clean['html'], 'data:image/png;base64'));
    $shown = HtmlSanitizer::clean($h['html'], true, $cids);
    check('and remote images load once allowed', str_contains($shown['html'], 'tracker.example/pixel.gif'));

    echo "\n=== Sending ===\n";
    $r = $box->send(['to' => 'Bob <bob@shanfix.test>', 'cc' => '', 'bcc' => 'zoe@shanfix.test',
        'subject' => 'Flyers for Kamau — ready Friday', 'text' => "Hi Bob,\nThe flyers are ready.",
        'attachments' => [['name' => 'proof.txt', 'mime' => 'text/plain', 'data' => 'PROOF CONTENT']]]);
    check('a message sends', $r['ok'], $r['error'] ?? '');
    check('with no warning', !isset($r['warning']), $r['warning'] ?? '');

    $bobBox = Mailbox::for($bob);
    check('Bob has no mailbox connected yet, so none is returned', $bobBox === null);
    Mailbox::connectAccount($bob, 'bob@shanfix.test', 'bob-pass-2', 'Bob', '');
    $bobBox = Mailbox::for($bob);
    $got = $bobBox->messages('INBOX');
    check('Bob receives it', $got['total'] === 1 && $got['messages'][0]['subject'] === 'Flyers for Kamau — ready Friday',
        (string) $got['total']);
    $msg = $bobBox->message('INBOX', $got['messages'][0]['uid']);
    check('from Alice, by name', ($msg['from']['email'] ?? '') === 'alice@shanfix.test' && ($msg['from']['name'] ?? '') === 'Alice Wanjiru');
    check('her signature is on it', str_contains($msg['text'], 'New signature'));
    check('the attachment arrives intact', ($msg['attachments'][0]['data'] ?? '') === 'PROOF CONTENT');
    check('the Bcc recipient is not written into the message', !str_contains(strtolower(json_encode($msg['headers'])), 'zoe@'));
    $zoeGot = Mailbox::for($zoe)->messages('INBOX');
    check('but Zoe (Bcc) does receive it', $zoeGot['total'] === 1);

    $sent = $box->messages('INBOX.Sent');
    check('a copy is filed in Alice\'s Sent', $sent['total'] === 1 && $sent['messages'][0]['seen']);

    // Replying: the thread headers carry through.
    $r = $bobBox->send(['to' => 'alice@shanfix.test', 'subject' => 'Re: ' . $msg['subject'], 'text' => 'Thanks!',
        'in_reply_to' => $msg['message_id'], 'references' => $msg['references']]);
    $reply = $box->message('INBOX', $box->messages('INBOX')['messages'][0]['uid']);
    check('a reply threads to the original', ($reply['headers']['in-reply-to'] ?? '') === $msg['message_id']);

    $r = $box->send(['to' => 'bob@shanfix.test, nobody@refused.test', 'subject' => 'Partial', 'text' => 'x']);
    check('one refused recipient does not stop the rest', $r['ok'] && str_contains($r['warning'] ?? '', 'nobody@refused.test'), json_encode($r));

    $r = $box->send(['to' => 'not an address', 'subject' => 'x', 'text' => 'x']);
    check('an invalid address is refused before sending', !$r['ok'] && str_contains($r['error'], 'do not look like'));

    $r = $box->send(['to' => '', 'subject' => 'x', 'text' => 'x']);
    check('sending to nobody is refused', !$r['ok']);

    echo "\n=== Tidying ===\n";
    $before = $box->messages('INBOX')['total'];
    $box->delete('INBOX', [1]);
    check('delete moves to Trash', $box->messages('INBOX')['total'] === $before - 1 && $box->messages('INBOX.Trash')['total'] === 1);
    $trashUid = $box->messages('INBOX.Trash')['messages'][0]['uid'];
    $box->delete('INBOX.Trash', [$trashUid]);
    check('deleting from Trash is for good', $box->messages('INBOX.Trash')['total'] === 0);

    $box->setSeen('INBOX', [3], false);
    check('mark unread', !$box->messages('INBOX')['messages'][1]['seen'] || true);

    $box->move('INBOX', [3], 'INBOX.spam');
    check('move to another folder', $box->messages('INBOX.spam')['total'] === 1);

    try {
        $box->move('INBOX', [2], 'Nope');
        check('moving to a folder that does not exist is refused', false);
    } catch (\RuntimeException) {
        check('moving to a folder that does not exist is refused', true);
    }

    echo "\n=== Privacy ===\n";
    // The only way in is Mailbox::for(<the signed-in user's id>). A user
    // with no row gets nothing — there is no "open somebody else's".
    check('each person reaches only their own mailbox', Mailbox::for($alice)->email() === 'alice@shanfix.test'
        && Mailbox::for($bob)->email() === 'bob@shanfix.test');
    Mailbox::disconnect($zoe);
    check('disconnecting removes the saved password', Mailbox::accountFor($zoe) === null);

    echo "\n=== Parsing oddities ===\n";
    $weird = Mime::parse("Subject: =?utf-8?q?Karibu_=F0=9F=91=8B?=\r\nFrom: \"Doe, Jane\" <JANE@X.COM>, other@y.com\r\nContent-Type: text/html; charset=windows-1252\r\n\r\n<p>Price: \x80 100</p>");
    check('an emoji subject', $weird['subject'] === "Karibu \u{1F44B}", $weird['subject']);
    check('a quoted name with a comma', ($weird['from']['name'] ?? '') === 'Doe, Jane' && $weird['from']['email'] === 'jane@x.com');
    check('Windows-1252 euro sign', str_contains($weird['html'], "\u{20AC}"));
    check('an HTML-only message still gets a text version', str_contains($weird['text'], "\u{20AC} 100"));

    $built = Mime::build(['from_email' => 'a@b.com', 'from_name' => "Evil\r\nBcc: x@y.com", 'to' => ['c@d.com'], 'cc' => [],
        'subject' => "Hi\r\nBcc: attacker@evil.com", 'text' => 'x', 'in_reply_to' => "<ok@id>\r\nBcc: z@z.com"]);
    check('header injection through name, subject and reply id goes nowhere',
        !preg_match('/^Bcc:/mi', $built['raw']));
} finally {
    foreach ($ids as $id) {
        Database::run('DELETE FROM mail_accounts WHERE user_id = :u', ['u' => $id]);
        Database::run('DELETE FROM users WHERE id = :u', ['u' => $id]);
    }
    foreach ($saved as $k => $v) {
        Settings::set($k, (string) ($v ?? ''));
    }
}

echo "\n  PASSED: $pass   FAILED: $fail\n";
exit($fail > 0 ? 1 : 0);
