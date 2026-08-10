<?php
namespace App\Services;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\View;

/**
 * Decides what to send, renders it, queues it, and dispatches it.
 *
 * Everything goes through the queue so a slow or unreachable mail server
 * never blocks the person clicking "send", and every attempt is on record.
 */
class Notifier
{
    public const EVENTS = [
        // Quotations
        'quotation_sent'      => 'Quotation sent to client',
        'quotation_accepted'  => 'Quotation accepted — confirming we are proceeding',
        'quotation_expiring'  => 'Quotation about to expire',

        // Invoices and money
        'invoice_sent'        => 'Invoice sent to client',
        'payment_reminder'    => 'Payment due shortly (before the due date)',
        'invoice_overdue'     => 'Overdue invoice reminder',
        'payment_partial'     => 'Part payment received, balance outstanding',
        'payment_received'    => 'Payment received in full',
        'receipt_issued'      => 'Receipt issued',

        // Production
        'proof_ready'         => 'Proof ready for client approval',
        'job_in_production'   => 'Job has gone into production',
        'job_ready'           => 'Job ready for collection',

        // Delivery
        'delivery_dispatched' => 'Delivery dispatched — on the way',
        'delivery_confirmed'  => 'Delivery received and signed for',
    ];

    /**
     * Events whose message is about a job or a delivery rather than a
     * document. The email template uses this to pick the detail block.
     */
    /**
     * Characters of the share token used in the SMS short link. Ten hex
     * characters is 40 bits — far too many to guess — and saves about 41
     * characters against the full link, which is the difference between one
     * billable SMS part and two.
     */
    public const SHORT_TOKEN_LENGTH = 10;

    public const JOB_EVENTS = [
        'proof_ready', 'job_in_production', 'job_ready',
        'delivery_dispatched', 'delivery_confirmed',
    ];

    // -- Queueing ------------------------------------------------------

