<?php

namespace App\Services\Mailbox;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Settings;
use App\Services\Mailer;
use RuntimeException;

/**
 * One member of staff's own mailbox.
 *
 * Constructed only from that person's own mail_accounts row — the
 * controller passes Auth::id() and nothing else — so there is no way to
 * ask it for somebody else's mail. That is the whole privacy model, and
 * it is deliberately that simple: no user id ever arrives from a request.
 *
 * Every public method opens one IMAP connection, does its work and lets
 * it close at the end of the request. cPanel hosting will not keep a
 * connection alive between requests anyway, and holding none means a
 * crashed request can never leave a mailbox locked.
 */
final class Mailbox
{
    private ?ImapClient $imap = null;

    /** Display order for folders a mailbox always has. */
    private const ROLE_ORDER = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'spam' => 4, 'trash' => 5];

    private function __construct(private array $account)
    {
    }

    // -----------------------------------------------------------------
    // The account
    // -----------------------------------------------------------------

    /** Is the company's mail server filled in at all? */
    public static function serverReady(): bool
    {
        return Settings::bool('mail_enabled', true)
            && trim((string) Settings::get('mail_imap_host', '')) !== ''
            && trim((string) Settings::get('mail_smtp_host', '')) !== '';
    }

    public static function accountFor(int $userId): ?array
    {
        return Database::first('SELECT * FROM mail_accounts WHERE user_id = :u', ['u' => $userId]);
    }

    /** This person's mailbox, or null if they have not connected one. */
    public static function for(int $userId): ?self
    {
        $row = self::accountFor($userId);

        return $row ? new self($row) : null;
    }

    /**
     * Connect a mailbox, proving the password works before keeping it.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function connectAccount(int $userId, string $email, string $password, string $displayName, string $signature): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That does not look like an email address.'];
        }

        $existing = self::accountFor($userId);

        // Keeping the saved password when the field is left empty, so
        // changing a signature does not mean typing the password again.
        if ($password === '' && $existing && strcasecmp($existing['email'], $email) === 0) {
            $password = (string) Crypto::decrypt($existing['password_enc']);
        }

        if ($password === '') {
            return ['ok' => false, 'error' => 'Enter the password for this mailbox.'];
        }

        // Another member of staff already reading this address here would
        // make "my mailbox" mean two people's.
        $taken = Database::scalar(
            'SELECT user_id FROM mail_accounts WHERE email = :e AND user_id <> :u',
            ['e' => $email, 'u' => $userId]
        );

        if ($taken) {
            return ['ok' => false, 'error' => 'Somebody else on the team has already connected that mailbox.'];
        }

        try {
            $probe = new self(['email' => $email, 'password_enc' => Crypto::encrypt($password)]);
            $probe->imap();
            $roles = $probe->discoverRoles();
            $probe->close();
        } catch (AuthFailed) {
            return ['ok' => false, 'error' => 'The mail server did not accept that email address and password.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $data = [
            'email'        => $email,
            'password_enc' => Crypto::encrypt($password),
            'display_name' => mb_substr(trim($displayName), 0, 120) ?: null,
            'signature'    => mb_substr(trim($signature), 0, 2000) ?: null,
            'sent_folder'  => $roles['sent'],
            'trash_folder' => $roles['trash'],
            'last_ok_at'   => date('Y-m-d H:i:s'),
            'last_error'   => null,
        ];

        if ($existing) {
            Database::update('mail_accounts', $data, ['user_id' => $userId]);
        } else {
            Database::insert('mail_accounts', $data + ['user_id' => $userId]);
        }

        return ['ok' => true];
    }

    public static function disconnect(int $userId): void
    {
        Database::delete('mail_accounts', ['user_id' => $userId]);
    }

    public function email(): string
    {
        return (string) $this->account['email'];
    }

    public function displayName(): string
    {
        return (string) ($this->account['display_name'] ?? '');
    }

    public function signature(): string
    {
        return (string) ($this->account['signature'] ?? '');
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Folders, the fixed ones first in the order every mail client uses.
     *
     * @return list<array{name:string, label:string, role:?string, unseen:int}>
     */
    public function folders(): array
    {
        $folders = $this->imap()->folders();

        foreach ($folders as &$f) {
            // Unread counts only where they are worth a round trip.
            $f['unseen'] = in_array($f['role'], ['inbox', 'spam'], true)
                ? $this->imap()->status($f['name'])['unseen']
                : 0;
        }
        unset($f);

        usort($folders, static function ($a, $b) {
            $ra = self::ROLE_ORDER[$a['role'] ?? ''] ?? 50;
            $rb = self::ROLE_ORDER[$b['role'] ?? ''] ?? 50;

            return $ra <=> $rb ?: strcasecmp($a['label'], $b['label']);
        });

        return $folders;
    }

    /**
     * One page of a folder.
     *
     * @return array{total:int, messages:list<array>}
     */
    public function messages(string $folder, int $page = 1, int $perPage = 30, string $search = ''): array
    {
        $this->imap()->select($folder, true);
        $uids = $this->imap()->search($search);
        $total = count($uids);

        $slice = array_slice($uids, max(0, ($page - 1) * $perPage), $perPage);
        $rows = $this->imap()->summaries($slice);

        $messages = [];

        // In the order search gave them — newest first — not fetch order.
        foreach ($slice as $uid) {
            if (!isset($rows[$uid])) {
                continue;
            }

            $r = $rows[$uid];
            $h = Mime::parseHeaders($r['headers']);

            $messages[] = [
                'uid'             => $uid,
                'subject'         => $h['subject'] !== '' ? $h['subject'] : '(no subject)',
                'from'            => $h['from'],
                'to'              => $h['to'],
                'date'            => $h['date'] ?? (strtotime($r['date']) ?: null),
                'size'            => $r['size'],
                'seen'            => in_array('\\Seen', $r['flags'], true),
                'flagged'         => in_array('\\Flagged', $r['flags'], true),
                'answered'        => in_array('\\Answered', $r['flags'], true),
                'has_attachments' => $h['has_attachments'],
            ];
        }

        return ['total' => $total, 'messages' => $messages];
    }

    /** A whole message, parsed. Opening it marks it read, as anywhere else. */
    public function message(string $folder, int $uid): ?array
    {
        $this->imap()->select($folder);
        $raw = $this->imap()->raw($uid);

        if ($raw === null) {
            return null;
        }

        $this->imap()->flag([$uid], '\\Seen', true);

        $m = Mime::parse($raw);
        $m['uid'] = $uid;
        $m['folder'] = $folder;

        return $m;
    }

    public function unreadInInbox(): int
    {
        return $this->imap()->status('INBOX')['unseen'];
    }

    // -----------------------------------------------------------------
    // Changing
    // -----------------------------------------------------------------

    public function setSeen(string $folder, array $uids, bool $seen): void
    {
        $this->imap()->select($folder);
        $this->imap()->flag($uids, '\\Seen', $seen);
    }

    public function setFlagged(string $folder, array $uids, bool $flagged): void
    {
        $this->imap()->select($folder);
        $this->imap()->flag($uids, '\\Flagged', $flagged);
    }

    /**
     * Delete: to Trash, as every client does. Deleting from Trash or Spam
     * is for good.
     */
    public function delete(string $folder, array $uids): void
    {
        $role = ImapClient::role($folder);
        $this->imap()->select($folder);

        if (in_array($role, ['trash', 'spam'], true) || !$this->trashFolder()) {
            $this->imap()->destroy($uids);
            return;
        }

        $this->imap()->move($uids, $this->trashFolder());
    }

    public function move(string $folder, array $uids, string $to): void
    {
        $known = array_column($this->imap()->folders(), 'name');

        if (!in_array($to, $known, true)) {
            throw new RuntimeException('There is no folder called ' . $to . '.');
        }

        $this->imap()->select($folder);
        $this->imap()->move($uids, $to);
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    /**
     * Send a message as this person, and file a copy in their Sent folder.
     *
     * Sent through the same cPanel server with their own login, so it
     * leaves from their address with their server's signatures on it —
     * not relayed through the system's notification account.
     *
     * @param array{to:string, cc?:string, bcc?:string, subject:string, text:string,
     *              in_reply_to?:string, references?:string,
     *              attachments?:list<array{name:string, mime:string, data:string}>} $in
     * @return array{ok:bool, error?:string, warning?:string}
     */
    public function send(array $in): array
    {
        $to = self::parseRecipients($in['to'] ?? '');
        $cc = self::parseRecipients($in['cc'] ?? '');
        $bcc = self::parseRecipients($in['bcc'] ?? '');

        foreach ([$to, $cc, $bcc] as $list) {
            if ($list['invalid']) {
                return ['ok' => false, 'error' => 'These do not look like email addresses: ' . implode(', ', $list['invalid'])];
            }
        }

        if (!$to['formatted']) {
            return ['ok' => false, 'error' => 'Add at least one person to send it to.'];
        }

        $text = rtrim((string) $in['text']);

        if ($this->signature() !== '' && !str_contains($text, $this->signature())) {
            $text .= "\n\n-- \n" . $this->signature();
        }

        $built = Mime::build([
            'from_email'  => $this->email(),
            'from_name'   => $this->displayName(),
            'to'          => $to['formatted'],
            'cc'          => $cc['formatted'],
            'subject'     => trim((string) $in['subject']) !== '' ? trim((string) $in['subject']) : '(no subject)',
            'text'        => $text,
            'in_reply_to' => $in['in_reply_to'] ?? '',
            'references'  => $in['references'] ?? '',
            'attachments' => $in['attachments'] ?? [],
        ]);

        $mailer = new Mailer(
            (string) Settings::get('mail_smtp_host', ''),
            Settings::int('mail_smtp_port', 465),
            (string) Settings::get('mail_smtp_security', 'ssl'),
            $this->email(),
            $this->password(),
            $this->email(),
            $this->displayName()
        );

        $result = $mailer->sendRaw($this->email(), array_merge($to['emails'], $cc['emails'], $bcc['emails']), $built['raw']);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => 'The message was not sent: ' . ($result['error'] ?? 'unknown error')];
        }

        // Filing the copy is separate from sending it. The message has gone
        // either way, so a failure here is a warning, not an error.
        $warning = null;

        try {
            $sent = $this->sentFolder();

            if ($sent) {
                $this->imap()->append($sent, $built['raw']);
            } else {
                $warning = 'Sent, but this mailbox has no Sent folder to keep a copy in.';
            }
        } catch (\Throwable $e) {
            $warning = 'Sent, but a copy could not be saved in Sent: ' . $e->getMessage();
        }

        if (!empty($result['refused'])) {
            $warning = trim(($warning ? $warning . ' ' : '') . 'The server refused: ' . implode(', ', $result['refused']) . '.');
        }

        return ['ok' => true] + ($warning ? ['warning' => $warning] : []);
    }

    /**
     * "Jane <jane@x.com>, bob@y.com" — split, checked, and returned both
     * as header-ready addresses and as bare addresses for the envelope.
     *
     * @return array{formatted:string[], emails:string[], invalid:string[]}
     */
    public static function parseRecipients(string $input): array
    {
        $out = ['formatted' => [], 'emails' => [], 'invalid' => []];

        // Commas and semicolons both, since people type both.
        foreach (Mime::addresses(str_replace(';', ',', $input)) as $a) {
            $email = $a['email'];

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out['invalid'][] = $a['name'] !== '' ? $a['name'] . ' <' . $email . '>' : $email;
                continue;
            }

            if (in_array($email, $out['emails'], true)) {
                continue;
            }

            $out['emails'][] = $email;
            $out['formatted'][] = Mime::formatAddress($a['name'], $email);
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------

    private function password(): string
    {
        $p = Crypto::decrypt((string) $this->account['password_enc']);

        if ($p === null) {
            throw new RuntimeException('The saved password for this mailbox can no longer be read. Connect it again.');
        }

        return $p;
    }

    private function imap(): ImapClient
    {
        if ($this->imap !== null) {
            return $this->imap;
        }

        $imap = new ImapClient(
            (string) Settings::get('mail_imap_host', ''),
            Settings::int('mail_imap_port', 993),
            (string) Settings::get('mail_imap_security', 'ssl')
        );
        $imap->connect();
        $imap->login($this->email(), $this->password());

        return $this->imap = $imap;
    }

    public function close(): void
    {
        $this->imap?->logout();
        $this->imap = null;
    }

    /** @return array{sent:?string, trash:?string} */
    private function discoverRoles(): array
    {
        $found = ['sent' => null, 'trash' => null];

        foreach ($this->imap()->folders() as $f) {
            // array_key_exists, not isset: the slots start as null, and
            // isset() is false for null — which left both unfound.
            $role = $f['role'] ?? '';

            if (array_key_exists($role, $found) && $found[$role] === null) {
                $found[$role] = $f['name'];
            }
        }

        return $found;
    }

    private function sentFolder(): ?string
    {
        return $this->account['sent_folder'] ?? null ?: $this->discoverRoles()['sent'];
    }

    private function trashFolder(): ?string
    {
        return $this->account['trash_folder'] ?? null ?: $this->discoverRoles()['trash'];
    }
}