    /**
     * Queue a message on each requested channel.
     *
     * @param array      $context  Placeholder values plus recipient details
     * @param bool       $force    Ignore the per-event toggles (an operator
     *                             pressing "send" overrides the automation)
     * @param array|null $channels Restrict to these channels; null = both
     *
     * @return array{queued:int, skipped:array<int,string>}
     */
    public static function dispatch(
        string $event,
        array $context,
        bool $force = false,
        ?array $channels = null
    ): array {
        $queued  = 0;
        $skipped = [];

        foreach (['email', 'sms'] as $channel) {
            if ($channels !== null && !in_array($channel, $channels, true)) {
                continue;
            }

            $enabled = Settings::bool("notify_{$event}_{$channel}", false);

            if (!$enabled && !$force) {
                continue;
            }

            $result = $channel === 'email'
                ? self::queueEmail($event, $context)
                : self::queueSms($event, $context);

            if ($result === true) {
                $queued++;
            } elseif (is_string($result)) {
                $skipped[] = $result;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    private static function queueEmail(string $event, array $context): true|string
    {
        if (!Settings::bool('smtp_enabled')) {
            return 'Email is not enabled in Settings.';
        }

        $to = trim((string) ($context['email'] ?? ''));

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'No valid email address on file for ' . ($context['client_name'] ?? 'this client') . '.';
        }

        $subject = self::render(Settings::get("tpl_{$event}_subject", ucfirst($event)), $context);
        $intro   = self::render(Settings::get("tpl_{$event}_intro", ''), $context);

        $company = Settings::company();

        // Mail clients fetch images over the internet, so the logo needs an
        // absolute URL and a route that does not require a session.
        $company['logo_url'] = $company['logo'] !== ''
            ? self::absoluteUrl('/brand/logo')
            : '';

        $html = View::capture('emails/document', [
            'event'   => $event,
            'intro'   => $intro,
            'context' => $context,
            'company' => $company,
            'footer'  => Settings::get('email_footer_note', ''),
        ], null);

        Database::insert('notifications', [
            'channel'        => 'email',
            'event'          => $event,
            'recipient'      => $to,
            'recipient_name' => $context['contact_name'] ?? ($context['client_name'] ?? null),
            'subject'        => mb_substr($subject, 0, 255),
            'body'           => $html,
            'entity_type'    => $context['entity_type'] ?? null,
            'entity_id'      => $context['entity_id'] ?? null,
            'client_id'      => $context['client_id'] ?? null,
            'created_by'     => Auth::id(),
        ]);

        return true;
    }

    private static function queueSms(string $event, array $context): true|string
    {
        if (!Settings::bool('sms_enabled')) {
            return 'SMS is not enabled in Settings.';
        }

        $template = Settings::get("tpl_sms_{$event}", '');

        if (trim((string) $template) === '') {
            return 'No SMS template set for this event.';
        }

        $phone = normalize_phone((string) ($context['phone'] ?? ''));

        if ($phone === null) {
            return 'No valid phone number on file for ' . ($context['client_name'] ?? 'this client') . '.';
        }

        // SMS links must be short enough to leave room for the message.
        $smsContext = $context;
        $smsContext['link'] = $context['short_link'] ?? ($context['link'] ?? '');

        Database::insert('notifications', [
            'channel'        => 'sms',
            'event'          => $event,
            'recipient'      => $phone,
            'recipient_name' => $context['contact_name'] ?? ($context['client_name'] ?? null),
            'body'           => self::render($template, $smsContext),
            'entity_type'    => $context['entity_type'] ?? null,
            'entity_id'      => $context['entity_id'] ?? null,
            'client_id'      => $context['client_id'] ?? null,
            'created_by'     => Auth::id(),
        ]);

        return true;
    }

    // -- Sending -------------------------------------------------------

    /**
     * Send queued messages. Called by cron.php, or directly after a user
     * action so they see the result immediately.
     *
     * @return array{sent:int, failed:int, processed:int}
     */
    public static function processQueue(int $limit = 25, ?int $onlyId = null): array
    {
        $maxAttempts = Settings::int('notify_max_attempts', 3);

        $where  = "status = 'queued' AND attempts < :max";
        $params = ['max' => $maxAttempts];

        if ($onlyId !== null) {
            $where .= ' AND id = :id';
            $params['id'] = $onlyId;
        } else {
            $where .= ' AND (scheduled_at IS NULL OR scheduled_at <= NOW())';
        }

        $rows = Database::all(
            "SELECT * FROM notifications WHERE {$where} ORDER BY id ASC LIMIT " . max(1, $limit),
            $params
        );

        $sent = 0;
        $failed = 0;

        $mailer = new Mailer();
        $sms    = new Sms();

        foreach ($rows as $row) {
            // Claim the row so two overlapping cron runs cannot double-send.
            $claimed = Database::run(
                "UPDATE notifications SET status = 'sending', attempts = attempts + 1
                  WHERE id = :id AND status = 'queued'",
                ['id' => $row['id']]
            )->rowCount();

            if ($claimed === 0) {
                continue;
            }

            $result = $row['channel'] === 'email'
                ? $mailer->send(
                    $row['recipient'],
                    (string) ($row['recipient_name'] ?? ''),
                    (string) $row['subject'],
                    (string) $row['body']
                  )
                : $sms->send($row['recipient'], (string) $row['body']);

            if ($result['ok']) {
                Database::update('notifications', [
                    'status'       => 'sent',
                    'sent_at'      => date('Y-m-d H:i:s'),
                    'provider_ref' => $result['ref'] ?? null,
                    'cost'         => $result['cost'] ?? null,
                    'last_error'   => null,
                ], ['id' => $row['id']]);

                $sent++;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $giveUp   = $attempts >= $maxAttempts;

                Database::update('notifications', [
                    'status'     => $giveUp ? 'failed' : 'queued',
                    'last_error' => mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 500),
                ], ['id' => $row['id']]);

                $failed++;

                Logger::warning('Notification send failed', [
                    'id'      => $row['id'],
                    'channel' => $row['channel'],
                    'error'   => $result['error'] ?? '',
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'processed' => count($rows)];
    }

    // -- Building context ----------------------------------------------

    /**
     * Everything a template might need about a document, including the
     * public share link.
     */
    public static function documentContext(array $doc): array
    {
        $token = self::ensureToken((int) $doc['id'], $doc['public_token'] ?? null);
        $link  = self::publicUrl($token);

        $paid    = (float) ($doc['amount_paid'] ?? 0);
        $balance = (float) ($doc['balance'] ?? 0);

        return [
            'entity_type'  => 'document',
            'entity_id'    => (int) $doc['id'],
            'client_id'    => (int) $doc['client_id'],
            'client_name'  => $doc['client_name'] ?? '',
            'contact_name' => self::firstName($doc['client_contact'] ?? ($doc['client_name'] ?? '')),
            'email'        => $doc['client_email'] ?? '',
            'phone'        => $doc['client_phone'] ?? '',
            'company_name' => Settings::get('company_name', 'Shanfix Technology'),
            'company_phone'=> Settings::get('company_phone', ''),
            'doc_number'   => $doc['doc_number'] ?? '',
            'doc_type'     => ucfirst((string) ($doc['doc_type'] ?? 'document')),
            'title'        => $doc['title'] ?? '',
            'amount'       => money($doc['total'] ?? 0),
            'subtotal'     => money($doc['subtotal'] ?? 0),
            'vat'          => money($doc['vat_amount'] ?? 0),
            'discount'     => money($doc['discount_amount'] ?? 0),
            'paid'         => money($paid),
            'balance'      => money($balance),
            'total_raw'    => (float) ($doc['total'] ?? 0),
            'balance_raw'  => $balance,
            'issue_date'   => fdate($doc['issue_date'] ?? null),
            'due_date'     => fdate($doc['due_date'] ?? null),
            'valid_until'  => fdate($doc['valid_until'] ?? null),
            'days_to_due'  => self::daysUntil($doc['due_date'] ?? null),
            'days_to_expiry' => self::daysUntil($doc['valid_until'] ?? null),
            'link'         => $link,
            'short_link'   => self::shortUrl($token),
            'document'     => $doc,
        ];
    }

    public static function jobContext(array $job): array
    {
        $stages = [
            'pending' => 'Queued', 'artwork' => 'Artwork', 'proof_sent' => 'Proof sent',
            'approved' => 'Approved', 'production' => 'In production', 'finishing' => 'Finishing',
            'ready' => 'Ready', 'delivered' => 'Delivered', 'on_hold' => 'On hold',
            'cancelled' => 'Cancelled',
        ];

        return [
            'entity_type'  => 'job',
            'entity_id'    => (int) $job['id'],
            'client_id'    => (int) $job['client_id'],
            'client_name'  => $job['client_name'] ?? '',
            'contact_name' => self::firstName($job['client_contact'] ?? ($job['client_name'] ?? '')),
            'email'        => $job['client_email'] ?? '',
            'phone'        => $job['client_phone'] ?? '',
            'company_name' => Settings::get('company_name', 'Shanfix Technology'),
            'company_phone'=> Settings::get('company_phone', ''),
            'company_address' => Settings::get('company_address', ''),
            'job_number'   => $job['job_number'] ?? '',
            'job_title'    => $job['title'] ?? '',
            'job_stage'    => $stages[$job['stage'] ?? ''] ?? ucfirst((string) ($job['stage'] ?? '')),
            'description'  => $job['description'] ?? '',
            'due_date'     => fdate($job['due_date'] ?? null),
            'doc_number'   => $job['doc_number'] ?? '',
            'link'         => '',
            'job'          => $job,
        ];
    }

    /**
     * Context for a delivery note. Carries the details a client actually
     * wants when something is on its way: who is bringing it, in what, and
     * where to.
     */
    public static function deliveryContext(array $note): array
    {
        return [
            'entity_type'   => 'delivery_note',
            'entity_id'     => (int) $note['id'],
            'client_id'     => (int) $note['client_id'],
            'client_name'   => $note['client_name'] ?? '',
            'contact_name'  => self::firstName($note['client_contact'] ?? ($note['client_name'] ?? '')),
            'email'         => $note['client_email'] ?? '',
            'phone'         => $note['client_phone'] ?? '',
            'company_name'  => Settings::get('company_name', 'Shanfix Technology'),
            'company_phone' => Settings::get('company_phone', ''),
            'dn_number'     => $note['dn_number'] ?? '',
            'job_number'    => $note['job_number'] ?? '',
            'doc_number'    => $note['doc_number'] ?? '',
            'job_title'     => $note['job_title'] ?? '',
            'delivery_date' => fdate($note['delivery_date'] ?? null),
            'delivered_to'  => $note['delivered_to'] ?? '',
            'delivery_address' => $note['delivery_address'] ?? '',
            'delivered_by'  => $note['delivered_by'] ?? '',
            'vehicle_reg'   => $note['vehicle_reg'] ?? '',
            'received_by'   => $note['received_by'] ?? '',
            'received_at'   => fdate($note['received_at'] ?? null),
            'link'          => '',
            'delivery'      => $note,
        ];
    }

    /** Whole days from today until $date; null when there is no date. */
    private static function daysUntil(?string $date): string
    {
        if (!$date) {
            return '';
        }

        $target = strtotime($date);

        if ($target === false) {
            return '';
        }

        $days = (int) floor(($target - strtotime('today')) / 86400);

        return (string) $days;
    }

    /**
     * The newest proof still awaiting a decision on this job, with a share
     * token minted if it does not have one yet.
     *
     * Returns null when there is nothing pending — a proof_ready message
     * with no proof behind it would only confuse the client.
     *
     * @return array{id:int, token:string, link:string, short_link:string}|null
     */
    public static function pendingProof(int $jobId): ?array
    {
        $proof = Database::first(
            "SELECT id, public_token, version
               FROM job_files
              WHERE job_id = :id AND file_type = 'proof' AND status = 'pending'
           ORDER BY version DESC, id DESC
              LIMIT 1",
            ['id' => $jobId]
        );

        if (!$proof) {
            return null;
        }

        $token = (string) ($proof['public_token'] ?? '');

        if ($token === '') {
            $token = bin2hex(random_bytes(24));   // 48 hex chars
            Database::update('job_files', ['public_token' => $token], ['id' => $proof['id']]);
        }

        return [
            'id'         => (int) $proof['id'],
            'version'    => (int) $proof['version'],
            'token'      => $token,
            'link'       => self::absoluteUrl('/proof/' . $token),
            'short_link' => self::absoluteUrl('/p/' . substr($token, 0, self::SHORT_TOKEN_LENGTH)),
        ];
    }

    /** Mint a share token the first time a document needs a public link. */
    public static function ensureToken(int $documentId, ?string $existing = null): string
    {
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(24));   // 48 hex chars

        Database::update('documents', ['public_token' => $token], ['id' => $documentId]);

        return $token;
    }

    public static function publicUrl(string $token): string
    {
        return self::absoluteUrl('/view/' . $token);
    }

    /**
     * The same document on a much shorter URL, for SMS.
     *
     * The full link is 79 characters, which alone pushes a routine text over
     * one billable part. This trims it to about 38.
     */
    public static function shortUrl(string $token): string
    {
        return self::absoluteUrl('/v/' . substr($token, 0, self::SHORT_TOKEN_LENGTH));
    }

    /**
     * A full https://host/... URL. Email needs this: a mail client fetching
     * an image has no idea what our base path is, and cron runs have no
     * request to infer a host from — so set app.url in config.php.
     */
    public static function absoluteUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $base = rtrim((string) Config::get('app.url', ''), '/');

        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($path);
        }

        return $base . base_path() . $path;
    }

    /**
     * Can we build a link a client could actually click?
     *
     * In a web request the host header stands in for a missing app.url, so
     * links come out right. Cron has no request: the fallback resolves to
     * "localhost", and every share link, proof link and logo in a reminder
     * would point at the server's own loopback address. Better to hold the
     * message than to send a dead link to a customer.
     */
    public static function canBuildLinks(): bool
    {
        if (rtrim((string) Config::get('app.url', ''), '/') !== '') {
            return true;
        }

        return !empty($_SERVER['HTTP_HOST']);
    }

    /**
     * Replace {placeholders}. Unknown ones are stripped rather than left
     * visible, so a stray token never reaches a client.
     */
    public static function render(?string $template, array $context): string
    {
        $template = (string) $template;

        if ($template === '') {
            return '';
        }

        $out = preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            static function (array $m) use ($context): string {
                $key = strtolower($m[1]);
                $val = $context[$key] ?? '';
                return is_scalar($val) ? (string) $val : '';
            },
            $template
        );

        // Tidy up the gaps left by empty placeholders.
        $out = preg_replace('/ {2,}/', ' ', (string) $out);

        return trim((string) $out);
    }

    private static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'there';
        }
        return explode(' ', $name)[0];
    }

    // -- Automatic reminders -------------------------------------------

    /**
     * Chase overdue invoices on the configured day offsets.
     * A lock row per invoice-per-offset stops repeat chasing.
     *
     * @return array{queued:int, checked:int}
     */
    public static function queueOverdueReminders(): array
    {
        return self::chase(
            'invoice_overdue',
            'overdue',
            (string) Settings::get('notify_overdue_days', '1,7,14'),
            "d.doc_type = 'invoice'
               AND d.status NOT IN ('cancelled','paid','draft')
               AND d.balance > 0
               AND d.due_date IS NOT NULL
               AND DATEDIFF(CURDATE(), d.due_date) = :days",
            'days_overdue'
        );
    }

    /**
     * Nudge before the due date, not after. Same machinery as the overdue
     * chase, counting the other way.
     *
     * @return array{queued:int, checked:int}
     */
    public static function queueDueReminders(): array
    {
        return self::chase(
            'payment_reminder',
            'due',
            (string) Settings::get('notify_due_days', '3'),
            "d.doc_type = 'invoice'
               AND d.status NOT IN ('cancelled','paid','draft')
               AND d.balance > 0
               AND d.due_date IS NOT NULL
               AND DATEDIFF(d.due_date, CURDATE()) = :days",
            'days_to_due'
        );
    }

    /**
     * Chase a quotation before it lapses. Only ones still open — an accepted
     * or rejected quote needs no chasing.
     *
     * @return array{queued:int, checked:int}
     */
    public static function queueExpiringQuotations(): array
    {
        return self::chase(
            'quotation_expiring',
            'expiring',
            (string) Settings::get('notify_expiry_days', '3'),
            "d.doc_type = 'quotation'
               AND d.status = 'sent'
               AND d.valid_until IS NOT NULL
               AND DATEDIFF(d.valid_until, CURDATE()) = :days",
            'days_to_expiry'
        );
    }

    /**
     * The shared engine behind every date-based chase.
     *
     * @param string $event     Which notification to send
     * @param string $lockKey   Prefix for the idempotency lock
     * @param string $offsets   Comma-separated day offsets from settings
     * @param string $where     SQL predicate over documents d / clients c, using :days
     * @param string $dayField  Context key to expose the offset under
     *
     * @return array{queued:int, checked:int}
     */
    private static function chase(
        string $event,
        string $lockKey,
        string $offsets,
        string $where,
        string $dayField
    ): array {
        // Nothing enabled on either channel means nothing to do.
        if (!Settings::bool("notify_{$event}_email") && !Settings::bool("notify_{$event}_sms")) {
            return ['queued' => 0, 'checked' => 0];
        }

        $days = array_values(array_filter(array_map(
            static fn($d) => (int) trim($d),
            explode(',', $offsets)
        ), static fn($d) => $d >= 0));

        if ($days === []) {
            return ['queued' => 0, 'checked' => 0];
        }

        $queued  = 0;
        $checked = 0;

        foreach ($days as $offset) {
            $rows = Database::all(
                "SELECT d.*, c.name AS client_name, c.email AS client_email,
                        c.phone AS client_phone, c.contact_person AS client_contact
                   FROM documents d
                   JOIN clients c ON c.id = d.client_id
                  WHERE {$where}",
                ['days' => $offset]
            );

            foreach ($rows as $row) {
                $checked++;

                // INSERT on a unique key is our idempotency guard.
                try {
                    Database::run(
                        'INSERT INTO notification_locks (lock_key) VALUES (:k)',
                        ['k' => $lockKey . ':' . $row['id'] . ':' . $offset]
                    );
                } catch (\Throwable) {
                    continue;   // already chased at this offset
                }

                $context = self::documentContext($row);
                $context[$dayField] = (string) $offset;

                $queued += self::dispatch($event, $context)['queued'];
            }
        }

        if ($queued > 0) {
            ActivityLog::record(
                $event . '_queued',
                'notification',
                null,
                $queued . ' ' . str_replace('_', ' ', $event) . ' message(s) queued'
            );
        }

        return ['queued' => $queued, 'checked' => $checked];
    }
}
